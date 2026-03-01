<?php
// actions.php - API Endpoint zum Ausführen von Aktionen
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header("Access-Control-Allow-Origin: $origin");
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

// --- AUTHENTIFIZIERUNG ---
session_name("mensa_login");
session_start();

$isAdmin = false;
$currentUserId = null;

if (isset($_SESSION['userid'])) {
    $stmt = $pdo->prepare("SELECT status FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['userid']]);
    if ($stmt->fetchColumn() === 'ADMIN') {
        $isAdmin = true;
        $currentUserId = $_SESSION['userid'];
    }
}

if (!$isAdmin && isset($_COOKIE['identifier']) && isset($_COOKIE['securitytoken'])) {
    $stmt = $pdo->prepare("SELECT u.id, u.status, s.securitytoken FROM securitytokens s JOIN users u ON s.user_id = u.id WHERE s.identifier = ?");
    $stmt->execute([$_COOKIE['identifier']]);
    $tokenData = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($tokenData && sha1($_COOKIE['securitytoken']) === $tokenData['securitytoken']) {
        if ($tokenData['status'] === 'ADMIN') {
            $isAdmin = true;
            $_SESSION['userid'] = $tokenData['id'];
            $currentUserId = $tokenData['id'];
        }
    }
}

if (!$isAdmin || !$currentUserId) {
    http_response_code(403);
    echo json_encode(['error' => 'Zugriff verweigert. Session abgelaufen oder keine Admin-Rechte.']);
    exit;
}
// --- ENDE AUTHENTIFIZIERUNG ---


$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';
$data = $input['data'] ?? [];

$response = ['success' => false];

try {
    switch ($action) {
        
        case 'assignCardNumber':
            $cardIdStr = $data['cardId'];
            $cardNumber = $data['cardNumber'];
            $faceData = $data['faceData'] ?? null;
            
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

        case 'updateCardStatus':
            $cardId = (int) str_replace('c', '', $data['cardId']);
            $active = ($data['newStatus'] === 'Aktiv') ? 1 : 0;
            $stmt = $pdo->prepare("UPDATE chip_cards SET active = ?, deactivated_at = " . ($active ? "NULL" : "CURDATE()") . " WHERE card_id = ?");
            $response['success'] = $stmt->execute([$active, $cardId]);
            break;

        case 'refundTransaction':
            $txId = (int) str_replace('t', '', $data['txId']);
            $stmt = $pdo->prepare("SELECT account_id, amount, description FROM account_transactions WHERE transaction_id = ?");
            $stmt->execute([$txId]);
            $tx = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($tx) {
                $pdo->beginTransaction();
                $refundAmount = abs((float)$tx['amount']);
                $pdo->prepare("UPDATE accounts SET balance = balance + ? WHERE account_id = ?")->execute([$refundAmount, $tx['account_id']]);
                $desc = "Erstattung für: " . $tx['description'];
                $pdo->prepare("INSERT INTO account_transactions (account_id, amount, transaction_type, description, occurred_at) VALUES (?, ?, 'REFUND', ?, NOW())")
                    ->execute([$tx['account_id'], $refundAmount, $desc]);
                $pdo->commit();
                $response['success'] = true;
            } else {
                $response['error'] = 'Transaktion nicht gefunden';
            }
            break;

        case 'deleteAbo':
            $aboId = (int) str_replace('abo', '', $data['aboId']);
            $stmt = $pdo->prepare("DELETE FROM subscriptions WHERE subscription_id = ?");
            $response['success'] = $stmt->execute([$aboId]);
            break;

        case 'markTransactionPaid':
            $unpaidId = (int) $data['unpaidId'];
            $transactionNr = $data['transactionNr']; // z.B. "Überweisung 28.02.2025 123456"

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT ut.subscription_id, ut.transaction_id, t.amount, t.description, t.transaction_type, t.account_id 
                                   FROM unpaid_transactions ut
                                   JOIN account_transactions t ON ut.transaction_id = t.transaction_id
                                   WHERE ut.unpaid_id = ?");
            $stmt->execute([$unpaidId]);
            $ut = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($ut) {
                // 1. Beschreibung der Transaktion aktualisieren 
                // Ersetzt Klammern wie "(warten auf Zahlungseingang)" mit der neuen "(Überweisung ... PIN)"
                $newDesc = preg_replace('/\s*\([^)]*(warten|ausstehend|Zahlungseingang|unbezahlt)[^)]*\)/i', " ($transactionNr)", $ut['description']);
                if ($newDesc === $ut['description']) {
                    // Falls nichts zum Ersetzen gefunden wurde, einfach anhängen
                    $newDesc .= " ($transactionNr)";
                }

                $stmt = $pdo->prepare("UPDATE account_transactions SET description = ? WHERE transaction_id = ?");
                $stmt->execute([$newDesc, $ut['transaction_id']]);

                // 2. Abos Logik (falls die Zahlung mit einem Abo verknüpft ist)
                if (!empty($ut['subscription_id'])) {
            $stmt = $pdo->prepare("UPDATE subscriptions SET transaction_nr = ? WHERE subscription_id = ?");
                    $stmt->execute([$transactionNr, $ut['subscription_id']]);
                }

                // 3. Aufladung Logik: Bei TOPUP wird die amount nun der Account Balance hinzugefügt
                if ($ut['transaction_type'] === 'TOPUP') {
                    $stmt = $pdo->prepare("UPDATE accounts SET balance = balance + ? WHERE account_id = ?");
                    $stmt->execute([abs((float)$ut['amount']), $ut['account_id']]);
                }

                // 4. Aus unpaid_transactions löschen, da nun bezahlt
                $stmt = $pdo->prepare("DELETE FROM unpaid_transactions WHERE unpaid_id = ?");
                $stmt->execute([$unpaidId]);

                $pdo->commit();
                $response['success'] = true;
            } else {
                $pdo->rollBack();
                $response['error'] = 'Unbezahlte Transaktion nicht gefunden.';
            }
            break;

        case 'editStudent':
            $studentId = (int) str_replace('s', '', $data['studentId']);
            $names = explode(' ', $data['name'], 2);
            $firstName = $names[0];
            $lastName = $names[1] ?? '';
            $stmt = $pdo->prepare("UPDATE card_holders SET first_name = ?, last_name = ? WHERE holder_id = ?");
            $response['success'] = $stmt->execute([$firstName, $lastName, $studentId]);
            break;

        case 'deposit':
            $userId = (int) str_replace('p', '', $data['parentId']);
            $amount = (float) $data['amount'];
            $desc = $data['description'] ?: 'Manuelle Einzahlung (Admin)';

            if ($amount <= 0) {
                $response['error'] = 'Der Betrag muss größer als 0 sein.';
                break;
            }

            // Finde die Account-ID des Users
            $stmt = $pdo->prepare("SELECT account_id FROM accounts WHERE user_id = ?");
            $stmt->execute([$userId]);
            $accountId = $stmt->fetchColumn();

            if ($accountId) {
                $pdo->beginTransaction();
                
                // 1. Guthaben aufbuchen
                $stmt = $pdo->prepare("UPDATE accounts SET balance = balance + ? WHERE account_id = ?");
                $stmt->execute([$amount, $accountId]);
                
                // 2. Transaktionshistorie pflegen
                $stmt = $pdo->prepare("INSERT INTO account_transactions (account_id, amount, transaction_type, description, occurred_at) VALUES (?, ?, 'TOPUP', ?, NOW())");
                $stmt->execute([$accountId, $amount, $desc]);
                
                $pdo->commit();
                $response['success'] = true;
            } else {
                $response['error'] = 'Zugehöriges Konto wurde nicht gefunden.';
            }
            break;

        case 'updateSettings':
            // Verarbeitet alle gesendeten Schlüssel-Wert-Paare (Preise, Bankdaten oder Rechtliches)
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("INSERT INTO default_values (name, def_value) VALUES (:name, :val) ON DUPLICATE KEY UPDATE def_value = :val2");
                
                foreach ($data as $key => $value) {
                    // Sicherheit: Nur erlaubte Keys verarbeiten (optional, aber empfohlen)
                    $allowedKeys = [
                        'full_year_per_day', 'half_year_per_day', 'single_entry', 
                        'single_entry_reuse', 'card_deposit', 'school_name', 
                        'school_iban', 'school_bic', 'imprint', 'privacy'
                    ];
                    
                    if (in_array($key, $allowedKeys)) {
                        $stmt->execute([
                            'name' => $key,
                            'val' => (string)$value,
                            'val2' => (string)$value
                        ]);
                    }
                }
                
                $pdo->commit();
                $response['success'] = true;
            } catch (Exception $e) {
                $pdo->rollBack();
                $response['error'] = 'Fehler beim Speichern der Einstellungen: ' . $e->getMessage();
            }
            break;

        case 'updateUserRole':
            $stmt = $pdo->prepare("UPDATE users SET status = ? WHERE id = ?");
            $stmt->execute([$data['role'], $data['userId']]);
            $response['success'] = true;
            break;

        case 'deleteUserAccount':
            $accountId = $data['userId'];
            try {
                $pdo->beginTransaction();
                
                // 1. Alle Karten der Kinder löschen
                $pdo->prepare("DELETE FROM chip_cards WHERE account_id IN (SELECT account_id FROM accounts WHERE user_id = ?)")->execute([$accountId]);
                
                // 2. Alle Abos der Kinder löschen
                $pdo->prepare("DELETE FROM subscriptions WHERE holder_id IN (SELECT holder_id FROM card_holders WHERE created_by IN (SELECT account_id FROM accounts WHERE user_id = ?))")->execute([$accountId]);
                
                // 3. Alle Kinder (Holder) löschen
                $pdo->prepare("DELETE FROM card_holders WHERE created_by IN (SELECT account_id FROM accounts WHERE user_id = ?)")->execute([$accountId]);
                
                // 4. Alle Transaktionen löschen
                $pdo->prepare("DELETE FROM account_transactions WHERE account_id IN (SELECT account_id FROM accounts WHERE user_id = ?)")->execute([$accountId]);
                
                // 5. Den Verrechnungsaccount löschen
                $pdo->prepare("DELETE FROM accounts WHERE account_id IN (SELECT account_id FROM accounts WHERE user_id = ?)")->execute([$accountId]);

                // 6. Hauptkonto löschen
                $pdo->prepare("DELETE FROM users WHERE id  = ?")->execute([$accountId]);
                
                $pdo->commit();
                $response['success'] = true;
            } catch (Exception $e) {
                $pdo->rollBack();
                $response['error'] = 'Fehler bei der DSGVO-Löschung: ' . $e->getMessage();
            }
            break;

        case 'collectCard':
            // IDs bereinigen, falls Präfixe mitgeschickt werden (z.B. 'c1', 's2')
            $cardId = (int) str_replace('c', '', $data['cardId']);
            $studentId = (int) str_replace('s', '', $data['studentId']);
            $deleteStudent = !empty($data['deleteStudent']);
            
            $pdo->beginTransaction();
            
            // 1. Account-ID der Karte ermitteln (für die Rückerstattung)
            $stmt = $pdo->prepare("SELECT account_id FROM chip_cards WHERE card_id = ?");
            $stmt->execute([$cardId]);
            $accountId = $stmt->fetchColumn();
            
            if ($accountId) {
                // 2. Aktuellen Pfand-Wert aus den Settings holen
                $stmt = $pdo->query("SELECT def_value FROM default_values WHERE name = 'card_deposit'");
                $depositAmount = (float) $stmt->fetchColumn();
                
                if ($depositAmount > 0) {
                    // 3. Balance auf dem Eltern-Account erhöhen
                    $stmt = $pdo->prepare("UPDATE accounts SET balance = balance + ? WHERE account_id = ?");
                    $stmt->execute([$depositAmount, $accountId]);
                    
                    // 4. Transaktion "Pfand zurückerstattet" eintragen
                    $stmt = $pdo->prepare("INSERT INTO account_transactions (account_id, amount, transaction_type, description, occurred_at) VALUES (?, ?, 'REFUND', 'Kartenpfand zurückerstattet', NOW())");
                    $stmt->execute([$accountId, $depositAmount]);
                }
            }
            
            // 5. Karte aus dem System löschen
            $stmt = $pdo->prepare("DELETE FROM chip_cards WHERE card_id = ?");
            $stmt->execute([$cardId]);
            
            // 6. Falls $deleteStudent == true ist (Schüler hat keine aktiven Abos mehr)
            if ($deleteStudent) {
                // Lösche alle eventuell noch vorhandenen abgelaufenen Abos dieses Schülers
                $stmt = $pdo->prepare("DELETE FROM subscriptions WHERE holder_id = ?");
                $stmt->execute([$studentId]);
                
                // Lösche den Schüler (Holder) aus der Datenbank
                $stmt = $pdo->prepare("DELETE FROM card_holders WHERE holder_id = ?");
                $stmt->execute([$studentId]);
            }
            
            $pdo->commit();
            $response['success'] = true;
            break;

        default:
            $response['error'] = 'Unbekannte Aktion';
            break;
    }
} catch (\Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Mensa Admin API Error [actions.php]: " . $e->getMessage());
    $response['error'] = 'Ein unerwarteter Serverfehler ist aufgetreten. Bitte versuche es später erneut.';
}

echo json_encode($response);