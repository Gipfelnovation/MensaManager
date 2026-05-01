<?php

declare(strict_types=1);

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo '403 Forbidden';
    exit;
}

final class MensaInstaller
{
    private string $rootDir;
    private string $sharedDir;
    private string $phpSharedDir;
    private string $stateFile;
    private string $lockFile;

    public function __construct(string $rootDir)
    {
        $this->rootDir = rtrim($rootDir, DIRECTORY_SEPARATOR);
        $this->sharedDir = $this->rootDir . DIRECTORY_SEPARATOR . 'shared';
        $this->phpSharedDir = $this->sharedDir . DIRECTORY_SEPARATOR . 'php';
        $this->stateFile = $this->sharedDir . DIRECTORY_SEPARATOR . '.installer-state.json';
        $this->lockFile = $this->sharedDir . DIRECTORY_SEPARATOR . '.installer-lock.json';

        $this->ensureBaseStructure();
        $this->writeSharedProtectionFiles();
    }

    public function isInstalled(): bool
    {
        return is_file($this->lockFile);
    }

    public function getInstallMeta(): array
    {
        return $this->readJsonFile($this->lockFile);
    }

    public function getState(): array
    {
        $state = $this->readJsonFile($this->stateFile);

        if (empty($state)) {
            return [
                'extracted' => false,
                'archive' => null,
                'last_error' => null,
            ];
        }

        return array_merge([
            'extracted' => false,
            'archive' => null,
            'last_error' => null,
        ], $state);
    }

    public function getSuggestedDefaults(): array
    {
        $scheme = $this->detectScheme();
        $host = $this->detectHost();
        $baseUrl = $scheme . '://' . $host;

        $domainBase = $this->suggestDomainBase($host);

        return [
            'mode' => 'subfolders',
            'base_url' => $baseUrl,
            'portal_url' => $scheme . '://' . ($domainBase !== '' ? 'www.' . $domainBase : $host),
            'teacher_url' => $scheme . '://' . ($domainBase !== '' ? 'lehrer.' . $domainBase : $host),
            'admin_url' => $scheme . '://' . ($domainBase !== '' ? 'admin.' . $domainBase : $host),
            'cookie_domain' => $domainBase !== '' ? $domainBase : $host,
            'db_host' => 'localhost',
            'db_charset' => 'utf8mb4',
            'remember_days' => '30',
            'smtp_port' => '587',
            'smtp_encryption' => 'tls',
            'paypal_env' => 'sandbox',
            'klarna_url' => 'https://api.playground.klarna.com',
        ];
    }

    public function listArchives(): array
    {
        $archives = [];

        foreach (glob($this->rootDir . DIRECTORY_SEPARATOR . '*.zip') ?: [] as $path) {
            $name = basename($path);

            if (str_starts_with($name, '.')) {
                continue;
            }

            $archives[] = [
                'name' => $name,
                'size' => filesize($path) ?: 0,
                'modified' => filemtime($path) ?: time(),
            ];
        }

        usort($archives, static function (array $left, array $right): int {
            return $right['modified'] <=> $left['modified'];
        });

        return $archives;
    }

