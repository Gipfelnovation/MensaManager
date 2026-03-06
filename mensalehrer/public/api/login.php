<?php
// /api/login.php - Sicherer Endpoint für Login & Session Check
$allowed_origins = [
    'https://lehrer.mensamanager.de'
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowed_origins)) {
header("Access-Control-Allow-Origin: $origin");
} else {
    header("Access-Control-Allow-Origin: " . $allowed_origins[0]); // Fallback
}
header('Access-Control-Allow-Credentials: true');
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
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

require_once($_SERVER['DOCUMENT_ROOT'] . "/api/config.inc.php");

// --- SESSION CHECK (GET) ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['check'])) {
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
                session_regenerate_id(true); // Security: Verhindert Session-Fixation auch bei Autologin
                $_SESSION['userid'] = $tokenData['id'];
                $isAuthorized = true;
            }
        }
    }
    
    echo json_encode(['success' => $isAuthorized]);
    exit;
}

// --- LOGIN PROCESS (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $input = $_POST;
    if (empty($input)) {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
    }

    $email = trim($input['email'] ?? '');
    $password = $input['password'] ?? '';
    $captcha = $input['h-captcha-response'] ?? '';
    
    $ip_address = $_SERVER['REMOTE_ADDR'];
    $max_attempts = 5;
    $lockout_time = 15;

    // Brute-Force Schutz: Alte Logins löschen & IP prüfen
    $pdo->query("DELETE FROM login_attempts WHERE last_attempt < (NOW() - INTERVAL $lockout_time MINUTE)");
    $stmt = $pdo->prepare("SELECT attempts FROM login_attempts WHERE ip_address = ?");
    $stmt->execute([$ip_address]);
    $attempts = $stmt->fetchColumn();

    if ($attempts >= $max_attempts) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => "Zu viele Fehlversuche. Bitte warte $lockout_time Minuten."]);
        exit;
    }

    // Captcha Verifizierung
    if (empty($captcha)) {
        echo json_encode(['success' => false, 'error' => 'Bitte bestätige, dass du kein Roboter bist (Captcha fehlt).']);
        exit;
    }

    // ACHTUNG: Trage hier deinen Google reCAPTCHA Secret Key ein!
    $hcaptcha_secret = '***REMOVED***'; 
    
    if ($hcaptcha_secret !== 'DEIN_HCAPTCHA_SECRET_KEY' && !empty($hcaptcha_secret)) {
        $data = [
            'secret' => $hcaptcha_secret,
            'response' => $captcha
        ];
        $options = [
            'http' => [
                'method' => 'POST',
                'header' => 'Content-Type: application/x-www-form-urlencoded',
                'content' => http_build_query($data)
            ]
        ];
        $context = stream_context_create($options);
        $verifyResponse = file_get_contents('https://hcaptcha.com/siteverify', false, $context);
        $responseData = json_decode($verifyResponse);
        if (!$responseData || !$responseData->success) {
            echo json_encode(['success' => false, 'error' => 'Captcha ungültig. Bitte lade die Seite neu.']);
            exit;
        }
    }

    // Nutzer prüfen
    $stmt = $pdo->prepare("SELECT id, passwort, status FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['passwort'])) {
        if ($user['status'] === 'ADMIN' || $user['status'] === 'TEACHER') {
            
            // Fehlversuche zurücksetzen
            $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE ip_address = ?");
            $stmt->execute([$ip_address]);

            // --- SICHERHEIT: SESSION-FIXATION VERHINDERN ---
            session_regenerate_id(true); 
            $_SESSION['userid'] = $user['id'];
            
            // Security Token für "Angemeldet bleiben"
            $identifier = bin2hex(random_bytes(16));
            $securitytoken = bin2hex(random_bytes(16));
            $stmt = $pdo->prepare("INSERT INTO securitytokens (user_id, identifier, securitytoken) VALUES (?, ?, ?)");
            $stmt->execute([$user['id'], $identifier, sha1($securitytoken)]);
            
            $cookie_options = [
                'expires' => time() + (3600 * 24 * 365),
                'path' => '/',
                'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
                'httponly' => true,
                'samesite' => 'Strict'
            ];
            setcookie("identifier", $identifier, $cookie_options);
            setcookie("securitytoken", $securitytoken, $cookie_options);

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