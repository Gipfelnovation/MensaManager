<?php

if (!class_exists('MmClientException')) {
    class MmClientException extends RuntimeException
    {
    }
}

if (!class_exists('MmConfigurationException')) {
    class MmConfigurationException extends RuntimeException
    {
    }
}

function mm_repo_root()
{
    return dirname(__DIR__, 2);
}

function mm_load_env_files()
{
    static $loaded = false;

    if ($loaded) {
        return;
    }

    $loaded = true;

    $files = [
        mm_repo_root() . DIRECTORY_SEPARATOR . 'shared' . DIRECTORY_SEPARATOR . '.env.local',
        mm_repo_root() . DIRECTORY_SEPARATOR . 'shared' . DIRECTORY_SEPARATOR . '.env',
        mm_repo_root() . DIRECTORY_SEPARATOR . '.env.local',
        mm_repo_root() . DIRECTORY_SEPARATOR . '.env',
    ];

    foreach ($files as $file) {
        if (!is_readable($file)) {
            continue;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            continue;
        }

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || strpos($trimmed, '#') === 0 || strpos($trimmed, '=') === false) {
                continue;
            }

            list($key, $value) = explode('=', $trimmed, 2);
            $key = trim($key);
            $value = trim($value);

            if ($key === '') {
                continue;
            }

            if (
                (substr($value, 0, 1) === '"' && substr($value, -1) === '"') ||
                (substr($value, 0, 1) === "'" && substr($value, -1) === "'")
            ) {
                $value = substr($value, 1, -1);
            }

            if (getenv($key) !== false || isset($_ENV[$key]) || isset($_SERVER[$key])) {
                continue;
            }

            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv($key . '=' . $value);
        }
    }
}

function mm_env($key, $default = null)
{
    mm_load_env_files();

    $value = getenv($key);
    if ($value === false && isset($_ENV[$key])) {
        $value = $_ENV[$key];
    }
    if ($value === false && isset($_SERVER[$key])) {
        $value = $_SERVER[$key];
    }

    if ($value === false || $value === null || $value === '') {
        return $default;
    }

    return $value;
}

function mm_env_required($key)
{
    $value = mm_env($key);
    if ($value === null || $value === '') {
        throw new MmConfigurationException('Missing required configuration: ' . $key);
    }

    return $value;
}

function mm_is_https()
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }

    if (!empty($_SERVER['REQUEST_SCHEME']) && strtolower((string) $_SERVER['REQUEST_SCHEME']) === 'https') {
        return true;
    }

    if (!empty($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
        return true;
    }

    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $forwardedProto = strtolower(trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_PROTO'])[0]));
        if ($forwardedProto === 'https') {
            return true;
        }
    }

    if (!empty($_SERVER['HTTP_X_FORWARDED_PORT']) && (int) $_SERVER['HTTP_X_FORWARDED_PORT'] === 443) {
        return true;
    }

    if (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_SSL']) === 'on') {
        return true;
    }

    if (!empty($_SERVER['HTTP_FRONT_END_HTTPS']) && strtolower((string) $_SERVER['HTTP_FRONT_END_HTTPS']) !== 'off') {
        return true;
    }

    return false;
}

function mm_current_host()
{
    $host = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '')));
    if ($host === '') {
        return '';
    }

    $parts = explode(':', $host, 2);
    return $parts[0];
}

function mm_normalize_cookie_domain($domain)
{
    $domain = strtolower(trim((string) $domain));
    if ($domain === '') {
        return '';
    }

    if (strpos($domain, '://') !== false) {
        $parsed = parse_url($domain, PHP_URL_HOST);
        $domain = $parsed !== null && $parsed !== false ? (string) $parsed : '';
    }

    return ltrim($domain, '.');
}

function mm_cookie_domain()
{
    $configuredDomain = mm_normalize_cookie_domain(mm_env('MM_COOKIE_DOMAIN', ''));
    if ($configuredDomain === '') {
        return '';
    }

    $currentHost = mm_current_host();
    if ($currentHost === '') {
        return $configuredDomain;
    }

    if ($currentHost === $configuredDomain) {
        return $configuredDomain;
    }

    if (substr($currentHost, -strlen('.' . $configuredDomain)) === '.' . $configuredDomain) {
        return $configuredDomain;
    }

    return '';
}

