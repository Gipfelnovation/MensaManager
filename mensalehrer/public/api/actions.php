<?php

require_once __DIR__ . '/../../shared/php/mm_security.php';
require_once __DIR__ . '/config.inc.php';

mm_apply_cors('teacher', ['POST', 'OPTIONS'], ['Content-Type', 'X-CSRF-Token']);
mm_start_session('mensa_login');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    mm_json_response(['success' => false, 'error' => 'Methode nicht erlaubt.'], 405);
}

$user = mm_authenticate_user($pdo, ['ADMIN', 'TEACHER']);
if (!$user) {
    mm_json_response(['success' => false, 'error' => 'Nicht autorisiert. Bitte neu einloggen.'], 403);
}

try {
    mm_require_csrf_token();
} catch (MmClientException $exception) {
    mm_json_response(['success' => false, 'error' => $exception->getMessage()], 403);
}

$currentUserId = (int) $user['id'];
$response = ['success' => false];
$action = (string) ($_POST['action'] ?? '');

try {
    switch ($action) {
        case 'assignCardNumber':
            $cardIdStr = (string) ($_POST['cardId'] ?? '');
            $cardNumber = trim((string) ($_POST['cardNumber'] ?? ''));
            $faceData = $_POST['faceData'] ?? null;

            if ($cardIdStr === '' || $cardNumber === '') {
                throw new MmClientException('Fehlende Parameter (Schueler oder Kartennummer).');
            }

            $duplicateStatement = $pdo->prepare('SELECT card_id FROM chip_cards WHERE card_uid = ? AND active = 1');
            $duplicateStatement->execute([$cardNumber]);
            if ($duplicateStatement->fetch()) {
                throw new MmClientException('Diese Kartennummer ist bereits im System vergeben.');
            }

            $blob = null;
            if ($faceData && preg_match('/^data:image\/png;base64,/', (string) $faceData)) {
                $base64 = substr((string) $faceData, strpos((string) $faceData, ',') + 1);
                $blob = base64_decode($base64, true);
                if ($blob === false) {
                    throw new MmClientException('Das uebermittelte Foto konnte nicht verarbeitet werden.');
                }
            }

            if (strpos($cardIdStr, 'pending_') === 0) {
                $holderId = (int) str_replace('pending_', '', $cardIdStr);

                $accountStatement = $pdo->prepare('SELECT created_by FROM card_holders WHERE holder_id = ?');
                $accountStatement->execute([$holderId]);
                $accountId = $accountStatement->fetchColumn();

                if (!$accountId) {
                    throw new MmClientException('Kein zugehoeriges Eltern-Konto zum Schueler gefunden.');
                }

                $pdo->beginTransaction();

                if ($blob !== null) {
                    $imageStatement = $pdo->prepare('UPDATE card_holders SET holder_image = ? WHERE holder_id = ?');
                    $imageStatement->execute([$blob, $holderId]);
                }

                $insertStatement = $pdo->prepare(
                    'INSERT INTO chip_cards (card_uid, account_id, holder_id, issued_by, issued_at, active)
                     VALUES (?, ?, ?, ?, CURDATE(), 1)'
                );
                $response['success'] = $insertStatement->execute([$cardNumber, $accountId, $holderId, $currentUserId]);
                $pdo->commit();
            } else {
                $cardId = (int) str_replace('c', '', $cardIdStr);
                $pdo->beginTransaction();

                if ($blob !== null) {
                    $holderStatement = $pdo->prepare('SELECT holder_id FROM chip_cards WHERE card_id = ?');
                    $holderStatement->execute([$cardId]);
                    $holderId = $holderStatement->fetchColumn();

                    if ($holderId) {
                        $imageStatement = $pdo->prepare('UPDATE card_holders SET holder_image = ? WHERE holder_id = ?');
                        $imageStatement->execute([$blob, $holderId]);
                    }
                }

                $updateStatement = $pdo->prepare('UPDATE chip_cards SET card_uid = ?, active = 1 WHERE card_id = ?');
                $response['success'] = $updateStatement->execute([$cardNumber, $cardId]);
                $pdo->commit();
            }
            break;

        case 'collectCard':
            $cardIdStr = (string) ($_POST['cardId'] ?? '');
            $studentIdStr = (string) ($_POST['studentId'] ?? '');
            $deleteStudent = !empty($_POST['deleteStudent']);

            $cardId = (int) str_replace('c', '', $cardIdStr);
            $studentId = (int) str_replace('s', '', $studentIdStr);

            if ($cardId <= 0) {
                throw new MmClientException('Keine Karten-ID angegeben.');
            }

            $pdo->beginTransaction();

            $accountStatement = $pdo->prepare('SELECT account_id FROM chip_cards WHERE card_id = ? FOR UPDATE');
            $accountStatement->execute([$cardId]);
            $accountId = $accountStatement->fetchColumn();

            if ($accountId) {
                $depositStatement = $pdo->query("SELECT def_value FROM default_values WHERE name = 'card_deposit'");
                $depositAmount = (float) $depositStatement->fetchColumn();

                if ($depositAmount > 0) {
                    $balanceStatement = $pdo->prepare('UPDATE accounts SET balance = balance + ? WHERE account_id = ?');
                    $balanceStatement->execute([$depositAmount, $accountId]);

                    $transactionStatement = $pdo->prepare(
                        "INSERT INTO account_transactions (account_id, amount, transaction_type, description, occurred_at)
                         VALUES (?, ?, 'REFUND', 'Kartenpfand zurueckerstattet', NOW())"
                    );
                    $transactionStatement->execute([$accountId, $depositAmount]);
                }
            }

            $deleteCardStatement = $pdo->prepare('DELETE FROM chip_cards WHERE card_id = ?');
            $deleteCardStatement->execute([$cardId]);

            if ($deleteStudent) {
                $deleteSubscriptions = $pdo->prepare('DELETE FROM subscriptions WHERE holder_id = ?');
                $deleteSubscriptions->execute([$studentId]);

                $deleteHolder = $pdo->prepare('DELETE FROM card_holders WHERE holder_id = ?');
                $deleteHolder->execute([$studentId]);
            }

            $pdo->commit();
            $response['success'] = true;
            break;

        default:
            throw new MmClientException('Unbekannte Aktion.');
    }
} catch (MmClientException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $response['error'] = $exception->getMessage();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    mm_log_exception('teacher_actions', $exception);
    $response['error'] = 'Die Aktion konnte gerade nicht ausgefuehrt werden.';
}

mm_json_response($response);

