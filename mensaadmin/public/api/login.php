<?php

require_once __DIR__ . '/../../shared/php/mm_security.php';
require_once __DIR__ . '/config.inc.php';

mm_apply_cors('admin', ['GET', 'POST', 'OPTIONS'], ['Content-Type']);
mm_start_session('mensa_login');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && isset($_GET['config'])) {
    mm_json_response([
        'success' => true,
        'captchaSiteKey' => mm_get_hcaptcha_site_key(),
    ]);
}

// --- KONFIGURATION LOGINSICHERHEIT ---
$max_attempts = 5;
$lockout_time = 15;

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
$ip_address = mm_get_real_ip();

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

        mm_get_csrf_token();

        $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE ip_address = ?");
        $stmt->execute([$ip_address]);

        if ($stayLoggedIn) {
            try {
                mm_issue_remember_me_token($pdo, $user['id']);
            } catch (MmConfigurationException $exception) {
                mm_log_exception('admin_remember_me_configuration', $exception);
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'Daueranmeldung ist momentan nicht verfuegbar.']);
                exit;
            }
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

    try {
        if (!mm_verify_hcaptcha_token($captchaResponse, $ip_address)) {
            logFailedAttempt($pdo, $ip_address, $max_attempts, $lockout_time);
        }
    } catch (MmConfigurationException $exception) {
        mm_log_exception('admin_hcaptcha_configuration', $exception);
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Die Anmeldung ist momentan nicht verfuegbar.']);
        exit;
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