    public function extractArchive(?string $selectedArchive = null): array
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('Die PHP-Erweiterung ZipArchive ist auf dem Server nicht aktiviert.');
        }

        $archives = $this->listArchives();
        if ($archives === []) {
            throw new RuntimeException('Im Webroot wurde keine ZIP-Datei gefunden.');
        }

        $archive = $this->resolveArchive($archives, $selectedArchive);
        $archivePath = $this->rootDir . DIRECTORY_SEPARATOR . $archive['name'];
        $workspace = $this->sharedDir . DIRECTORY_SEPARATOR . '.installer-work';
        $extractDir = $workspace . DIRECTORY_SEPARATOR . 'extract-' . date('Ymd-His');

        $this->writeState([
            'extracted' => false,
            'archive' => $archive['name'],
            'last_error' => null,
        ]);

        $this->ensureDirectory($workspace);
        $this->removeDirectory($extractDir);
        $this->ensureDirectory($extractDir);

        $zip = new ZipArchive();
        if ($zip->open($archivePath) !== true) {
            $this->removeDirectory($extractDir);
            throw new RuntimeException('Die ZIP-Datei konnte nicht geöffnet werden.');
        }

        $this->assertSafeArchive($zip);

        try {
            $this->extractZipArchive($zip, $extractDir);
        } catch (Throwable $exception) {
            $zip->close();
            $this->removeDirectory($extractDir);
            throw $exception;
        }

        $zip->close();

        $packageRoot = $this->locatePackageRoot($extractDir);
        $packageDirectories = $this->resolveAppDirectories($packageRoot);

        foreach (['user', 'lehrer', 'admin'] as $appName) {
            $source = $packageDirectories[$appName];
            $target = $this->rootDir . DIRECTORY_SEPARATOR . $appName;

            $this->removeDirectory($target);
            $this->copyDirectory($source, $target);
        }

        $this->removeDirectory($extractDir);

        $this->writeState([
            'extracted' => true,
            'archive' => $archive['name'],
            'last_error' => null,
        ]);

        return [
            'archive' => $archive['name'],
            'apps' => ['user', 'lehrer', 'admin'],
        ];
    }

    public function install(array $input): array
    {
        $this->assertAppsExtracted();

        $config = $this->normalizeInstallInput($input);
        $plan = $this->buildInstallPlan($config);

        $this->writeEnvFile($plan);
        $this->writeRuntimeConfigFiles($plan);
        $this->publishRootPortalFilesIfNeeded($plan);
        $this->writeRootApacheConfig($plan);
        $this->writeInstallLock($plan);
        $this->writeState([
            'extracted' => true,
            'archive' => $this->getState()['archive'] ?? null,
            'last_error' => null,
            'installed_at' => date(DATE_ATOM),
        ]);

        return [
            'mode' => $plan['mode'],
            'urls' => $plan['public_urls'],
            'cookie_domain' => $plan['env']['MM_COOKIE_DOMAIN'],
            'uses_apache_router' => $plan['mode'] === 'subdomains',
        ];
    }

    public function serveInstalledEntry(): void
    {
        $meta = $this->getInstallMeta();
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        if ($meta === []) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Installations-Metadaten konnten nicht gelesen werden.';
            exit;
        }

        if ($meta['mode'] === 'subfolders') {
            // Falls der Aufruf ohne Slash am Ende passiert, leiten wir auf die URL inkl. Slash um
            if (preg_match('#/(lehrer|admin)$#', $path)) {
                $this->redirect($path . '/');
            }

            // Auslieferung der index.html je nach Applikation (admin oder lehrer)
            if (preg_match('#/(lehrer|admin)/#', $path, $matches)) {
                $this->serveHtmlFile($this->rootDir . DIRECTORY_SEPARATOR . $matches[1] . DIRECTORY_SEPARATOR . 'index.html');
            }

            // Fallback auf die User-App
            $this->serveHtmlFile($this->rootDir . DIRECTORY_SEPARATOR . 'user' . DIRECTORY_SEPARATOR . 'index.html');
        }

        $app = $this->resolveSubdomainApp($meta);
        $this->serveHtmlFile($this->rootDir . DIRECTORY_SEPARATOR . $app . DIRECTORY_SEPARATOR . 'index.html');
    }

    private function normalizeInstallInput(array $input): array
    {
        $mode = $this->cleanString($input['install_mode'] ?? 'subfolders');
        if (!in_array($mode, ['subfolders', 'subdomains'], true)) {
            throw new RuntimeException('Ungültiger Installationsmodus.');
        }

        $dbHost = $this->cleanString($input['db_host'] ?? '');
        $dbName = $this->cleanString($input['db_name'] ?? '');
        $dbUser = $this->cleanString($input['db_user'] ?? '');

        if ($dbHost === '' || $dbName === '' || $dbUser === '') {
            throw new RuntimeException('Bitte fülle mindestens Host, Datenbankname und Benutzer für die Datenbank aus.');
        }

        $baseUrl = $mode === 'subfolders'
            ? $this->normalizeUrl($input['base_url'] ?? '')
            : '';

        if ($mode === 'subfolders' && $baseUrl === '') {
            throw new RuntimeException('Für den Subfolder-Modus wird eine Basis-URL benötigt.');
        }

        $portalUrl = $mode === 'subdomains'
            ? $this->normalizeUrl($input['portal_url'] ?? '')
            : $baseUrl;
        $teacherUrl = $mode === 'subdomains'
            ? $this->normalizeUrl($input['teacher_url'] ?? '')
            : $baseUrl;
        $adminUrl = $mode === 'subdomains'
            ? $this->normalizeUrl($input['admin_url'] ?? '')
            : $baseUrl;

        if ($portalUrl === '' || $teacherUrl === '' || $adminUrl === '') {
            throw new RuntimeException('Bitte gib alle benötigten URLs für die gewählte Installationsart an.');
        }

        $cookieDomain = $this->cleanString($input['cookie_domain'] ?? '');
        if ($cookieDomain === '') {
            $cookieDomain = $this->deriveCookieDomain($portalUrl, $teacherUrl, $adminUrl);
        }

        return [
            'mode' => $mode,
            'base_url' => $baseUrl,
            'portal_url' => $portalUrl,
            'teacher_url' => $teacherUrl,
            'admin_url' => $adminUrl,
            'cookie_domain' => $cookieDomain,
            'db_host' => $dbHost,
            'db_name' => $dbName,
            'db_user' => $dbUser,
            'db_password' => $this->cleanMultilineString($input['db_password'] ?? ''),
            'db_charset' => $this->cleanString($input['db_charset'] ?? 'utf8mb4') ?: 'utf8mb4',
            'hcaptcha_site_key' => $this->cleanString($input['hcaptcha_site_key'] ?? ''),
            'hcaptcha_secret' => $this->cleanString($input['hcaptcha_secret'] ?? ''),
            'remember_secret' => $this->cleanString($input['remember_secret'] ?? '') ?: bin2hex(random_bytes(32)),
            'remember_days' => $this->cleanString($input['remember_days'] ?? '30') ?: '30',
            'paypal_client_id' => $this->cleanString($input['paypal_client_id'] ?? ''),
            'paypal_client_secret' => $this->cleanString($input['paypal_client_secret'] ?? ''),
            'paypal_env' => in_array($this->cleanString($input['paypal_env'] ?? 'sandbox'), ['sandbox', 'production'], true)
                ? $this->cleanString($input['paypal_env'] ?? 'sandbox')
                : 'sandbox',
            'klarna_username' => $this->cleanString($input['klarna_username'] ?? ''),
            'klarna_password' => $this->cleanString($input['klarna_password'] ?? ''),
            'klarna_url' => $this->normalizeUrl($input['klarna_url'] ?? 'https://api.playground.klarna.com'),
            'smtp_host' => $this->cleanString($input['smtp_host'] ?? ''),
            'smtp_port' => $this->cleanString($input['smtp_port'] ?? '587') ?: '587',
            'smtp_username' => $this->cleanString($input['smtp_username'] ?? ''),
            'smtp_password' => $this->cleanMultilineString($input['smtp_password'] ?? ''),
            'smtp_encryption' => $this->cleanString($input['smtp_encryption'] ?? 'tls') ?: 'tls',
            'mail_from_address' => $this->cleanString($input['mail_from_address'] ?? ''),
            'mail_from_name' => $this->cleanString($input['mail_from_name'] ?? 'MensaManager') ?: 'MensaManager',
            'mail_reply_to_address' => $this->cleanString($input['mail_reply_to_address'] ?? ''),
            'mail_reply_to_name' => $this->cleanString($input['mail_reply_to_name'] ?? ''),
        ];
    }

    private function buildInstallPlan(array $config): array
    {
        $portalOrigins = [$config['portal_url']];
        $portalHost = $this->extractHost($config['portal_url']);

        if (
            $config['mode'] === 'subdomains' &&
            str_starts_with($portalHost, 'www.') &&
            filter_var(parse_url($config['portal_url'], PHP_URL_SCHEME) . '://' . substr($portalHost, 4), FILTER_VALIDATE_URL)
        ) {
            $portalOrigins[] = parse_url($config['portal_url'], PHP_URL_SCHEME) . '://' . substr($portalHost, 4);
        }

        $env = [
            'MM_DB_HOST' => $config['db_host'],
            'MM_DB_NAME' => $config['db_name'],
            'MM_DB_USER' => $config['db_user'],
            'MM_DB_PASSWORD' => $config['db_password'],
            'MM_DB_CHARSET' => $config['db_charset'],
            'MM_HCAPTCHA_SITE_KEY' => $config['hcaptcha_site_key'],
            'MM_HCAPTCHA_SECRET' => $config['hcaptcha_secret'],
            'MM_REMEMBER_ME_SECRET' => $config['remember_secret'],
            'MM_COOKIE_DOMAIN' => $config['cookie_domain'],
            'MM_REMEMBER_ME_DAYS' => $config['remember_days'],
            'MM_ADMIN_ALLOWED_ORIGINS' => $config['admin_url'],
            'MM_TEACHER_ALLOWED_ORIGINS' => $config['teacher_url'],
            'MM_PORTAL_ALLOWED_ORIGINS' => implode(',', array_values(array_unique($portalOrigins))),
            'MM_PAYPAL_CLIENT_ID' => $config['paypal_client_id'],
            'MM_PAYPAL_CLIENT_SECRET' => $config['paypal_client_secret'],
            'MM_PAYPAL_ENV' => $config['paypal_env'],
            'MM_KLARNA_USERNAME' => $config['klarna_username'],
            'MM_KLARNA_PASSWORD' => $config['klarna_password'],
            'MM_KLARNA_URL' => $config['klarna_url'],
            'MM_SMTP_HOST' => $config['smtp_host'],
            'MM_SMTP_PORT' => $config['smtp_port'],
            'MM_SMTP_USERNAME' => $config['smtp_username'],
            'MM_SMTP_PASSWORD' => $config['smtp_password'],
            'MM_SMTP_ENCRYPTION' => $config['smtp_encryption'],
            'MM_MAIL_FROM_ADDRESS' => $config['mail_from_address'],
            'MM_MAIL_FROM_NAME' => $config['mail_from_name'],
            'MM_MAIL_REPLY_TO_ADDRESS' => $config['mail_reply_to_address'],
            'MM_MAIL_REPLY_TO_NAME' => $config['mail_reply_to_name'],
        ];

        $runtimeConfig = [
            'user' => ['apiBase' => '/api'],
            'lehrer' => ['apiBase' => $config['mode'] === 'subfolders' ? '/lehrer/api' : '/api'],
            'admin' => ['apiBase' => $config['mode'] === 'subfolders' ? '/admin/api' : '/api'],
        ];

        return [
            'mode' => $config['mode'],
            'env' => $env,
            'runtime' => $runtimeConfig,
            'public_urls' => [
                'user' => $config['mode'] === 'subfolders' ? $config['base_url'] : $config['portal_url'],
                'lehrer' => $config['mode'] === 'subfolders' ? rtrim($config['base_url'], '/') . '/lehrer/' : $config['teacher_url'],
                'admin' => $config['mode'] === 'subfolders' ? rtrim($config['base_url'], '/') . '/admin/' : $config['admin_url'],
            ],
            'hosts' => [
                'portal' => $this->extractHost($config['portal_url']),
                'teacher' => $this->extractHost($config['teacher_url']),
                'admin' => $this->extractHost($config['admin_url']),
            ],
        ];
    }

    private function writeEnvFile(array $plan): void
    {
        $contents = [
            '# Automatisch durch den MensaManager Installer erzeugt am ' . date('Y-m-d H:i:s'),
            '',
            '# Datenbank',
            $this->formatEnvLine('MM_DB_HOST', $plan['env']['MM_DB_HOST']),
            $this->formatEnvLine('MM_DB_NAME', $plan['env']['MM_DB_NAME']),
            $this->formatEnvLine('MM_DB_USER', $plan['env']['MM_DB_USER']),
            $this->formatEnvLine('MM_DB_PASSWORD', $plan['env']['MM_DB_PASSWORD']),
            $this->formatEnvLine('MM_DB_CHARSET', $plan['env']['MM_DB_CHARSET']),
            '',
            '# Sicherheit',
            $this->formatEnvLine('MM_HCAPTCHA_SITE_KEY', $plan['env']['MM_HCAPTCHA_SITE_KEY']),
            $this->formatEnvLine('MM_HCAPTCHA_SECRET', $plan['env']['MM_HCAPTCHA_SECRET']),
            $this->formatEnvLine('MM_REMEMBER_ME_SECRET', $plan['env']['MM_REMEMBER_ME_SECRET']),
            $this->formatEnvLine('MM_COOKIE_DOMAIN', $plan['env']['MM_COOKIE_DOMAIN']),
            $this->formatEnvLine('MM_REMEMBER_ME_DAYS', $plan['env']['MM_REMEMBER_ME_DAYS']),
            '',
            '# Erlaubte Origins',
            $this->formatEnvLine('MM_ADMIN_ALLOWED_ORIGINS', $plan['env']['MM_ADMIN_ALLOWED_ORIGINS']),
            $this->formatEnvLine('MM_TEACHER_ALLOWED_ORIGINS', $plan['env']['MM_TEACHER_ALLOWED_ORIGINS']),
            $this->formatEnvLine('MM_PORTAL_ALLOWED_ORIGINS', $plan['env']['MM_PORTAL_ALLOWED_ORIGINS']),
            '',
            '# PayPal',
            $this->formatEnvLine('MM_PAYPAL_CLIENT_ID', $plan['env']['MM_PAYPAL_CLIENT_ID']),
            $this->formatEnvLine('MM_PAYPAL_CLIENT_SECRET', $plan['env']['MM_PAYPAL_CLIENT_SECRET']),
            $this->formatEnvLine('MM_PAYPAL_ENV', $plan['env']['MM_PAYPAL_ENV']),
            '',
            '# Klarna',
            $this->formatEnvLine('MM_KLARNA_USERNAME', $plan['env']['MM_KLARNA_USERNAME']),
            $this->formatEnvLine('MM_KLARNA_PASSWORD', $plan['env']['MM_KLARNA_PASSWORD']),
            $this->formatEnvLine('MM_KLARNA_URL', $plan['env']['MM_KLARNA_URL']),
            '',
            '# SMTP',
            $this->formatEnvLine('MM_SMTP_HOST', $plan['env']['MM_SMTP_HOST']),
            $this->formatEnvLine('MM_SMTP_PORT', $plan['env']['MM_SMTP_PORT']),
            $this->formatEnvLine('MM_SMTP_USERNAME', $plan['env']['MM_SMTP_USERNAME']),
            $this->formatEnvLine('MM_SMTP_PASSWORD', $plan['env']['MM_SMTP_PASSWORD']),
            $this->formatEnvLine('MM_SMTP_ENCRYPTION', $plan['env']['MM_SMTP_ENCRYPTION']),
            $this->formatEnvLine('MM_MAIL_FROM_ADDRESS', $plan['env']['MM_MAIL_FROM_ADDRESS']),
            $this->formatEnvLine('MM_MAIL_FROM_NAME', $plan['env']['MM_MAIL_FROM_NAME']),
            $this->formatEnvLine('MM_MAIL_REPLY_TO_ADDRESS', $plan['env']['MM_MAIL_REPLY_TO_ADDRESS']),
            $this->formatEnvLine('MM_MAIL_REPLY_TO_NAME', $plan['env']['MM_MAIL_REPLY_TO_NAME']),
            '',
        ];

        $envFile = $this->sharedDir . DIRECTORY_SEPARATOR . '.env';
        file_put_contents($envFile, implode(PHP_EOL, $contents));
        $this->setPermissionsIfPossible($envFile, 0640);
    }

    private function writeRuntimeConfigFiles(array $plan): void
    {
        foreach ($plan['runtime'] as $appName => $config) {
            $appDir = $appName === 'lehrer' ? 'lehrer' : $appName;
            $target = $this->rootDir . DIRECTORY_SEPARATOR . $appDir . DIRECTORY_SEPARATOR . 'runtime-config.js';
            $content = "window.MM_RUNTIME_CONFIG = {\n"
                . "  apiBase: '" . addslashes($config['apiBase']) . "',\n"
                . "};\n";

            file_put_contents($target, $content);
            $this->setPermissionsIfPossible($target, 0644);
        }
    }

    private function publishRootPortalFilesIfNeeded(array $plan): void
    {
        if ($plan['mode'] !== 'subfolders') {
            return;
        }

        $source = $this->rootDir . DIRECTORY_SEPARATOR . 'user';
        $protectedRootEntries = ['admin', 'installer', 'index.php', 'lehrer', 'shared', 'user'];

        foreach (scandir($source) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === 'index.html') {
                continue;
            }

            if (in_array($entry, $protectedRootEntries, true)) {
                continue;
            }

            $from = $source . DIRECTORY_SEPARATOR . $entry;
            $to = $this->rootDir . DIRECTORY_SEPARATOR . $entry;

            $this->removePath($to);
            $this->copyPath($from, $to);
        }
    }

    private function writeRootApacheConfig(array $plan): void
    {
        $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');

        $contents = [
            'Options -Indexes',
            'DirectoryIndex index.php index.html',
            '',
            '<IfModule mod_rewrite.c>',
            'RewriteEngine On',
            'RewriteRule ^(?:shared|installer)(?:/|$) - [F,L]',
            // Verwende %{REQUEST_URI} anstatt nur $1, um den absoluten Apache-Pfad zu unterdrücken
            'RewriteRule ^(lehrer|admin)$ %{REQUEST_URI}/ [R=302,L]',
        ];

        if ($plan['mode'] === 'subdomains') {
            $hostRules = [
                'user' => $this->buildHostPattern($plan['hosts']['portal'], true),
                'lehrer' => $this->buildHostPattern($plan['hosts']['teacher']),
                'admin' => $this->buildHostPattern($plan['hosts']['admin']),
            ];

            foreach ($hostRules as $appName => $pattern) {
                $contents[] = '';
                $contents[] = 'RewriteCond %{HTTP_HOST} ' . $pattern . ' [NC]';
                $contents[] = 'RewriteRule ^runtime-config\.js$ ' . $basePath . '/' . $appName . '/runtime-config.js [L]';
                $contents[] = 'RewriteCond %{HTTP_HOST} ' . $pattern . ' [NC]';
                $contents[] = 'RewriteRule ^(assets|api)(/.*)?$ ' . $basePath . '/' . $appName . '/$1$2 [L]';
            }
        }

        $contents[] = '</IfModule>';
        $contents[] = '';
        $contents[] = '<FilesMatch "^(?:\\.env|\\.installer.*|\\.installer-state\\.json|\\.installer-lock\\.json)$">';
        $contents[] = '    <IfModule mod_authz_core.c>';
        $contents[] = '        Require all denied';
        $contents[] = '    </IfModule>';
        $contents[] = '    <IfModule !mod_authz_core.c>';
        $contents[] = '        Deny from all';
        $contents[] = '    </IfModule>';
        $contents[] = '</FilesMatch>';
        $contents[] = '';

        $target = $this->rootDir . DIRECTORY_SEPARATOR . '.htaccess';
        file_put_contents($target, implode(PHP_EOL, $contents));
        $this->setPermissionsIfPossible($target, 0644);
    }

    private function writeInstallLock(array $plan): void
    {
        $payload = [
            'installed_at' => date(DATE_ATOM),
            'mode' => $plan['mode'],
            'public_urls' => $plan['public_urls'],
            'hosts' => $plan['hosts'],
            'archive' => $this->getState()['archive'] ?? null,
        ];

        file_put_contents(
            $this->lockFile,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        $this->setPermissionsIfPossible($this->lockFile, 0640);
    }

    private function ensureBaseStructure(): void
    {
        $this->ensureDirectory($this->sharedDir);
        $this->ensureDirectory($this->phpSharedDir);

        $mmSecurity = $this->phpSharedDir . DIRECTORY_SEPARATOR . 'mm_security.php';
        if (!is_file($mmSecurity)) {
            throw new RuntimeException('shared/php/mm_security.php fehlt. Bitte den Installer zusammen mit dem shared-Verzeichnis hochladen.');
        }

        $this->setPermissionsIfPossible($this->sharedDir, 0750);
        $this->setPermissionsIfPossible($this->phpSharedDir, 0750);
        $this->setPermissionsIfPossible($mmSecurity, 0644);
    }

    private function writeSharedProtectionFiles(): void
    {
        $htaccess = implode(PHP_EOL, [
            'Options -Indexes',
            '<IfModule mod_authz_core.c>',
            '    Require all denied',
            '</IfModule>',
            '<IfModule !mod_authz_core.c>',
            '    Deny from all',
            '</IfModule>',
            '',
        ]);

        $webConfig = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<configuration>
  <system.webServer>
    <handlers>
      <clear />
    </handlers>
    <security>
      <authorization>
        <remove users="*" roles="" verbs="" />
        <add accessType="Deny" users="*" />
      </authorization>
    </security>
  </system.webServer>
</configuration>
XML;

        $denyIndex = <<<PHP
<?php
http_response_code(403);
header('Content-Type: text/plain; charset=utf-8');
echo '403 Forbidden';
PHP;

        file_put_contents($this->sharedDir . DIRECTORY_SEPARATOR . '.htaccess', $htaccess);
        file_put_contents($this->sharedDir . DIRECTORY_SEPARATOR . 'web.config', $webConfig . PHP_EOL);
        file_put_contents($this->sharedDir . DIRECTORY_SEPARATOR . 'index.php', $denyIndex . PHP_EOL);

        $this->setPermissionsIfPossible($this->sharedDir . DIRECTORY_SEPARATOR . '.htaccess', 0644);
        $this->setPermissionsIfPossible($this->sharedDir . DIRECTORY_SEPARATOR . 'web.config', 0644);
        $this->setPermissionsIfPossible($this->sharedDir . DIRECTORY_SEPARATOR . 'index.php', 0644);
    }

    private function resolveArchive(array $archives, ?string $selectedArchive): array
    {
        if ($selectedArchive !== null && $selectedArchive !== '') {
            foreach ($archives as $archive) {
                if ($archive['name'] === $selectedArchive) {
                    return $archive;
                }
            }

            throw new RuntimeException('Die ausgewählte ZIP-Datei wurde nicht gefunden.');
        }

        if (count($archives) > 1) {
            throw new RuntimeException('Mehrere ZIP-Dateien gefunden. Bitte wähle explizit ein Archiv aus.');
        }

        return $archives[0];
    }

    private function assertSafeArchive(ZipArchive $zip): void
    {
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entryName = $zip->getNameIndex($index);
            if ($entryName === false) {
                continue;
            }

            $normalized = $this->normalizeZipEntryName($entryName);
            if ($normalized === '') {
                continue;
            }

            if (
                str_contains($normalized, '../') ||
                str_starts_with($normalized, '/') ||
                preg_match('/^[a-zA-Z]:[\\\\\\/]/', $normalized)
            ) {
                throw new RuntimeException('Die ZIP-Datei enthält unsichere Pfade und wurde daher abgelehnt.');
            }
        }
    }

    private function extractZipArchive(ZipArchive $zip, string $targetDir): void
    {
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entryName = $zip->getNameIndex($index);
            if ($entryName === false) {
                continue;
            }

            $normalizedEntryName = $this->normalizeZipEntryName($entryName);
            if ($normalizedEntryName === '') {
                continue;
            }

            $destinationPath = $targetDir . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $normalizedEntryName);

            if (str_ends_with($normalizedEntryName, '/')) {
                $this->ensureDirectory(rtrim($destinationPath, DIRECTORY_SEPARATOR));
                continue;
            }

            $parentDirectory = dirname($destinationPath);
            $this->ensureDirectory($parentDirectory);

            $stream = $zip->getStream($entryName);
            if ($stream === false) {
                throw new RuntimeException('Ein ZIP-Eintrag konnte nicht gelesen werden: ' . $entryName);
            }

            $targetHandle = fopen($destinationPath, 'wb');
            if ($targetHandle === false) {
                fclose($stream);
                throw new RuntimeException('Datei konnte nicht geschrieben werden: ' . $normalizedEntryName);
            }

            stream_copy_to_stream($stream, $targetHandle);
            fclose($targetHandle);
            fclose($stream);
            $this->setPermissionsIfPossible($destinationPath, 0644);
        }
    }

    private function normalizeZipEntryName(string $entryName): string
    {
        $normalized = str_replace('\\', '/', $entryName);
        $normalized = preg_replace('#/+#', '/', $normalized);
        $normalized = ltrim((string) $normalized, '/');

        while (str_starts_with($normalized, './')) {
            $normalized = substr($normalized, 2);
        }

        return trim($normalized);
    }

    private function locatePackageRoot(string $extractDir): string
    {
        $packageRoot = $this->findPackageRoot($extractDir);
        if ($packageRoot !== null) {
            return $packageRoot;
        }

        $nestedPackageRoot = $this->extractNestedPackageRoot($extractDir);
        if ($nestedPackageRoot !== null) {
            return $nestedPackageRoot;
        }

        throw new RuntimeException(
            'Die ZIP-Datei muss die Unterordner user, lehrer und admin enthalten. '
            . 'Gefundene Top-Level-Einträge: ' . $this->describeDirectoryEntries($extractDir)
        );
    }

    private function hasAllAppDirectories(string $directory): bool
    {
        return count($this->resolveAppDirectories($directory, false)) === 3;
    }

    private function findPackageRoot(string $directory, int $depth = 0, int $maxDepth = 4): ?string
    {
        if ($this->hasAllAppDirectories($directory)) {
            return $directory;
        }

        if ($depth >= $maxDepth) {
            return null;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $candidate = $directory . DIRECTORY_SEPARATOR . $entry;
            if (!is_dir($candidate)) {
                continue;
            }

            $found = $this->findPackageRoot($candidate, $depth + 1, $maxDepth);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private function extractNestedPackageRoot(string $extractDir): ?string
    {
        $nestedArchives = $this->findZipFiles($extractDir);
        if ($nestedArchives === []) {
            return null;
        }

        $nestedWorkspace = $extractDir . DIRECTORY_SEPARATOR . '.nested-archives';
        $this->removeDirectory($nestedWorkspace);
        $this->ensureDirectory($nestedWorkspace);

        foreach ($nestedArchives as $archivePath) {
            $archiveName = strtolower(basename($archivePath));
            if (str_contains($archiveName, 'upload-package')) {
                continue;
            }

            $targetDir = $nestedWorkspace . DIRECTORY_SEPARATOR . md5($archivePath);
            $this->removeDirectory($targetDir);
            $this->ensureDirectory($targetDir);

            $zip = new ZipArchive();
            if ($zip->open($archivePath) !== true) {
                continue;
            }

            $this->assertSafeArchive($zip);

            try {
                $this->extractZipArchive($zip, $targetDir);
            } catch (Throwable $exception) {
                $zip->close();
                $this->removeDirectory($targetDir);
                continue;
            }

            $zip->close();

            $packageRoot = $this->findPackageRoot($targetDir);
            if ($packageRoot !== null) {
                return $packageRoot;
            }
        }

        return null;
    }

    private function findZipFiles(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $zipFiles = [];
        $flags = FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, $flags),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $fileInfo) {
            $pathName = $fileInfo->getPathname();

            if (str_contains($pathName, DIRECTORY_SEPARATOR . '.nested-archives' . DIRECTORY_SEPARATOR)) {
                continue;
            }

            if ($fileInfo->isFile() && strtolower($fileInfo->getExtension()) === 'zip') {
                $zipFiles[] = $pathName;
            }
        }

        usort($zipFiles, static function (string $left, string $right): int {
            $leftName = strtolower(basename($left));
            $rightName = strtolower(basename($right));

            $leftScore = str_contains($leftName, 'apps') ? 0 : 1;
            $rightScore = str_contains($rightName, 'apps') ? 0 : 1;

            if ($leftScore !== $rightScore) {
                return $leftScore <=> $rightScore;
            }

            return strcmp($leftName, $rightName);
        });

        return $zipFiles;
    }

    private function resolveAppDirectories(string $directory, bool $throwOnMissing = true): array
    {
        $appDirectories = [];
        $childDirectories = $this->listChildDirectories($directory);

        foreach (['user', 'lehrer', 'admin'] as $appName) {
            if (!isset($childDirectories[$appName])) {
                if ($throwOnMissing) {
                    throw new RuntimeException(
                        'Die ZIP-Datei muss die Unterordner user, lehrer und admin enthalten. '
                        . 'Gefundene Einträge in ' . basename($directory) . ': ' . $this->describeDirectoryEntries($directory)
                    );
                }

                return [];
            }

            if (!$this->looksLikeBuiltAppDirectory($childDirectories[$appName])) {
                if ($throwOnMissing) {
                    throw new RuntimeException(
                        'Die gefundenen Unterordner user, lehrer und admin sehen nicht wie gebaute Frontends aus. '
                        . 'Erwartet wird jeweils mindestens eine index.html-Datei.'
                    );
                }

                return [];
            }

            $appDirectories[$appName] = $childDirectories[$appName];
        }

        return $appDirectories;
    }

    private function listChildDirectories(string $directory): array
    {
        $directories = [];

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (!is_dir($path)) {
                continue;
            }

            $directories[strtolower($entry)] = $path;
        }

        return $directories;
    }

    private function describeDirectoryEntries(string $directory): string
    {
        $entries = [];

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $entries[] = $entry;
        }

        if ($entries === []) {
            return '(leer)';
        }

        sort($entries, SORT_NATURAL | SORT_FLAG_CASE);

        return implode(', ', $entries);
    }

    private function looksLikeBuiltAppDirectory(string $directory): bool
    {
        return is_file($directory . DIRECTORY_SEPARATOR . 'index.html');
    }

    private function assertAppsExtracted(): void
    {
        foreach (['user', 'lehrer', 'admin'] as $appName) {
            if (!is_dir($this->rootDir . DIRECTORY_SEPARATOR . $appName)) {
                throw new RuntimeException('Die entpackten App-Verzeichnisse wurden nicht gefunden. Bitte die ZIP-Datei zuerst entpacken.');
            }
        }
    }

    private function resolveSubdomainApp(array $meta): string
    {
        $host = $this->detectHost();

        if (($meta['hosts']['teacher'] ?? '') === $host) {
            return 'lehrer';
        }

        if (($meta['hosts']['admin'] ?? '') === $host) {
            return 'admin';
        }

        return 'user';
    }

    private function serveHtmlFile(string $file): void
    {
        if (!is_file($file)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Die Startdatei der installierten Anwendung wurde nicht gefunden.';
            exit;
        }

        header('Content-Type: text/html; charset=utf-8');
        readfile($file);
        exit;
    }

    private function redirect(string $location): void
    {
        header('Location: ' . $location, true, 302);
        exit;
    }

    private function readJsonFile(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }

        $contents = file_get_contents($file);
        if ($contents === false || trim($contents) === '') {
            return [];
        }

        $decoded = json_decode($contents, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function writeState(array $payload): void
    {
        file_put_contents(
            $this->stateFile,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        $this->setPermissionsIfPossible($this->stateFile, 0640);
    }

    private function detectScheme(): string
    {
        if (
            (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ||
            (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
        ) {
            return 'https';
        }

        return 'http';
    }

    private function detectHost(): string
    {
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $host = trim(explode(':', $host, 2)[0]);

        return $host !== '' ? $host : 'localhost';
    }

    private function suggestDomainBase(string $host): string
    {
        $host = strtolower(trim($host));

        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) || $host === 'localhost') {
            return '';
        }

        if (str_starts_with($host, 'www.')) {
            return substr($host, 4);
        }

        $labels = explode('.', $host);
        if (count($labels) >= 2) {
            return $host;
        }

        return '';
    }

    private function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (!preg_match('#^https?://#i', $url)) {
            $url = $this->detectScheme() . '://' . ltrim($url, '/');
        }

        $validated = filter_var($url, FILTER_VALIDATE_URL);
        if ($validated === false) {
            throw new RuntimeException('Ungültige URL angegeben: ' . $url);
        }

        return rtrim($validated, '/');
    }

    private function deriveCookieDomain(string $portalUrl, string $teacherUrl, string $adminUrl): string
    {
        $hosts = array_filter([
            $this->extractHost($portalUrl),
            $this->extractHost($teacherUrl),
            $this->extractHost($adminUrl),
        ]);

        if ($hosts === []) {
            return '';
        }

        $hostParts = array_map(static fn (string $host): array => array_reverse(explode('.', $host)), $hosts);
        $commonParts = [];

        for ($index = 0; ; $index++) {
            $currentPart = null;

            foreach ($hostParts as $parts) {
                if (!isset($parts[$index])) {
                    $currentPart = null;
                    break 2;
                }

                if ($currentPart === null) {
                    $currentPart = $parts[$index];
                    continue;
                }

                if ($parts[$index] !== $currentPart) {
                    $currentPart = null;
                    break 2;
                }
            }

            if ($currentPart === null) {
                break;
            }

            $commonParts[] = $currentPart;
        }

        if ($commonParts === []) {
            return $hosts[0];
        }

        return implode('.', array_reverse($commonParts));
    }

    private function extractHost(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        return is_string($host) ? strtolower($host) : '';
    }

    private function buildHostPattern(string $host, bool $includeBarePortal = false): string
    {
        $escapedHost = preg_quote($host, '#');

        if ($includeBarePortal && str_starts_with($host, 'www.')) {
            $bare = preg_quote(substr($host, 4), '#');
            return '^(?:' . $escapedHost . '|' . $bare . ')$';
        }

        return '^' . $escapedHost . '$';
    }

    private function copyDirectory(string $source, string $target): void
    {
        $this->ensureDirectory($target);

        foreach (scandir($source) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $this->copyPath(
                $source . DIRECTORY_SEPARATOR . $entry,
                $target . DIRECTORY_SEPARATOR . $entry
            );
        }
    }

    private function copyPath(string $source, string $target): void
    {
        if (is_dir($source)) {
            $this->copyDirectory($source, $target);
            return;
        }

        $parent = dirname($target);
        $this->ensureDirectory($parent);

        if (!copy($source, $target)) {
            throw new RuntimeException('Datei konnte nicht kopiert werden: ' . basename($source));
        }

        $this->setPermissionsIfPossible($target, 0644);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            $this->removePath($path);
        }

        @rmdir($directory);
    }

    private function removePath(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            $this->removeDirectory($path);
            return;
        }

        if (is_file($path) || is_link($path)) {
            @unlink($path);
        }
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Verzeichnis konnte nicht erstellt werden: ' . $directory);
        }
    }

    private function cleanString(string $value): string
    {
        return trim(str_replace(["\r", "\n"], '', $value));
    }

    private function cleanMultilineString(string $value): string
    {
        return trim(str_replace(["\r", "\n"], '', $value));
    }

    private function formatEnvLine(string $key, string $value): string
    {
        return $key . '=' . $value;
    }

    private function setPermissionsIfPossible(string $path, int $mode): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return;
        }

        @chmod($path, $mode);
    }
}
