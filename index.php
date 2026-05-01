<?php

declare(strict_types=1);

require_once __DIR__ . '/installer/MensaInstaller.php';

$installer = new MensaInstaller(__DIR__);

if ($installer->isInstalled() && !isset($_GET['installer'])) {
    $installer->serveInstalledEntry();
}

header('X-Robots-Tag: noindex, nofollow', true);

$action = $_GET['action'] ?? '';

if ($action !== '') {
    header('Content-Type: application/json; charset=utf-8');

    try {
        if ($installer->isInstalled()) {
            throw new RuntimeException('Die Installation ist bereits abgeschlossen. Lösche die Lock-Datei in shared, um den Installer erneut freizuschalten.');
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            throw new RuntimeException('Ungültige Anfrage.');
        }

        if ($action === 'extract') {
            $result = $installer->extractArchive($_POST['archive'] ?? null);

            echo json_encode([
                'status' => 'success',
                'message' => 'Archiv erfolgreich entpackt.',
                'result' => $result,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action === 'install') {
            $result = $installer->install($_POST);

            echo json_encode([
                'status' => 'success',
                'message' => 'Installation abgeschlossen.',
                'result' => $result,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        throw new RuntimeException('Unbekannte Aktion.');
    } catch (Throwable $exception) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => $exception->getMessage(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$archives = $installer->listArchives();
$state = $installer->getState();
$defaults = $installer->getSuggestedDefaults();
$installMeta = $installer->getInstallMeta();
$installed = $installer->isInstalled();
$hasExtractedApps = $state['extracted'] === true;
$singleArchive = count($archives) === 1 ? $archives[0]['name'] : null;
$viewState = 'missing-archive';

if ($installed) {
    $viewState = 'installed';
} elseif ($hasExtractedApps) {
    $viewState = 'setup';
} elseif (count($archives) > 1) {
    $viewState = 'archive-select';
} elseif ($singleArchive !== null) {
    $viewState = 'extracting';
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$defaultBaseUrl = $defaults['base_url'];
$defaultPortalUrl = $defaults['portal_url'];
$defaultTeacherUrl = $defaults['teacher_url'];
$defaultAdminUrl = $defaults['admin_url'];
$defaultCookieDomain = $defaults['cookie_domain'];
?>
<!doctype html>
<html lang="de">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>MensaManager Installer</title>
    <style>
      :root {
        color-scheme: light;
        --bg: #eef4f2;
        --panel: rgba(255, 255, 255, 0.9);
        --panel-border: rgba(17, 24, 39, 0.08);
        --text: #0f172a;
        --muted: #516072;
        --accent: #0f766e;
        --accent-strong: #115e59;
        --accent-soft: rgba(15, 118, 110, 0.12);
        --danger: #be123c;
        --shadow: 0 22px 60px rgba(15, 23, 42, 0.12);
      }

      * {
        box-sizing: border-box;
      }

      body {
        margin: 0;
        min-height: 100vh;
        font-family: "Segoe UI", "Helvetica Neue", Arial, sans-serif;
        color: var(--text);
        background:
          radial-gradient(circle at top left, rgba(15, 118, 110, 0.2), transparent 28%),
          radial-gradient(circle at bottom right, rgba(22, 163, 74, 0.16), transparent 24%),
          linear-gradient(160deg, #f7fbfa 0%, #e7f0ec 55%, #dce8e5 100%);
      }

      .shell {
        width: min(1120px, calc(100vw - 32px));
        margin: 40px auto;
      }

      .hero {
        display: grid;
        gap: 28px;
      }

      .panel {
        background: var(--panel);
        border: 1px solid var(--panel-border);
        border-radius: 28px;
        box-shadow: var(--shadow);
        backdrop-filter: blur(14px);
      }

      .intro {
        padding: 34px 34px 30px;
      }

      .eyebrow {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 14px;
        border-radius: 999px;
        background: var(--accent-soft);
        color: var(--accent-strong);
        font-size: 13px;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
      }

      h1 {
        margin: 18px 0 10px;
        font-size: clamp(2rem, 4vw, 3.4rem);
        line-height: 0.96;
      }

      .lead {
        margin: 0;
        max-width: 760px;
        color: var(--muted);
        font-size: 1.03rem;
        line-height: 1.65;
      }

      .content {
        padding: 30px 34px 34px;
      }

      .status-card {
        display: grid;
        gap: 18px;
        justify-items: center;
        text-align: center;
        padding: 44px 28px;
      }

      .spinner {
        width: 82px;
        height: 82px;
        border-radius: 50%;
        border: 6px solid rgba(15, 118, 110, 0.16);
        border-top-color: var(--accent);
        animation: spin 1s linear infinite;
      }

      @keyframes spin {
        to {
          transform: rotate(360deg);
        }
      }

      .status-title {
        margin: 0;
        font-size: 1.65rem;
      }

      .status-copy {
        margin: 0;
        max-width: 620px;
        color: var(--muted);
        line-height: 1.6;
      }

      .status-log {
        min-height: 24px;
        color: var(--accent-strong);
        font-weight: 600;
      }

      .archive-list {
        display: grid;
        gap: 12px;
        margin-top: 24px;
      }

      .archive-option {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        padding: 18px 20px;
        border-radius: 20px;
        border: 1px solid rgba(15, 23, 42, 0.08);
        background: #fff;
      }

      .archive-option input {
        margin-right: 12px;
      }

      .archive-meta {
        color: var(--muted);
        font-size: 0.93rem;
      }

      .wizard {
        display: grid;
        gap: 24px;
      }

      .steps {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
      }

      .step-pill {
        display: inline-flex;
        align-items: center;
        gap: 10px;
        padding: 10px 16px;
        border-radius: 999px;
        background: rgba(15, 23, 42, 0.05);
        color: var(--muted);
        font-weight: 600;
        font-size: 0.92rem;
      }

      .step-pill.active {
        background: var(--accent);
        color: #fff;
      }

      form {
        display: grid;
        gap: 24px;
      }

      .step-panel {
        display: none;
        gap: 18px;
      }

      .step-panel.active {
        display: grid;
      }

      .fieldset {
        display: grid;
        gap: 18px;
      }

      .fieldset.two {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }

      .fieldset.three {
        grid-template-columns: repeat(3, minmax(0, 1fr));
      }

      .field {
        display: grid;
        gap: 8px;
      }

      label {
        font-weight: 600;
      }

      input,
      select {
        width: 100%;
        padding: 13px 14px;
        border-radius: 14px;
        border: 1px solid rgba(15, 23, 42, 0.12);
        background: #fff;
        color: var(--text);
        font: inherit;
      }

      input:focus,
      select:focus {
        outline: none;
        border-color: rgba(15, 118, 110, 0.55);
        box-shadow: 0 0 0 4px rgba(15, 118, 110, 0.12);
      }

      .hint {
        color: var(--muted);
        font-size: 0.92rem;
        line-height: 1.5;
      }

      .radio-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 14px;
      }

      .radio-card {
        position: relative;
        display: grid;
        gap: 8px;
        padding: 18px 18px 18px 48px;
        border-radius: 20px;
        border: 1px solid rgba(15, 23, 42, 0.12);
        background: #fff;
        cursor: pointer;
      }

      .radio-card input {
        position: absolute;
        left: 18px;
        top: 20px;
        width: auto;
      }

      .radio-card strong {
        font-size: 1rem;
      }

      .mode-group {
        display: none;
      }

      .mode-group.active {
        display: grid;
        gap: 18px;
      }

      .actions {
        display: flex;
        justify-content: space-between;
        gap: 12px;
        flex-wrap: wrap;
      }

      button {
        border: 0;
        border-radius: 14px;
        padding: 14px 20px;
        font: inherit;
        font-weight: 700;
        cursor: pointer;
        transition: transform 0.15s ease, box-shadow 0.15s ease, opacity 0.15s ease;
      }

      button:hover {
        transform: translateY(-1px);
      }

      button:disabled {
        cursor: wait;
        opacity: 0.7;
        transform: none;
      }

      .btn-primary {
        background: linear-gradient(135deg, var(--accent) 0%, #0b5f59 100%);
        color: #fff;
        box-shadow: 0 14px 28px rgba(15, 118, 110, 0.22);
      }

      .btn-secondary {
        background: rgba(15, 23, 42, 0.06);
        color: var(--text);
      }

      .message {
        padding: 14px 16px;
        border-radius: 16px;
        font-weight: 600;
      }

      .message.error {
        background: rgba(190, 18, 60, 0.12);
        color: var(--danger);
      }

      .message.success {
        background: rgba(22, 163, 74, 0.12);
        color: #166534;
      }

      .summary {
        display: grid;
        gap: 10px;
        padding: 22px;
        border-radius: 22px;
        background: rgba(15, 118, 110, 0.06);
      }

      .summary-line {
        display: flex;
        justify-content: space-between;
        gap: 16px;
        flex-wrap: wrap;
      }

      .summary-line span:last-child {
        font-weight: 700;
      }

      code {
        font-family: "Cascadia Code", "SFMono-Regular", Consolas, monospace;
        font-size: 0.95em;
      }

      @media (max-width: 820px) {
        .shell {
          width: min(100vw, calc(100vw - 20px));
          margin: 18px auto;
        }

        .intro,
        .content {
          padding: 24px 20px;
        }

        .fieldset.two,
        .fieldset.three {
          grid-template-columns: 1fr;
        }
      }
    </style>
  </head>
  <body>
    <div class="shell">
      <section class="hero">
        <div class="panel intro">
          <div class="eyebrow">MensaManager Deployment</div>
          <h1>PHP Installer für User-, Lehrer- und Admin-Frontend</h1>
          <p class="lead">
            Dieses Setup entpackt das Build-Archiv, schreibt die vollständige <code>.env</code> in <code>/shared</code>,
            schützt sensible Serverdateien und konfiguriert die drei Oberflächen für Subdomains oder Unterordner.
          </p>
        </div>

        <div class="panel content">
          <?php if ($viewState === 'missing-archive'): ?>
            <div class="status-card">
              <h2 class="status-title">Keine ZIP-Datei gefunden</h2>
              <p class="status-copy">
                Bitte lade eine ZIP-Datei in dieses Verzeichnis hoch. Das Archiv muss die drei Ordner
                <code>user</code>, <code>lehrer</code> und <code>admin</code> enthalten.
              </p>
              <div class="message error">Im Webroot wurde aktuell kein passendes <code>*.zip</code>-Archiv gefunden.</div>
            </div>
          <?php elseif ($viewState === 'extracting'): ?>
            <div class="status-card" id="extract-card" data-archive="<?= h($singleArchive) ?>">
              <div class="spinner" aria-hidden="true"></div>
              <h2 class="status-title">Build-Archiv wird entpackt</h2>
              <p class="status-copy">
                Das Setup prüft die ZIP-Datei und stellt die Ordner <code>user</code>, <code>lehrer</code> und <code>admin</code>
                im Webroot bereit. Anschließend startet automatisch der Konfigurations-Assistent.
              </p>
              <p class="status-log" id="extract-log">Archiv wird vorbereitet ...</p>
              <div class="message error" id="extract-error" style="display:none;"></div>
            </div>
          <?php elseif ($viewState === 'archive-select'): ?>
            <div class="wizard">
              <h2 class="status-title">ZIP-Datei auswählen</h2>
              <p class="status-copy">
                Im Webroot wurden mehrere ZIP-Dateien gefunden. Wähle das Archiv aus, das die gebauten
                Verzeichnisse <code>user</code>, <code>lehrer</code> und <code>admin</code> enthält.
              </p>
              <div class="archive-list">
                <?php foreach ($archives as $archive): ?>
                  <label class="archive-option">
                    <span>
                      <input type="radio" name="archive_pick" value="<?= h($archive['name']) ?>" <?= $archive === $archives[0] ? 'checked' : '' ?>>
                      <strong><?= h($archive['name']) ?></strong>
                    </span>
                    <span class="archive-meta">
                      <?= h(number_format(($archive['size'] / 1024 / 1024), 2, ',', '.')) ?> MB
                    </span>
                  </label>
                <?php endforeach; ?>
              </div>
              <div class="actions">
                <div class="hint">Nach dem Entpacken geht es direkt in den Setup-Assistenten.</div>
                <button class="btn-primary" type="button" id="pick-archive-button">Archiv entpacken</button>
              </div>
              <div class="message error" id="archive-error" style="display:none;"></div>
            </div>
          <?php elseif ($viewState === 'installed'): ?>
            <div class="status-card">
              <h2 class="status-title">Installation ist bereits gesperrt</h2>
              <p class="status-copy">
                Das System wurde bereits eingerichtet. Der Produktivbetrieb läuft jetzt über die installierten
                Frontends. Wenn du das Setup absichtlich erneut starten willst, entferne die Lock-Datei in
                <code>shared/.installer-lock.json</code>.
              </p>
              <?php if (!empty($installMeta['public_urls'])): ?>
                <div class="summary">
                  <div class="summary-line"><span>User</span><span><?= h($installMeta['public_urls']['user'] ?? '') ?></span></div>
                  <div class="summary-line"><span>Lehrer</span><span><?= h($installMeta['public_urls']['lehrer'] ?? '') ?></span></div>
                  <div class="summary-line"><span>Admin</span><span><?= h($installMeta['public_urls']['admin'] ?? '') ?></span></div>
                </div>
              <?php endif; ?>
            </div>
          <?php else: ?>
            <div class="wizard">
              <div class="steps" id="step-pills">
                <div class="step-pill active">1. Bereitstellung</div>
                <div class="step-pill">2. Datenbank</div>
                <div class="step-pill">3. Sicherheit & Mail</div>
                <div class="step-pill">4. Payments</div>
                <div class="step-pill">5. Abschluss</div>
              </div>

              <form id="installer-form">
                <div class="step-panel active" data-step="0">
                  <h2 class="status-title">Bereitstellungsart wählen</h2>
                  <p class="hint">
                    Im Subdomain-Modus bleiben die drei Apps in ihren eigenen Ordnern. Im Unterordner-Modus
                    läuft das User-Portal im Root, Lehrer und Admin unter <code>/lehrer</code> und <code>/admin</code>.
                  </p>

                  <div class="radio-grid">
                    <label class="radio-card">
                      <input type="radio" name="install_mode" value="subfolders" checked>
                      <strong>Unterordner</strong>
                      <span>User im Root, Lehrer/Admin als Unterverzeichnisse.</span>
                    </label>
                    <label class="radio-card">
                      <input type="radio" name="install_mode" value="subdomains">
                      <strong>Subdomains</strong>
                      <span>Eigene Hosts wie <code>www</code>, <code>lehrer</code> und <code>admin</code>.</span>
                    </label>
                  </div>

                  <div class="mode-group active" data-mode="subfolders">
                    <div class="fieldset">
                      <div class="field">
                        <label for="base_url">Basis-URL</label>
                        <input id="base_url" name="base_url" type="url" value="<?= h($defaultBaseUrl) ?>">
                        <div class="hint">Beispiel: <code>https://xy.de</code></div>
                      </div>
                    </div>
                  </div>

                  <div class="mode-group" data-mode="subdomains">
                    <div class="fieldset three">
                      <div class="field">
                        <label for="portal_url">User-URL</label>
                        <input id="portal_url" name="portal_url" type="url" value="<?= h($defaultPortalUrl) ?>">
                      </div>
                      <div class="field">
                        <label for="teacher_url">Lehrer-URL</label>
                        <input id="teacher_url" name="teacher_url" type="url" value="<?= h($defaultTeacherUrl) ?>">
                      </div>
                      <div class="field">
                        <label for="admin_url">Admin-URL</label>
                        <input id="admin_url" name="admin_url" type="url" value="<?= h($defaultAdminUrl) ?>">
                      </div>
                    </div>
                    <div class="hint">
                      Wenn alle Subdomains auf denselben Webroot zeigen, erzeugt der Installer eine passende Apache-Weiterleitung.
                      Alternativ können die DocumentRoots auch direkt auf <code>/user</code>, <code>/lehrer</code> und <code>/admin</code> zeigen.
                    </div>
                  </div>

                  <div class="fieldset">
                    <div class="field">
                      <label for="cookie_domain">Cookie-Domain</label>
                      <input id="cookie_domain" name="cookie_domain" type="text" value="<?= h($defaultCookieDomain) ?>">
                      <div class="hint">Leer lassen oder überschreiben. Für Subdomains meist die gemeinsame Root-Domain, z. B. <code>xy.de</code>.</div>
                    </div>
                  </div>
                </div>

                <div class="step-panel" data-step="1">
                  <h2 class="status-title">Datenbank konfigurieren</h2>
                  <div class="fieldset two">
                    <div class="field">
                      <label for="db_host">DB Host</label>
                      <input id="db_host" name="db_host" type="text" value="<?= h($defaults['db_host']) ?>" required>
                    </div>
                    <div class="field">
                      <label for="db_charset">DB Charset</label>
                      <input id="db_charset" name="db_charset" type="text" value="<?= h($defaults['db_charset']) ?>" required>
                    </div>
                    <div class="field">
                      <label for="db_name">DB Name</label>
                      <input id="db_name" name="db_name" type="text" required>
                    </div>
                    <div class="field">
                      <label for="db_user">DB Benutzer</label>
                      <input id="db_user" name="db_user" type="text" required>
                    </div>
                    <div class="field">
                      <label for="db_password">DB Passwort</label>
                      <input id="db_password" name="db_password" type="password">
                    </div>
                  </div>
                </div>

                <div class="step-panel" data-step="2">
                  <h2 class="status-title">Sicherheit und Mail</h2>
                  <div class="fieldset two">
                    <div class="field">
                      <label for="hcaptcha_site_key">hCaptcha Site Key</label>
                      <input id="hcaptcha_site_key" name="hcaptcha_site_key" type="text">
                    </div>
                    <div class="field">
                      <label for="hcaptcha_secret">hCaptcha Secret</label>
                      <input id="hcaptcha_secret" name="hcaptcha_secret" type="text">
                    </div>
                    <div class="field">
                      <label for="remember_days">Remember-Me Tage</label>
                      <input id="remember_days" name="remember_days" type="number" min="1" value="<?= h($defaults['remember_days']) ?>">
                    </div>
                    <div class="field">
                      <label for="remember_secret">Remember-Me Secret</label>
                      <input id="remember_secret" name="remember_secret" type="text">
                      <div class="hint">Optional. Wenn leer, wird automatisch ein sicherer Schlüssel erzeugt.</div>
                    </div>
                  </div>

                  <div class="fieldset three">
                    <div class="field">
                      <label for="smtp_host">SMTP Host</label>
                      <input id="smtp_host" name="smtp_host" type="text">
                    </div>
                    <div class="field">
                      <label for="smtp_port">SMTP Port</label>
                      <input id="smtp_port" name="smtp_port" type="number" value="<?= h($defaults['smtp_port']) ?>">
                    </div>
                    <div class="field">
                      <label for="smtp_encryption">SMTP Verschlüsselung</label>
                      <select id="smtp_encryption" name="smtp_encryption">
                        <option value="tls" selected>TLS</option>
                        <option value="ssl">SSL</option>
                        <option value="">Keine</option>
                      </select>
                    </div>
                    <div class="field">
                      <label for="smtp_username">SMTP Benutzer</label>
                      <input id="smtp_username" name="smtp_username" type="text">
                    </div>
                    <div class="field">
                      <label for="smtp_password">SMTP Passwort</label>
                      <input id="smtp_password" name="smtp_password" type="password">
                    </div>
                    <div class="field">
                      <label for="mail_from_address">Absender-Adresse</label>
                      <input id="mail_from_address" name="mail_from_address" type="email">
                    </div>
                    <div class="field">
                      <label for="mail_from_name">Absender-Name</label>
                      <input id="mail_from_name" name="mail_from_name" type="text" value="MensaManager">
                    </div>
                    <div class="field">
                      <label for="mail_reply_to_address">Reply-To Adresse</label>
                      <input id="mail_reply_to_address" name="mail_reply_to_address" type="email">
                    </div>
                    <div class="field">
                      <label for="mail_reply_to_name">Reply-To Name</label>
                      <input id="mail_reply_to_name" name="mail_reply_to_name" type="text">
                    </div>
                  </div>
                </div>

                <div class="step-panel" data-step="3">
                  <h2 class="status-title">Zahlungsanbieter</h2>
                  <div class="fieldset two">
                    <div class="field">
                      <label for="paypal_client_id">PayPal Client ID</label>
                      <input id="paypal_client_id" name="paypal_client_id" type="text">
                    </div>
                    <div class="field">
                      <label for="paypal_client_secret">PayPal Client Secret</label>
                      <input id="paypal_client_secret" name="paypal_client_secret" type="text">
                    </div>
                    <div class="field">
                      <label for="paypal_env">PayPal Modus</label>
                      <select id="paypal_env" name="paypal_env">
                        <option value="sandbox" selected>Sandbox</option>
                        <option value="production">Production</option>
                      </select>
                    </div>
                    <div class="field">
                      <label for="klarna_url">Klarna API URL</label>
                      <input id="klarna_url" name="klarna_url" type="url" value="<?= h($defaults['klarna_url']) ?>">
                    </div>
                    <div class="field">
                      <label for="klarna_username">Klarna Benutzer</label>
                      <input id="klarna_username" name="klarna_username" type="text">
                    </div>
                    <div class="field">
                      <label for="klarna_password">Klarna Passwort</label>
                      <input id="klarna_password" name="klarna_password" type="password">
                    </div>
                  </div>
                </div>

                <div class="step-panel" data-step="4">
                  <h2 class="status-title">Abschluss</h2>
                  <p class="hint">
                    Beim letzten Schritt schreibt der Installer die vollständige <code>shared/.env</code>,
                    aktualisiert die API-Basen aller drei Frontends und sperrt den <code>/shared</code>-Ordner gegen Webzugriff.
                  </p>
                  <div class="summary" id="review-summary">
                    <div class="summary-line"><span>Modus</span><span id="summary-mode">Unterordner</span></div>
                    <div class="summary-line"><span>User</span><span id="summary-user"><?= h($defaultBaseUrl) ?></span></div>
                    <div class="summary-line"><span>Lehrer</span><span id="summary-teacher"><?= h(rtrim($defaultBaseUrl, '/') . '/lehrer/') ?></span></div>
                    <div class="summary-line"><span>Admin</span><span id="summary-admin"><?= h(rtrim($defaultBaseUrl, '/') . '/admin/') ?></span></div>
                    <div class="summary-line"><span>Archiv</span><span><?= h((string) ($state['archive'] ?? $singleArchive ?? '')) ?></span></div>
                  </div>
                  <div class="hint">
                    Nach erfolgreicher Installation bleibt der Installer per Lock-Datei gesperrt. Für Subdomain-Setups
                    ohne eigene DocumentRoots wird zusätzlich eine Apache-Weiterleitung im Root erzeugt.
                  </div>
                </div>

                <div class="actions">
                  <button type="button" class="btn-secondary" id="back-button" style="visibility:hidden;">Zurück</button>
                  <div style="display:flex; gap:12px; flex-wrap:wrap;">
                    <button type="button" class="btn-secondary" id="next-button">Weiter</button>
                    <button type="submit" class="btn-primary" id="submit-button" style="display:none;">Installation abschließen</button>
                  </div>
                </div>

                <div class="message error" id="form-error" style="display:none;"></div>
                <div class="message success" id="form-success" style="display:none;"></div>
              </form>
            </div>
          <?php endif; ?>
        </div>
      </section>
    </div>

    <script>
      const viewState = <?= json_encode($viewState, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

      function postAction(action, formData) {
        return fetch(`?action=${action}`, {
          method: 'POST',
          body: formData
        }).then(async (response) => {
          const payload = await response.json().catch(() => ({
            status: 'error',
            message: 'Die Serverantwort konnte nicht gelesen werden.'
          }));

          if (!response.ok || payload.status !== 'success') {
            throw new Error(payload.message || 'Die Anfrage ist fehlgeschlagen.');
          }

          return payload;
        });
      }

      if (viewState === 'extracting') {
        const extractCard = document.getElementById('extract-card');
        const log = document.getElementById('extract-log');
        const errorBox = document.getElementById('extract-error');
        const archive = extractCard?.dataset.archive || '';
        const states = [
          'ZIP-Datei wird geprüft ...',
          'Dateien werden entpackt ...',
          'Verzeichnisstruktur wird validiert ...',
          'Apps werden bereitgestellt ...'
        ];
        let idx = 0;
        const interval = setInterval(() => {
          idx = (idx + 1) % states.length;
          if (log) {
            log.textContent = states[idx];
          }
        }, 1100);

        const formData = new FormData();
        formData.append('archive', archive);

        postAction('extract', formData)
          .then(() => {
            clearInterval(interval);
            if (log) {
              log.textContent = 'Entpacken abgeschlossen. Setup wird geöffnet ...';
            }
            window.setTimeout(() => window.location.reload(), 500);
          })
          .catch((error) => {
            clearInterval(interval);
            if (errorBox) {
              errorBox.style.display = 'block';
              errorBox.textContent = error.message;
            }
            if (log) {
              log.textContent = 'Entpacken fehlgeschlagen.';
            }
          });
      }

      if (viewState === 'archive-select') {
        const button = document.getElementById('pick-archive-button');
        const errorBox = document.getElementById('archive-error');

        button?.addEventListener('click', () => {
          const checked = document.querySelector('input[name="archive_pick"]:checked');
          if (!checked) {
            if (errorBox) {
              errorBox.style.display = 'block';
              errorBox.textContent = 'Bitte wähle zuerst eine ZIP-Datei aus.';
            }
            return;
          }

          const formData = new FormData();
          formData.append('archive', checked.value);

          button.disabled = true;
          button.textContent = 'Archiv wird entpackt ...';

          postAction('extract', formData)
            .then(() => window.location.reload())
            .catch((error) => {
              button.disabled = false;
              button.textContent = 'Archiv entpacken';
              if (errorBox) {
                errorBox.style.display = 'block';
                errorBox.textContent = error.message;
              }
            });
        });
      }

      if (viewState === 'setup') {
        const form = document.getElementById('installer-form');
        const panels = Array.from(document.querySelectorAll('.step-panel'));
        const pills = Array.from(document.querySelectorAll('.step-pill'));
        const backButton = document.getElementById('back-button');
        const nextButton = document.getElementById('next-button');
        const submitButton = document.getElementById('submit-button');
        const errorBox = document.getElementById('form-error');
        const successBox = document.getElementById('form-success');
        const modeInputs = Array.from(document.querySelectorAll('input[name="install_mode"]'));
        const modeGroups = Array.from(document.querySelectorAll('.mode-group'));
        let currentStep = 0;

        function selectedMode() {
          return form.querySelector('input[name="install_mode"]:checked')?.value || 'subfolders';
        }

        function updateSummary() {
          const mode = selectedMode();
          const baseUrl = (document.getElementById('base_url')?.value || '').replace(/\/+$/, '');
          const portalUrl = (document.getElementById('portal_url')?.value || '').replace(/\/+$/, '');
          const teacherUrl = (document.getElementById('teacher_url')?.value || '').replace(/\/+$/, '');
          const adminUrl = (document.getElementById('admin_url')?.value || '').replace(/\/+$/, '');

          document.getElementById('summary-mode').textContent = mode === 'subfolders' ? 'Unterordner' : 'Subdomains';
          document.getElementById('summary-user').textContent = mode === 'subfolders' ? baseUrl : portalUrl;
          document.getElementById('summary-teacher').textContent = mode === 'subfolders' ? `${baseUrl}/lehrer/` : teacherUrl;
          document.getElementById('summary-admin').textContent = mode === 'subfolders' ? `${baseUrl}/admin/` : adminUrl;
        }

        function syncModeFields() {
          const mode = selectedMode();

          modeGroups.forEach((group) => {
            group.classList.toggle('active', group.dataset.mode === mode);
          });

          const baseUrl = document.getElementById('base_url');
          const portalUrl = document.getElementById('portal_url');
          const teacherUrl = document.getElementById('teacher_url');
          const adminUrl = document.getElementById('admin_url');

          if (baseUrl) {
            baseUrl.required = mode === 'subfolders';
          }
          if (portalUrl) {
            portalUrl.required = mode === 'subdomains';
          }
          if (teacherUrl) {
            teacherUrl.required = mode === 'subdomains';
          }
          if (adminUrl) {
            adminUrl.required = mode === 'subdomains';
          }

          updateSummary();
        }

        function renderStep() {
          panels.forEach((panel, index) => {
            panel.classList.toggle('active', index === currentStep);
          });

          pills.forEach((pill, index) => {
            pill.classList.toggle('active', index === currentStep);
          });

          backButton.style.visibility = currentStep === 0 ? 'hidden' : 'visible';
          nextButton.style.display = currentStep === panels.length - 1 ? 'none' : 'inline-flex';
          submitButton.style.display = currentStep === panels.length - 1 ? 'inline-flex' : 'none';

          updateSummary();
        }

        function currentPanel() {
          return panels[currentStep];
        }

        function validateCurrentStep() {
          const panel = currentPanel();
          const requiredFields = Array.from(panel.querySelectorAll('input, select')).filter((field) => {
            if (field.closest('.mode-group') && !field.closest('.mode-group').classList.contains('active')) {
              return false;
            }

            return !field.disabled;
          });

          for (const field of requiredFields) {
            if (!field.reportValidity()) {
              return false;
            }
          }

          return true;
        }

        function showError(message) {
          if (errorBox) {
            errorBox.style.display = 'block';
            errorBox.textContent = message;
          }
          if (successBox) {
            successBox.style.display = 'none';
          }
        }

        function clearMessages() {
          if (errorBox) {
            errorBox.style.display = 'none';
            errorBox.textContent = '';
          }
          if (successBox) {
            successBox.style.display = 'none';
            successBox.textContent = '';
          }
        }

        modeInputs.forEach((input) => input.addEventListener('change', syncModeFields));
        form.querySelectorAll('input, select').forEach((field) => field.addEventListener('input', updateSummary));

        nextButton?.addEventListener('click', () => {
          clearMessages();
          if (!validateCurrentStep()) {
            return;
          }
          currentStep = Math.min(currentStep + 1, panels.length - 1);
          renderStep();
        });

        backButton?.addEventListener('click', () => {
          clearMessages();
          currentStep = Math.max(currentStep - 1, 0);
          renderStep();
        });

        form?.addEventListener('submit', (event) => {
          event.preventDefault();
          clearMessages();

          if (!validateCurrentStep()) {
            return;
          }

          submitButton.disabled = true;
          submitButton.textContent = 'Installation läuft ...';

          const formData = new FormData(form);

          postAction('install', formData)
            .then((payload) => {
              if (successBox) {
                successBox.style.display = 'block';
                successBox.textContent = 'Installation abgeschlossen. Die Seite wird neu geladen ...';
              }
              window.setTimeout(() => {
                window.location.href = payload.result?.urls?.user || window.location.pathname;
              }, 900);
            })
            .catch((error) => {
              showError(error.message);
              submitButton.disabled = false;
              submitButton.textContent = 'Installation abschließen';
            });
        });

        syncModeFields();
        renderStep();
      }
    </script>
  </body>
</html>
