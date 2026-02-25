<?php
// data.php - API Endpoint
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header("Access-Control-Allow-Origin: $origin");
header('Access-Control-Allow-Credentials: true');
header('Content-Type: application/json; charset=utf-8');

try {
    require_once($_SERVER['DOCUMENT_ROOT'] . "/api/config.inc.php");

    if (isset($pdo)) {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    // --- AUTHENTIFIZIERUNG ---
    session_name("mensa_login");
    session_start();

    $isAdmin = false;
    if (isset($_SESSION['userid'])) {
        $stmt = $pdo->prepare("SELECT status FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['userid']]);
        if ($stmt->fetchColumn() === 'ADMIN') {
            $isAdmin = true;
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
            }
        }
    }

    if (!$isAdmin) {
        http_response_code(403);
        echo json_encode(['error' => 'Zugriff verweigert. Session abgelaufen oder keine Admin-Rechte.']);
        exit;
    }
    // --- ENDE AUTHENTIFIZIERUNG ---

    $action = $_GET['action'] ?? 'dashboard';
    $response = [];

    if ($action === 'dashboard') {
        $response['stats'] = [
            'totalBalance' => 0.0,
            'activeCards' => 0,
            'pendingCards' => 0,
            'unpaidAbos' => 0
        ];

        $response['stats']['totalBalance'] = (float) $pdo->query("SELECT SUM(balance) FROM accounts")->fetchColumn();
        $response['stats']['activeCards'] = (int) $pdo->query("SELECT COUNT(*) FROM chip_cards WHERE active = 1")->fetchColumn();
        $response['stats']['pendingCards'] = (int) $pdo->query("SELECT COUNT(*) FROM card_holders h LEFT JOIN chip_cards c ON h.holder_id = c.holder_id WHERE c.card_id IS NULL")->fetchColumn();
        $response['stats']['unpaidAbos'] = (int) $pdo->query("SELECT COUNT(*) FROM subscriptions WHERE transaction_nr IS NULL OR transaction_nr = ''")->fetchColumn();

        $stmt = $pdo->query("
            SELECT t.transaction_id, a.user_id, t.occurred_at, t.amount, t.description, t.transaction_type 
            FROM account_transactions t
            JOIN accounts a ON t.account_id = a.account_id
            WHERE t.transaction_type != 'SUBSCRIPTION_USAGE'
            ORDER BY t.occurred_at DESC LIMIT 5
        ");
        $response['recentTransactions'] = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $response['recentTransactions'][] = [
                'id' => 't' . $row['transaction_id'],
                'date' => $row['occurred_at'],
                'amount' => (float) $row['amount'],
                'description' => $row['description'] ?? 'Keine Beschreibung',
            ];
        }

        // Standardwerte auslesen
        $response['defaultValues'] = [
            'full_year_per_day' => '0.00',
            'half_year_per_day' => '0.00',
            'single_entry' => '0.00',
            'single_entry_reuse' => '0.00',
            'card_deposit' => '0.00'
        ];
        $stmt = $pdo->query("SELECT name, def_value FROM default_values WHERE name IN ('full_year_per_day', 'half_year_per_day', 'single_entry', 'single_entry_reuse', 'card_deposit')");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $response['defaultValues'][$row['name']] = $row['def_value'];
        }
    } 
    
    elseif ($action === 'search') {
        $query = $_GET['q'] ?? '';
        $searchTerm = "%$query%";
        $results = [];

        if (strlen($query) >= 2) {
            $stmt = $pdo->prepare("SELECT id, email, CONCAT(vorname, ' ', nachname) AS name FROM users WHERE email LIKE ? OR vorname LIKE ? OR nachname LIKE ? LIMIT 5");
            $stmt->execute([$searchTerm, $searchTerm, $searchTerm]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $results[] = ['title' => $row['name'], 'subtitle' => $row['email'], 'category' => 'Elternaccount', 'parentId' => 'p' . $row['id'], 'iconType' => 'Users'];
            }

            $stmt = $pdo->prepare("SELECT h.holder_id, h.first_name, h.last_name, h.class, a.user_id FROM card_holders h JOIN accounts a ON h.created_by = a.account_id WHERE h.first_name LIKE ? OR h.last_name LIKE ? LIMIT 5");
            $stmt->execute([$searchTerm, $searchTerm]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $results[] = ['title' => $row['first_name'] . ' ' . $row['last_name'], 'subtitle' => 'Klasse ' . $row['class'], 'category' => 'Schülerprofil', 'parentId' => 'p' . $row['user_id'], 'iconType' => 'Edit2'];
            }

            $stmt = $pdo->prepare("SELECT c.card_uid, h.first_name, h.last_name, a.user_id FROM chip_cards c JOIN card_holders h ON c.holder_id = h.holder_id JOIN accounts a ON h.created_by = a.account_id WHERE c.card_uid LIKE ? LIMIT 5");
            $stmt->execute([$searchTerm]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $results[] = ['title' => 'Karte: ' . $row['card_uid'], 'subtitle' => 'Gehört zu: ' . $row['first_name'] . ' ' . $row['last_name'], 'category' => 'Kartennummer', 'parentId' => 'p' . $row['user_id'], 'iconType' => 'CreditCard'];
            }
        }
        $response['results'] = $results;
    } 
    
    elseif ($action === 'parent') {
        $parentId = (int) str_replace('p', '', $_GET['id'] ?? '0');
        
        $stmt = $pdo->prepare("SELECT u.id, CONCAT(u.vorname, ' ', u.nachname) AS name, u.email, a.balance FROM users u JOIN accounts a ON u.id = a.user_id WHERE u.id = ?");
        $stmt->execute([$parentId]);
        $parent = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($parent) {
            $response['parent'] = [
                'id' => 'p' . $parent['id'],
                'name' => $parent['name'],
                'email' => $parent['email'] ?? 'keine@email.de',
                'balance' => (float) $parent['balance']
            ];

            $response['students'] = [];
            $stmt = $pdo->prepare("SELECT h.holder_id, CONCAT(h.first_name, ' ', h.last_name) AS name, h.class FROM card_holders h JOIN accounts a ON h.created_by = a.account_id WHERE a.user_id = ?");
            $stmt->execute([$parentId]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $response['students'][] = ['id' => 's' . $row['holder_id'], 'name' => $row['name'], 'grade' => $row['class'], 'parentId' => 'p' . $parentId];
            }

            $response['cards'] = [];
            $stmt = $pdo->prepare("
                SELECT c.card_id, c.holder_id, c.card_uid, c.active, c.issued_at 
                FROM chip_cards c JOIN card_holders h ON c.holder_id = h.holder_id JOIN accounts a ON h.created_by = a.account_id WHERE a.user_id = ?
            ");
            $stmt->execute([$parentId]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $response['cards'][] = ['id' => 'c' . $row['card_id'], 'studentId' => 's' . $row['holder_id'], 'cardNumber' => $row['card_uid'], 'status' => ($row['active'] == 1) ? 'Aktiv' : 'Gesperrt'];
            }

            $stmt = $pdo->prepare("
                SELECT h.holder_id FROM card_holders h LEFT JOIN chip_cards c ON h.holder_id = c.holder_id JOIN accounts a ON h.created_by = a.account_id 
                WHERE a.user_id = ? AND c.card_id IS NULL
            ");
            $stmt->execute([$parentId]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $response['cards'][] = ['id' => 'pending_' . $row['holder_id'], 'studentId' => 's' . $row['holder_id'], 'cardNumber' => '-', 'status' => 'Bestellt'];
            }

            // Abos (Status anhand von transaction_nr prüfen + Daten & Tage auslesen)
            $response['subscriptions'] = [];
            $stmt = $pdo->prepare("SELECT s.subscription_id, s.holder_id, s.type, s.transaction_nr, s.weekdays, s.start_date, s.end_date FROM subscriptions s JOIN card_holders h ON s.holder_id = h.holder_id JOIN accounts a ON h.created_by = a.account_id WHERE a.user_id = ?");
            $stmt->execute([$parentId]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $status = empty($row['transaction_nr']) ? 'Unbezahlt' : 'Bezahlt';
                $response['subscriptions'][] = [
                    'id' => 'abo' . $row['subscription_id'], 
                    'studentId' => 's' . $row['holder_id'], 
                    'planName' => $row['type'], 
                    'status' => $status,
                    'transactionNr' => $row['transaction_nr'],
                    'weekdays' => $row['weekdays'],
                    'startDate' => $row['start_date'],
                    'endDate' => $row['end_date'],
                    'price' => 50.0
                ];
            }

            $response['transactions'] = [];
            $stmt = $pdo->prepare("SELECT t.transaction_id, t.occurred_at, t.amount, t.description, t.transaction_type FROM account_transactions t JOIN accounts a ON t.account_id = a.account_id WHERE a.user_id = ? AND t.transaction_type != 'SUBSCRIPTION_USAGE' ORDER BY t.occurred_at DESC");
            $stmt->execute([$parentId]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $response['transactions'][] = ['id' => 't' . $row['transaction_id'], 'date' => $row['occurred_at'], 'amount' => (float) $row['amount'], 'description' => $row['description'], 'status' => ($row['transaction_type'] === 'REFUND') ? 'Erstattet' : 'Abgeschlossen'];
            }
        } else {
            http_response_code(404);
            $response['error'] = 'Elternaccount nicht gefunden';
        }
    }

    elseif ($action === 'pending') {
        $response['pendingCards'] = [];
        $stmt = $pdo->query("
            SELECT h.holder_id, h.created_at, CONCAT(h.first_name, ' ', h.last_name) AS student_name, h.class, u.id AS parent_id, CONCAT(u.vorname, ' ', u.nachname) AS parent_name
            FROM card_holders h
            LEFT JOIN chip_cards c ON h.holder_id = c.holder_id
            JOIN accounts a ON h.created_by = a.account_id
            JOIN users u ON a.user_id = u.id
            WHERE c.card_id IS NULL
        ");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $response['pendingCards'][] = [
                'id' => 'pending_' . $row['holder_id'],
                'orderDate' => $row['created_at'],
                'studentName' => $row['student_name'],
                'grade' => $row['class'],
                'parentId' => 'p' . $row['parent_id'],
                'parentName' => $row['parent_name']
            ];
        }
    }
    
    elseif ($action === 'unpaid') {
        $response['unpaidAbos'] = [];
        $stmt = $pdo->query("
            SELECT s.subscription_id, s.type, h.holder_id, CONCAT(h.first_name, ' ', h.last_name) AS student_name, h.class, u.id AS parent_id, CONCAT(u.vorname, ' ', u.nachname) AS parent_name
            FROM subscriptions s
            JOIN card_holders h ON s.holder_id = h.holder_id
            JOIN accounts a ON h.created_by = a.account_id
            JOIN users u ON a.user_id = u.id
            WHERE s.transaction_nr IS NULL OR s.transaction_nr = ''
        ");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $response['unpaidAbos'][] = [
                'id' => 'abo' . $row['subscription_id'],
                'planName' => $row['type'],
                'studentId' => 's' . $row['holder_id'],
                'studentName' => $row['student_name'],
                'grade' => $row['class'],
                'parentId' => 'p' . $row['parent_id'],
                'parentName' => $row['parent_name']
            ];
        }
    }

    $jsonOutput = json_encode($response, JSON_INVALID_UTF8_SUBSTITUTE);
    if ($jsonOutput === false) throw new \Exception("JSON Error: " . json_last_error_msg());
    echo $jsonOutput;

} catch (\Throwable $e) {
    http_response_code(500);
    error_log("Backend Absturz [data.php]: " . $e->getMessage() . " in Zeile " . $e->getLine());
    echo json_encode(['error' => "Ein Fehler beim Laden der Datenbank ist aufgetreten."]);
}