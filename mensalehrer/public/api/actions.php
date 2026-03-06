<?php
// actions.php - API Endpoint zum Ausführen von Aktionen (Lehrer-Interface)

// --- 1. SICHERE CORS KONFIGURATION ---
$allowed_origins = [
    'https://lehrer.mensamanager.de'
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowed_origins)) {
header("Access-Control-Allow-Origin: $origin");
} else {
    header("Access-Control-Allow-Origin: " . $allowed_origins[0]);
}
header('Access-Control-Allow-Credentials: true');
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    exit;
}

require_once($_SERVER['DOCUMENT_ROOT'] . "/api/config.inc.php");
if (file_exists($_SERVER['DOCUMENT_ROOT'] . "/api/functions.inc.php")) {
    require_once($_SERVER['DOCUMENT_ROOT'] . "/api/functions.inc.php");
}

// --- 2. SICHERES SESSION MANAGEMENT ---
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '', 
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
    'httponly' => true,
    'samesite' => 'Strict'
]);
session_name("mensa_login");
session_start();

$isAuthorized = false;
$currentUserId = null;

if (isset($_SESSION['userid'])) {
    $stmt = $pdo->prepare("SELECT status FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['userid']]);
    $status = $stmt->fetchColumn();
    if ($status === 'ADMIN' || $status === 'TEACHER') {
        $isAuthorized = true;
        $currentUserId = $_SESSION['userid'];
    }
}

if (!$isAuthorized && isset($_COOKIE['identifier']) && isset($_COOKIE['securitytoken'])) {
    $stmt = $pdo->prepare("SELECT u.id, u.status, s.securitytoken FROM securitytokens s JOIN users u ON s.user_id = u.id WHERE s.identifier = ?");
    $stmt->execute([$_COOKIE['identifier']]);
    $tokenData = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($tokenData && sha1($_COOKIE['securitytoken']) === $tokenData['securitytoken']) {
        if ($tokenData['status'] === 'ADMIN' || $tokenData['status'] === 'TEACHER') {
            $isAuthorized = true;
            $currentUserId = $tokenData['id'];
        }
    }
}

if (!$isAuthorized) {
    http_response_code(403);
    echo json_encode(['error' => 'Nicht autorisiert. Bitte neu einloggen.']);
    exit;
}

$response = ['success' => false];
$action = $_POST['action'] ?? '';

try {
    switch ($action) {
        
        case 'assignCardNumber':
            $cardIdStr = $_POST['cardId'] ?? '';
            $cardNumber = $_POST['cardNumber'] ?? '';
            $faceData = $_POST['faceData'] ?? null;
            
            if (empty($cardIdStr) || empty($cardNumber)) {
                throw new \Exception('Fehlende Parameter (Schüler oder Kartennummer).');
            }

            $stmt = $pdo->prepare("SELECT card_id FROM chip_cards WHERE card_uid = ? AND active = 1");
            $stmt->execute([$cardNumber]);
            if($stmt->fetch()) {
                throw new \Exception("Fehler: Diese Kartennummer ist bereits im System vergeben!");
            }
            
            $blob = null;
            if ($faceData && preg_match('/^data:image\/png;base64,/', $faceData)) {
                $base64 = substr($faceData, strpos($faceData, ',') + 1);
                $blob = base64_decode($base64);
            }
            
            if (strpos($cardIdStr, 'pending_') === 0) {
                $holderId = (int) str_replace('pending_', '', $cardIdStr);
                
                $stmt = $pdo->prepare("SELECT created_by FROM card_holders WHERE holder_id = ?");
                $stmt->execute([$holderId]);
                $accountId = $stmt->fetchColumn();
                
                if (!$accountId) {
                    throw new \Exception("Fehler: Kein zugehöriges Eltern-Konto zum Schüler gefunden.");
                }
                
                $pdo->beginTransaction();
                if ($blob !== null) {
                    $stmt = $pdo->prepare("UPDATE card_holders SET holder_image = ? WHERE holder_id = ?");
                    $stmt->execute([$blob, $holderId]);
                }
                
                $stmt = $pdo->prepare("INSERT INTO chip_cards (card_uid, account_id, holder_id, issued_by, issued_at, active) VALUES (?, ?, ?, ?, CURDATE(), 1)");
                $response['success'] = $stmt->execute([$cardNumber, $accountId, $holderId, $currentUserId]);
                $pdo->commit();
                
            } else {
                $cardId = (int) str_replace('c', '', $cardIdStr);
                $pdo->beginTransaction();
                if ($blob !== null) {
                    $stmt = $pdo->prepare("SELECT holder_id FROM chip_cards WHERE card_id = ?");
                    $stmt->execute([$cardId]);
                    $hId = $stmt->fetchColumn();
                    if ($hId) {
                        $stmt = $pdo->prepare("UPDATE card_holders SET holder_image = ? WHERE holder_id = ?");
                        $stmt->execute([$blob, $hId]);
                    }
                }
                $stmt = $pdo->prepare("UPDATE chip_cards SET card_uid = ?, active = 1 WHERE card_id = ?");
                $response['success'] = $stmt->execute([$cardNumber, $cardId]);
                $pdo->commit();
            }
            break;

        case 'collectCard':
            $cardIdStr = $_POST['cardId'] ?? '';
            $studentIdStr = $_POST['studentId'] ?? '';
            $deleteStudent = !empty($_POST['deleteStudent']);
            
            $cardId = (int) str_replace('c', '', $cardIdStr);
            $studentId = (int) str_replace('s', '', $studentIdStr);

            if (empty($cardId)) {
                throw new \Exception('Keine Karten-ID angegeben.');
            }

            $pdo->beginTransaction();

            // --- SICHERHEIT: RACE CONDITION SCHUTZ (FOR UPDATE) ---
            // Sperrt diese Zeile in der Datenbank, bis die Transaktion abgeschlossen ist.
            // Verhindert doppelte Ausführung von Pfand-Rückerstattungen bei Doppel-Klicks.
            $stmt = $pdo->prepare("SELECT account_id FROM chip_cards WHERE card_id = ? FOR UPDATE");
            $stmt->execute([$cardId]);
            $accountId = $stmt->fetchColumn();

            if ($accountId) {
                $stmt = $pdo->query("SELECT def_value FROM default_values WHERE name = 'card_deposit'");
                $depositAmount = (float) $stmt->fetchColumn();

                if ($depositAmount > 0) {
                    $stmt = $pdo->prepare("UPDATE accounts SET balance = balance + ? WHERE account_id = ?");
                    $stmt->execute([$depositAmount, $accountId]);

                    $stmt = $pdo->prepare("INSERT INTO account_transactions (account_id, amount, transaction_type, description, occurred_at) VALUES (?, ?, 'REFUND', 'Kartenpfand zurückerstattet', NOW())");
                    $stmt->execute([$accountId, $depositAmount]);
                }
            }

            $stmt = $pdo->prepare("DELETE FROM chip_cards WHERE card_id = ?");
            $stmt->execute([$cardId]);

            if ($deleteStudent) {
                $stmt = $pdo->prepare("DELETE FROM subscriptions WHERE holder_id = ?");
                $stmt->execute([$studentId]);

                $stmt = $pdo->prepare("DELETE FROM card_holders WHERE holder_id = ?");
                $stmt->execute([$studentId]);
            }

            $pdo->commit();
            $response['success'] = true;
            break;

        default:
            throw new \Exception('Unbekannte Aktion.');
    }
} catch (\Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Mensa Teacher API Error [actions.php]: " . $e->getMessage());
    $response['error'] = $e->getMessage();
}

echo json_encode($response);