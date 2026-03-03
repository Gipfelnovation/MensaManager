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
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Strict'
]);
session_start();

require_once($_SERVER['DOCUMENT_ROOT'] . "/api/config.inc.php");
require_once($_SERVER['DOCUMENT_ROOT'] . "/api/functions.inc.php");

// --- KONFIGURATION LOGINSICHERHEIT ---
$max_attempts = 5;               // Maximale Fehlversuche
$lockout_time = 15;              // Sperrzeit in Minuten
$hcaptcha_secret = '***REMOVED***';

// --- TOTP HILFSFUNKTIONEN ---
function generateBase32Secret($length = 16) {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = '';
    for ($i = 0; $i < $length; $i++) {
        $secret .= $chars[random_int(0, 31)];
    }
    return $secret;
}

function base32_decode($b32) {
    $b32 = strtoupper($b32);
    if (!preg_match('/^[A-Z2-7]+$/', $b32)) return false;
    $l = strlen($b32); $n = 0; $j = 0; $val = '';
    $map = array(
        'A'=>0, 'B'=>1, 'C'=>2, 'D'=>3, 'E'=>4, 'F'=>5, 'G'=>6, 'H'=>7,
        'I'=>8, 'J'=>9, 'K'=>10, 'L'=>11, 'M'=>12, 'N'=>13, 'O'=>14, 'P'=>15,
        'Q'=>16, 'R'=>17, 'S'=>18, 'T'=>19, 'U'=>20, 'V'=>21, 'W'=>22, 'X'=>23,
        'Y'=>24, 'Z'=>25, '2'=>26, '3'=>27, '4'=>28, '5'=>29, '6'=>30, '7'=>31
    );
    for ($i = 0; $i < $l; $i++) {
        $n = $n << 5;
        $n = $n + $map[$b32[$i]];
        $j = $j + 5;
        if ($j >= 8) {
            $j = $j - 8;
            $val .= chr(($n & (0xFF << $j)) >> $j);
        }
    }
    return $val;
}

function verifyTOTP($secret, $code, $window = 1) {
    if (empty($secret)) return false;
    $decoded = base32_decode($secret);
    if (!$decoded) return false;
    $time = floor(time() / 30);
    for ($i = -$window; $i <= $window; $i++) {
        $t = pack('N*', 0) . pack('N*', $time + $i);
        $hash = hash_hmac('sha1', $t, $decoded, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $calculated = (
            ((ord($hash[$offset+0]) & 0x7F) << 24) |
            ((ord($hash[$offset+1]) & 0xFF) << 16) |
            ((ord($hash[$offset+2]) & 0xFF) << 8) |
            (ord($hash[$offset+3]) & 0xFF)
        ) % 1000000;
        if (str_pad($calculated, 6, '0', STR_PAD_LEFT) === $code) {
            return true;
        }
    }
    return false;
}

// --- IP SCHUTZ ---
function getRealIpAddr() {
    $ip = $_SERVER['REMOTE_ADDR'];
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $forwarded_ip = trim($ips[0]);
        if (filter_var($forwarded_ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            $ip = $forwarded_ip;
        }
    }
    return $ip;
}
$ip_address = getRealIpAddr();

function logFailedAttempt($pdo, $ip_address, $max_attempts, $lockout_time) {
    $stmt = $pdo->prepare("INSERT INTO login_attempts (ip_address, attempts, last_attempt) 
                           VALUES (?, 1, NOW()) 
                           ON DUPLICATE KEY UPDATE attempts = attempts + 1, last_attempt = NOW()");
    $stmt->execute([$ip_address]);
    
    $stmt = $pdo->prepare("SELECT attempts FROM login_attempts WHERE ip_address = ?");
    $stmt->execute([$ip_address]);
    $attemptsNow = $stmt->fetchColumn();
    
    $remaining = $max_attempts - $attemptsNow;
    if ($remaining <= 0) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => 'Zu viele Fehlversuche. Bitte warte ' . $lockout_time . ' Minuten.']);
    } else {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Anmeldung fehlgeschlagen. Noch ' . $remaining . ' Versuch(e) übrig.']);
    }
    exit;
}

