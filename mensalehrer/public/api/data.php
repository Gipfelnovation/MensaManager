<?php
// data.php - API Endpoint exklusiv für das Lehrer-Interface

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
header('Content-Type: application/json; charset=utf-8');

try {
    require_once($_SERVER['DOCUMENT_ROOT'] . "/api/config.inc.php");
    require_once($_SERVER['DOCUMENT_ROOT'] . "/api/functions.inc.php");

    if (isset($pdo)) {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
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
    
    if (isset($_SESSION['userid'])) {
        $stmt = $pdo->prepare("SELECT status FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['userid']]);
        $status = $stmt->fetchColumn();
        if ($status === 'ADMIN' || $status === 'TEACHER') {
            $isAuthorized = true;
        }
    }

    if (!$isAuthorized && isset($_COOKIE['identifier']) && isset($_COOKIE['securitytoken'])) {
        $stmt = $pdo->prepare("SELECT u.id, u.status, s.securitytoken FROM securitytokens s JOIN users u ON s.user_id = u.id WHERE s.identifier = ?");
        $stmt->execute([$_COOKIE['identifier']]);
        $tokenData = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($tokenData && sha1($_COOKIE['securitytoken']) === $tokenData['securitytoken']) {
            if ($tokenData['status'] === 'ADMIN' || $tokenData['status'] === 'TEACHER') {
                $isAuthorized = true;
            }
        }
    }

    if (!$isAuthorized) {
        http_response_code(403);
        echo json_encode(['error' => 'Nicht autorisiert. Bitte einloggen.']);
        exit;
    }

    // --- DATENABFRAGEN FÜR LEHRER ---
    $action = $_GET['action'] ?? '';

    if ($action === 'pending') {
        $stmt = $pdo->query("
        SELECT 
                ch.holder_id AS studentId, 
                CONCAT(ch.first_name, ' ', ch.last_name) AS studentName, 
                ch.class AS grade, 
            CONCAT(u.vorname, ' ', u.nachname) AS parentName
        FROM card_holders ch
        JOIN users u ON ch.created_by = u.id
        LEFT JOIN chip_cards cc ON ch.holder_id = cc.holder_id
        WHERE cc.card_id IS NULL
    ");
        echo json_encode(['pendingCards' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    if ($action === 'active_cards') {
        $stmt = $pdo->query("
        SELECT 
                ch.holder_id AS studentId, 
                CONCAT(ch.first_name, ' ', ch.last_name) AS studentName, 
                ch.class AS grade, 
            CONCAT(u.vorname, ' ', u.nachname) AS parentName,
                cc.card_id AS cardId,
                (SELECT COUNT(*) FROM subscriptions sub WHERE sub.holder_id = ch.holder_id AND (sub.end_date >= CURDATE() OR sub.end_date IS NULL)) > 0 AS hasActiveAbo
        FROM card_holders ch
        JOIN chip_cards cc ON ch.holder_id = cc.holder_id
        JOIN accounts a ON cc.account_id = a.account_id
        JOIN users u ON a.user_id = u.id
    ");
        $cards = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($cards as &$card) {
            $card['hasActiveAbo'] = (bool)$card['hasActiveAbo'];
        }
        echo json_encode(['activeCards' => $cards]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Ungültige Aktion.']);

} catch (\Throwable $e) {
    http_response_code(500);
    error_log("Mensa Teacher API Error [data.php]: " . $e->getMessage());
    echo json_encode(['error' => 'Serverfehler aufgetreten.']);
}