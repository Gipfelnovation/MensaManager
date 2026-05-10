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

mm_apply_cors('teacher', ['GET', 'POST', 'OPTIONS'], ['Content-Type']);
mm_start_session('mensa_login');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && isset($_GET['config'])) {
    mm_json_response([
        'success' => true,
        'captchaSiteKey' => mm_get_hcaptcha_site_key(),
    ]);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && isset($_GET['check'])) {
    try {
        $user = mm_authenticate_user($pdo, ['ADMIN', 'TEACHER']);
        mm_json_response([
            'success' => $user !== null,
            'csrfToken' => $user ? mm_get_csrf_token() : null,
        ]);
    } catch (Throwable $exception) {
        mm_log_exception('teacher_auth_check', $exception);
        mm_json_response(['success' => false], 500);
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    mm_json_response(['success' => false, 'error' => 'Methode nicht erlaubt.'], 405);
}

$input = $_POST;
if (empty($input)) {
    $decoded = json_decode(file_get_contents('php://input'), true);
    $input = is_array($decoded) ? $decoded : [];
}

$email = trim((string) ($input['email'] ?? ''));
$password = (string) ($input['password'] ?? '');
$captcha = (string) ($input['h-captcha-response'] ?? '');

$ipAddress = mm_get_real_ip();
$maxAttempts = 5;
$lockoutTime = 15;

$pdo->query("DELETE FROM login_attempts WHERE last_attempt < (NOW() - INTERVAL $lockoutTime MINUTE)");
$attemptStatement = $pdo->prepare('SELECT attempts FROM login_attempts WHERE ip_address = ?');
$attemptStatement->execute([$ipAddress]);
$attempts = $attemptStatement->fetchColumn();

if ($attempts !== false && (int) $attempts >= $maxAttempts) {
    mm_json_response([
        'success' => false,
        'error' => "Zu viele Fehlversuche. Bitte warte $lockoutTime Minuten.",
    ], 429);
}

if (mm_is_hcaptcha_enabled() && $captcha === '') {
    mm_json_response([
        'success' => false,
        'error' => 'Bitte bestaetige, dass du kein Roboter bist (Captcha fehlt).',
    ], 400);
}

try {
    if (!mm_verify_hcaptcha_token($captcha, $ipAddress)) {
        mm_json_response([
            'success' => false,
            'error' => 'Captcha ungueltig. Bitte lade die Seite neu.',
        ], 400);
    }
} catch (MmConfigurationException $exception) {
    mm_log_exception('teacher_hcaptcha_configuration', $exception);
    mm_json_response([
        'success' => false,
        'error' => 'Die Anmeldung ist momentan nicht verfuegbar. Bitte spaeter erneut versuchen.',
    ], 500);
}

$userStatement = $pdo->prepare('SELECT id, passwort, status FROM users WHERE email = ?');
$userStatement->execute([$email]);
$user = $userStatement->fetch(PDO::FETCH_ASSOC);

if ($user && password_verify($password, (string) $user['passwort'])) {
    if ($user['status'] !== 'ADMIN' && $user['status'] !== 'TEACHER') {
        mm_json_response([
            'success' => false,
            'error' => 'Zugriff verweigert. Dieser Bereich ist nur fuer Lehrkraefte.',
        ], 403);
    }

    $clearAttempts = $pdo->prepare('DELETE FROM login_attempts WHERE ip_address = ?');
    $clearAttempts->execute([$ipAddress]);

    session_regenerate_id(true);
    $_SESSION['userid'] = $user['id'];

    mm_json_response([
        'success' => true,
        'csrfToken' => mm_get_csrf_token(),
    ]);
}

$recordFailure = $pdo->prepare(
    'INSERT INTO login_attempts (ip_address, attempts, last_attempt)
     VALUES (?, 1, NOW())
     ON DUPLICATE KEY UPDATE attempts = attempts + 1, last_attempt = NOW()'
);
$recordFailure->execute([$ipAddress]);

$attemptsNow = ((int) $attempts) + 1;
$remaining = max(0, $maxAttempts - $attemptsNow);

sleep(1);

mm_json_response([
    'success' => false,
    'error' => "E-Mail oder Passwort falsch. Noch $remaining Versuch(e).",
], 401);

