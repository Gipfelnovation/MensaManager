<?php
session_name("mensa_login");

// CORS Konfiguration
if (isset($_SERVER['HTTP_ORIGIN'])) {
    header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
} else {
    header("Access-Control-Allow-Origin: *");
}
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

session_start();

// --- PAYPAL SDK IMPORTS ---
require_once __DIR__ . '/paypal/vendor/autoload.php';
// --- MAILER IMPORT ---
require_once __DIR__ . '/mailer.php';

use PaypalServerSdkLib\Authentication\ClientCredentialsAuthCredentialsBuilder;
use PaypalServerSdkLib\Environment;
use PaypalServerSdkLib\PaypalServerSdkClientBuilder;
use PaypalServerSdkLib\Models\Builders\OrderRequestBuilder;
use PaypalServerSdkLib\Models\CheckoutPaymentIntent;
use PaypalServerSdkLib\Models\Builders\PurchaseUnitRequestBuilder;
use PaypalServerSdkLib\Models\Builders\AmountWithBreakdownBuilder;

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

// Authentifizierung prüfen
$userId = $_SESSION['userid'] ?? null;
if (!$userId) {
    echo json_encode(['status' => 'error', 'message' => 'Nicht autorisiert.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$action = $_GET['action'] ?? ($input['action'] ?? '');

$stmt = $pdo->prepare("SELECT account_id, balance FROM accounts WHERE user_id = ?");
$stmt->execute([$userId]);
$account = $stmt->fetch();
if (!$account) {
    echo json_encode(['status' => 'error', 'message' => 'Konto nicht gefunden.']);
    exit;
}
$accountId = $account['account_id'];
$currentBalance = (float)$account['balance'];


// ==========================================
// HILFSFUNKTIONEN FÜR DATENBANK-UPDATES
// ==========================================

// NEU: Hilfsfunktion für asynchronen Response & Weiterlauf im Hintergrund
function sendJsonResponseAndContinueBackground($responseData) {
    if (session_id()) session_write_close();
    ignore_user_abort(true);
    set_time_limit(0);
    ob_start();
    echo json_encode($responseData);
    $size = ob_get_length();
    header("Content-Type: application/json; charset=utf-8");
    header("Connection: close");
    header("Content-Length: $size");
    ob_end_flush();
    @ob_flush();
    flush();
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
}

function generatePaymentPin() {
    return strtoupper(substr(str_shuffle("ABCDEFGHJKLMNPQRSTUVWXYZ23456789"), 0, 6));
}

function executeTopup($pdo, $accountId, $amount, $method, $transactionId = null, &$generatedPin = null) {
    if ($method === 'Überweisung') {
        $desc = "Aufladung (warten auf Zahlungseingang)";
        
        $pdo->prepare("INSERT INTO account_transactions (account_id, amount, transaction_type, description) VALUES (?, ?, 'TOPUP', ?)")
            ->execute([$accountId, $amount, $desc]);
            
        $newTxId = $pdo->lastInsertId();
        $generatedPin = generatePaymentPin();
        
        $pdo->prepare("INSERT INTO unpaid_transactions (account_id, transaction_id, payment_pin) VALUES (?, ?, ?)")
            ->execute([$accountId, $newTxId, $generatedPin]);
    } else {
    $pdo->prepare("UPDATE accounts SET balance = balance + ? WHERE account_id = ?")->execute([$amount, $accountId]);
    
    $desc = "Aufladung ($method)";
        if ($transactionId) {
            $desc = "Aufladung ($method TAN: " . $transactionId . ")";
    }

    $pdo->prepare("INSERT INTO account_transactions (account_id, amount, transaction_type, description) VALUES (?, ?, 'TOPUP', ?)")
        ->execute([$accountId, $amount, $desc]);
}
}

function executeOrderCard($pdo, $accountId, $currentBalance, $data, $paymentMethod, $transactionId = null, &$generatedPin = null, &$amountToPay = 0) {
    $firstName = $data['firstName'] ?? '';
    $lastName = $data['lastName'] ?? '';
    $class = $data['class'] ?? '';
    $useBalance = $data['useBalance'] ?? false;
    
    $stmtVal = $pdo->prepare("SELECT def_value FROM default_values WHERE name = 'card_deposit'");
    $stmtVal->execute();
    $res = $stmtVal->fetch();
    $cardCost = $res ? (float)$res['def_value'] : 5.00;

    $balanceDeduction = ($useBalance && $currentBalance > 0) ? min($cardCost, $currentBalance) : 0;
    $remainingToPay = $cardCost - $balanceDeduction;
    $amountToPay = $remainingToPay;

    $stmt = $pdo->prepare("INSERT INTO card_holders (first_name, last_name, class, created_by) VALUES (?, ?, ?, ?)");
    $stmt->execute([$firstName, $lastName, $class, $accountId]);

    $descPayment = "Kartenpfand ($paymentMethod";
    if ($transactionId) {
        $descPayment .= " TAN: $transactionId)";
    } elseif ($paymentMethod === 'Überweisung') {
        $descPayment .= " ausstehend)";
    } else {
        $descPayment .= ")";
    }

    if ($balanceDeduction > 0) {
        $pdo->prepare("UPDATE accounts SET balance = balance - ? WHERE account_id = ?")->execute([$balanceDeduction, $accountId]);
        $pdo->prepare("INSERT INTO account_transactions (account_id, amount, transaction_type, description) VALUES (?, ?, 'DEPOSIT', ?)")
            ->execute([$accountId, -$balanceDeduction, "Kartenpfand (Guthaben)"]);
    }
    if ($remainingToPay > 0) {
        $pdo->prepare("INSERT INTO account_transactions (account_id, amount, transaction_type, description) VALUES (?, ?, 'DEPOSIT', ?)")
            ->execute([$accountId, -$remainingToPay, $descPayment]);
        if ($paymentMethod === 'Überweisung') {
            $generatedPin = generatePaymentPin();
            $pdo->prepare("INSERT INTO unpaid_transactions (account_id, transaction_id, payment_pin) VALUES (?, ?, ?)")
                ->execute([$accountId, $pdo->lastInsertId(), $generatedPin]);
        }
    }
}

function executeBuyAbo($pdo, $accountId, $currentBalance, $data, $paymentMethod, $transactionId = null, &$generatedPin = null, &$amountToPay = 0) {
    $type = $data['type'] ?? 'halb';
    $days = $data['days'] ?? [];
    $cardOption = $data['cardOption'] ?? 'existing';
    $selectedHolderId = $data['selectedHolderId'] ?? '';
    $newStudent = $data['newStudent'] ?? [];
    $useBalance = $data['useBalance'] ?? false;

    $stmtVals = $pdo->query("SELECT name, def_value FROM default_values WHERE name IN ('card_deposit', 'full_year_per_day', 'half_year_per_day')");
    $dbPrices = ['card_deposit' => 5.0, 'full_year_per_day' => 120.0, 'half_year_per_day' => 80.0];
    while ($row = $stmtVals->fetch()) {
        $dbPrices[$row['name']] = (float)$row['def_value'];
    }

    $totalPrice = (count($days) * ($type === 'halb' ? $dbPrices['half_year_per_day'] : $dbPrices['full_year_per_day'])) + ($cardOption === 'new' ? $dbPrices['card_deposit'] : 0);
    $balanceDeduction = ($useBalance && $currentBalance > 0) ? min($totalPrice, $currentBalance) : 0;
    $remainingToPay = $totalPrice - $balanceDeduction;
    $amountToPay = $remainingToPay;

    if ($cardOption === 'new') {
        $pdo->prepare("INSERT INTO card_holders (first_name, last_name, class, created_by) VALUES (?, ?, ?, ?)")
            ->execute([$newStudent['firstName'], $newStudent['lastName'], $newStudent['class'], $accountId]);
        $holderId = $pdo->lastInsertId();
    } else {
        $holderId = $selectedHolderId;
    }

    $endDate = (date('m') > ($type === 'halb' ? 1 : 7) ? date('Y')+1 : date('Y')) . ($type === 'halb' ? '-01-31' : '-07-31');
    $pdo->prepare("INSERT INTO subscriptions (holder_id, type, weekdays, start_date, end_date, transaction_nr) VALUES (?, ?, ?, CURDATE(), ?, ?)")
        ->execute([$holderId, ($type === 'halb' ? 'HALF_YEAR' : 'FULL_YEAR'), implode(',', $days), $endDate, $transactionId]);
    $subId = $pdo->lastInsertId();

    $descPayment = ($type === 'halb' ? 'Halbjahresabo' : 'Ganzjahresabo') . " ($paymentMethod";
    if ($transactionId) {
        $descPayment .= " TAN: $transactionId)";
    } elseif ($paymentMethod === 'Überweisung') {
        $descPayment .= " ausstehend)";
    } else {
        $descPayment .= ")";
    }

    if ($balanceDeduction > 0) {
        $pdo->prepare("UPDATE accounts SET balance = balance - ? WHERE account_id = ?")->execute([$balanceDeduction, $accountId]);
        $pdo->prepare("INSERT INTO account_transactions (account_id, amount, transaction_type, description) VALUES (?, ?, 'SUBSCRIPTION_PURCHASE', ?)")
            ->execute([$accountId, -$balanceDeduction, ($type === 'halb' ? 'Halbjahresabo' : 'Ganzjahresabo') . " (Guthaben)"]);
        }
    if ($remainingToPay > 0) {
        $pdo->prepare("INSERT INTO account_transactions (account_id, amount, transaction_type, description) VALUES (?, ?, 'SUBSCRIPTION_PURCHASE', ?)")
            ->execute([$accountId, -$remainingToPay, $descPayment]);
        if ($paymentMethod === 'Überweisung') {
            $generatedPin = generatePaymentPin();
            $pdo->prepare("INSERT INTO unpaid_transactions (account_id, transaction_id, subscription_id, payment_pin) VALUES (?, ?, ?, ?)")
                ->execute([$accountId, $pdo->lastInsertId(), $subId, $generatedPin]);
        }
    }
}

function executeBlockCard($pdo, $accountId, $data) {
    $pdo->prepare("UPDATE chip_cards SET active = 0, deactivated_at = CURDATE() WHERE card_uid = ? AND holder_id IN (SELECT holder_id FROM card_holders WHERE created_by = ?)")
        ->execute([$data['cardId'], $accountId]);
}

function executeReorderCard($pdo, $accountId, $currentBalance, $data, $paymentMethod, $transactionId = null, &$generatedPin = null, &$amountToPay = 0) {
    $stmtVal = $pdo->prepare("SELECT def_value FROM default_values WHERE name = 'card_deposit'");
    $stmtVal->execute();
    $res = $stmtVal->fetch();
    $cardCost = $res ? (float)$res['def_value'] : 5.00;

    $balanceDeduction = ($data['useBalance'] && $currentBalance > 0) ? min($cardCost, $currentBalance) : 0;
    $remainingToPay = $cardCost - $balanceDeduction;
    $amountToPay = $remainingToPay;

    $pdo->prepare("DELETE FROM chip_cards WHERE holder_id = ?")->execute([$data['holderId']]);
    
    $descPayment = "Ersatzkarte ($paymentMethod";
    if ($transactionId) {
        $descPayment .= " TAN: $transactionId)";
    } elseif ($paymentMethod === 'Überweisung') {
        $descPayment .= " ausstehend)";
    } else {
        $descPayment .= ")";
    }

    if ($balanceDeduction > 0) {
        $pdo->prepare("UPDATE accounts SET balance = balance - ? WHERE account_id = ?")->execute([$balanceDeduction, $accountId]);
        $pdo->prepare("INSERT INTO account_transactions (account_id, amount, transaction_type, description) VALUES (?, ?, 'DEPOSIT', ?)")
            ->execute([$accountId, -$balanceDeduction, "Ersatzkarte (Guthaben)"]);
    }
    if ($remainingToPay > 0) {
        $pdo->prepare("INSERT INTO account_transactions (account_id, amount, transaction_type, description) VALUES (?, ?, 'DEPOSIT', ?)")
            ->execute([$accountId, -$remainingToPay, $descPayment]);
        if ($paymentMethod === 'Überweisung') {
            $generatedPin = generatePaymentPin();
            $pdo->prepare("INSERT INTO unpaid_transactions (account_id, transaction_id, payment_pin) VALUES (?, ?, ?)")
                ->execute([$accountId, $pdo->lastInsertId(), $generatedPin]);
        }
    }
}

// ==========================================
// KLARNA API INTEGRATION (DIREKT ÜBER SDK)
// ==========================================

const KLARNA_UID = '***REMOVED***'; // Ersetze mit deinen Klarna API Credentials
const KLARNA_PW  = '***REMOVED***';
const KLARNA_URL = 'https://api.playground.klarna.com'; // Für Live: https://api.klarna.com

function klarnaRequest($endpoint, $data = null) {
    $ch = curl_init(KLARNA_URL . $endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, KLARNA_UID . ":" . KLARNA_PW);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    if ($data) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    $response = curl_exec($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);
    return ['status' => $info['http_code'], 'body' => json_decode($response, true)];
}

if ($action === 'create_klarna_session') {
    $actionType = $input['actionType'] ?? '';
    $actionData = $input['actionData'] ?? [];
    $amount = (float)($input['amount'] ?? 0);

    if ($actionType !== 'topup') {
    $stmtVals = $pdo->query("SELECT name, def_value FROM default_values WHERE name IN ('card_deposit', 'full_year_per_day', 'half_year_per_day')");
    $dbPrices = ['card_deposit' => 5.0, 'full_year_per_day' => 120.0, 'half_year_per_day' => 80.0];
    while ($row = $stmtVals->fetch()) {
        $dbPrices[$row['name']] = (float)$row['def_value'];
    }
        $cardDeposit = $dbPrices['card_deposit'];
        
        if ($actionType === 'order_card' || $actionType === 'reorder_card') {
            $useBalance = $actionData['useBalance'] ?? false;
            $balanceDeduction = ($useBalance && $currentBalance > 0) ? min($cardDeposit, $currentBalance) : 0;
            $amount = $cardDeposit - $balanceDeduction;
        } elseif ($actionType === 'buy_abo') {
            $type = $actionData['type'] ?? 'halb';
            $days = $actionData['days'] ?? [];
            $cardOption = $actionData['cardOption'] ?? 'existing';
            $useBalance = $actionData['useBalance'] ?? false;

    $basePrice = ($type === 'halb') ? $dbPrices['half_year_per_day'] : $dbPrices['full_year_per_day'];
    $daysCost = count($days) * $basePrice;
            $cardCost = ($cardOption === 'new') ? $cardDeposit : 0;
    $totalPrice = $daysCost + $cardCost;

    $balanceDeduction = ($useBalance && $currentBalance > 0) ? min($totalPrice, $currentBalance) : 0;
            $amount = $totalPrice - $balanceDeduction;
        }
    }
    
    $amount = max(0, $amount);
    $order_amount = (int)round($amount * 100);

    $sessionData = [
        "purchase_country" => "DE",
        "purchase_currency" => "EUR",
        "locale" => "de-DE",
        "order_amount" => $order_amount,
        "order_tax_amount" => 0,
        "order_lines" => [
            [
                "type" => "digital",
                "name" => "MensaPay Service",
                "quantity" => 1,
                "unit_price" => $order_amount,
                "tax_rate" => 0,
                "total_amount" => $order_amount,
                "total_tax_amount" => 0
            ]
        ]
    ];

    $res = klarnaRequest('/payments/v1/sessions', $sessionData);
    if ($res['status'] >= 200 && $res['status'] < 300) {
        $_SESSION['klarna_intent'] = ['actionType' => $actionType, 'actionData' => $actionData, 'amount' => $amount, 'session_id' => $res['body']['session_id']];
        echo json_encode(['client_token' => $res['body']['client_token']]);
    } else {
        error_log("Klarna Session Error: " . print_r($res, true));
        echo json_encode(['status' => 'error', 'message' => 'Klarna-Sitzung konnte nicht erstellt werden.']);
    }
    exit;
}

if ($action === 'place_klarna_order') {
    $authToken = $input['authorization_token'] ?? '';
    $intent = $_SESSION['klarna_intent'] ?? null;

    if (!$intent || !$authToken) {
        echo json_encode(['status' => 'error', 'message' => 'Ungültige Klarna Sitzung.']);
        exit;
    }

    $order_amount = (int)round($intent['amount'] * 100);
    $orderData = [
        "purchase_country" => "DE",
        "purchase_currency" => "EUR",
        "locale" => "de-DE",
        "order_amount" => $order_amount,
        "order_tax_amount" => 0,
        "order_lines" => [
            [
                "type" => "digital",
                "name" => "MensaPay Service",
                "quantity" => 1,
                "unit_price" => $order_amount,
                "tax_rate" => 0,
                "total_amount" => $order_amount,
                "total_tax_amount" => 0
            ]
        ]
    ];

    $res = klarnaRequest('/payments/v1/authorizations/' . $authToken . '/order', $orderData);

    if ($res['status'] >= 200 && $res['status'] < 300) {
        $klarnaOrderId = $res['body']['order_id'];
        
        try {
            $pdo->beginTransaction();
        $actionType = $intent['actionType'];
        $actionData = $intent['actionData'];
            $paymentMethod = 'Klarna';
            $generatedPin = null;
            $amountToNotify = $intent['amount'];

            if ($actionType === 'topup') {
                executeTopup($pdo, $accountId, $intent['amount'], $paymentMethod, $klarnaOrderId, $generatedPin);
            } elseif ($actionType === 'order_card') {
                executeOrderCard($pdo, $accountId, $currentBalance, $actionData, $paymentMethod, $klarnaOrderId, $generatedPin, $amountToNotify);
            } elseif ($actionType === 'buy_abo') {
                executeBuyAbo($pdo, $accountId, $currentBalance, $actionData, $paymentMethod, $klarnaOrderId, $generatedPin, $amountToNotify);
            } elseif ($actionType === 'reorder_card') {
                executeReorderCard($pdo, $accountId, $currentBalance, $actionData, $paymentMethod, $klarnaOrderId, $generatedPin, $amountToNotify);
            }

        $pdo->commit();
        unset($_SESSION['klarna_intent']);
            
            $stmtUser = $pdo->prepare("SELECT vorname, email FROM users WHERE id = ?");
            $stmtUser->execute([$userId]);
            $userData = $stmtUser->fetch();
            
            sendJsonResponseAndContinueBackground(['status' => 'success']);
            
            if ($userData && !empty($userData['email'])) {
                sendOrderConfirmationEmail($userData['email'], $userData['vorname'], $actionType, $amountToNotify, $paymentMethod, $actionData, $generatedPin);
            }
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    } else {
        error_log("Klarna Order Error: " . print_r($res, true));
        echo json_encode(['status' => 'error', 'message' => 'Klarna-Zahlung konnte nicht finalisiert werden.']);
    }
    exit;
}

// ==========================================
// PAYPAL: 1. ORDER ERSTELLEN
// ==========================================
if ($action === 'create_paypal_order') {
    $actionType = $input['actionType'] ?? ''; 
    $actionData = $input['actionData'] ?? [];

    $stmtVals = $pdo->query("SELECT name, def_value FROM default_values WHERE name IN ('card_deposit', 'full_year_per_day', 'half_year_per_day')");
    $dbPrices = ['card_deposit' => 5.0, 'full_year_per_day' => 120.0, 'half_year_per_day' => 80.0];
    while ($row = $stmtVals->fetch()) {
        $dbPrices[$row['name']] = (float)$row['def_value'];
    }
    $cardDeposit = $dbPrices['card_deposit'];

    $amount = 0.0;

    if ($actionType === 'topup') {
        $amount = (float)($input['amount'] ?? 0);
    } elseif ($actionType === 'order_card' || $actionType === 'reorder_card') {
        $useBalance = $actionData['useBalance'] ?? false;
        $balanceDeduction = ($useBalance && $currentBalance > 0) ? min($cardDeposit, $currentBalance) : 0;
        $amount = $cardDeposit - $balanceDeduction;
    } elseif ($actionType === 'buy_abo') {
        $type = $actionData['type'] ?? 'halb';
        $days = $actionData['days'] ?? [];
        $cardOption = $actionData['cardOption'] ?? 'existing';
        $useBalance = $actionData['useBalance'] ?? false;

        $basePrice = ($type === 'halb') ? $dbPrices['half_year_per_day'] : $dbPrices['full_year_per_day'];
        $daysCost = count($days) * $basePrice;
        $cardCost = ($cardOption === 'new') ? $cardDeposit : 0;
        $totalPrice = $daysCost + $cardCost;

        $balanceDeduction = ($useBalance && $currentBalance > 0) ? min($totalPrice, $currentBalance) : 0;
        $amount = $totalPrice - $balanceDeduction;
    }
    
    $amount = max(0, $amount);

    $PAYPAL_CLIENT_ID = '***REMOVED***';
    $PAYPAL_CLIENT_SECRET = '***REMOVED***';

    if ($PAYPAL_CLIENT_SECRET === '') {
        echo json_encode(['status' => 'error', 'message' => 'Bitte trage dein PayPal Secret in actions.php ein.']);
        exit;
    }

    try {
        $client = PaypalServerSdkClientBuilder::init()
            ->clientCredentialsAuthCredentials(
                ClientCredentialsAuthCredentialsBuilder::init($PAYPAL_CLIENT_ID, $PAYPAL_CLIENT_SECRET)
            )
            ->environment(Environment::SANDBOX) 
            ->build();

        $orderBody = [
            'body' => OrderRequestBuilder::init(
                CheckoutPaymentIntent::CAPTURE,
                [
                    PurchaseUnitRequestBuilder::init(
                        AmountWithBreakdownBuilder::init('EUR', number_format($amount, 2, '.', ''))->build()
                    )->build()
                ]
            )->build()
        ];

        $apiResponse = $client->getOrdersController()->ordersCreate($orderBody);
        $order = $apiResponse->getResult();

        $_SESSION['paypal_intent_' . $order->getId()] = [
            'actionType' => $actionType,
            'actionData' => $actionData,
            'amount' => $amount
        ];

        echo json_encode(['id' => $order->getId()]);
    } catch(Exception $e) {
        error_log("Paypal Create Error: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'Fehler beim Erstellen der PayPal-Zahlung.']);
    }
    exit;
}

// ==========================================
// PAYPAL: 2. ORDER CAPTURE
// ==========================================
if ($action === 'capture_paypal_order') {
    $orderID = $input['orderID'] ?? '';
    
    $PAYPAL_CLIENT_ID = '***REMOVED***';
    $PAYPAL_CLIENT_SECRET = '***REMOVED***';

    try {
        $client = PaypalServerSdkClientBuilder::init()
            ->clientCredentialsAuthCredentials(
                ClientCredentialsAuthCredentialsBuilder::init($PAYPAL_CLIENT_ID, $PAYPAL_CLIENT_SECRET)
            )
            ->environment(Environment::SANDBOX) 
            ->build();

        $captureBody = ['id' => $orderID];
        
        $apiResponse = $client->getOrdersController()->ordersCapture($captureBody);
        $result = $apiResponse->getResult();

        if ($result->getStatus() === 'COMPLETED') {
            $intent = $_SESSION['paypal_intent_' . $orderID] ?? null;
            if ($intent) {
                $transactionId = $orderID;
                $purchaseUnits = $result->getPurchaseUnits();
                if (!empty($purchaseUnits) && $purchaseUnits[0]->getPayments() && !empty($purchaseUnits[0]->getPayments()->getCaptures())) {
                    $capture = $purchaseUnits[0]->getPayments()->getCaptures()[0];
                    if ($capture->getId()) {
                        $transactionId = $capture->getId();
                    }
                }

                $pdo->beginTransaction();
                
                $actionType = $intent['actionType'];
                $actionData = $intent['actionData'];
                $paymentMethod = 'PayPal';

                if ($actionType === 'topup') {
                    executeTopup($pdo, $accountId, $intent['amount'], $paymentMethod, $transactionId);
                } elseif ($actionType === 'order_card') {
                    executeOrderCard($pdo, $accountId, $currentBalance, $actionData, $paymentMethod, $transactionId);
                } elseif ($actionType === 'buy_abo') {
                    executeBuyAbo($pdo, $accountId, $currentBalance, $actionData, $paymentMethod, $transactionId);
                } elseif ($actionType === 'reorder_card') {
                    executeReorderCard($pdo, $accountId, $currentBalance, $actionData, $paymentMethod, $transactionId);
                }

                $pdo->commit();
                unset($_SESSION['paypal_intent_' . $orderID]);
                
                // NEU: E-Mail asynchron im Hintergrund versenden
                $stmtUser = $pdo->prepare("SELECT vorname, email FROM users WHERE id = ?");
                $stmtUser->execute([$userId]);
                $userData = $stmtUser->fetch();
                
                sendJsonResponseAndContinueBackground(['status' => 'success']);
                
                if ($userData && !empty($userData['email'])) {
                    sendOrderConfirmationEmail(
                        $userData['email'], 
                        $userData['vorname'], 
                        $actionType, 
                        $intent['amount'], 
                        $paymentMethod, 
                        $actionData
                    );
                }
                exit;
            } else {
                throw new Exception("Security Error: Order Intent wurde nicht gefunden.");
            }
        } else {
            throw new Exception("Die Zahlung wurde nicht vollständig abgeschlossen.");
        }
    } catch (\PDOException $e) {
        if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
        error_log("Database Error: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'Ein interner Datenbankfehler ist aufgetreten.']);
    } catch(Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// ==========================================
// MANUELLE / GUTHABEN AKTIONEN
// ==========================================
if (in_array($action, ['topup', 'order_card', 'buy_abo', 'block_card', 'reorder_card'])) {
    $paymentMethod = $input['paymentMethod'] ?? 'Guthaben';
    try {
        $pdo->beginTransaction();
        $responseData = ['status' => 'success'];
        $generatedPin = null;
        $amountToNotify = 0;
        
        if ($action === 'topup') {
            $amountToNotify = (float)($input['amount'] ?? 0);
            executeTopup($pdo, $accountId, $amountToNotify, $paymentMethod, null, $generatedPin);
        } elseif ($action === 'order_card') {
            // Betrag wird nun per Referenz aus der Funktion zurückgegeben
            executeOrderCard($pdo, $accountId, $currentBalance, $input, $paymentMethod, null, $generatedPin, $amountToNotify);
        } elseif ($action === 'buy_abo') {
            executeBuyAbo($pdo, $accountId, $currentBalance, $input, $paymentMethod, null, $generatedPin, $amountToNotify);
        } elseif ($action === 'block_card') {
            executeBlockCard($pdo, $accountId, $input);
        } elseif ($action === 'reorder_card') {
            executeReorderCard($pdo, $accountId, $currentBalance, $input, $paymentMethod, null, $generatedPin, $amountToNotify);
        }
        
        if ($generatedPin) {
            $responseData['payment_pin'] = $generatedPin;
        }

        $pdo->commit();

        // NEU: E-Mail asynchron im Hintergrund versenden (nur bei bestell-relevanten Aktionen)
        if ($action !== 'block_card') {
            $stmtUser = $pdo->prepare("SELECT vorname, email FROM users WHERE id = ?");
            $stmtUser->execute([$userId]);
            $userData = $stmtUser->fetch();
            
            // Response an User senden und Verbindung trennen
            sendJsonResponseAndContinueBackground($responseData);
            
            // Ab hier Hintergrund-Logik
            if ($userData && !empty($userData['email'])) {
                sendOrderConfirmationEmail(
                    $userData['email'], 
                    $userData['vorname'], 
                    $action, 
                    $amountToNotify, // Hinweis: Für genaue Beträge bei Abos/Karten müsste hier eine Preisberechnung erfolgen, falls gewünscht
                    $paymentMethod, 
                    $input, 
                    $generatedPin
                );
            }
        } else {
        echo json_encode($responseData);
        }
    } catch (\PDOException $e) {
        if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
        error_log("Database Error: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'Ein interner Datenbankfehler ist aufgetreten.']);
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Unbekannte Aktion.']);