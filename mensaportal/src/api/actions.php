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

function executeTopup($pdo, $accountId, $amount, $method, $transactionId = null) {
    $pdo->prepare("UPDATE accounts SET balance = balance + ? WHERE account_id = ?")->execute([$amount, $accountId]);
    
    $desc = "Aufladung ($method)";
    if ($method === 'PayPal/Klarna' && $transactionId) {
        $desc = "Aufladung (Paypal TAN: " . $transactionId . ")";
    }

    $pdo->prepare("INSERT INTO account_transactions (account_id, amount, transaction_type, description) VALUES (?, ?, 'TOPUP', ?)")
        ->execute([$accountId, $amount, $desc]);
}

function executeOrderCard($pdo, $accountId, $currentBalance, $data, $paymentMethod, $transactionId = null) {
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

    $stmt = $pdo->prepare("INSERT INTO card_holders (first_name, last_name, class, created_by) VALUES (?, ?, ?, ?)");
    $stmt->execute([$firstName, $lastName, $class, $accountId]);
    $holderId = $pdo->lastInsertId();

    $descGuthaben = "Kartenpfand (Guthaben)";
    $descPayment = "Kartenpfand";
    if ($paymentMethod === 'PayPal/Klarna' && $transactionId) {
        $descPayment .= " (Paypal TAN: " . $transactionId . ")";
    }

    if ($balanceDeduction > 0) {
        $pdo->prepare("UPDATE accounts SET balance = balance - ? WHERE account_id = ?")->execute([$balanceDeduction, $accountId]);
        $pdo->prepare("INSERT INTO account_transactions (account_id, amount, transaction_type, description) VALUES (?, ?, 'USAGE', ?)")
            ->execute([$accountId, -$balanceDeduction, $descGuthaben]);
    }
    if ($remainingToPay > 0) {
        $pdo->prepare("INSERT INTO account_transactions (account_id, amount, transaction_type, description) VALUES (?, ?, 'USAGE', ?)")
            ->execute([$accountId, -$remainingToPay, $descPayment]);
    }
}

function executeBuyAbo($pdo, $accountId, $currentBalance, $data, $paymentMethod, $transactionId = null) {
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

    $depositCost = $dbPrices['card_deposit'];
    $basePrice = ($type === 'halb') ? $dbPrices['half_year_per_day'] : $dbPrices['full_year_per_day'];
    
    $daysCost = count($days) * $basePrice;
    $cardCost = ($cardOption === 'new') ? $depositCost : 0;
    $totalPrice = $daysCost + $cardCost;

    $balanceDeduction = ($useBalance && $currentBalance > 0) ? min($totalPrice, $currentBalance) : 0;
    $remainingToPay = $totalPrice - $balanceDeduction;

    $holderId = null;

    if ($cardOption === 'new') {
        $stmt = $pdo->prepare("INSERT INTO card_holders (first_name, last_name, class, created_by) VALUES (?, ?, ?, ?)");
        $stmt->execute([$newStudent['firstName'], $newStudent['lastName'], $newStudent['class'], $accountId]);
        $holderId = $pdo->lastInsertId();
    } else {
        $stmt = $pdo->prepare("SELECT holder_id FROM card_holders WHERE holder_id = ? AND created_by = ?");
        $stmt->execute([$selectedHolderId, $accountId]);
        $res = $stmt->fetch();
        if (!$res) throw new Exception("Ausgewähltes Profil nicht gefunden.");
        $holderId = $res['holder_id'];
    }

    $dbType = ($type === 'halb') ? 'HALF_YEAR' : 'FULL_YEAR';
    $dayMap = ['Mo'=>'MONDAY', 'Di'=>'TUESDAY', 'Mi'=>'WEDNESDAY', 'Do'=>'THURSDAY', 'Fr'=>'FRIDAY'];
    $dbDays = array_map(function($d) use ($dayMap) { return $dayMap[$d] ?? $d; }, $days);
    $weekdaysSet = implode(',', $dbDays);

    $year = date('Y');
    $endMonth = ($type === 'halb') ? '01-31' : '07-31';
    if (($type === 'halb' && date('m') > 1) || ($type === 'ganz' && date('m') > 7)) { $year++; }
    $endDate = "$year-$endMonth";

    // Transaktionsnummer für Guthabenkäufe setzen
    if (!$transactionId && $paymentMethod === 'Guthaben') {
        $transactionId = "Guthaben " . date('d.m.Y H:i');
    }

    $stmt = $pdo->prepare("INSERT INTO subscriptions (holder_id, type, weekdays, start_date, end_date, transaction_nr) VALUES (?, ?, ?, CURDATE(), ?, ?)");
    $stmt->execute([$holderId, $dbType, $weekdaysSet, $endDate, $transactionId]);

    $aboName = ($type === 'halb') ? 'Halbjahresabo' : 'Ganzjahresabo';
    
    $descGuthaben = "$aboName (Guthaben)";
    $descPayment = "$aboName";
    if ($paymentMethod === 'PayPal/Klarna' && $transactionId) {
        $descPayment .= " (Paypal TAN: " . $transactionId . ")";
    }

    if ($balanceDeduction > 0) {
        $pdo->prepare("UPDATE accounts SET balance = balance - ? WHERE account_id = ?")->execute([$balanceDeduction, $accountId]);
        $pdo->prepare("INSERT INTO account_transactions (account_id, amount, transaction_type, description) VALUES (?, ?, 'SUBSCRIPTION_PURCHASE', ?)")
            ->execute([$accountId, -$balanceDeduction, $descGuthaben]);
    }
    if ($remainingToPay > 0) {
        $pdo->prepare("INSERT INTO account_transactions (account_id, amount, transaction_type, description) VALUES (?, ?, 'SUBSCRIPTION_PURCHASE', ?)")
            ->execute([$accountId, -$remainingToPay, $descPayment]);
    }
}

