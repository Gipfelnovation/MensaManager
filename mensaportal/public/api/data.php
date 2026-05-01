<?php

require_once __DIR__ . '/mm_bootstrap.php';

mm_apply_cors('portal', ['GET', 'POST', 'OPTIONS'], ['Content-Type', 'X-CSRF-Token']);
mm_start_session('mensa_portal_login');

try {
    $pdo = mm_build_pdo();
} catch (Throwable $exception) {
    mm_log_exception('portal_data_bootstrap', $exception);
    mm_json_response([
        'status' => 'error',
        'message' => 'Die Daten konnten gerade nicht geladen werden.',
    ], 500);
}

function portal_data_input()
{
    static $input = null;

    if ($input !== null) {
        return $input;
    }

    $decoded = json_decode(file_get_contents('php://input'), true);
    $input = is_array($decoded) ? $decoded : [];

    return $input;
}

function portal_require_method($method)
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $method) {
        mm_json_response([
            'status' => 'error',
            'message' => 'Methode nicht erlaubt.',
        ], 405);
    }
}

function portal_get_session_user_id()
{
    return isset($_SESSION['userid']) ? (int) $_SESSION['userid'] : 0;
}

function portal_require_logged_in_user_id()
{
    $userId = portal_get_session_user_id();
    if ($userId <= 0) {
        mm_json_response([
            'status' => 'unauthorized',
            'message' => 'Bitte einloggen.',
        ], 401);
    }

    return $userId;
}

$input = portal_data_input();
$action = (string) ($_GET['action'] ?? ($input['action'] ?? ''));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && isset($_GET['config'])) {
    mm_json_response([
        'status' => 'success',
        'captchaSiteKey' => mm_get_hcaptcha_site_key(),
        'paypalClientId' => mm_get_paypal_client_id(),
    ]);
}

if ($action === 'getLegalContent') {
    portal_require_method('GET');

    $type = (string) ($_GET['type'] ?? '');
    $dbKey = '';

    if ($type === 'imprint') {
        $dbKey = 'imprint';
    } elseif ($type === 'privacy') {
        $dbKey = 'privacy';
    } else {
        mm_json_response([
            'status' => 'error',
            'message' => 'Ungueltiger Typ angefordert.',
        ], 400);
    }

    $statement = $pdo->prepare('SELECT def_value FROM default_values WHERE name = ?');
    $statement->execute([$dbKey]);
    $result = $statement->fetch(PDO::FETCH_ASSOC);

    mm_json_response([
        'status' => 'success',
        'content' => $result ? $result['def_value'] : 'Inhalt wurde noch nicht hinterlegt.',
    ]);
}

if ($action === 'login') {
    portal_require_method('POST');

    $email = trim((string) ($input['email'] ?? ''));
    $password = (string) ($input['passwort'] ?? '');
    $captchaToken = (string) ($input['captchaToken'] ?? '');

    if ($email === '' || $password === '') {
        mm_json_response([
            'status' => 'error',
            'message' => 'Bitte alle Felder ausfuellen.',
        ], 400);
    }

    if ($captchaToken === '') {
        mm_json_response([
            'status' => 'error',
            'message' => 'Bitte bestaetige, dass du ein Mensch bist (Captcha).',
        ], 400);
    }

    $maxAttempts = 5;
    $lockoutTime = 15;
    $ipAddress = mm_get_real_ip();

    $pdo->query("DELETE FROM login_attempts WHERE last_attempt < (NOW() - INTERVAL $lockoutTime MINUTE)");

    $attemptStatement = $pdo->prepare('SELECT attempts FROM login_attempts WHERE ip_address = ?');
    $attemptStatement->execute([$ipAddress]);
    $attempts = $attemptStatement->fetchColumn();

    if ($attempts !== false && (int) $attempts >= $maxAttempts) {
        mm_json_response([
            'status' => 'error',
            'message' => "Zu viele fehlgeschlagene Logins. Bitte warte $lockoutTime Minuten.",
        ], 429);
    }

    try {
        if (!mm_verify_hcaptcha_token($captchaToken, $ipAddress)) {
            mm_json_response([
                'status' => 'error',
                'message' => 'Captcha-Verifizierung fehlgeschlagen. Bitte versuche es erneut.',
            ], 400);
        }
    } catch (MmConfigurationException $exception) {
        mm_log_exception('portal_hcaptcha_configuration', $exception);
        mm_json_response([
            'status' => 'error',
            'message' => 'Die Anmeldung ist momentan nicht verfuegbar.',
        ], 500);
    }

    $userStatement = $pdo->prepare('SELECT id, passwort FROM users WHERE email = ?');
    $userStatement->execute([$email]);
    $userRow = $userStatement->fetch(PDO::FETCH_ASSOC);

    if ($userRow && password_verify($password, (string) $userRow['passwort'])) {
        $clearStatement = $pdo->prepare('DELETE FROM login_attempts WHERE ip_address = ?');
        $clearStatement->execute([$ipAddress]);

        session_regenerate_id(true);
        $_SESSION['userid'] = (int) $userRow['id'];

        mm_json_response([
            'status' => 'success',
            'csrfToken' => mm_get_csrf_token(),
        ]);
    }

    $recordFailure = $pdo->prepare(
        'INSERT INTO login_attempts (ip_address, attempts, last_attempt)
         VALUES (?, 1, NOW())
         ON DUPLICATE KEY UPDATE attempts = attempts + 1, last_attempt = NOW()'
    );
    $recordFailure->execute([$ipAddress]);

    sleep(1);

    mm_json_response([
        'status' => 'error',
        'message' => 'E-Mail oder Passwort falsch.',
    ], 401);
}

