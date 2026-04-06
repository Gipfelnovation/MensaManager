# Sicherheitsanalyse MensaManager (Stand: 28.03.2026)

## 1) Scope & Methodik

Analysiert wurden die produktiven API-Bereiche der drei Teilanwendungen:

- `mensaadmin/public/api/*`
- `mensalehrer/public/api/*`
- `mensaportal/public/api/*`

Methodik:

1. Manuelle Code-Review der Authentifizierungs-, Session-, CORS-, Zahlungs- und Mail-Flows.
2. Pattern-Scan nach typischen Schwachstellen (Secrets, Cookie-Handling, Kryptografie, CSRF/CORS, Input-Validation).
3. Risikobewertung in **Kritisch / Hoch / Mittel / Niedrig** mit praxisnahen Angriffsfolgen.

Wichtiger Hinweis: Diese Analyse ist eine **statische Codeanalyse**. Ohne Laufzeit-Setup (Server-Konfiguration, WAF, DB-Rechte, TLS-Terminierung, CI/CD-Secret-Handling) kann keine vollständige Penetrationstest-Aussage getroffen werden.

---

## 2) Executive Summary

### Kritisch

1. **Produktiv- und Zahlungsgeheimnisse sind im Repository im Klartext hinterlegt** (DB, Mailjet, Klarna, PayPal, hCaptcha).

### Hoch

2. **Dauerlogin-Token werden mit SHA-1 (ohne HMAC/Argon2) gespeichert/verglichen**.
3. **CORS ist in mehreren Endpunkten potentiell fehlkonfiguriert (Reflexion/Fallback über `HTTP_HOST`)**.
4. **Lehrer- und Portal-Action-Endpunkte haben keinen expliziten CSRF-Token-Schutz für state-changing Requests**.

### Mittel

5. **Unklare Autorisierungsverknüpfung in Lehrer-`pending` Query (Join über `created_by = users.id`)**.
6. **Informationsabfluss durch interne Fehlermeldungen an Clients in Teilen der API**.
7. **Teilweise nicht-kryptografische Zufallsgenerierung für Zahlungs-PIN (`str_shuffle`)**.

### Niedrig

8. **Legacy-/Altcode mit unsicheren Fallbacks (`md5(uniqid())`, alte Password-Compat-Pfade)**.

---

## 3) Detaillierte Findings

## Finding C1 (Kritisch): Klartext-Secrets im Codebase

**Betroffene Bereiche**

- DB-Zugangsdaten im Klartext in allen drei Anwendungen.
- Mailjet SMTP User/Password im Klartext.
- Klarna API Credentials im Klartext.
- PayPal Client ID/Secret im Klartext.
- hCaptcha Secret im Klartext.

**Evidenz (Auszug)**

- DB-Credentials: `db-connect.php` (alle Apps).
- Mailjet-Credentials: `mensaportal/public/api/mailer.php`.
- Klarna/PayPal-Credentials: `mensaportal/public/api/actions.php`.
- hCaptcha Secret: `mensaadmin/public/api/login.php`, `mensalehrer/public/api/login.php`, `mensaportal/public/api/data.php`.

**Risiko**

- Bei Repo-Leak oder Insider-Zugriff sind sofortige Kontoübernahmen/Abrechnungsbetrug, Mail-Missbrauch, Captcha-Bypass, Datenbankexfiltration möglich.
- Selbst bei privatem Repo erhöht das die Blast Radius massiv.

**Empfehlung**

- Sofortige Rotation aller betroffenen Secrets.
- Secrets ausschließlich über Environment-Variablen / Secret-Manager (Vault, AWS/GCP Secret Manager, Doppler, etc.).
- Commit-History auf Secret-Leaks prüfen, ggf. `git filter-repo` + vollständige Rotation.
- CI-Policy mit Secret-Scanning (z. B. Gitleaks/TruffleHog) verpflichtend.

---

## Finding H1 (Hoch): Dauerlogin-Token nur mit SHA-1 gespeichert/verifiziert

**Evidenz**

- Speicherung als `sha1($securitytoken)` in Admin/Lehrer-Logik.
- Vergleich ebenfalls per SHA-1.

**Risiko**

- SHA-1 ist kryptografisch veraltet und ungeeignet als Passwort-/Token-Hash ohne Key-Stretching.
- Bei Datenbankabzug sind Token-Offline-Angriffe deutlich leichter als bei Argon2id/Bcrypt/HMAC-basierter Konstruktion.

**Empfehlung**

- Token in DB als `hash_hmac('sha256', token, server_secret)` oder als `password_hash`/Argon2id speichern.
- Token-Rotation pro Nutzung beibehalten, aber zusätzlich Device-Binding, TTL, und Revocation hinzufügen.
- Cookies zwingend `Secure`, `HttpOnly`, `SameSite=Strict` + eindeutige Domain-Strategie.

---

## Finding H2 (Hoch): CORS-Fallback potentiell unsicher (Host-basierte Rückgabe)

**Evidenz**

- In Admin-Endpunkten wird bei nicht erlaubtem Origin ein Header aus `$_SERVER['HTTP_HOST']` gebildet.
- In einzelnen Endpunkten wird Origin direkt gespiegelt.

**Risiko**

- CORS-Fehlkonfigurationen führen häufig zu ungewolltem Freigeben sensibler Antworten gegenüber fremden Origins.
- `HTTP_HOST` ist request-beeinflussbar (Proxy-/Header-Kontext) und als Security-Entscheidungsbasis ungeeignet.

**Empfehlung**

- Nur exakt erlaubte Origins whitelisten, sonst **keinen** `Access-Control-Allow-Origin` setzen.
- Nie dynamisch aus `HTTP_HOST`/unkontrollierten Headern ableiten.
- Einheitliches CORS-Middleware-Modul statt streuender Einzelimplementierungen.

