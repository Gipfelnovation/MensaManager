<?php
// /api/login.php - Minimaler Endpoint für Login & Session Check
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header("Access-Control-Allow-Origin: $origin");
header('Access-Control-Allow-Credentials: true');
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

session_name("mensa_login");
session_start();

require_once($_SERVER['DOCUMENT_ROOT'] . "/api/config.inc.php");

// --- 1. SESSION CHECK (Wird beim Laden der App aufgerufen via ?check=1) ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['check'])) {
    $isAuthorized = false;
    
    // Prüfe aktuelle Session
    if (isset($_SESSION['userid'])) {
        $stmt = $pdo->prepare("SELECT status FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['userid']]);
        $status = $stmt->fetchColumn();
        if ($status === 'ADMIN' || $status === 'TEACHER') {
            $isAuthorized = true;
        }
    }
    
    // Prüfe "Angemeldet bleiben" Cookie
    if (!$isAuthorized && isset($_COOKIE['identifier']) && isset($_COOKIE['securitytoken'])) {
        $stmt = $pdo->prepare("SELECT u.id, u.status, s.securitytoken FROM securitytokens s JOIN users u ON s.user_id = u.id WHERE s.identifier = ?");
        $stmt->execute([$_COOKIE['identifier']]);
        $tokenData = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($tokenData && sha1($_COOKIE['securitytoken']) === $tokenData['securitytoken']) {
            if ($tokenData['status'] === 'ADMIN' || $tokenData['status'] === 'TEACHER') {
                $_SESSION['userid'] = $tokenData['id'];
                $isAuthorized = true;
            }
        }
    }
    
    echo json_encode(['success' => $isAuthorized]);
    exit;
}

// --- 2. LOGIN PROCESS (Wird beim Absenden des Formulars aufgerufen) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Lese Daten aus (Unterstützt FormData und JSON)
    $input = $_POST;
    if (empty($input)) {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
    }

    $email = trim($input['email'] ?? '');
    $password = $input['password'] ?? '';
    $captcha = $input['g-recaptcha-response'] ?? '';
    
    $ip_address = $_SERVER['REMOTE_ADDR'];
    $max_attempts = 5;
    $lockout_time = 15;

    // 1. Alte (abgelaufene) Login-Versuche bereinigen
    $pdo->query("DELETE FROM login_attempts WHERE last_attempt < (NOW() - INTERVAL $lockout_time MINUTE)");

    // 2. Prüfen, ob die IP aktuell gesperrt ist
    $stmt = $pdo->prepare("SELECT attempts FROM login_attempts WHERE ip_address = ?");
    $stmt->execute([$ip_address]);
    $attempts = $stmt->fetchColumn();

    if ($attempts >= $max_attempts) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => "Zu viele Fehlversuche. Bitte warte $lockout_time Minuten."]);
        exit;
    }

    // 3. Captcha Check
    if (empty($captcha)) {
        echo json_encode(['success' => false, 'error' => 'Bitte bestätige, dass du kein Roboter bist (Captcha fehlt).']);
        exit;
    }

    // ACHTUNG: Trage hier deinen Google reCAPTCHA Secret Key ein!
    $recaptcha_secret = '***REMOVED***'; 
    
    if ($recaptcha_secret !== 'DEIN_SECRET_KEY' && !empty($recaptcha_secret)) {
        $verifyResponse = file_get_contents('https://www.google.com/recaptcha/api/siteverify?secret=' . $recaptcha_secret . '&response=' . $captcha);
        $responseData = json_decode($verifyResponse);
        if (!$responseData->success) {
            echo json_encode(['success' => false, 'error' => 'Captcha ungültig. Bitte lade die Seite neu.']);
            exit;
        }
    }

    // 4. E-Mail und Passwort prüfen
    $stmt = $pdo->prepare("SELECT id, passwort, status FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['passwort'])) {
        // Erfolgreicher Login - Prüfe auf Admin oder Lehrer
        if ($user['status'] === 'ADMIN' || $user['status'] === 'TEACHER') {
            
            // Fehlversuche zurücksetzen
            $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE ip_address = ?");
            $stmt->execute([$ip_address]);

            $_SESSION['userid'] = $user['id'];
            
            // Security Token für "Angemeldet bleiben"
            $identifier = bin2hex(random_bytes(16));
            $securitytoken = bin2hex(random_bytes(16));
            $stmt = $pdo->prepare("INSERT INTO securitytokens (user_id, identifier, securitytoken) VALUES (?, ?, ?)");
            $stmt->execute([$user['id'], $identifier, sha1($securitytoken)]);
            
            setcookie("identifier", $identifier, time()+(3600*24*365), '/', '', true, true);
            setcookie("securitytoken", $securitytoken, time()+(3600*24*365), '/', '', true, true);

            echo json_encode(['success' => true]);
            exit;
        } else {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Zugriff verweigert. Dieser Bereich ist nur für Lehrer.']);
            exit;
        }
    } else {
        // Fehlgeschlagener Login eintragen
        $stmt = $pdo->prepare("INSERT INTO login_attempts (ip_address, attempts, last_attempt) 
                               VALUES (?, 1, NOW()) 
                               ON DUPLICATE KEY UPDATE attempts = attempts + 1, last_attempt = NOW()");
        $stmt->execute([$ip_address]);
        
        $attemptsNow = ($attempts ? $attempts : 0) + 1;
        $remaining = $max_attempts - $attemptsNow;

        http_response_code(401);
        echo json_encode(['success' => false, 'error' => "E-Mail oder Passwort falsch. Noch $remaining Versuch(e)."]);
        exit;
    }
}