// 1. Alte Versuche bereinigen & Lockout prüfen
$pdo->query("DELETE FROM login_attempts WHERE last_attempt < (NOW() - INTERVAL $lockout_time MINUTE)");
$stmt = $pdo->prepare("SELECT attempts FROM login_attempts WHERE ip_address = ?");
$stmt->execute([$ip_address]);
if (($attempts = $stmt->fetchColumn()) && $attempts >= $max_attempts) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Zu viele fehlgeschlagene Logins. Bitte warte ' . $lockout_time . ' Minuten.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Keine Daten übermittelt']);
    exit;
}

$action = $input['action'] ?? 'login';

// ==========================================
// SCHRITT 2: 2FA CODE VERIFIZIERUNG
// ==========================================
if ($action === 'verify_2fa') {
    if (empty($_SESSION['pending_2fa_userid'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Kein ausstehender Login. Bitte lade die Seite neu.']);
        exit;
    }

    $code = $input['totp_code'] ?? '';
    $stayLoggedIn = $input['angemeldet_bleiben'] ?? false;

    if (!preg_match('/^[0-9]{6}$/', $code)) {
        logFailedAttempt($pdo, $ip_address, $max_attempts, $lockout_time);
    }

    $stmt = $pdo->prepare("SELECT id, totp_secret FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['pending_2fa_userid']]);
    $user = $stmt->fetch();

    if ($user && verifyTOTP($user['totp_secret'], $code)) {
        // --- 2FA ERFOLGREICH: VOLLSTÄNDIGER LOGIN ---
        session_regenerate_id(true);
        $_SESSION['userid'] = $user['id'];
        unset($_SESSION['pending_2fa_userid']);

        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE ip_address = ?");
        $stmt->execute([$ip_address]);

        if ($stayLoggedIn) {
            $identifier = bin2hex(random_bytes(16));
            $securitytoken = bin2hex(random_bytes(16));
            
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
                'samesite' => 'Strict'
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
        logFailedAttempt($pdo, $ip_address, $max_attempts, $lockout_time);
    }
}

// ==========================================
// SCHRITT 1: PASSWORT & CAPTCHA VERIFIZIERUNG
// ==========================================
if ($action === 'login') {
$captchaResponse = $input['captcha'] ?? '';
if (empty($captchaResponse)) {
    http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Bitte bestätige, dass du kein Roboter bist.']);
    exit;
}

$verifyResponse = file_get_contents('https://hcaptcha.com/siteverify', false, stream_context_create([
            'http' => [
                'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                'method' => 'POST',
                'content' => http_build_query([
                    'secret' => $hcaptcha_secret,
                    'response' => $captchaResponse,
                    'remoteip' => $ip_address
                ])
            ]
        ]));
    
$responseData = json_decode($verifyResponse);
    if (!$responseData || !$responseData->success) {
        logFailedAttempt($pdo, $ip_address, $max_attempts, $lockout_time);
    }

    $email = $input['email'] ?? '';
    $passwort = $input['passwort'] ?? '';

    $statement = $pdo->prepare("SELECT * FROM users WHERE email = :email");
    $statement->execute(array('email' => $email));
    $user = $statement->fetch();

    if ($user !== false && password_verify($passwort, $user['passwort'])) {
        
        // ZUGANGSKONTROLLE: Nur Admins reinlassen!
        if ($user['status'] !== "ADMIN") {
            logFailedAttempt($pdo, $ip_address, $max_attempts, $lockout_time);
            }
            
        // --- AUTHENTIFIZIERUNG TEIL 1 ERFOLGREICH ---
            $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE ip_address = ?");
            $stmt->execute([$ip_address]);

            $_SESSION['pending_2fa_userid'] = $user['id'];

        // Prüfen, ob 2FA eingerichtet ist
        if (empty($user['totp_secret'])) {
            $newSecret = generateBase32Secret();
            $pdo->prepare("UPDATE users SET totp_secret = ? WHERE id = ?")->execute([$newSecret, $user['id']]);
            echo json_encode(['success' => true, 'requires2FA' => true, 'setupSecret' => $newSecret, 'email' => $email]);
        } else {
            echo json_encode(['success' => true, 'requires2FA' => true]);
        }
        exit;
    } else {
        logFailedAttempt($pdo, $ip_address, $max_attempts, $lockout_time);
    }
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Ungültige Aktion.']);
?>