<?php

require_once __DIR__ . '/mm_bootstrap.php';
require_once __DIR__ . '/paypal/vendor/autoload.php';

use PaypalServerSdkLib\Authentication\ClientCredentialsAuthCredentialsBuilder;
use PaypalServerSdkLib\Environment;
use PaypalServerSdkLib\Models\Builders\AmountWithBreakdownBuilder;
use PaypalServerSdkLib\Models\Builders\OrderRequestBuilder;
use PaypalServerSdkLib\Models\Builders\PurchaseUnitRequestBuilder;
use PaypalServerSdkLib\Models\CheckoutPaymentIntent;
use PaypalServerSdkLib\PaypalServerSdkClientBuilder;

mm_apply_cors('portal', ['POST', 'OPTIONS'], ['Content-Type', 'X-CSRF-Token']);
mm_start_session('mensa_portal_login');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    mm_json_response(['status' => 'error', 'message' => 'Methode nicht erlaubt.'], 405);
}

try {
    $pdo = mm_build_pdo();
} catch (Throwable $exception) {
    mm_log_exception('portal_actions_bootstrap', $exception);
    mm_json_response(['status' => 'error', 'message' => 'Die Aktion konnte gerade nicht vorbereitet werden.'], 500);
}

if (empty($_SESSION['userid'])) {
    mm_json_response(['status' => 'error', 'message' => 'Nicht autorisiert.'], 401);
}

try {
    mm_require_csrf_token();
} catch (MmClientException $exception) {
    mm_json_response(['status' => 'error', 'message' => $exception->getMessage()], 403);
}

$userId = (int) $_SESSION['userid'];
$input = json_decode(file_get_contents('php://input'), true);
$input = is_array($input) ? $input : [];
$action = (string) ($_GET['action'] ?? ($input['action'] ?? ''));

$accountStatement = $pdo->prepare('SELECT account_id, balance FROM accounts WHERE user_id = ?');
$accountStatement->execute([$userId]);
$account = $accountStatement->fetch(PDO::FETCH_ASSOC);

if (!$account) {
    mm_json_response(['status' => 'error', 'message' => 'Konto nicht gefunden.'], 404);
}

$accountId = (int) $account['account_id'];
$currentBalance = (float) $account['balance'];

function sendJsonResponseAndContinueBackground(array $responseData)
{
    $payload = json_encode($responseData, JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        $payload = '{"status":"error","message":"Antwort konnte nicht erzeugt werden."}';
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    ignore_user_abort(true);
    set_time_limit(0);

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Connection: close');
    header('Content-Length: ' . strlen($payload));

    echo $payload;
    flush();

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
}

function portalSendOrderConfirmationEmail($toEmail, $toName, $actionType, $amount, $paymentMethod, $details = [], $paymentPin = null)
{
    require_once __DIR__ . '/mailer.php';
    return sendOrderConfirmationEmail($toEmail, $toName, $actionType, $amount, $paymentMethod, $details, $paymentPin);
}

function generatePaymentPin()
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $pin = '';
    $maxIndex = strlen($alphabet) - 1;

    for ($i = 0; $i < 6; $i++) {
        $pin .= $alphabet[random_int(0, $maxIndex)];
    }

    return $pin;
}

function portalCleanText($value)
{
    return htmlspecialchars(strip_tags(trim((string) $value)), ENT_QUOTES, 'UTF-8');
}

function loadPriceConfig(PDO $pdo)
{
    $prices = [
        'card_deposit' => 5.0,
        'full_year_per_day' => 120.0,
        'half_year_per_day' => 80.0,
    ];

    $statement = $pdo->query(
        "SELECT name, def_value FROM default_values WHERE name IN ('card_deposit', 'full_year_per_day', 'half_year_per_day')"
    );

    while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
        $prices[$row['name']] = (float) $row['def_value'];
    }

    return $prices;
}

function klarnaRequest($endpoint, $data = null)
{
    $config = mm_get_klarna_config();
    $ch = curl_init($config['url'] . $endpoint);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, $config['username'] . ':' . $config['password']);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('Klarna request failed: ' . $curlError);
    }

    $decoded = json_decode($response, true);
    return ['status' => $status, 'body' => is_array($decoded) ? $decoded : []];
}

