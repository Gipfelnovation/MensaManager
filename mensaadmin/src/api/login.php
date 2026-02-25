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

// Da React die Daten als JSON Body sendet, lesen wir sie hier aus
$input = json_decode(file_get_contents('php://input'), true);

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
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'E-Mail oder Passwort war ungültig']);
        exit;
    }
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Keine Daten übermittelt']);