function mm_session_cookie_params($persistent = false)
{
    $params = [
        'lifetime' => 0,
        'path' => '/',
        'secure' => mm_is_https(),
        'httponly' => true,
        'samesite' => 'Strict',
    ];

    $domain = mm_cookie_domain();
    if ($domain !== '') {
        $params['domain'] = $domain;
    }

    if ($persistent) {
        $days = (int) mm_env('MM_REMEMBER_ME_DAYS', '30');
        if ($days < 1) {
            $days = 30;
        }
        $params['expires'] = time() + ($days * 86400);
    }

    return $params;
}

function mm_start_session($name = 'mensa_login')
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', mm_is_https() ? '1' : '0');
    ini_set('session.cookie_samesite', 'Strict');

    session_name($name);
    session_set_cookie_params(mm_session_cookie_params());
    session_start();

    if (!isset($_SESSION['initiated'])) {
        session_regenerate_id(true);
        $_SESSION['initiated'] = true;
    }
}

function mm_apply_cors($app, $methods = ['GET', 'POST', 'OPTIONS'], $headers = ['Content-Type', 'X-CSRF-Token'])
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowedOrigins = mm_allowed_origins($app);

    header('Vary: Origin', false);
    header('Access-Control-Allow-Methods: ' . implode(', ', $methods));
    header('Access-Control-Allow-Headers: ' . implode(', ', $headers));
    header('Content-Type: application/json; charset=utf-8');

    if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        if ($origin !== '' && !in_array($origin, $allowedOrigins, true)) {
            http_response_code(403);
        }
        exit;
    }
}

function mm_allowed_origins($app)
{
    $defaults = [
        'admin' => ['https://admin.mensamanager.de'],
        'teacher' => ['https://lehrer.mensamanager.de'],
        'portal' => ['https://www.mensamanager.de', 'https://mensamanager.de'],
    ];

    $envKey = 'MM_' . strtoupper($app) . '_ALLOWED_ORIGINS';
    $configured = (string) mm_env($envKey, '');

    if ($configured !== '') {
        $origins = array_values(array_filter(array_map('trim', explode(',', $configured))));
        if (!empty($origins)) {
            return $origins;
        }
    }

    return $defaults[$app] ?? [];
}

function mm_json_response($payload, $status = 200)
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        $status = 500;
        $json = '{"status":"error","message":"Antwort konnte nicht erzeugt werden."}';
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Length: ' . strlen($json));
    echo $json;
    exit;
}

function mm_log_exception($context, $exception)
{
    error_log(sprintf(
        '[%s] %s in %s:%d',
        $context,
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine()
    ));
}

function mm_get_real_ip()
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $forwardedIps = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']);
        $forwardedIp = trim($forwardedIps[0]);
        if (filter_var($forwardedIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            $ip = $forwardedIp;
        }
    }

    return $ip;
}

function mm_verify_hcaptcha_token($token, $ipAddress)
{
    if (!mm_is_hcaptcha_enabled()) {
        return true;
    }

    $secret = mm_env_required('MM_HCAPTCHA_SECRET');
    $response = file_get_contents('https://hcaptcha.com/siteverify', false, stream_context_create([
        'http' => [
            'header' => "Content-type: application/x-www-form-urlencoded\r\n",
            'method' => 'POST',
            'content' => http_build_query([
                'secret' => $secret,
                'response' => $token,
                'remoteip' => $ipAddress,
            ]),
        ],
    ]));

    if ($response === false) {
        return false;
    }

    $decoded = json_decode($response);
    return !empty($decoded) && !empty($decoded->success);
}

function mm_get_hcaptcha_site_key()
{
    return (string) mm_env('MM_HCAPTCHA_SITE_KEY', '');
}

function mm_is_hcaptcha_enabled()
{
    return mm_env('MM_HCAPTCHA_SITE_KEY', '') !== '' && mm_env('MM_HCAPTCHA_SECRET', '') !== '';
}

function mm_get_paypal_client_id()
{
    return (string) mm_env('MM_PAYPAL_CLIENT_ID', '');
}

function mm_get_paypal_client_secret()
{
    return mm_env_required('MM_PAYPAL_CLIENT_SECRET');
}

