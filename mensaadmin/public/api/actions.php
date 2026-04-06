<?php

require_once __DIR__ . '/../../shared/php/mm_security.php';
require_once __DIR__ . '/config.inc.php';

mm_apply_cors('admin', ['POST', 'OPTIONS'], ['Content-Type', 'X-CSRF-Token']);
mm_start_session('mensa_login');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    mm_json_response(['error' => 'Methode nicht erlaubt.'], 405);
}

$user = mm_authenticate_user($pdo, ['ADMIN']);
if (!$user) {
    mm_json_response(['error' => 'Zugriff verweigert. Session abgelaufen oder keine Admin-Rechte.'], 403);
}

$currentUserId = (int) $user['id'];

try {
    mm_require_csrf_token();
} catch (MmClientException $exception) {
    mm_json_response(['error' => $exception->getMessage()], 403);
}

$input = json_decode(file_get_contents('php://input'), true);
$input = is_array($input) ? $input : [];
$action = $input['action'] ?? '';
$data = $input['data'] ?? [];

// --- SICHERHEIT: Anti-Race-Condition (Idempotency) ---
// Verhindert versehentliche Doppelbuchungen bei zu schnellem Klicken
$payloadHash = md5(json_encode($input));
if (isset($_SESSION['last_action_hash']) && $_SESSION['last_action_hash'] === $payloadHash && time() - $_SESSION['last_action_time'] < 2) {
    echo json_encode(['success' => true, 'note' => 'Ignoriert, da exaktes Duplikat innerhalb von 2 Sekunden']);
    exit;
}
$_SESSION['last_action_hash'] = $payloadHash;
$_SESSION['last_action_time'] = time();
// --- ENDE ANTI-RACE-CONDITION ---

$response = ['success' => false];

