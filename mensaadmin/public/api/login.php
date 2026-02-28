<?php
// /api/login.php
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header("Access-Control-Allow-Origin: $origin");
header('Access-Control-Allow-Credentials: true');
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

session_name("mensa_login");
session_start();

require_once($_SERVER['DOCUMENT_ROOT'] . "/api/config.inc.php");
require_once($_SERVER['DOCUMENT_ROOT'] . "/api/functions.inc.php");

// --- KONFIGURATION LOGINSICHERHEIT ---
$max_attempts = 5;               // Maximale Fehlversuche
$lockout_time = 15;              // Sperrzeit in Minuten
$recaptcha_secret = '***REMOVED***';

$ip_address = $_SERVER['REMOTE_ADDR'];

// 1. Alte (abgelaufene) Login-Versuche bereinigen
$pdo->query("DELETE FROM login_attempts WHERE last_attempt < (NOW() - INTERVAL $lockout_time MINUTE)");

// 2. Prüfen, ob die IP aktuell gesperrt ist
$stmt = $pdo->prepare("SELECT attempts FROM login_attempts WHERE ip_address = ?");
$stmt->execute([$ip_address]);
$attempts = $stmt->fetchColumn();

if ($attempts && $attempts >= $max_attempts) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Zu viele fehlgeschlagene Logins. Bitte warte ' . $lockout_time . ' Minuten.']);
    exit;
}

// Da React die Daten als JSON Body sendet, lesen wir sie hier aus
$input = json_decode(file_get_contents('php://input'), true);

// 3. Captcha validieren
$captchaToken = $input['captcha'] ?? '';
if (empty($captchaToken)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Bitte bestätige, dass du kein Roboter bist (Captcha fehlt).']);
    exit;
}

$verifyResponse = file_get_contents('https://www.google.com/recaptcha/api/siteverify?secret=' . $recaptcha_secret . '&response=' . $captchaToken);
$responseData = json_decode($verifyResponse);

if (!$responseData->success) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Captcha-Überprüfung fehlgeschlagen. Bitte lade die Seite neu.']);
    exit;
}

// 4. Eigentlicher Login-Prozess
if (isset($input['email']) && isset($input['passwort'])) {
    $email = $input['email'];
    $passwort = $input['passwort'];

    $statement = $pdo->prepare("SELECT * FROM users WHERE email = :email");
    $statement->execute(array('email' => $email));
    $user = $statement->fetch();

    // Überprüfung des Passworts
    if ($user !== false && password_verify($passwort, $user['passwort'])) {
        
        // ZUGANGSKONTROLLE: Nur Admins reinlassen!
        if ($user['status'] === "ADMIN") {
            
            // BEI ERFOLG: IP aus den Fehlversuchen löschen
            $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE ip_address = ?");
            $stmt->execute([$ip_address]);
            
            $_SESSION['userid'] = $user['id'];
            
            // "Angemeldet bleiben" Logik
            if (!empty($input['angemeldet_bleiben'])) {
                $identifier = random_string();
                $securitytoken = random_string();
                
                $insert = $pdo->prepare("INSERT INTO securitytokens (user_id, identifier, securitytoken) VALUES (:user_id, :identifier, :securitytoken)");
                $insert->execute(array('user_id' => $user['id'], 'identifier' => $identifier, 'securitytoken' => sha1($securitytoken)));
                
                $host = $_SERVER['HTTP_HOST'] ?? '';
                $host = preg_replace('/:\d+$/', '', $host);
                $parts = explode('.', $host);
                if (count($parts) > 2) {
                    array_shift($parts);
                }
                $root = implode('.', $parts);
                $root = preg_replace('/^www\./', '', $root);
                $cookieDomain = '.' . $root;
                $cookieExpire = time() + (3600*24*365);
                $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

                $cookieOptions = [
                    'expires'  => $cookieExpire,
                    'path'     => '/',
                    'domain'   => $cookieDomain,
                    'secure'   => $secure,
                    'httponly' => true,
                    'samesite' => 'None'
                ];
                setcookie("identifier", $identifier, $cookieOptions);
                setcookie("securitytoken", $securitytoken, $cookieOptions);
                
                // Lokaler Fallback
                $localOptions = $cookieOptions;
                $localOptions['domain'] = '';
                setcookie("identifier", $identifier, $localOptions);
                setcookie("securitytoken", $securitytoken, $localOptions);
            }
            
            echo json_encode(['success' => true, 'isAdmin' => true]);
            exit;
        } else {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Zugriff verweigert. Nur für Admins freigegeben.']);
            exit;
        }
    } else {
        // Falsches Passwort oder Email -> Wird wie ein Fehlversuch behandelt
        $errorMsg = 'E-Mail oder Passwort war ungültig.';
        $httpCode = 401;
    }
    
    // --- FEHLGESCHLAGENER LOGIN (Passwort falsch oder kein Admin) ---
    $stmt = $pdo->prepare("INSERT INTO login_attempts (ip_address, attempts, last_attempt) 
                           VALUES (?, 1, NOW()) 
                           ON DUPLICATE KEY UPDATE attempts = attempts + 1, last_attempt = NOW()");
    $stmt->execute([$ip_address]);
    
    $attemptsNow = ($attempts ? $attempts : 0) + 1;
    $remaining = $max_attempts - $attemptsNow;

    if ($remaining <= 0) {
        $errorMsg = 'Zu viele fehlgeschlagene Logins. Bitte warte ' . $lockout_time . ' Minuten.';
        $httpCode = 429;
    } else {
        $errorMsg .= " Noch $remaining Versuch(e) übrig.";
    }

    http_response_code($httpCode);
    echo json_encode(['success' => false, 'error' => $errorMsg]);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Keine Daten übermittelt']);