function mm_get_paypal_environment()
{
    return strtolower((string) mm_env('MM_PAYPAL_ENV', 'sandbox'));
}

function mm_get_klarna_config()
{
    return [
        'username' => mm_env_required('MM_KLARNA_USERNAME'),
        'password' => mm_env_required('MM_KLARNA_PASSWORD'),
        'url' => rtrim((string) mm_env('MM_KLARNA_URL', 'https://api.playground.klarna.com'), '/'),
    ];
}

function mm_get_mailer_config()
{
    return [
        'host' => mm_env_required('MM_SMTP_HOST'),
        'port' => (int) mm_env('MM_SMTP_PORT', '587'),
        'username' => mm_env_required('MM_SMTP_USERNAME'),
        'password' => mm_env_required('MM_SMTP_PASSWORD'),
        'encryption' => (string) mm_env('MM_SMTP_ENCRYPTION', 'tls'),
        'from_address' => mm_env_required('MM_MAIL_FROM_ADDRESS'),
        'from_name' => (string) mm_env('MM_MAIL_FROM_NAME', 'MensaManager'),
        'reply_to_address' => (string) mm_env('MM_MAIL_REPLY_TO_ADDRESS', ''),
        'reply_to_name' => (string) mm_env('MM_MAIL_REPLY_TO_NAME', ''),
    ];
}

function mm_get_db_config()
{
    return [
        'host' => mm_env_required('MM_DB_HOST'),
        'name' => mm_env_required('MM_DB_NAME'),
        'user' => mm_env_required('MM_DB_USER'),
        'password' => (string) mm_env('MM_DB_PASSWORD', ''),
        'charset' => (string) mm_env('MM_DB_CHARSET', 'utf8mb4'),
    ];
}

function mm_build_pdo()
{
    $config = mm_get_db_config();
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $config['host'],
        $config['name'],
        $config['charset']
    );

    return new PDO($dsn, $config['user'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function mm_get_csrf_token()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function mm_require_csrf_token()
{
    $clientToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $clientToken)) {
        throw new MmClientException('Ungültiges Sicherheits-Token (CSRF). Bitte Seite neu laden.');
    }
}

function mm_remember_token_hash($token)
{
    return substr(hash_hmac('sha256', $token, mm_env_required('MM_REMEMBER_ME_SECRET')), 0, 40);
}

function mm_set_cookie_value($name, $value, $persistent = false)
{
    $options = mm_session_cookie_params($persistent);
    setcookie($name, $value, $options);

    if (!empty($options['domain'])) {
        $hostOnlyOptions = $options;
        unset($hostOnlyOptions['domain']);
        setcookie($name, $value, $hostOnlyOptions);
    }
}

function mm_cookie_candidate_domains()
{
    $domains = [''];
    $currentHost = mm_current_host();
    $cookieDomain = mm_cookie_domain();

    if ($currentHost !== '') {
        $domains[] = $currentHost;
    }

    if ($cookieDomain !== '') {
        $domains[] = $cookieDomain;
        $domains[] = '.' . $cookieDomain;
    }

    return array_values(array_unique($domains));
}

function mm_expire_cookie($name, $httponly = true)
{
    $baseOptions = [
        'expires' => time() - 42000,
        'path' => '/',
        'secure' => mm_is_https(),
        'httponly' => $httponly,
        'samesite' => 'Strict',
    ];

    foreach (mm_cookie_candidate_domains() as $domain) {
        $options = $baseOptions;
        if ($domain !== '') {
            $options['domain'] = $domain;
        }

        setcookie($name, '', $options);
    }
}

function mm_issue_remember_me_token($pdo, $userId)
{
    $identifier = bin2hex(random_bytes(16));
    $securityToken = bin2hex(random_bytes(32));
    $tokenHash = mm_remember_token_hash($securityToken);

    $delete = $pdo->prepare('DELETE FROM securitytokens WHERE user_id = ?');
    $delete->execute([$userId]);

    $insert = $pdo->prepare('INSERT INTO securitytokens (user_id, identifier, securitytoken) VALUES (?, ?, ?)');
    $insert->execute([$userId, $identifier, $tokenHash]);

    mm_set_cookie_value('identifier', $identifier, true);
    mm_set_cookie_value('securitytoken', $securityToken, true);
}