if ($action === 'register') {
    portal_require_method('POST');

    $firstName = htmlspecialchars(strip_tags(trim((string) ($input['vorname'] ?? ''))), ENT_QUOTES, 'UTF-8');
    $lastName = htmlspecialchars(strip_tags(trim((string) ($input['nachname'] ?? ''))), ENT_QUOTES, 'UTF-8');
    $email = trim((string) ($input['email'] ?? ''));
    $password = (string) ($input['passwort'] ?? '');
    $passwordRepeat = (string) ($input['passwort2'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        mm_json_response([
            'status' => 'error',
            'message' => 'Ungueltige E-Mail Adresse.',
        ], 400);
    }

    $passwordRegex = '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).{8,}$/';
    if (!preg_match($passwordRegex, $password)) {
        mm_json_response([
            'status' => 'error',
            'message' => 'Das Passwort muss mind. 8 Zeichen, Gross-/Kleinbuchstaben, Zahlen und Sonderzeichen enthalten.',
        ], 400);
    }

    if ($password !== $passwordRepeat) {
        mm_json_response([
            'status' => 'error',
            'message' => 'Passwoerter stimmen nicht ueberein.',
        ], 400);
    }

    $existingUserStatement = $pdo->prepare('SELECT 1 FROM users WHERE email = ?');
    $existingUserStatement->execute([$email]);
    if ($existingUserStatement->fetchColumn()) {
        mm_json_response([
            'status' => 'error',
            'message' => 'Diese E-Mail ist bereits registriert.',
        ], 409);
    }

    try {
        $pdo->beginTransaction();

        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $userStatement = $pdo->prepare(
            "INSERT INTO users (vorname, nachname, email, passwort, status) VALUES (?, ?, ?, ?, 'USER')"
        );
        $userStatement->execute([$firstName, $lastName, $email, $hashedPassword]);
        $newUserId = (int) $pdo->lastInsertId();

        $accountStatement = $pdo->prepare('INSERT INTO accounts (user_id, balance) VALUES (?, 0.00)');
        $accountStatement->execute([$newUserId]);

        $pdo->commit();
        mm_json_response(['status' => 'success']);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        mm_log_exception('portal_register', $exception);
        mm_json_response([
            'status' => 'error',
            'message' => 'Fehler bei der Registrierung.',
        ], 500);
    }
}

if ($action === 'logout') {
    portal_require_method('POST');

    if ($userId > 0) {
        try {
            mm_require_csrf_token();
        } catch (MmClientException $exception) {
            mm_json_response([
                'status' => 'error',
                'message' => $exception->getMessage(),
            ], 403);
        }
    }

    $identifier = $_COOKIE['identifier'] ?? null;
    $allPossibleSessions = [
        'mensa_login',
        'mensa_portal_login',
        'mensa_admin_login',
        'mensa_teacher_login',
        'PHPSESSID'
    ];
    $sessionsToDelete = array_values(array_intersect($allPossibleSessions, array_keys($_COOKIE)));

    mm_logout_user($pdo, $userId, $identifier, $sessionsToDelete);
    mm_json_response(['status' => 'success']);
}

if ($action === 'getData') {
    portal_require_method('GET');

    $userId = portal_require_logged_in_user_id();

    $userStatement = $pdo->prepare(
        'SELECT u.vorname, u.nachname, u.email, a.balance, a.account_id
         FROM users u
         LEFT JOIN accounts a ON u.id = a.user_id
         WHERE u.id = ?'
    );
    $userStatement->execute([$userId]);
    $userData = $userStatement->fetch(PDO::FETCH_ASSOC);

    if (!$userData) {
        mm_destroy_session();
        mm_json_response([
            'status' => 'error',
            'message' => 'Benutzer nicht gefunden.',
        ], 404);
    }

    $accountId = $userData['account_id'];
    $csrfToken = mm_get_csrf_token();

    $response = [
        'status' => 'success',
        'csrfToken' => $csrfToken,
        'data' => [
            'user' => [
                'firstName' => $userData['vorname'],
                'lastName' => $userData['nachname'],
                'email' => $userData['email'],
                'balance' => (float) $userData['balance'],
            ],
            'transactions' => [],
            'abos' => [],
            'cards' => [],
            'config' => [],
        ],
    ];

    $configStatement = $pdo->query(
        "SELECT name, def_value
         FROM default_values
         WHERE name IN ('card_deposit', 'full_year_per_day', 'half_year_per_day', 'school_name', 'school_bic', 'school_iban')"
    );
    $configData = [];
    while ($row = $configStatement->fetch(PDO::FETCH_ASSOC)) {
        $name = $row['name'];
        $value = $row['def_value'];

        if (in_array($name, ['card_deposit', 'full_year_per_day', 'half_year_per_day'], true)) {
            $configData[$name] = (float) $value;
        } else {
            $configData[$name] = $value;
        }
    }
    $response['data']['config'] = $configData;

    if ($accountId) {
        $transactionsStatement = $pdo->prepare(
            "SELECT transaction_id, amount, transaction_type, occurred_at, description
             FROM account_transactions
             WHERE account_id = ? AND transaction_type != 'SUBSCRIPTION_USAGE'
             ORDER BY occurred_at DESC"
        );
        $transactionsStatement->execute([$accountId]);
        $transactions = $transactionsStatement->fetchAll(PDO::FETCH_ASSOC);

        foreach ($transactions as $transaction) {
            $isDeposit = in_array($transaction['transaction_type'], ['TOPUP', 'REFUND', 'REUSE'], true);
            $icon = 'Wallet';
            if (in_array($transaction['transaction_type'], ['USAGE', 'SUBSCRIPTION_USAGE'], true)) {
                $icon = 'Utensils';
            }
            if ($transaction['transaction_type'] === 'SUBSCRIPTION_PURCHASE') {
                $icon = 'CalendarDays';
            }
            if ($transaction['transaction_type'] === 'TOPUP' && stripos((string) $transaction['description'], 'Bar') !== false) {
                $icon = 'Banknote';
            }

            $response['data']['transactions'][] = [
                'id' => $transaction['transaction_id'],
                'type' => $isDeposit ? 'deposit' : 'expense',
                'amount' => (float) $transaction['amount'],
                'date' => date('d.m.Y', strtotime((string) $transaction['occurred_at'])),
                'description' => !empty($transaction['description']) ? $transaction['description'] : $transaction['transaction_type'],
                'iconName' => $icon,
            ];
        }

        $usageStatement = $pdo->prepare(
            "SELECT t.occurred_at, c.holder_id
             FROM account_transactions t
             JOIN chip_cards c ON t.card_id = c.card_uid
             WHERE t.account_id = ? AND t.transaction_type = 'SUBSCRIPTION_USAGE'"
        );
        $usageStatement->execute([$accountId]);
        $usages = $usageStatement->fetchAll(PDO::FETCH_ASSOC);

        $subscriptionsStatement = $pdo->prepare(
            "SELECT s.subscription_id, s.type, s.weekdays, s.start_date, s.end_date, h.holder_id, h.first_name, h.last_name
             FROM subscriptions s
             JOIN card_holders h ON s.holder_id = h.holder_id
             WHERE h.created_by = ?"
        );
        $subscriptionsStatement->execute([$accountId]);
        $subscriptions = $subscriptionsStatement->fetchAll(PDO::FETCH_ASSOC);

        foreach ($subscriptions as $subscription) {
            $isActive = strtotime((string) $subscription['start_date']) <= time()
                && strtotime((string) $subscription['end_date']) >= time();

            $daysMap = [
                'MONDAY' => 'Mo',
                'TUESDAY' => 'Di',
                'WEDNESDAY' => 'Mi',
                'THURSDAY' => 'Do',
                'FRIDAY' => 'Fr',
            ];
            $daysMapNumber = [
                'MONDAY' => 1,
                'TUESDAY' => 2,
                'WEDNESDAY' => 3,
                'THURSDAY' => 4,
                'FRIDAY' => 5,
            ];

            $daysArray = [];
            $validDays = [];
            foreach (explode(',', (string) $subscription['weekdays']) as $day) {
                $trimmedDay = trim($day);
                $daysArray[] = $daysMap[$trimmedDay] ?? $trimmedDay;
                if (isset($daysMapNumber[$trimmedDay])) {
                    $validDays[] = $daysMapNumber[$trimmedDay];
                }
            }

            $subscriptionType = 'Abo';
            if ($subscription['type'] === 'HALF_YEAR') {
                $subscriptionType = 'Halbjahresabo';
            } elseif ($subscription['type'] === 'FULL_YEAR') {
                $subscriptionType = 'Ganzjahresabo';
            }

            $usageCount = 0;
            $subscriptionStart = strtotime($subscription['start_date'] . ' 00:00:00');
            $subscriptionEnd = strtotime($subscription['end_date'] . ' 23:59:59');

            foreach ($usages as $usage) {
                if ((int) $usage['holder_id'] !== (int) $subscription['holder_id']) {
                    continue;
                }

                $usageTime = strtotime((string) $usage['occurred_at']);
                if ($usageTime < $subscriptionStart || $usageTime > $subscriptionEnd) {
                    continue;
                }

                if (in_array((int) date('N', $usageTime), $validDays, true)) {
                    $usageCount++;
                }
            }

            $response['data']['abos'][] = [
                'id' => $subscription['subscription_id'],
                'type' => $subscriptionType,
                'student' => $subscription['first_name'] . ' ' . $subscription['last_name'],
                'days' => $daysArray,
                'validUntil' => date('d.m.Y', strtotime((string) $subscription['end_date'])),
                'isActive' => $isActive,
                'usageCount' => $usageCount,
            ];
        }

        $cardsStatement = $pdo->prepare(
            "SELECT h.holder_id, h.first_name, h.last_name,
                    c.card_uid, c.active,
                    COALESCE(
                        (SELECT s.type
                         FROM subscriptions s
                         WHERE s.holder_id = h.holder_id
                         AND s.start_date <= CURDATE()
                         AND s.end_date >= CURDATE()
                         LIMIT 1),
                        'PREPAID'
                    ) AS subscription_type
             FROM card_holders h
             LEFT JOIN chip_cards c ON h.holder_id = c.holder_id
             WHERE h.created_by = ?"
        );
        $cardsStatement->execute([$accountId]);
        $cards = $cardsStatement->fetchAll(PDO::FETCH_ASSOC);

        foreach ($cards as $card) {
            $hasCard = !empty($card['card_uid']);
            $response['data']['cards'][] = [
                'holderId' => $card['holder_id'],
                'id' => $hasCard ? $card['card_uid'] : 'Wartend...',
                'student' => htmlspecialchars($card['first_name'] . ' ' . $card['last_name'], ENT_QUOTES, 'UTF-8'),
                'status' => $hasCard ? ($card['active'] ? 'Aktiv' : 'Gesperrt') : 'Karte ausstehend',
                'isPrepaidOnly' => $card['subscription_type'] === 'PREPAID',
                'img' => 'https://api.dicebear.com/7.x/avataaars/svg?seed=' . urlencode((string) $card['first_name']),
            ];
        }
    }

    mm_json_response($response);
}

mm_json_response([
    'status' => 'error',
    'message' => 'Aktion nicht gefunden.',
], 400);
