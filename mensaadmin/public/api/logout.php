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

mm_apply_cors('admin', ['POST', 'OPTIONS'], ['Content-Type', 'X-CSRF-Token']);
mm_start_session('mensa_login');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    mm_json_response(['success' => false, 'error' => 'Methode nicht erlaubt.'], 405);
}

$userId = isset($_SESSION['userid']) ? (int) $_SESSION['userid'] : 0;
if ($userId > 0) {
    try {
        mm_require_csrf_token();
    } catch (MmClientException $exception) {
        mm_json_response(['success' => false, 'error' => $exception->getMessage()], 403);
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

mm_json_response(['success' => true]);