---

## Finding H3 (Hoch): Fehlender expliziter CSRF-Schutz in state-changing Endpunkten

**Evidenz**

- `mensaadmin/public/api/actions.php` prüft CSRF-Header (gut).
- `mensalehrer/public/api/actions.php` und `mensaportal/public/api/actions.php` haben keinen vergleichbaren CSRF-Token-Check.

**Risiko**

- Bei Cookie-basierter Authentisierung können Cross-Site Requests unter bestimmten Browser-/Deployment-Konstellationen state changes auslösen.
- SameSite=Strict reduziert Risiko, ersetzt aber keinen robusten CSRF-Schutz bei komplexen Flows, Subdomain-Setups, Legacy-Browsern oder Fehlkonfiguration.

**Empfehlung**

- Für alle state-changing Requests (`POST/PUT/PATCH/DELETE`) synchronen CSRF-Token einführen.
- Alternativ konsequent auf Bearer/JWT im `Authorization`-Header umstellen (kein Cookie-Auth für API).

---

## Finding M1 (Mittel): Autorisierungs-Join in Lehrer-`pending` wirkt inkonsistent

**Evidenz**

- Query nutzt `JOIN users u ON ch.created_by = u.id`, während in anderen Bereichen `created_by` als `account_id` behandelt wird.

**Risiko**

- Bei inkonsistenter Datenmodellannahme können falsche Elternzuordnungen oder Dateninkonsistenzen auftreten (potentiell Datenschutzproblem).

**Empfehlung**

- Fremdschlüsselbeziehungen vereinheitlichen (`created_by` eindeutig als `accounts.account_id` oder `users.id`).
- Query korrigieren und per FK-Constraints absichern.

---

## Finding M2 (Mittel): Interne Fehlerdetails teilweise direkt an Client

**Evidenz**

- In mehreren `catch`-Blöcken werden Rohfehlermeldungen (`$e->getMessage()`) in API-Responses gegeben.

**Risiko**

- Erleichtert Reconnaissance (interne Tabellen-/Flow-/Provider-Details, Validierungs- und Integrationsverhalten).

**Empfehlung**

- Extern nur generische Fehlercodes/-texte.
- Volle Details ausschließlich serverseitig loggen (strukturiert, mit Correlation-ID).

---

## Finding M3 (Mittel): Zahlungs-PIN nutzt nicht-kryptografischen Zufall

**Evidenz**

- `generatePaymentPin()` basiert auf `str_shuffle()`.

**Risiko**

- Potentiell vorhersagbar/biased je nach Runtime; für Zahlungsreferenzen suboptimal.

**Empfehlung**

- Ersetzung durch CSPRNG (`random_int`) und klare Entropieanforderung (z. B. 10+ Zeichen alphanumerisch ohne Ambiguitäten).
- Rate-Limits und Ablaufzeit für PINs ergänzen.

---

## Finding L1 (Niedrig): Legacy-Krypto-Fallbacks vorhanden

**Evidenz**

- `md5(uniqid())` Fallback in `functions.inc.php`.
- Alte Password-Compat-Bibliotheken eingebunden.

**Risiko**

- Altcode erhöht Wartungs- und Sicherheitsrisiko; bei versehentlicher Nutzung drohen schwache Token.

**Empfehlung**

- Legacy-Fallbacks entfernen, Mindest-PHP-Version modernisieren, zentrale Crypto-Utility mit `random_bytes`/Argon2id einsetzen.

---

## 4) Positive Sicherheitsaspekte (bereits gut umgesetzt)

- Weitgehend vorbereitete SQL-Statements (reduziert SQLi-Risiko).
- Session-Regeneration bei Login in mehreren Flows.
- HttpOnly/Secure/SameSite-Flags in vielen Session-Cookies.
- Login-Rate-Limiting-Ansätze vorhanden.
- IDOR-Prüfungen in Teilen der Portal-Logik (`created_by`-Checks bei Karten/Abo).

---

## 5) Priorisierter Maßnahmenplan

## Sofort (0–48h)

1. **Alle geleakten Secrets rotieren** (DB, Mailjet, Klarna, PayPal, hCaptcha).
2. Secrets aus Code entfernen, nur noch via Secret-Store/ENV.
3. Incident-Assessment: Log-Review auf Missbrauch seit erstem Secret-Commit.

## Kurzfristig (1–2 Wochen)

4. Dauerlogin-Token auf HMAC-SHA-256 (mit Server-Secret) oder Argon2id-Hashing migrieren.
5. Einheitliches, hartes CORS-Modul implementieren.
6. CSRF-Schutz in Lehrer- und Portal-Action-Endpoints ergänzen.
7. Fehlerantworten härten (kein internes Exception-Leaking).

## Mittelfristig (2–6 Wochen)

8. Autorisierungs-/Datenmodellkonsistenz für `created_by` technisch erzwingen (FK + Migrationsskripte).
9. PIN-/Token-Generatoren auf CSPRNG standardisieren.
10. Sicherheits-Tests in CI (SAST, Dependency-Scan, Secret-Scan, minimaler DAST-Smoke).

---

## 6) Empfehlung für Re-Assessment

Nach Umsetzung der Sofort- und Kurzfristmaßnahmen sollte ein gezielter Re-Test erfolgen:

- AuthN/AuthZ Testmatrix (Admin/Lehrer/Portal Rollen).
- CSRF/CORS Negative Tests.
- Session-Management Tests (Fixation, Token Replay, Logout-Invalidation).
- Zahlungs-Workflow-Tests inkl. Tampering und Replay.

