<?php
/*
 * MensaManager - Digitale Schulverpflegung
 * Copyright (C) 2026 Lukas Trausch
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see [https://www.gnu.org/licenses/](https://www.gnu.org/licenses/).
 */

require_once __DIR__ . '/mm_bootstrap.php';
require_once __DIR__ . '/config.inc.php';

mm_apply_cors('admin', ['GET', 'OPTIONS'], ['Content-Type']);
mm_start_session('mensa_login');

try {
    $user = mm_authenticate_user($pdo, ['ADMIN']);
    if (!$user) {
        mm_json_response(['error' => 'Zugriff verweigert. Session abgelaufen oder keine Admin-Rechte.'], 403);
    }

    $csrfToken = mm_get_csrf_token();
    $action = $_GET['action'] ?? 'dashboard';
    $response = [];
    if ($action === 'dashboard') {
        $response['csrfToken'] = $csrfToken;
        $response['stats'] = [
            'totalBalance' => 0.0,
            'activeCards' => 0,
            'pendingCards' => 0,
            'unpaidTransactions' => 0
        ];

        $response['stats']['totalBalance'] = (float) $pdo->query("SELECT SUM(balance) FROM accounts")->fetchColumn();
        $response['stats']['activeCards'] = (int) $pdo->query("SELECT COUNT(*) FROM chip_cards WHERE active = 1")->fetchColumn();
        $response['stats']['pendingCards'] = (int) $pdo->query("SELECT COUNT(*) FROM card_holders h LEFT JOIN chip_cards c ON h.holder_id = c.holder_id WHERE c.card_id IS NULL")->fetchColumn();
        $response['stats']['unpaidTransactions'] = (int) $pdo->query("SELECT COUNT(*) FROM unpaid_transactions")->fetchColumn();

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
            'card_deposit' => '0.00',
            'school_name' => '',
            'school_iban' => '',
            'school_bic' => '',
            'imprint' => '',
            'privacy' => ''
        ];
        
        $fields = [
            'full_year_per_day', 'half_year_per_day', 'single_entry', 
            'single_entry_reuse', 'card_deposit', 'school_name', 
            'school_iban', 'school_bic', 'imprint', 'privacy'
        ];
        $placeholders = implode(',', array_fill(0, count($fields), '?'));
        
        $stmt = $pdo->prepare("SELECT name, def_value FROM default_values WHERE name IN ($placeholders)");
        $stmt->execute($fields);
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $response['defaultValues'][$row['name']] = $row['def_value'];
        }
    } 
    
    elseif ($action === 'search') {
        // SICHERHEIT: Rate Limiting f�r die Suchfunktion (Schutz vor Scraping)
        if (!isset($_SESSION['last_search_time'])) {
            $_SESSION['last_search_time'] = time();
            $_SESSION['search_count'] = 0;
        }
        if (time() - $_SESSION['last_search_time'] < 10) { // Zeitfenster: 10 Sekunden
            $_SESSION['search_count']++;
            if ($_SESSION['search_count'] > 15) { // Max 15 Suchen pro 10 Sekunden
                http_response_code(429);
                echo json_encode(['error' => 'Zu viele Suchanfragen. Bitte kurz warten.']);
                exit;
            }
        } else {
            $_SESSION['last_search_time'] = time();
            $_SESSION['search_count'] = 1;
        }

        $query = $_GET['q'] ?? '';
        $searchTerm = "%$query%";
        $results = [];

        if (strlen($query) >= 2) {
            $stmt = $pdo->prepare("SELECT id, email, status, CONCAT(vorname, ' ', nachname) AS name FROM users WHERE email LIKE ? OR vorname LIKE ? OR nachname LIKE ? LIMIT 5");
            $stmt->execute([$searchTerm, $searchTerm, $searchTerm]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $roleMap = [
                    'ADMIN' => 'Adminaccount',
                    'TEACHER' => 'Lehreraccount',
                    'USER' => 'Elternaccount'
                ];
                $categoryName = isset($roleMap[$row['status']]) ? $roleMap[$row['status']] : 'Elternaccount';
                $results[] = ['title' => $row['name'], 'subtitle' => $row['email'], 'category' => $categoryName, 'parentId' => 'p' . $row['id'], 'iconType' => 'Users'];
            }

            $stmt = $pdo->prepare("SELECT h.holder_id, h.first_name, h.last_name, h.class, a.user_id FROM card_holders h JOIN accounts a ON h.created_by = a.account_id WHERE h.first_name LIKE ? OR h.last_name LIKE ? LIMIT 5");
            $stmt->execute([$searchTerm, $searchTerm]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $results[] = ['title' => $row['first_name'] . ' ' . $row['last_name'], 'subtitle' => 'Klasse ' . $row['class'], 'category' => 'Sch�lerprofil', 'parentId' => 'p' . $row['user_id'], 'iconType' => 'Edit2'];
            }

            $stmt = $pdo->prepare("SELECT c.card_uid, h.first_name, h.last_name, a.user_id FROM chip_cards c JOIN card_holders h ON c.holder_id = h.holder_id JOIN accounts a ON h.created_by = a.account_id WHERE c.card_uid LIKE ? LIMIT 5");
            $stmt->execute([$searchTerm]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $results[] = ['title' => 'Karte: ' . $row['card_uid'], 'subtitle' => 'Geh�rt zu: ' . $row['first_name'] . ' ' . $row['last_name'], 'category' => 'Kartennummer', 'parentId' => 'p' . $row['user_id'], 'iconType' => 'CreditCard'];
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

            // Abos (Status anhand von transaction_nr pr�fen + Daten & Tage auslesen)
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

    elseif ($action === 'active_cards') {
        $response['activeCards'] = [];
        $stmt = $pdo->query("
            SELECT c.card_id, c.card_uid, h.holder_id, CONCAT(h.first_name, ' ', h.last_name) AS student_name, h.class, 
                   u.id AS parent_id, CONCAT(u.vorname, ' ', u.nachname) AS parent_name,
                   (SELECT COUNT(*) FROM subscriptions s WHERE s.holder_id = h.holder_id AND s.start_date <= CURDATE() AND (s.end_date IS NULL OR s.end_date >= CURDATE()) AND s.transaction_nr IS NOT NULL AND s.transaction_nr != '') AS active_abo_count
            FROM chip_cards c
            JOIN card_holders h ON c.holder_id = h.holder_id
            JOIN accounts a ON h.created_by = a.account_id
            JOIN users u ON a.user_id = u.id
            WHERE c.active = 1
            ORDER BY h.class, student_name
        ");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $response['activeCards'][] = [
                'id' => 'c' . $row['card_id'],
                'cardId' => 'c' . $row['card_id'],
                'cardNumber' => $row['card_uid'],
                'studentId' => 's' . $row['holder_id'],
                'studentName' => $row['student_name'],
                'grade' => $row['class'],
                'parentId' => 'p' . $row['parent_id'],
                'parentName' => $row['parent_name'],
                'hasActiveAbo' => $row['active_abo_count'] > 0
            ];
        }
    }
    
    elseif ($action === 'unpaid') {
        $response['unpaidTransactions'] = [];
        $stmt = $pdo->query("
            SELECT ut.unpaid_id, ut.payment_pin, ut.subscription_id, 
                   t.transaction_id, t.amount, t.description, t.transaction_type, t.occurred_at,
                   u.id AS user_id, CONCAT(u.vorname, ' ', u.nachname) AS user_name
            FROM unpaid_transactions ut
            JOIN account_transactions t ON ut.transaction_id = t.transaction_id
            JOIN accounts a ON ut.account_id = a.account_id
            JOIN users u ON a.user_id = u.id
            ORDER BY t.occurred_at DESC
        ");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $response['unpaidTransactions'][] = [
                'unpaidId' => $row['unpaid_id'],
                'pin' => $row['payment_pin'],
                'subscriptionId' => $row['subscription_id'],
                'transactionId' => $row['transaction_id'],
                'amount' => (float)$row['amount'],
                'description' => $row['description'],
                'type' => $row['transaction_type'],
                'date' => $row['occurred_at'],
                'userId' => 'p' . $row['user_id'],
                'userName' => $row['user_name']
            ];
        }
    }

    // --- NEUER ACCOUNTING BEREICH ---
    elseif ($action === 'accounting') {
        $startDate = ($_GET['start'] ?? '2000-01-01') . ' 00:00:00';
        $endDate = ($_GET['end'] ?? date('Y-m-d')) . ' 23:59:59';

        // 1. Gesamtguthaben aller User berechnet aus den TOPUP-Transaktionen
        $stmt = $pdo->prepare("SELECT SUM(amount) FROM account_transactions WHERE transaction_type = 'TOPUP' AND occurred_at BETWEEN ? AND ?");
        $stmt->execute([$startDate, $endDate]);
        $response['totalBalance'] = (float) $stmt->fetchColumn();

        // 2. Gesamtwert externer Abok�ufe (Ausschlie�en von "(Guthaben)")
        //    Da die Transaktionen im negativen Bereich liegen (-334.00), verwenden wir ABS()
        $stmt = $pdo->prepare("
            SELECT SUM(ABS(amount)) 
            FROM account_transactions 
            WHERE transaction_type = 'SUBSCRIPTION_PURCHASE' 
            AND description NOT LIKE '%(Guthaben)%'
            AND occurred_at BETWEEN ? AND ?
        ");
        $stmt->execute([$startDate, $endDate]);
        $response['externalSubRevenue'] = (float) $stmt->fetchColumn();

        // 3. Wert externer Kartenpfand
        $stmt = $pdo->prepare("
            SELECT SUM(ABS(amount))
            FROM account_transactions 
            WHERE transaction_type = 'DEPOSIT'
            AND description NOT LIKE '%Guthaben%'
            AND occurred_at BETWEEN ? AND ?
        ");
        $stmt->execute([$startDate, $endDate]);
        $response['externalCardDeposit'] = (float) $stmt->fetchColumn();

        // 4. Wochentags-Counter initialisieren
        $weekdayCounts = ['1' => 0, '2' => 0, '3' => 0, '4' => 0, '5' => 0];
        
        // Alle eingetragenen Wochentage aus den AKTIVEN Abos auslesen und zusammenz�hlen
        // G�ltig: Startdatum ist erreicht UND (Enddatum ist nicht gesetzt ODER Enddatum liegt noch in der Zukunft/Heute)
        // UND: Das Abo muss bezahlt sein (transaction_nr ist nicht leer)
        $stmt = $pdo->query("
            SELECT weekdays 
            FROM subscriptions 
            WHERE start_date <= CURDATE() 
            AND (end_date IS NULL OR end_date >= CURDATE())
        ");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (empty($row['weekdays'])) continue;
            
            $days = explode(',', $row['weekdays']);
            foreach ($days as $d) {
                $d = trim(strtoupper($d));
                // Z�hlt den jeweiligen Tag (Unterst�tzt numerisch und Englisch)
                if ($d === '1' || $d === 'MONDAY')    $weekdayCounts['1']++;
                if ($d === '2' || $d === 'TUESDAY')   $weekdayCounts['2']++;
                if ($d === '3' || $d === 'WEDNESDAY') $weekdayCounts['3']++;
                if ($d === '4' || $d === 'THURSDAY')  $weekdayCounts['4']++;
                if ($d === '5' || $d === 'FRIDAY')    $weekdayCounts['5']++;
            }
        }
        $response['weekdayCounts'] = $weekdayCounts;
    }
    // --- ENDE ACCOUNTING BEREICH ---

    // --- NEUER BEREICH: BUCHHALTUNG EXPORT ---
    elseif ($action === 'accounting_export') {
        $startDate = ($_GET['start'] ?? '2000-01-01') . ' 00:00:00';
        $endDate = ($_GET['end'] ?? date('Y-m-d')) . ' 23:59:59';
        
        $response['transactions'] = [];
        
        // Hole alle f�r die Buchhaltung relevanten Transaktionen:
        // 1. Alle echten Einzahlungen (TOPUP)
        // 2. Alle externen Abok�ufe (SUBSCRIPTION_PURCHASE ohne 'Guthaben')
        // 3. Alle externen Kartenpfande (USAGE mit 'Kartenpfand' ohne 'Guthaben')
        
        // UPDATE: LEFT JOIN und COALESCE hinzugef�gt, damit keine Zeilen wegen fehlender Accounts oder NULL-Beschreibungen verworfen werden.
        $stmt = $pdo->prepare("
            SELECT t.transaction_id, t.occurred_at, ABS(t.amount), t.description, t.transaction_type, 
                   a.user_id, CONCAT(u.vorname, ' ', u.nachname) AS user_name
            FROM account_transactions t
            LEFT JOIN accounts a ON t.account_id = a.account_id
            LEFT JOIN users u ON a.user_id = u.id
            WHERE t.occurred_at BETWEEN ? AND ?
            AND (
                  t.transaction_type = 'TOPUP'
               OR (t.transaction_type = 'SUBSCRIPTION_PURCHASE' AND COALESCE(t.description, '') NOT LIKE '%(Guthaben)%')
               OR (t.transaction_type = 'DEPOSIT' AND COALESCE(t.description, '') NOT LIKE '%Guthaben%')
            )
            ORDER BY t.occurred_at DESC
        ");
        $stmt->execute([$startDate, $endDate]);
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            // Typ-�bersetzung f�r die Excel-Tabelle
            $typLabel = 'Unbekannt';
            if ($row['transaction_type'] === 'TOPUP') {
                $typLabel = 'Guthaben-Einzahlung';
            } elseif ($row['transaction_type'] === 'SUBSCRIPTION_PURCHASE') {
                $typLabel = 'Abo (Extern bezahlt)';
            } elseif ($row['transaction_type'] === 'USAGE') {
                $typLabel = 'Kartenpfand (Extern bezahlt)';
            }

            $response['transactions'][] = [
                'id' => $row['transaction_id'],
                'date' => $row['occurred_at'],
                'amount' => (float) $row['amount'],
                'description' => $row['description'],
                'type' => $typLabel,
                'userName' => $row['user_name'],
                'userId' => $row['user_id']
            ];
        }
    }
    // --- ENDE BUCHHALTUNG EXPORT ---

    $jsonOutput = json_encode($response, JSON_INVALID_UTF8_SUBSTITUTE);
    if ($jsonOutput === false) throw new \Exception("JSON Error: " . json_last_error_msg());
    echo $jsonOutput;

} catch (\Throwable $e) {
    http_response_code(500);
    mm_log_exception('admin_data', $e);
    echo json_encode(['error' => "Ein Fehler beim Laden der Datenbank ist aufgetreten."]);
}