try {
    switch ($action) {
        
        case 'assignCardNumber':
            $cardIdStr = $data['cardId'];
            $cardNumber = $data['cardNumber'];
            $faceData = $data['faceData'] ?? null;
            
            $blob = null;
            if ($faceData && preg_match('/^data:image\/png;base64,/', $faceData)) {
                $base64 = substr($faceData, strpos($faceData, ',') + 1);
                $blob = base64_decode($base64);
            }
            
            if (strpos($cardIdStr, 'pending_') === 0) {
                $holderId = (int) str_replace('pending_', '', $cardIdStr);
                $stmt = $pdo->prepare("SELECT created_by FROM card_holders WHERE holder_id = ?");
                $stmt->execute([$holderId]);
                $accountId = $stmt->fetchColumn();
                
                $pdo->beginTransaction();
                if ($blob !== null) {
                    $stmt = $pdo->prepare("UPDATE card_holders SET holder_image = ? WHERE holder_id = ?");
                    $stmt->execute([$blob, $holderId]);
                }
                $stmt = $pdo->prepare("INSERT INTO chip_cards (card_uid, account_id, holder_id, issued_by, issued_at, active) VALUES (?, ?, ?, ?, CURDATE(), 1)");
                $response['success'] = $stmt->execute([$cardNumber, $accountId, $holderId, $currentUserId]);
                $pdo->commit();
            } else {
                $cardId = (int) str_replace('c', '', $cardIdStr);
                $pdo->beginTransaction();
                if ($blob !== null) {
                    $stmt = $pdo->prepare("SELECT holder_id FROM chip_cards WHERE card_id = ?");
                    $stmt->execute([$cardId]);
                    $hId = $stmt->fetchColumn();
                    if ($hId) {
                        $stmt = $pdo->prepare("UPDATE card_holders SET holder_image = ? WHERE holder_id = ?");
                        $stmt->execute([$blob, $hId]);
                    }
                }
                $stmt = $pdo->prepare("UPDATE chip_cards SET card_uid = ?, active = 1 WHERE card_id = ?");
                $response['success'] = $stmt->execute([$cardNumber, $cardId]);
                $pdo->commit();
            }
            break;

        case 'updateCardStatus':
            $cardId = (int) str_replace('c', '', $data['cardId']);
            $active = ($data['newStatus'] === 'Aktiv') ? 1 : 0;
            $stmt = $pdo->prepare("UPDATE chip_cards SET active = ?, deactivated_at = " . ($active ? "NULL" : "CURDATE()") . " WHERE card_id = ?");
            $response['success'] = $stmt->execute([$active, $cardId]);
            break;

        case 'refundTransaction':
            $txId = (int) str_replace('t', '', $data['txId']);
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT account_id, amount, description FROM account_transactions WHERE transaction_id = ? FOR UPDATE");
            $stmt->execute([$txId]);
            $tx = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($tx) {
                $refundAmount = abs((float)$tx['amount']);
                $pdo->prepare("UPDATE accounts SET balance = balance + ? WHERE account_id = ?")->execute([$refundAmount, $tx['account_id']]);
                // SICHERHEIT: Audit Log (wer hat erstattet?)
                $desc = "Erstattung für: " . $tx['description'];
                $pdo->prepare("INSERT INTO account_transactions (account_id, amount, transaction_type, description, occurred_at, admin_id) VALUES (?, ?, 'REFUND', ?, NOW(), ?)")
                    ->execute([$tx['account_id'], $refundAmount, $desc, $currentUserId]);
                $pdo->commit();
                $response['success'] = true;
            } else {
                $pdo->rollBack();
                $response['error'] = 'Transaktion nicht gefunden';
            }
            break;

        case 'deleteAbo':
            $aboId = (int) str_replace('abo', '', $data['aboId']);
            $stmt = $pdo->prepare("DELETE FROM subscriptions WHERE subscription_id = ?");
            $response['success'] = $stmt->execute([$aboId]);
            break;

        case 'markAboPaid':
            $aboId = (int) str_replace('abo', '', $data['aboId']);
            // SICHERHEIT: Audit Log (Admin-ID nicht mehr in Description, nur noch regul�r abspeichern falls n�tig)
            $txNr = strip_tags($data['transactionNr']);
            $stmt = $pdo->prepare("UPDATE subscriptions SET transaction_nr = ? WHERE subscription_id = ?");
            $response['success'] = $stmt->execute([$txNr, $aboId]);
            break;

        case 'markTransactionPaid':
            $unpaidId = (int) $data['unpaidId'];
            // SICHERHEIT: Audit Log
            $transactionNr = strip_tags($data['transactionNr']);

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT ut.subscription_id, ut.transaction_id, t.amount, t.description, t.transaction_type, t.account_id 
                                   FROM unpaid_transactions ut
                                   JOIN account_transactions t ON ut.transaction_id = t.transaction_id
                                   WHERE ut.unpaid_id = ? FOR UPDATE");
            $stmt->execute([$unpaidId]);
            $ut = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($ut) {
                $newDesc = preg_replace('/\s*\([^)]*(warten|ausstehend|Zahlungseingang|unbezahlt)[^)]*\)/i', " ($transactionNr)", $ut['description']);
                if ($newDesc === $ut['description']) {
                    $newDesc .= " ($transactionNr)";
                }

                $stmt = $pdo->prepare("UPDATE account_transactions SET description = ?, admin_id = ? WHERE transaction_id = ?");
                $stmt->execute([$newDesc, $currentUserId, $ut['transaction_id']]);

                if (!empty($ut['subscription_id'])) {
            $stmt = $pdo->prepare("UPDATE subscriptions SET transaction_nr = ? WHERE subscription_id = ?");
                    $stmt->execute([$transactionNr, $ut['subscription_id']]);
                }

                if ($ut['transaction_type'] === 'TOPUP') {
                    $stmt = $pdo->prepare("UPDATE accounts SET balance = balance + ? WHERE account_id = ?");
                    $stmt->execute([abs((float)$ut['amount']), $ut['account_id']]);
                }

                $stmt = $pdo->prepare("DELETE FROM unpaid_transactions WHERE unpaid_id = ?");
                $stmt->execute([$unpaidId]);

                $pdo->commit();
                $response['success'] = true;
            } else {
                $pdo->rollBack();
                $response['error'] = 'Unbezahlte Transaktion nicht gefunden.';
            }
            break;

        case 'editStudent':
            $studentId = (int) str_replace('s', '', $data['studentId']);
            $names = explode(' ', $data['name'], 2);
            $firstName = $names[0];
            $lastName = $names[1] ?? '';
            $stmt = $pdo->prepare("UPDATE card_holders SET first_name = ?, last_name = ? WHERE holder_id = ?");
            $response['success'] = $stmt->execute([$firstName, $lastName, $studentId]);
            break;

        case 'deposit':
            $userId = (int) str_replace('p', '', $data['parentId']);
            $amount = (float) $data['amount'];
            // SICHERHEIT: Audit Log (wer hat eingezahlt?)
            $desc = $data['description'] ?: 'Manuelle Einzahlung (Admin)';

            if ($amount <= 0) {
                $response['error'] = 'Der Betrag muss größer als 0 sein.';
                break;
            }

            $pdo->beginTransaction();

            // Finde die Account-ID des Users und sperre diese Zeile fuer die Transaktion
            $stmt = $pdo->prepare("SELECT account_id FROM accounts WHERE user_id = ? FOR UPDATE");
            $stmt->execute([$userId]);
            $accountId = $stmt->fetchColumn();

            if ($accountId) {
                // 1. Guthaben aufbuchen
                $stmt = $pdo->prepare("UPDATE accounts SET balance = balance + ? WHERE account_id = ?");
                $stmt->execute([$amount, $accountId]);
                
                // 2. Transaktionshistorie pflegen
                $stmt = $pdo->prepare("INSERT INTO account_transactions (account_id, amount, transaction_type, description, occurred_at, admin_id) VALUES (?, ?, 'TOPUP', ?, NOW(), ?)");
                $stmt->execute([$accountId, $amount, $desc, $currentUserId]);
                
                $pdo->commit();
                $response['success'] = true;
            } else {
                $pdo->rollBack();
                $response['error'] = 'Zugehöriges Konto wurde nicht gefunden.';
            }
            break;

        case 'updateSettings':
            // Verarbeitet alle gesendeten Schl�ssel-Wert-Paare (Preise, Bankdaten oder Rechtliches)
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("INSERT INTO default_values (name, def_value) VALUES (:name, :val) ON DUPLICATE KEY UPDATE def_value = :val2");
                
                foreach ($data as $key => $value) {
                    // Sicherheit: Nur erlaubte Keys verarbeiten (optional, aber empfohlen)
                    $allowedKeys = [
                        'full_year_per_day', 'half_year_per_day', 'single_entry', 
                        'single_entry_reuse', 'card_deposit', 'school_name', 
                        'school_iban', 'school_bic', 'imprint', 'privacy'
                    ];
                    
                    if (in_array($key, $allowedKeys)) {
                        // SICHERHEIT: HTML-Tags strikt filtern, au�er bei den rechtlichen Dokumenten
                        $cleanValue = in_array($key, ['imprint', 'privacy']) ? $value : strip_tags((string)$value);
                        $stmt->execute([
                            'name' => $key,
                            'val' => $cleanValue,
                            'val2' => $cleanValue
                        ]);
                    }
                }
                
                $pdo->commit();
                $response['success'] = true;
            } catch (Exception $e) {
                $pdo->rollBack();
                mm_log_exception('admin_update_settings', $e);
                $response['error'] = 'Fehler beim Speichern der Einstellungen.';
            }
            break;

        case 'updateUserRole':
            // SICHERHEIT: Selbst-Aussperrung (Sich selbst die Admin-Rechte entziehen) verhindern
            if ((int)$data['userId'] === (int)$currentUserId && $data['role'] !== 'ADMIN') {
                $response['error'] = 'Du kannst dir nicht selbst die Admin-Rechte entziehen.';
                break;
            }
            $stmt = $pdo->prepare("UPDATE users SET status = ? WHERE id = ?");
            $stmt->execute([$data['role'], $data['userId']]);
            $response['success'] = true;
            break;

        case 'deleteUserAccount':
            $accountId = $data['userId'];
            // SICHERHEIT: L�schung des eigenen Accounts �ber das Admin-Panel verhindern
            if ((int)$accountId === (int)$currentUserId) {
                $response['error'] = 'Du kannst deinen eigenen Account hier nicht l�schen.';
                break;
            }
            try {
                $pdo->beginTransaction();
                
                // 1. Alle Karten der Kinder l�schen
                $pdo->prepare("DELETE FROM chip_cards WHERE account_id IN (SELECT account_id FROM accounts WHERE user_id = ?)")->execute([$accountId]);
                
                // 2. Alle Abos der Kinder l�schen
                $pdo->prepare("DELETE FROM subscriptions WHERE holder_id IN (SELECT holder_id FROM card_holders WHERE created_by IN (SELECT account_id FROM accounts WHERE user_id = ?))")->execute([$accountId]);
                
                // 3. Alle Kinder (Holder) l�schen
                $pdo->prepare("DELETE FROM card_holders WHERE created_by IN (SELECT account_id FROM accounts WHERE user_id = ?)")->execute([$accountId]);
                
                // 4. Alle Transaktionen l�schen
                $pdo->prepare("DELETE FROM account_transactions WHERE account_id IN (SELECT account_id FROM accounts WHERE user_id = ?)")->execute([$accountId]);
                
                // 5. Den Verrechnungsaccount l�schen
                $pdo->prepare("DELETE FROM accounts WHERE account_id IN (SELECT account_id FROM accounts WHERE user_id = ?)")->execute([$accountId]);

                // 6. Hauptkonto l�schen
                $pdo->prepare("DELETE FROM users WHERE id  = ?")->execute([$accountId]);
                
                $pdo->commit();
                $response['success'] = true;
            } catch (Exception $e) {
                $pdo->rollBack();
                mm_log_exception('admin_delete_user_account', $e);
                $response['error'] = 'Fehler bei der DSGVO-Loeschung.';
            }
            break;

        case 'collectCard':
            // IDs bereinigen, falls Prfixe mitgeschickt werden (z.B. 'c1', 's2')
            $cardId = (int) str_replace('c', '', $data['cardId']);
            $studentId = (int) str_replace('s', '', $data['studentId']);
            $deleteStudent = !empty($data['deleteStudent']);
            
            $pdo->beginTransaction();
            
            // 1. Account-ID der Karte ermitteln (fr die Rckerstattung) und Datensatz sperren
            $stmt = $pdo->prepare("SELECT account_id FROM chip_cards WHERE card_id = ? FOR UPDATE");
            $stmt->execute([$cardId]);
            $accountId = $stmt->fetchColumn();
            
            if ($accountId) {
                // 2. Aktuellen Pfand-Wert aus den Settings holen
                $stmt = $pdo->query("SELECT def_value FROM default_values WHERE name = 'card_deposit'");
                $depositAmount = (float) $stmt->fetchColumn();
                
                if ($depositAmount > 0) {
                    // 3. Balance auf dem Eltern-Account erh�hen
                    $stmt = $pdo->prepare("UPDATE accounts SET balance = balance + ? WHERE account_id = ?");
                    $stmt->execute([$depositAmount, $accountId]);
                    
                    // 4. Transaktion "Pfand zur�ckerstattet" eintragen
                    $stmt = $pdo->prepare("INSERT INTO account_transactions (account_id, amount, transaction_type, description, occurred_at, admin_id) VALUES (?, ?, 'REFUND', 'Kartenpfand zur�ckerstattet', NOW(), ?)");
                    $stmt->execute([$accountId, $depositAmount, $currentUserId]);
                }
            }
            
            // 5. Karte aus dem System l�schen
            $stmt = $pdo->prepare("DELETE FROM chip_cards WHERE card_id = ?");
            $stmt->execute([$cardId]);
            
            // 6. Falls $deleteStudent == true ist (Sch�ler hat keine aktiven Abos mehr)
            if ($deleteStudent) {
                // Lsche alle eventuell noch vorhandenen abgelaufenen Abos dieses Schlers
                $stmt = $pdo->prepare("DELETE FROM subscriptions WHERE holder_id = ?");
                $stmt->execute([$studentId]);
                
                // Lsche den Schler (Holder) aus der Datenbank
                $stmt = $pdo->prepare("DELETE FROM card_holders WHERE holder_id = ?");
                $stmt->execute([$studentId]);
            }
            
            $pdo->commit();
            $response['success'] = true;
            break;

        default:
            $response['error'] = 'Unbekannte Aktion';
            break;
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    mm_log_exception('admin_actions', $e);
    $response['error'] = 'Die Aktion konnte gerade nicht ausgeführt werden.';
}

echo json_encode($response);
?>