function paypalEnvironment()
{
    $environment = mm_get_paypal_environment();
    if ($environment === 'live' || $environment === 'production') {
        return Environment::PRODUCTION;
    }

    return Environment::SANDBOX;
}

function buildPaypalClient()
{
    $clientId = mm_get_paypal_client_id();
    if ($clientId === '') {
        throw new MmConfigurationException('Missing required configuration: MM_PAYPAL_CLIENT_ID');
    }

    return PaypalServerSdkClientBuilder::init()
        ->clientCredentialsAuthCredentials(
            ClientCredentialsAuthCredentialsBuilder::init($clientId, mm_get_paypal_client_secret())
        )
        ->environment(paypalEnvironment())
        ->build();
}

function calculateCheckoutAmount($actionType, array $actionData, $requestedAmount, $currentBalance, array $prices)
{
    if ($actionType === 'topup') {
        $amount = (float) $requestedAmount;
        if ($amount < 0.01 || $amount > 1000) {
            throw new MmClientException('Ungueltiger Betrag.');
        }

        return round($amount, 2);
    }

    if ($actionType === 'order_card' || $actionType === 'reorder_card') {
        $useBalance = filter_var($actionData['useBalance'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $balanceDeduction = ($useBalance && $currentBalance > 0) ? min($prices['card_deposit'], $currentBalance) : 0;
        return round(max(0, $prices['card_deposit'] - $balanceDeduction), 2);
    }

    if ($actionType === 'buy_abo') {
        $type = (string) ($actionData['type'] ?? 'halb');
        $days = is_array($actionData['days'] ?? null) ? $actionData['days'] : [];
        $cardOption = (string) ($actionData['cardOption'] ?? 'existing');
        $useBalance = filter_var($actionData['useBalance'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $validDays = array_values(array_intersect($days, ['Mo', 'Di', 'Mi', 'Do', 'Fr']));

        if (empty($validDays)) {
            throw new MmClientException('Ungueltige Tage ausgewaehlt.');
        }

        $basePrice = $type === 'halb' ? $prices['half_year_per_day'] : $prices['full_year_per_day'];
        $totalPrice = (count($validDays) * $basePrice) + ($cardOption === 'new' ? $prices['card_deposit'] : 0);
        $balanceDeduction = ($useBalance && $currentBalance > 0) ? min($totalPrice, $currentBalance) : 0;

        return round(max(0, $totalPrice - $balanceDeduction), 2);
    }

    throw new MmClientException('Unbekannte Aktion.');
}

function executeTopup(PDO $pdo, $accountId, $amount, $method, $transactionId = null, &$generatedPin = null)
{
    $amount = (float) $amount;
    if ($amount < 0.01 || $amount > 1000) {
        throw new MmClientException('Ungueltiger Aufladebetrag.');
    }

    if ($method === 'Überweisung') {
        $description = 'Aufladung (warten auf Zahlungseingang)';
        $transactionStatement = $pdo->prepare(
            "INSERT INTO account_transactions (account_id, amount, transaction_type, description) VALUES (?, ?, 'TOPUP', ?)"
        );
        $transactionStatement->execute([$accountId, $amount, $description]);
        $newTransactionId = (int) $pdo->lastInsertId();

        $generatedPin = generatePaymentPin();
        $unpaidStatement = $pdo->prepare(
            'INSERT INTO unpaid_transactions (account_id, transaction_id, payment_pin) VALUES (?, ?, ?)'
        );
        $unpaidStatement->execute([$accountId, $newTransactionId, $generatedPin]);
        return;
    }

    $updateBalance = $pdo->prepare('UPDATE accounts SET balance = balance + ? WHERE account_id = ?');
    $updateBalance->execute([$amount, $accountId]);

    $description = "Aufladung ($method)";
    if ($transactionId) {
        $description = "Aufladung ($method TAN: $transactionId)";
    }

    $transactionStatement = $pdo->prepare(
        "INSERT INTO account_transactions (account_id, amount, transaction_type, description) VALUES (?, ?, 'TOPUP', ?)"
    );
    $transactionStatement->execute([$accountId, $amount, $description]);
}

function executeOrderCard(PDO $pdo, $accountId, $currentBalance, array $data, $paymentMethod, $transactionId = null, &$generatedPin = null, &$amountToPay = 0)
{
    $firstName = portalCleanText($data['firstName'] ?? '');
    $lastName = portalCleanText($data['lastName'] ?? '');
    $class = portalCleanText($data['class'] ?? '');
    $useBalance = filter_var($data['useBalance'] ?? false, FILTER_VALIDATE_BOOLEAN);

    $priceStatement = $pdo->prepare("SELECT def_value FROM default_values WHERE name = 'card_deposit'");
    $priceStatement->execute();
    $cardCost = (float) ($priceStatement->fetchColumn() ?: 5.00);

    $balanceDeduction = ($useBalance && $currentBalance > 0) ? min($cardCost, $currentBalance) : 0;
    $remainingToPay = $cardCost - $balanceDeduction;
    $amountToPay = $remainingToPay;

    $holderStatement = $pdo->prepare(
        'INSERT INTO card_holders (first_name, last_name, class, created_by) VALUES (?, ?, ?, ?)'
    );
    $holderStatement->execute([$firstName, $lastName, $class, $accountId]);

    $description = "Kartenpfand ($paymentMethod";
    if ($transactionId) {
        $description .= " TAN: $transactionId)";
    } elseif ($paymentMethod === 'Überweisung') {
        $description .= ' ausstehend)';
    } else {
        $description .= ')';
    }

    if ($balanceDeduction > 0) {
        $balanceStatement = $pdo->prepare('UPDATE accounts SET balance = balance - ? WHERE account_id = ?');
        $balanceStatement->execute([$balanceDeduction, $accountId]);

        $transactionStatement = $pdo->prepare(
            "INSERT INTO account_transactions (account_id, amount, transaction_type, description) VALUES (?, ?, 'DEPOSIT', ?)"
        );
        $transactionStatement->execute([$accountId, -$balanceDeduction, 'Kartenpfand (Guthaben)']);
    }

    if ($remainingToPay > 0) {
        $transactionStatement = $pdo->prepare(
            "INSERT INTO account_transactions (account_id, amount, transaction_type, description) VALUES (?, ?, 'DEPOSIT', ?)"
        );
        $transactionStatement->execute([$accountId, -$remainingToPay, $description]);

        if ($paymentMethod === 'Überweisung') {
            $generatedPin = generatePaymentPin();
            $unpaidStatement = $pdo->prepare(
                'INSERT INTO unpaid_transactions (account_id, transaction_id, payment_pin) VALUES (?, ?, ?)'
            );
            $unpaidStatement->execute([$accountId, (int) $pdo->lastInsertId(), $generatedPin]);
        }
    }
}

function executeBuyAbo(PDO $pdo, $accountId, $currentBalance, array $data, $paymentMethod, $transactionId = null, &$generatedPin = null, &$amountToPay = 0)
{
    $type = (string) ($data['type'] ?? 'halb');
    $days = is_array($data['days'] ?? null) ? $data['days'] : [];
    $cardOption = (string) ($data['cardOption'] ?? 'existing');
    $selectedHolderId = (int) ($data['selectedHolderId'] ?? 0);
    $newStudent = is_array($data['newStudent'] ?? null) ? $data['newStudent'] : [];
    $useBalance = filter_var($data['useBalance'] ?? false, FILTER_VALIDATE_BOOLEAN);

    if ($cardOption === 'existing') {
        if ($selectedHolderId <= 0) {
            throw new MmClientException('Bitte ein gueltiges Schuelerprofil auswaehlen.');
        }

        $ownershipStatement = $pdo->prepare(
            'SELECT 1 FROM card_holders WHERE holder_id = ? AND created_by = ?'
        );
        $ownershipStatement->execute([$selectedHolderId, $accountId]);
        if (!$ownershipStatement->fetchColumn()) {
            throw new MmClientException('Unberechtigter Zugriff auf Schuelerprofil.');
        }
    }

    $prices = loadPriceConfig($pdo);
    $validDays = array_values(array_intersect($days, ['Mo', 'Di', 'Mi', 'Do', 'Fr']));
    if (empty($validDays)) {
        throw new MmClientException('Ungueltige Tage ausgewaehlt.');
    }

    $totalPrice = (count($validDays) * ($type === 'halb' ? $prices['half_year_per_day'] : $prices['full_year_per_day']))
        + ($cardOption === 'new' ? $prices['card_deposit'] : 0);
    $balanceDeduction = ($useBalance && $currentBalance > 0) ? min($totalPrice, $currentBalance) : 0;
    $remainingToPay = $totalPrice - $balanceDeduction;
    $amountToPay = $remainingToPay;

    if ($cardOption === 'new') {
        $firstName = portalCleanText($newStudent['firstName'] ?? '');
        $lastName = portalCleanText($newStudent['lastName'] ?? '');
        $class = portalCleanText($newStudent['class'] ?? '');

        $holderStatement = $pdo->prepare(
            'INSERT INTO card_holders (first_name, last_name, class, created_by) VALUES (?, ?, ?, ?)'
        );
        $holderStatement->execute([$firstName, $lastName, $class, $accountId]);
        $holderId = (int) $pdo->lastInsertId();
    } else {
        $holderId = $selectedHolderId;
    }

    $dbType = $type === 'halb' ? 'HALF_YEAR' : 'FULL_YEAR';
    $dayMap = [
        'Mo' => 'MONDAY',
        'Di' => 'TUESDAY',
        'Mi' => 'WEDNESDAY',
        'Do' => 'THURSDAY',
        'Fr' => 'FRIDAY',
    ];
    $dbDays = array_map(function ($day) use ($dayMap) {
        return $dayMap[$day] ?? $day;
    }, $validDays);
    $endDate = (date('m') > ($type === 'halb' ? 1 : 7) ? date('Y') + 1 : date('Y'))
        . ($type === 'halb' ? '-01-31' : '-07-31');

    $subscriptionStatement = $pdo->prepare(
        'INSERT INTO subscriptions (holder_id, type, weekdays, start_date, end_date, transaction_nr) VALUES (?, ?, ?, CURDATE(), ?, ?)'
    );
    $subscriptionStatement->execute([$holderId, $dbType, implode(',', $dbDays), $endDate, $transactionId]);
    $subscriptionId = (int) $pdo->lastInsertId();

    $description = ($type === 'halb' ? 'Halbjahresabo' : 'Ganzjahresabo') . " ($paymentMethod";
    if ($transactionId) {
        $description .= " TAN: $transactionId)";
    } elseif ($paymentMethod === 'Überweisung') {
        $description .= ' ausstehend)';
    } else {
        $description .= ')';
    }

    if ($balanceDeduction > 0) {
        $balanceStatement = $pdo->prepare('UPDATE accounts SET balance = balance - ? WHERE account_id = ?');
        $balanceStatement->execute([$balanceDeduction, $accountId]);

        $transactionStatement = $pdo->prepare(
            "INSERT INTO account_transactions (account_id, amount, transaction_type, description) VALUES (?, ?, 'SUBSCRIPTION_PURCHASE', ?)"
        );
        $transactionStatement->execute([
            $accountId,
            -$balanceDeduction,
            ($type === 'halb' ? 'Halbjahresabo' : 'Ganzjahresabo') . ' (Guthaben)',
        ]);
    }

    if ($remainingToPay > 0) {
        $transactionStatement = $pdo->prepare(
            "INSERT INTO account_transactions (account_id, amount, transaction_type, description) VALUES (?, ?, 'SUBSCRIPTION_PURCHASE', ?)"
        );
        $transactionStatement->execute([$accountId, -$remainingToPay, $description]);

        if ($paymentMethod === 'Überweisung') {
            $generatedPin = generatePaymentPin();
            $unpaidStatement = $pdo->prepare(
                'INSERT INTO unpaid_transactions (account_id, transaction_id, subscription_id, payment_pin) VALUES (?, ?, ?, ?)'
            );
            $unpaidStatement->execute([$accountId, (int) $pdo->lastInsertId(), $subscriptionId, $generatedPin]);
        }
    }
}

function executeBlockCard(PDO $pdo, $accountId, array $data)
{
    $cardId = (string) ($data['cardId'] ?? '');
    $statement = $pdo->prepare(
        'UPDATE chip_cards SET active = 0, deactivated_at = CURDATE() WHERE card_uid = ? AND holder_id IN (SELECT holder_id FROM card_holders WHERE created_by = ?)'
    );
    $statement->execute([$cardId, $accountId]);

    if ($statement->rowCount() === 0) {
        throw new MmClientException('Die Karte konnte nicht gesperrt werden.');
    }
}

function executeReorderCard(PDO $pdo, $accountId, $currentBalance, array $data, $paymentMethod, $transactionId = null, &$generatedPin = null, &$amountToPay = 0)
{
    $holderId = (int) ($data['holderId'] ?? 0);
    if ($holderId <= 0) {
        throw new MmClientException('Bitte ein gueltiges Schuelerprofil auswaehlen.');
    }

    $ownershipStatement = $pdo->prepare('SELECT 1 FROM card_holders WHERE holder_id = ? AND created_by = ?');
    $ownershipStatement->execute([$holderId, $accountId]);
    if (!$ownershipStatement->fetchColumn()) {
        throw new MmClientException('Unberechtigter Zugriff. Diese Karte gehoert nicht zu deinem Account.');
    }

    $priceStatement = $pdo->prepare("SELECT def_value FROM default_values WHERE name = 'card_deposit'");
    $priceStatement->execute();
    $cardCost = (float) ($priceStatement->fetchColumn() ?: 5.00);

    $useBalance = filter_var($data['useBalance'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $balanceDeduction = ($useBalance && $currentBalance > 0) ? min($cardCost, $currentBalance) : 0;
    $remainingToPay = $cardCost - $balanceDeduction;
    $amountToPay = $remainingToPay;

    $deleteCardStatement = $pdo->prepare('DELETE FROM chip_cards WHERE holder_id = ?');
    $deleteCardStatement->execute([$holderId]);

    $description = "Ersatzkarte ($paymentMethod";
    if ($transactionId) {
        $description .= " TAN: $transactionId)";
    } elseif ($paymentMethod === 'Überweisung') {
        $description .= ' ausstehend)';
    } else {
        $description .= ')';
    }

    if ($balanceDeduction > 0) {
        $balanceStatement = $pdo->prepare('UPDATE accounts SET balance = balance - ? WHERE account_id = ?');
        $balanceStatement->execute([$balanceDeduction, $accountId]);

        $transactionStatement = $pdo->prepare(
            "INSERT INTO account_transactions (account_id, amount, transaction_type, description) VALUES (?, ?, 'DEPOSIT', ?)"
        );
        $transactionStatement->execute([$accountId, -$balanceDeduction, 'Ersatzkarte (Guthaben)']);
    }

    if ($remainingToPay > 0) {
        $transactionStatement = $pdo->prepare(
            "INSERT INTO account_transactions (account_id, amount, transaction_type, description) VALUES (?, ?, 'DEPOSIT', ?)"
        );
        $transactionStatement->execute([$accountId, -$remainingToPay, $description]);

        if ($paymentMethod === 'Überweisung') {
            $generatedPin = generatePaymentPin();
            $unpaidStatement = $pdo->prepare(
                'INSERT INTO unpaid_transactions (account_id, transaction_id, payment_pin) VALUES (?, ?, ?)'
            );
            $unpaidStatement->execute([$accountId, (int) $pdo->lastInsertId(), $generatedPin]);
        }
    }
}

function getLockedBalance(PDO $pdo, $accountId)
{
    $statement = $pdo->prepare('SELECT balance FROM accounts WHERE account_id = ? FOR UPDATE');
    $statement->execute([$accountId]);
    $result = $statement->fetch(PDO::FETCH_ASSOC);

    return $result ? (float) $result['balance'] : 0.0;
}

function loadUserContact(PDO $pdo, $userId)
{
    $statement = $pdo->prepare('SELECT vorname, email FROM users WHERE id = ?');
    $statement->execute([$userId]);

    return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
}

try {
    switch ($action) {
        case 'create_klarna_session':
            $actionType = (string) ($input['actionType'] ?? '');
            $actionData = is_array($input['actionData'] ?? null) ? $input['actionData'] : [];
            $prices = loadPriceConfig($pdo);
            $amount = calculateCheckoutAmount($actionType, $actionData, $input['amount'] ?? 0, $currentBalance, $prices);

            $orderAmount = (int) round($amount * 100);
            $sessionData = [
                'purchase_country' => 'DE',
                'purchase_currency' => 'EUR',
                'locale' => 'de-DE',
                'order_amount' => $orderAmount,
                'order_tax_amount' => 0,
                'order_lines' => [[
                    'type' => 'digital',
                    'name' => 'MensaPay Service',
                    'quantity' => 1,
                    'unit_price' => $orderAmount,
                    'tax_rate' => 0,
                    'total_amount' => $orderAmount,
                    'total_tax_amount' => 0,
                ]],
            ];

            try {
                $result = klarnaRequest('/payments/v1/sessions', $sessionData);
            } catch (Throwable $exception) {
                mm_log_exception('portal_klarna_session', $exception);
                mm_json_response(['status' => 'error', 'message' => 'Klarna ist momentan nicht verfuegbar.'], 500);
            }

            if ($result['status'] >= 200 && $result['status'] < 300 && !empty($result['body']['client_token'])) {
                $_SESSION['klarna_intent'] = [
                    'actionType' => $actionType,
                    'actionData' => $actionData,
                    'amount' => $amount,
                    'session_id' => $result['body']['session_id'] ?? null,
                ];

                mm_json_response(['client_token' => $result['body']['client_token']]);
            }

            error_log('Klarna Session Error: ' . json_encode($result, JSON_UNESCAPED_UNICODE));
            mm_json_response(['status' => 'error', 'message' => 'Klarna-Sitzung konnte nicht erstellt werden.'], 502);
            break;

        case 'place_klarna_order':
            $authorizationToken = (string) ($input['authorization_token'] ?? '');
            $intent = $_SESSION['klarna_intent'] ?? null;

            if (!$intent || $authorizationToken === '') {
                throw new MmClientException('Ungueltige Klarna-Sitzung.');
            }

            $orderAmount = (int) round(((float) $intent['amount']) * 100);
            $orderData = [
                'purchase_country' => 'DE',
                'purchase_currency' => 'EUR',
                'locale' => 'de-DE',
                'order_amount' => $orderAmount,
                'order_tax_amount' => 0,
                'order_lines' => [[
                    'type' => 'digital',
                    'name' => 'MensaPay Service',
                    'quantity' => 1,
                    'unit_price' => $orderAmount,
                    'tax_rate' => 0,
                    'total_amount' => $orderAmount,
                    'total_tax_amount' => 0,
                ]],
            ];

            try {
                $result = klarnaRequest('/payments/v1/authorizations/' . rawurlencode($authorizationToken) . '/order', $orderData);
            } catch (Throwable $exception) {
                mm_log_exception('portal_klarna_order', $exception);
                mm_json_response(['status' => 'error', 'message' => 'Klarna-Zahlung konnte nicht finalisiert werden.'], 500);
            }

            if ($result['status'] < 200 || $result['status'] >= 300 || empty($result['body']['order_id'])) {
                error_log('Klarna Order Error: ' . json_encode($result, JSON_UNESCAPED_UNICODE));
                mm_json_response(['status' => 'error', 'message' => 'Klarna-Zahlung konnte nicht finalisiert werden.'], 502);
            }

            try {
                $pdo->beginTransaction();
                $lockedBalance = getLockedBalance($pdo, $accountId);

                $actionType = $intent['actionType'];
                $actionData = is_array($intent['actionData'] ?? null) ? $intent['actionData'] : [];
                $paymentMethod = 'Klarna';
                $generatedPin = null;
                $amountToNotify = (float) $intent['amount'];
                $klarnaOrderId = (string) $result['body']['order_id'];

                if ($actionType === 'topup') {
                    executeTopup($pdo, $accountId, $intent['amount'], $paymentMethod, $klarnaOrderId, $generatedPin);
                } elseif ($actionType === 'order_card') {
                    executeOrderCard($pdo, $accountId, $lockedBalance, $actionData, $paymentMethod, $klarnaOrderId, $generatedPin, $amountToNotify);
                } elseif ($actionType === 'buy_abo') {
                    executeBuyAbo($pdo, $accountId, $lockedBalance, $actionData, $paymentMethod, $klarnaOrderId, $generatedPin, $amountToNotify);
                } elseif ($actionType === 'reorder_card') {
                    executeReorderCard($pdo, $accountId, $lockedBalance, $actionData, $paymentMethod, $klarnaOrderId, $generatedPin, $amountToNotify);
                } else {
                    throw new MmClientException('Unbekannte Aktion.');
                }

                $pdo->commit();
                unset($_SESSION['klarna_intent']);

                $userData = loadUserContact($pdo, $userId);
                sendJsonResponseAndContinueBackground(['status' => 'success']);

                if ($userData && !empty($userData['email'])) {
                    portalSendOrderConfirmationEmail($userData['email'], $userData['vorname'], $actionType, $amountToNotify, $paymentMethod, $actionData, $generatedPin);
                }
                exit;
            } catch (MmClientException $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                mm_json_response(['status' => 'error', 'message' => $exception->getMessage()], 400);
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                mm_log_exception('portal_klarna_finalize', $exception);
                mm_json_response(['status' => 'error', 'message' => 'Die Zahlung konnte nicht abgeschlossen werden.'], 500);
            }
            break;

        case 'create_paypal_order':
            $actionType = (string) ($input['actionType'] ?? '');
            $actionData = is_array($input['actionData'] ?? null) ? $input['actionData'] : [];
            $prices = loadPriceConfig($pdo);
            $amount = calculateCheckoutAmount($actionType, $actionData, $input['amount'] ?? 0, $currentBalance, $prices);

            if ($amount <= 0) {
                throw new MmClientException('Kein offener Betrag vorhanden.');
            }

            try {
                $client = buildPaypalClient();
            } catch (Throwable $exception) {
                mm_log_exception('portal_paypal_configuration', $exception);
                mm_json_response(['status' => 'error', 'message' => 'PayPal ist momentan nicht verfuegbar.'], 500);
            }

            try {
                $orderBody = [
                    'body' => OrderRequestBuilder::init(
                        CheckoutPaymentIntent::CAPTURE,
                        [
                            PurchaseUnitRequestBuilder::init(
                                AmountWithBreakdownBuilder::init('EUR', number_format($amount, 2, '.', ''))->build()
                            )->build(),
                        ]
                    )->build(),
                ];

                $apiResponse = $client->getOrdersController()->ordersCreate($orderBody);
                $order = $apiResponse->getResult();

                $_SESSION['paypal_intent_' . $order->getId()] = [
                    'actionType' => $actionType,
                    'actionData' => $actionData,
                    'amount' => $amount,
                ];

                mm_json_response(['id' => $order->getId()]);
            } catch (Throwable $exception) {
                mm_log_exception('portal_paypal_create', $exception);
                mm_json_response(['status' => 'error', 'message' => 'Fehler beim Erstellen der PayPal-Zahlung.'], 500);
            }
            break;

        case 'capture_paypal_order':
            $orderId = (string) ($input['orderID'] ?? '');
            if ($orderId === '') {
                throw new MmClientException('Ungueltige PayPal-Order.');
            }

            try {
                $client = buildPaypalClient();
            } catch (Throwable $exception) {
                mm_log_exception('portal_paypal_configuration', $exception);
                mm_json_response(['status' => 'error', 'message' => 'PayPal ist momentan nicht verfuegbar.'], 500);
            }

            try {
                $apiResponse = $client->getOrdersController()->ordersCapture(['id' => $orderId]);
                $result = $apiResponse->getResult();
            } catch (Throwable $exception) {
                mm_log_exception('portal_paypal_capture', $exception);
                mm_json_response(['status' => 'error', 'message' => 'Die PayPal-Zahlung konnte nicht abgeschlossen werden.'], 500);
            }

            if ($result->getStatus() !== 'COMPLETED') {
                mm_json_response(['status' => 'error', 'message' => 'Die Zahlung wurde nicht vollstaendig abgeschlossen.'], 400);
            }

            $intent = $_SESSION['paypal_intent_' . $orderId] ?? null;
            if (!$intent) {
                mm_json_response(['status' => 'error', 'message' => 'Die Zahlungssitzung ist abgelaufen. Bitte starte den Vorgang erneut.'], 400);
            }

            try {
                $transactionId = $orderId;
                $purchaseUnits = $result->getPurchaseUnits();
                if (!empty($purchaseUnits) && $purchaseUnits[0]->getPayments() && !empty($purchaseUnits[0]->getPayments()->getCaptures())) {
                    $capture = $purchaseUnits[0]->getPayments()->getCaptures()[0];
                    if ($capture->getId()) {
                        $transactionId = $capture->getId();
                    }
                }

                $pdo->beginTransaction();
                $lockedBalance = getLockedBalance($pdo, $accountId);

                $actionType = $intent['actionType'];
                $actionData = is_array($intent['actionData'] ?? null) ? $intent['actionData'] : [];
                $paymentMethod = 'PayPal';
                $generatedPin = null;
                $amountToNotify = (float) $intent['amount'];

                if ($actionType === 'topup') {
                    executeTopup($pdo, $accountId, $intent['amount'], $paymentMethod, $transactionId, $generatedPin);
                } elseif ($actionType === 'order_card') {
                    executeOrderCard($pdo, $accountId, $lockedBalance, $actionData, $paymentMethod, $transactionId, $generatedPin, $amountToNotify);
                } elseif ($actionType === 'buy_abo') {
                    executeBuyAbo($pdo, $accountId, $lockedBalance, $actionData, $paymentMethod, $transactionId, $generatedPin, $amountToNotify);
                } elseif ($actionType === 'reorder_card') {
                    executeReorderCard($pdo, $accountId, $lockedBalance, $actionData, $paymentMethod, $transactionId, $generatedPin, $amountToNotify);
                } else {
                    throw new MmClientException('Unbekannte Aktion.');
                }

                $pdo->commit();
                unset($_SESSION['paypal_intent_' . $orderId]);

                $userData = loadUserContact($pdo, $userId);
                sendJsonResponseAndContinueBackground(['status' => 'success']);

                if ($userData && !empty($userData['email'])) {
                    portalSendOrderConfirmationEmail($userData['email'], $userData['vorname'], $actionType, $amountToNotify, $paymentMethod, $actionData, $generatedPin);
                }
                exit;
            } catch (MmClientException $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                mm_json_response(['status' => 'error', 'message' => $exception->getMessage()], 400);
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                mm_log_exception('portal_paypal_finalize', $exception);
                mm_json_response(['status' => 'error', 'message' => 'Die Zahlung konnte nicht abgeschlossen werden.'], 500);
            }
            break;

        case 'topup':
        case 'order_card':
        case 'buy_abo':
        case 'block_card':
        case 'reorder_card':
            $paymentMethod = (string) ($input['paymentMethod'] ?? 'Guthaben');

            try {
                $pdo->beginTransaction();
                $lockedBalance = getLockedBalance($pdo, $accountId);

                $responseData = ['status' => 'success'];
                $generatedPin = null;
                $amountToNotify = 0.0;

                if ($action === 'topup') {
                    $amountToNotify = (float) ($input['amount'] ?? 0);
                    executeTopup($pdo, $accountId, $amountToNotify, $paymentMethod, null, $generatedPin);
                } elseif ($action === 'order_card') {
                    executeOrderCard($pdo, $accountId, $lockedBalance, $input, $paymentMethod, null, $generatedPin, $amountToNotify);
                } elseif ($action === 'buy_abo') {
                    executeBuyAbo($pdo, $accountId, $lockedBalance, $input, $paymentMethod, null, $generatedPin, $amountToNotify);
                } elseif ($action === 'block_card') {
                    executeBlockCard($pdo, $accountId, $input);
                } elseif ($action === 'reorder_card') {
                    executeReorderCard($pdo, $accountId, $lockedBalance, $input, $paymentMethod, null, $generatedPin, $amountToNotify);
                }

                if ($generatedPin) {
                    $responseData['payment_pin'] = $generatedPin;
                }

                $pdo->commit();

                if ($action !== 'block_card') {
                    $userData = loadUserContact($pdo, $userId);
                    sendJsonResponseAndContinueBackground($responseData);

                    if ($userData && !empty($userData['email'])) {
                        portalSendOrderConfirmationEmail($userData['email'], $userData['vorname'], $action, $amountToNotify, $paymentMethod, $input, $generatedPin);
                    }
                    exit;
                }

                mm_json_response($responseData);
            } catch (MmClientException $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                mm_json_response(['status' => 'error', 'message' => $exception->getMessage()], 400);
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                mm_log_exception('portal_manual_action', $exception);
                mm_json_response(['status' => 'error', 'message' => 'Die Aktion konnte nicht abgeschlossen werden.'], 500);
            }
            break;

        default:
            throw new MmClientException('Unbekannte Aktion.');
    }
} catch (MmClientException $exception) {
    mm_json_response(['status' => 'error', 'message' => $exception->getMessage()], 400);
} catch (Throwable $exception) {
    mm_log_exception('portal_actions', $exception);
    mm_json_response(['status' => 'error', 'message' => 'Ein interner Fehler ist aufgetreten.'], 500);
}