function executeBlockCard($pdo, $accountId, $data) {
    $holderId = $data['holderId'] ?? '';
    $cardUId = $data['cardId'] ?? '';
    
    $stmt = $pdo->prepare("SELECT holder_id FROM card_holders WHERE holder_id = ? AND created_by = ?");
    $stmt->execute([$holderId, $accountId]);
    if (!$stmt->fetch()) throw new Exception("Profil nicht gefunden oder keine Berechtigung.");
    
    $pdo->prepare("UPDATE chip_cards SET active = 0, deactivated_at = CURDATE() WHERE card_uid = ? AND holder_id = ?")
        ->execute([$cardUId, $holderId]);
}

function executeReorderCard($pdo, $accountId, $currentBalance, $data, $paymentMethod, $transactionId = null) {
    $holderId = $data['holderId'] ?? '';
    $useBalance = $data['useBalance'] ?? false;
    
    $stmtVal = $pdo->prepare("SELECT def_value FROM default_values WHERE name = 'card_deposit'");
    $stmtVal->execute();
    $res = $stmtVal->fetch();
    $cardCost = $res ? (float)$res['def_value'] : 5.00;

    $balanceDeduction = ($useBalance && $currentBalance > 0) ? min($cardCost, $currentBalance) : 0;
    $remainingToPay = $cardCost - $balanceDeduction;

    $stmt = $pdo->prepare("SELECT holder_id FROM card_holders WHERE holder_id = ? AND created_by = ?");
    $stmt->execute([$holderId, $accountId]);
    if (!$stmt->fetch()) throw new Exception("Profil nicht gefunden oder keine Berechtigung.");

    // Alte Karte löschen (FK ON DELETE SET NULL sorgt für Transaktionserhalt)
    $pdo->prepare("DELETE FROM chip_cards WHERE holder_id = ?")->execute([$holderId]);

    $descGuthaben = "Ersatzkarte (Guthaben)";
    $descPayment = "Ersatzkarte";
    if ($paymentMethod === 'PayPal/Klarna' && $transactionId) {
        $descPayment .= " (Paypal TAN: " . $transactionId . ")";
    }

    if ($balanceDeduction > 0) {
        $pdo->prepare("UPDATE accounts SET balance = balance - ? WHERE account_id = ?")->execute([$balanceDeduction, $accountId]);
        $pdo->prepare("INSERT INTO account_transactions (account_id, amount, transaction_type, description) VALUES (?, ?, 'USAGE', ?)")
            ->execute([$accountId, -$balanceDeduction, $descGuthaben]);
    }
    if ($remainingToPay > 0) {
        $pdo->prepare("INSERT INTO account_transactions (account_id, amount, transaction_type, description) VALUES (?, ?, 'USAGE', ?)")
            ->execute([$accountId, -$remainingToPay, $descPayment]);
    }
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
                $paymentMethod = 'PayPal/Klarna';

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
                echo json_encode(['status' => 'success']);
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
        if ($action === 'topup') {
            $amount = (float)($input['amount'] ?? 0);
            executeTopup($pdo, $accountId, $amount, $paymentMethod);
        } elseif ($action === 'order_card') {
            executeOrderCard($pdo, $accountId, $currentBalance, $input, $paymentMethod);
        } elseif ($action === 'buy_abo') {
            executeBuyAbo($pdo, $accountId, $currentBalance, $input, $paymentMethod);
        } elseif ($action === 'block_card') {
            executeBlockCard($pdo, $accountId, $input);
        } elseif ($action === 'reorder_card') {
            executeReorderCard($pdo, $accountId, $currentBalance, $input, $paymentMethod);
        }
        $pdo->commit();
        echo json_encode(['status' => 'success']);
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

// ==========================================
// PREISE ABRUFEN
// ==========================================
if ($action === 'get_prices') {
    $stmt = $pdo->query("SELECT name, def_value FROM default_values WHERE name IN ('card_deposit', 'full_year_per_day', 'half_year_per_day')");
    $prices = ['card_deposit' => 5.0, 'full_year_per_day' => 120.0, 'half_year_per_day' => 80.0];
    while ($row = $stmt->fetch()) {
        $prices[$row['name']] = (float)$row['def_value'];
    }
    echo json_encode(['status' => 'success', 'data' => $prices]);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Unbekannte Aktion.']);