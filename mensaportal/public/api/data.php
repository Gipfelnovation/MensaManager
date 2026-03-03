<?php
// --- 1. SITZUNGSSICHERHEIT (Cookies härten) ---
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1);
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_strict_mode', 1);

session_name("mensa_login");

// --- 2. CORS EINSCHRÄNKUNG (Strikte Whitelist) ---
$allowedOrigins = [
    'https://www.mensamanager.de',
    'https://mensamanager.de'
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins)) {
    header("Access-Control-Allow-Origin: $origin");
} else {
    header("Access-Control-Allow-Origin: https://www.mensamanager.de");
}

header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

session_start();

require_once('db-connect.php');

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Datenbankverbindung fehlgeschlagen.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$action = $_GET['action'] ?? ($input['action'] ?? '');

// ==========================================
// ÖFFENTLICHE ENDPUNKTE (Kein Login nötig)
// ==========================================

if ($action === 'getLegalContent') {
    $type = $_GET['type'] ?? '';
    $dbKey = '';
    
    if ($type === 'imprint') $dbKey = 'imprint';
    elseif ($type === 'privacy') $dbKey = 'privacy';
    else {
        echo json_encode(['status' => 'error', 'message' => 'Ungültiger Typ angefordert.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT def_value FROM default_values WHERE name = ?");
    $stmt->execute([$dbKey]);
    $res = $stmt->fetch();

    echo json_encode([
        'status' => 'success',
        'content' => $res ? $res['def_value'] : 'Inhalt wurde noch nicht hinterlegt.'
    ]);
    exit;
}

// ==========================================
// AUTHENTIFIZIERUNG (Login, Register, Logout)
// ==========================================

if ($action === 'login') {
    $email = trim($input['email'] ?? '');
    $password = $input['passwort'] ?? '';
    $captchaToken = $input['captchaToken'] ?? '';

    if (empty($email) || empty($password)) {
        echo json_encode(['status' => 'error', 'message' => 'Bitte alle Felder ausfüllen.']);
        exit;
    }

    if (empty($captchaToken)) {
        echo json_encode(['status' => 'error', 'message' => 'Bitte bestätige, dass du ein Mensch bist (Captcha).']);
        exit;
    }

    $max_attempts = 5;               // Maximale Fehlversuche
    $lockout_time = 15;              // Sperrzeit in Minuten
    $hcaptcha_secret = '***REMOVED***';
    $ip_address = $_SERVER['REMOTE_ADDR'];

    // 1. Alte (abgelaufene) Login-Versuche bereinigen
    $pdo->query("DELETE FROM login_attempts WHERE last_attempt < (NOW() - INTERVAL $lockout_time MINUTE)");

    // 2. Prüfen, ob die IP aktuell gesperrt ist
    $stmt = $pdo->prepare("SELECT attempts FROM login_attempts WHERE ip_address = ?");
    $stmt->execute([$ip_address]);
    $attempts = $stmt->fetchColumn();

    if ($attempts !== false && $attempts >= $max_attempts) {
        http_response_code(429);
        echo json_encode(['status' => 'error', 'message' => 'Zu viele fehlgeschlagene Logins. Bitte warte ' . $lockout_time . ' Minuten.']);
        exit;
    }

    // 3. hCaptcha verifizieren
    $verifyResponse = file_get_contents('https://hcaptcha.com/siteverify', false, stream_context_create([
        'http' => [
            'header' => "Content-type: application/x-www-form-urlencoded\r\n",
            'method' => 'POST',
            'content' => http_build_query([
                'secret' => $hcaptcha_secret,
                'response' => $captchaToken,
                'remoteip' => $ip_address
            ])
        ]
    ]));
    
    $responseData = json_decode($verifyResponse);
    if (!$responseData || !$responseData->success) {
        echo json_encode(['status' => 'error', 'message' => 'Captcha-Verifizierung fehlgeschlagen. Bitte versuche es erneut.']);
        exit;
    }

    // 4. Benutzer prüfen
    $stmt = $pdo->prepare("SELECT id, passwort FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $userRow = $stmt->fetch();

    // PW-Verifizierung (Voraussetzung: Passwörter sind mit password_hash() gespeichert!)
    if ($userRow && password_verify($password, $userRow['passwort'])) {
        // Erfolgreicher Login: Brute-Force Zähler zurücksetzen
        $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE ip_address = ?");
        $stmt->execute([$ip_address]);

        // SICHERHEIT: Session-Fixation Schutz! Alte Session verwerfen, neue ID generieren.
        session_regenerate_id(true);
        $_SESSION['userid'] = $userRow['id'];
        
        echo json_encode(['status' => 'success']);
    } else {
        // Fehlgeschlagener Login: Zähler erhöhen
        if ($attempts === false) {
            $stmt = $pdo->prepare("INSERT INTO login_attempts (ip_address, attempts, last_attempt) VALUES (?, 1, NOW())");
            $stmt->execute([$ip_address]);
        } else {
            $stmt = $pdo->prepare("UPDATE login_attempts SET attempts = attempts + 1, last_attempt = NOW() WHERE ip_address = ?");
            $stmt->execute([$ip_address]);
        }
        
        // SICHERHEIT: Anti-Brute-Force (Künstliche Verzögerung bei falschem Login)
        sleep(1); 
        echo json_encode(['status' => 'error', 'message' => 'E-Mail oder Passwort falsch.']);
    }
    exit;
}

if ($action === 'register') {
    $vorname = htmlspecialchars(strip_tags(trim($input['vorname'] ?? '')));
    $nachname = htmlspecialchars(strip_tags(trim($input['nachname'] ?? '')));
    $email = trim($input['email'] ?? '');
    $passwort = $input['passwort'] ?? '';
    $passwort2 = $input['passwort2'] ?? '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['status' => 'error', 'message' => 'Ungültige E-Mail Adresse.']);
        exit;
    }
    
    $passwordRegex = '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).{8,}$/';
    if (!preg_match($passwordRegex, $passwort)) {
        echo json_encode(['status' => 'error', 'message' => 'Das Passwort muss mind. 8 Zeichen, Groß-/Kleinbuchstaben, Zahlen und Sonderzeichen enthalten.']);
        exit;
    }

    if ($passwort !== $passwort2) {
        echo json_encode(['status' => 'error', 'message' => 'Passwörter stimmen nicht überein.']);
        exit;
    }

    // Prüfen ob E-Mail existiert
    $stmt = $pdo->prepare("SELECT 1 FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetchColumn()) {
        echo json_encode(['status' => 'error', 'message' => 'Diese E-Mail ist bereits registriert.']);
        exit;
    }

    try {
        $pdo->beginTransaction();
        
        // Passwort sicher hashen!
        $hashedPassword = password_hash($passwort, PASSWORD_DEFAULT);
        
        $stmt = $pdo->prepare("INSERT INTO users (vorname, nachname, email, passwort) VALUES (?, ?, ?, ?)");
        $stmt->execute([$vorname, $nachname, $email, $hashedPassword]);
        $newUserId = $pdo->lastInsertId();

        // Account für Finanzen anlegen
        $stmtAcc = $pdo->prepare("INSERT INTO accounts (user_id, balance) VALUES (?, 0.00)");
        $stmtAcc->execute([$newUserId]);

        $pdo->commit();
        echo json_encode(['status' => 'success']);
    } catch(Exception $e) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'Fehler bei der Registrierung.']);
    }
    exit;
}