function mm_clear_remember_me_token($pdo = null, $identifier = null)
{
    if ($pdo !== null && !empty($identifier)) {
        try {
            $statement = $pdo->prepare('DELETE FROM securitytokens WHERE identifier = ?');
            $statement->execute([$identifier]);
        } catch (Throwable $exception) {
            mm_log_exception('remember_me_cleanup', $exception);
        }
    }

    mm_expire_cookie('identifier');
    mm_expire_cookie('securitytoken');
}

function mm_clear_remember_me_tokens_for_user($pdo, $userId)
{
    if ($pdo === null || (int) $userId <= 0) {
        return;
    }

    try {
        $statement = $pdo->prepare('DELETE FROM securitytokens WHERE user_id = ?');
        $statement->execute([(int) $userId]);
    } catch (Throwable $exception) {
        mm_log_exception('remember_me_user_cleanup', $exception);
    }
}

function mm_restore_remembered_user($pdo, $allowedStatuses)
{
    if (empty($_COOKIE['identifier']) || empty($_COOKIE['securitytoken'])) {
        return null;
    }

    $identifier = (string) $_COOKIE['identifier'];
    $presentedToken = (string) $_COOKIE['securitytoken'];

    $statement = $pdo->prepare('SELECT u.id, u.status, s.securitytoken FROM securitytokens s JOIN users u ON s.user_id = u.id WHERE s.identifier = ?');
    $statement->execute([$identifier]);
    $tokenData = $statement->fetch(PDO::FETCH_ASSOC);

    if (!$tokenData) {
        mm_clear_remember_me_token(null, $identifier);
        return null;
    }

    $storedHash = (string) $tokenData['securitytoken'];
    $isValid = false;

    try {
        $currentHash = mm_remember_token_hash($presentedToken);
        $isValid = hash_equals($storedHash, $currentHash);
    } catch (MmConfigurationException $exception) {
        mm_log_exception('remember_me_configuration', $exception);
        mm_clear_remember_me_token(null, $identifier);
        return null;
    }

    if (!$isValid && preg_match('/^[a-f0-9]{40}$/i', $storedHash)) {
        $isValid = hash_equals(strtolower($storedHash), sha1($presentedToken));
    }

    if (!$isValid || !in_array($tokenData['status'], $allowedStatuses, true)) {
        mm_clear_remember_me_token($pdo, $identifier);
        return null;
    }

    $newSecurityToken = bin2hex(random_bytes(32));
    $update = $pdo->prepare('UPDATE securitytokens SET securitytoken = ? WHERE identifier = ?');
    $update->execute([mm_remember_token_hash($newSecurityToken), $identifier]);
    mm_set_cookie_value('identifier', $identifier, true);
    mm_set_cookie_value('securitytoken', $newSecurityToken, true);

    session_regenerate_id(true);
    $_SESSION['userid'] = $tokenData['id'];

    return $tokenData;
}

function mm_authenticate_user($pdo, $allowedStatuses)
{
    if (!empty($_SESSION['userid'])) {
        $statement = $pdo->prepare('SELECT id, status FROM users WHERE id = ?');
        $statement->execute([$_SESSION['userid']]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);

        if ($user && in_array($user['status'], $allowedStatuses, true)) {
            return $user;
        }
    }

    return mm_restore_remembered_user($pdo, $allowedStatuses);
}

function mm_destroy_session($additionalSessionNames = [])
{
    $sessionNames = array_values(array_unique(array_filter(array_merge(
        [session_name() ?: 'PHPSESSID'],
        is_array($additionalSessionNames) ? $additionalSessionNames : []
    ))));

    $_SESSION = [];

    foreach ($sessionNames as $sessionName) {
        mm_expire_cookie($sessionName);
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

function mm_logout_user($pdo = null, $userId = null, $identifier = null, $additionalSessionNames = [])
{
    if ($pdo !== null && (int) $userId > 0) {
        mm_clear_remember_me_tokens_for_user($pdo, (int) $userId);
    }

    mm_clear_remember_me_token($pdo, $identifier);
    mm_destroy_session($additionalSessionNames);
}

