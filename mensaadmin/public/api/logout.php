<?php

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