if ($action === 'logout') {
    // Session komplett zerstören
    $_SESSION = array();
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
    }
    session_destroy();
    echo json_encode(['status' => 'success']);
    exit;
}

// ==========================================
// GESCHÜTZTE ENDPUNKTE (Login erforderlich)
// ==========================================

if ($action === 'getData') {
    if (!isset($_SESSION['userid'])) {
        http_response_code(401);
        echo json_encode(['status' => 'unauthorized', 'message' => 'Bitte einloggen.']);
        exit;
    }

    $userId = (int)$_SESSION['userid'];

    // User & Account-Balance holen
    $stmt = $pdo->prepare("
        SELECT u.vorname, u.nachname, u.email, a.balance, a.account_id 
        FROM users u 
        LEFT JOIN accounts a ON u.id = a.user_id 
        WHERE u.id = ?
    ");
    $stmt->execute([$userId]);
    $userData = $stmt->fetch();

    if (!$userData) {
        echo json_encode(['status' => 'error', 'message' => 'Benutzer nicht gefunden.']);
        exit;
    }

    $accountId = $userData['account_id'];

    $response = [
        'status' => 'success',
        'data' => [
            'user' => [
                'firstName' => $userData['vorname'],
                'lastName'  => $userData['nachname'],
                'email'     => $userData['email'],
                'balance'   => (float)$userData['balance']
            ],
            'transactions' => [],
            'abos'         => [],
            'cards'        => [],
            'config'       => []
        ]
    ];

    // --- System-Konfiguration (default_values) laden ---
    $stmtConf = $pdo->query("SELECT name, def_value FROM default_values WHERE name IN ('card_deposit', 'full_year_per_day', 'half_year_per_day', 'school_name', 'school_bic', 'school_iban')");
    $configData = [];
    while ($row = $stmtConf->fetch()) {
        $name = $row['name'];
        $val = $row['def_value'];
        
        if (in_array($name, ['card_deposit', 'full_year_per_day', 'half_year_per_day'])) {
            $configData[$name] = (float)$val;
        } else {
            $configData[$name] = $val;
        }
    }
    $response['data']['config'] = $configData;

    if ($accountId) {
        // Transaktionen (Sicher, da hart auf $accountId gefiltert wird)
        $stmt = $pdo->prepare("
            SELECT transaction_id, amount, transaction_type, occurred_at, description 
            FROM account_transactions 
            WHERE account_id = ? AND transaction_type != 'SUBSCRIPTION_USAGE'
            ORDER BY occurred_at DESC
        ");
        $stmt->execute([$accountId]);
        $txs = $stmt->fetchAll();
        
        foreach ($txs as $tx) {
            $isDeposit = in_array($tx['transaction_type'], ['TOPUP', 'REFUND', 'REUSE']);
            $icon = 'Wallet';
            if (in_array($tx['transaction_type'], ['USAGE', 'SUBSCRIPTION_USAGE'])) $icon = 'Utensils';
            if ($tx['transaction_type'] === 'SUBSCRIPTION_PURCHASE') $icon = 'CalendarDays';
            if ($tx['transaction_type'] === 'TOPUP' && stripos($tx['description'], 'Bar') !== false) $icon = 'Banknote';

            $response['data']['transactions'][] = [
                'id'          => $tx['transaction_id'],
                'type'        => $isDeposit ? 'deposit' : 'expense',
                'amount'      => (float)$tx['amount'],
                'date'        => date('d.m.Y', strtotime($tx['occurred_at'])),
                'description' => !empty($tx['description']) ? $tx['description'] : $tx['transaction_type'],
                'iconName'    => $icon
            ];
        }

        // Abo-Verwendungen
        $stmtUsages = $pdo->prepare("
            SELECT t.occurred_at, c.holder_id 
            FROM account_transactions t
            JOIN chip_cards c ON t.card_id = c.card_uid
            WHERE t.account_id = ? AND t.transaction_type = 'SUBSCRIPTION_USAGE'
        ");
        $stmtUsages->execute([$accountId]);
        $usages = $stmtUsages->fetchAll();

        // Abos (Sicher, da created_by = $accountId)
        $stmt = $pdo->prepare("
            SELECT s.subscription_id, s.type, s.weekdays, s.start_date, s.end_date, h.holder_id, h.first_name, h.last_name
            FROM subscriptions s
            JOIN card_holders h ON s.holder_id = h.holder_id
            WHERE h.created_by = ?
        ");
        $stmt->execute([$accountId]);
        $subs = $stmt->fetchAll();

        foreach ($subs as $sub) {
            $isActive = (strtotime($sub['start_date']) <= time() && strtotime($sub['end_date']) >= time());
            $daysMap = ['MONDAY'=>'Mo', 'TUESDAY'=>'Di', 'WEDNESDAY'=>'Mi', 'THURSDAY'=>'Do', 'FRIDAY'=>'Fr'];
            $daysMapNum = ['MONDAY'=>1, 'TUESDAY'=>2, 'WEDNESDAY'=>3, 'THURSDAY'=>4, 'FRIDAY'=>5];
            
            $rawDays = explode(',', $sub['weekdays']);
            $daysArray = [];
            $validDays = [];
            foreach ($rawDays as $d) {
                $dTrim = trim($d);
                $daysArray[] = $daysMap[$dTrim] ?? $dTrim;
                if (isset($daysMapNum[$dTrim])) $validDays[] = $daysMapNum[$dTrim];
            }
            $subType = 'Abo';
            if ($sub['type'] === 'HALF_YEAR') $subType = 'Halbjahresabo';
            if ($sub['type'] === 'FULL_YEAR') $subType = 'Ganzjahresabo';

            $usageCount = 0;
            $subStart = strtotime($sub['start_date'] . ' 00:00:00');
            $subEnd = strtotime($sub['end_date'] . ' 23:59:59');

            foreach ($usages as $use) {
                if ($use['holder_id'] == $sub['holder_id']) {
                    $useTime = strtotime($use['occurred_at']);
                    if ($useTime >= $subStart && $useTime <= $subEnd) {
                        $useDay = (int)date('N', $useTime);
                        if (in_array($useDay, $validDays)) $usageCount++;
                    }
                }
            }

            $response['data']['abos'][] = [
                'id'         => $sub['subscription_id'],
                'type'       => $subType,
                'student'    => $sub['first_name'] . ' ' . $sub['last_name'],
                'days'       => $daysArray,
                'validUntil' => date('d.m.Y', strtotime($sub['end_date'])),
                'isActive'   => $isActive,
                'usageCount' => $usageCount
            ];
        }

        // Karten & Holder (Sicher, da created_by = $accountId)
        $stmt = $pdo->prepare("
            SELECT h.holder_id, h.first_name, h.last_name, 
                   c.card_uid, c.active,
                   COALESCE(
                       (SELECT s.type
                       FROM subscriptions s
                       WHERE s.holder_id = h.holder_id
                       AND s.start_date <= CURDATE()
                       AND s.end_date   >= CURDATE()
                       LIMIT 1),
                       'PREPAID'
                   ) AS subscription_type
            FROM card_holders h
            LEFT JOIN chip_cards c ON h.holder_id = c.holder_id
            WHERE h.created_by = ?
        ");
        $stmt->execute([$accountId]);
        $cards = $stmt->fetchAll();

        foreach ($cards as $card) {
            $hasCard = !empty($card['card_uid']);
            $response['data']['cards'][] = [
                'holderId'      => $card['holder_id'],
                'id'            => $hasCard ? $card['card_uid'] : 'Wartend...',
                'student'       => htmlspecialchars($card['first_name'] . ' ' . $card['last_name']),
                'status'        => $hasCard ? ($card['active'] ? 'Aktiv' : 'Gesperrt') : 'Karte ausstehend',
                'isPrepaidOnly' => ($card['subscription_type'] === 'PREPAID'),
                'img'           => "https://api.dicebear.com/7.x/avataaars/svg?seed=" . urlencode($card['first_name'])
            ];
        }
    }

    echo json_encode($response);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Aktion nicht gefunden.']);