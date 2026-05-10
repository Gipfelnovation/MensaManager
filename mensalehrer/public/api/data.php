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

mm_apply_cors('teacher', ['GET', 'OPTIONS'], ['Content-Type']);
mm_start_session('mensa_login');

try {
    $user = mm_authenticate_user($pdo, ['ADMIN', 'TEACHER']);
    if (!$user) {
        mm_json_response(['error' => 'Nicht autorisiert. Bitte einloggen.'], 403);
    }

    $csrfToken = mm_get_csrf_token();
    $action = (string) ($_GET['action'] ?? '');

    if ($action === 'pending') {
        $statement = $pdo->query(
            "SELECT
                ch.holder_id AS studentId,
                CONCAT(ch.first_name, ' ', ch.last_name) AS studentName,
                ch.class AS grade,
                CONCAT(u.vorname, ' ', u.nachname) AS parentName
             FROM card_holders ch
             JOIN accounts a ON ch.created_by = a.account_id
             JOIN users u ON a.user_id = u.id
             LEFT JOIN chip_cards cc ON ch.holder_id = cc.holder_id
             WHERE cc.card_id IS NULL"
        );

        mm_json_response([
            'pendingCards' => $statement->fetchAll(PDO::FETCH_ASSOC),
            'csrfToken' => $csrfToken,
        ]);
    }

    if ($action === 'active_cards') {
        $statement = $pdo->query(
            "SELECT
                ch.holder_id AS studentId,
                CONCAT(ch.first_name, ' ', ch.last_name) AS studentName,
                ch.class AS grade,
                CONCAT(u.vorname, ' ', u.nachname) AS parentName,
                cc.card_id AS cardId,
                (SELECT COUNT(*)
                 FROM subscriptions sub
                 WHERE sub.holder_id = ch.holder_id
                 AND (sub.end_date >= CURDATE() OR sub.end_date IS NULL)) > 0 AS hasActiveAbo
             FROM card_holders ch
             JOIN chip_cards cc ON ch.holder_id = cc.holder_id
             JOIN accounts a ON cc.account_id = a.account_id
             JOIN users u ON a.user_id = u.id"
        );
        $cards = $statement->fetchAll(PDO::FETCH_ASSOC);

        foreach ($cards as &$card) {
            $card['hasActiveAbo'] = (bool) $card['hasActiveAbo'];
        }
        unset($card);

        mm_json_response([
            'activeCards' => $cards,
            'csrfToken' => $csrfToken,
        ]);
    }

    mm_json_response(['error' => 'Ungueltige Aktion.'], 400);
} catch (Throwable $exception) {
    mm_log_exception('teacher_data', $exception);
    mm_json_response(['error' => 'Serverfehler aufgetreten.'], 500);
}
