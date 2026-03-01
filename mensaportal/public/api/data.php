<?php
// Session Name definieren, wie in deinem Originalcode
session_name("mensa_login");

// --- CORS Configuration für lokales React Setup ---
if (isset($_SERVER['HTTP_ORIGIN'])) {
    header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
} else {
    header("Access-Control-Allow-Origin: *");
}
header("Access-Control-Allow-Credentials: true"); // Wichtig für Cookies/Sessions via Fetch
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header('Content-Type: application/json; charset=utf-8');

// OPTIONS Requests für Preflight abfangen
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

// Lese JSON-Input aus
$input = json_decode(file_get_contents('php://input'), true);
$action = $_GET['action'] ?? ($input['action'] ?? 'getData');

// ==========================================
// 1. ACTION: LOGIN
// ==========================================
if ($action === 'login') {
    $email = trim($input['email'] ?? '');
    $passwort = $input['passwort'] ?? '';

    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :email");
    $stmt->execute(['email' => $email]);
    $userRow = $stmt->fetch();

    if ($userRow !== false && password_verify($passwort, $userRow['passwort'])) {
        $_SESSION['userid'] = $userRow['id'];
        $_SESSION['status'] = $userRow['status'];
        echo json_encode(['status' => 'success', 'message' => 'Login erfolgreich']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'E-Mail oder Passwort war ungültig.']);
    }
    exit;
}

// ==========================================
// 2. ACTION: REGISTER
// ==========================================
if ($action === 'register') {
    $vorname = trim($input['vorname'] ?? '');
    $nachname = trim($input['nachname'] ?? '');
    $email = trim($input['email'] ?? '');
    $passwort = $input['passwort'] ?? '';
    $passwort2 = $input['passwort2'] ?? '';

    if(empty($vorname) || empty($nachname) || empty($email) || empty($passwort)) {
        echo json_encode(['status' => 'error', 'message' => 'Bitte alle Felder ausfüllen.']);
        exit;
    }
    if(!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['status' => 'error', 'message' => 'Bitte eine gültige E-Mail-Adresse eingeben.']);
        exit;
    }
    if($passwort !== $passwort2) {
        echo json_encode(['status' => 'error', 'message' => 'Die Passwörter müssen übereinstimmen.']);
        exit;
    }

    // Check ob Email schon existiert
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email");
    $stmt->execute(['email' => $email]);
    if($stmt->fetch() !== false) {
        echo json_encode(['status' => 'error', 'message' => 'Diese E-Mail-Adresse ist bereits vergeben.']);
        exit;
    }

    // User anlegen
    $passwort_hash = password_hash($passwort, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users (email, passwort, vorname, nachname) VALUES (:email, :passwort, :vorname, :nachname)");
    $result = $stmt->execute([
        'email' => $email, 
        'passwort' => $passwort_hash, 
        'vorname' => $vorname, 
        'nachname' => $nachname
    ]);

    if ($result) {
        $userId = $pdo->lastInsertId();
        // Account für den User erstellen
        $accStmt = $pdo->prepare("INSERT INTO accounts (user_id) VALUES (:uid)");
        $accStmt->execute(['uid' => $userId]);
        
        echo json_encode(['status' => 'success', 'message' => 'Account erfolgreich angelegt.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Beim Abspeichern ist ein Fehler aufgetreten.']);
    }
    exit;
}

// ==========================================
// 3. ACTION: LOGOUT
// ==========================================
if ($action === 'logout') {
    session_destroy();
    echo json_encode(['status' => 'success']);
    exit;
}

if ($action === 'getLegalContent') {
    $type = $_GET['type'] ?? '';
    $dbKey = '';
    
    if ($type === 'imprint') {
        $dbKey = 'imprint';
    } elseif ($type === 'privacy') {
        $dbKey = 'privacy';
    } else {
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
// 4. ACTION: GET DATA (Dashboard Daten laden)
// ==========================================
if ($action === 'getData') {
    if (!isset($_SESSION['userid'])) {
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
    $stmtConf = $pdo->query("SELECT name, def_value FROM default_values WHERE name IN ('card_deposit', 'full_year_per_day', 'half_year_per_day', 'imprint', 'privacy', 'school_name', 'school_bic', 'school_iban')");
    $configData = [];
    while ($row = $stmtConf->fetch()) {
        $name = $row['name'];
        $val = $row['def_value'];
        
        // Numerische Werte konvertieren, Texte lassen
        if (in_array($name, ['card_deposit', 'full_year_per_day', 'half_year_per_day'])) {
            $configData[$name] = (float)$val;
        } else {
            $configData[$name] = $val;
        }
    }
    $response['data']['config'] = $configData;

    if ($accountId) {
        // Transaktionen (SUBSCRIPTION_USAGE herausfiltern)
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

        // Abo-Verwendungen (SUBSCRIPTION_USAGE) laden für die Zählung (über card_id -> chip_cards -> holder_id verknüpft)
        $stmtUsages = $pdo->prepare("
            SELECT t.occurred_at, c.holder_id 
            FROM account_transactions t
            JOIN chip_cards c ON t.card_id = c.card_uid
            WHERE t.account_id = ? AND t.transaction_type = 'SUBSCRIPTION_USAGE'
        ");
        $stmtUsages->execute([$accountId]);
        $usages = $stmtUsages->fetchAll();

        // Abos (inklusive holder_id, um Nutzungen zuzuordnen)
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
                if (isset($daysMapNum[$dTrim])) {
                    $validDays[] = $daysMapNum[$dTrim]; // Numerische Repräsentation der erlaubten Wochentage
                }
            }
            $subType = 'Abo';
            if ($sub['type'] === 'HALF_YEAR') $subType = 'Halbjahresabo';
            if ($sub['type'] === 'FULL_YEAR') $subType = 'Ganzjahresabo';

            // Zählung der tatsächlichen Nutzungen für dieses spezifische Abo
            $usageCount = 0;
            $subStart = strtotime($sub['start_date'] . ' 00:00:00');
            $subEnd = strtotime($sub['end_date'] . ' 23:59:59');

            foreach ($usages as $use) {
                if ($use['holder_id'] == $sub['holder_id']) {
                    $useTime = strtotime($use['occurred_at']);
                    // Prüfen, ob im Gültigkeitszeitraum
                    if ($useTime >= $subStart && $useTime <= $subEnd) {
                        $useDay = (int)date('N', $useTime); // 1 (Mo) bis 7 (So)
                        // Prüfen, ob an einem gültigen Wochentag
                        if (in_array($useDay, $validDays)) {
                            $usageCount++;
                        }
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
                'usageCount' => $usageCount // Neu: Die ermittelten Nutzungen
            ];
        }

        // Karten & Holder (Auch Holder ohne zugewiesene Karte werden geladen)
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
                'student'       => $card['first_name'] . ' ' . $card['last_name'],
                'status'        => $hasCard ? ($card['active'] ? 'Aktiv' : 'Gesperrt') : 'Karte ausstehend',
                'isPrepaidOnly' => ($card['subscription_type'] === 'PREPAID'),
                'img'           => "https://api.dicebear.com/7.x/avataaars/svg?seed=" . urlencode($card['first_name'])
            ];
        }
    }

    echo json_encode($response);
    exit;
}