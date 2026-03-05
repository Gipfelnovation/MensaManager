
# 🏗️ Architektur, Datenbank & Sicherheit

Dieses Dokument beschreibt den technischen Aufbau und die Sicherheitskonzepte des PHP-Backends.

## 1. Technischer Aufbau

Das Backend verfolgt einen modularen API-Ansatz. Das Frontend (React) kommuniziert zustandsbehaftet (via sicheren Cookies) mit zwei Haupt-Einstiegspunkten:

-   `data.php`: Zuständig für unkritische Datenabfragen, Registrierung und Authentifizierung (Login/Logout).
    
-   `actions.php`: Zuständig für alle finanzrelevanten, schreibenden Aktionen (Käufe, Zahlungsabwicklung, Kartensperrungen).
    

## 2. Datenbank-Struktur (ERM-Auszug)

Die MySQL/MariaDB Datenbank ist relational aufgebaut. Die wichtigsten Tabellen sind:

-   `users`: Speichert Eltern- und Admin-Accounts (inkl. gehashter Passwörter und TOTP-Secrets).
    
-   `accounts`: Das finanzielle Hauptkonto einer Familie (enthält den `balance` Wert). Ein `user` hat ein `account`.
    
-   `card_holders`: Schülerprofile. Sind über `created_by` an einen `account` gekoppelt.
    
-   `chip_cards`: Repräsentiert die physische Karte (`card_uid`) und ist an einen `card_holder` gebunden.
    
-   `subscriptions`: Speichert aktive Abos (Halb/Ganzjahr), die gebuchten Wochentage und Gültigkeitszeiträume.
    
-   `account_transactions`: Ein unveränderliches Audit-Log für alle Finanzbewegungen (Guthaben auf/ab).
    
-   `default_values`: Key-Value-Store für Systemvariablen (Preise, IBAN, etc.).
    

## 3. Sicherheitskonzepte (Defense in Depth)

Das Backend ist nach dem **Zero Trust Prinzip** aufgebaut. Dem Frontend wird bei Berechnungen und Preisangaben nicht vertraut.

### 3.1 Authentifizierung & Brute-Force Schutz

-   **Session-Fixation:** Bei jedem Login (`session_regenerate_id(true)`) wird eine neue Session-ID generiert.
    
-   **Sichere Cookies:** Sessions werden mit `HttpOnly`, `SameSite=Strict` und (im Live-Betrieb) `Secure` übertragen, was XSS- und CSRF-Angriffe verhindert.
    
-   **Brute-Force Limitierung:** Fehlgeschlagene Logins werden pro IP (`login_attempts`) geloggt. Nach 5 Fehlversuchen greift eine 15-minütige Sperre. Zusätzlich verzögert ein `sleep(1)` im Fehlerfall das automatisierte Durchprobieren.
    
-   **hCaptcha:** Die Login- und Registrierungsendpunkte erfordern ein gültiges serverseitig verifiziertes Captcha-Token.
    

### 3.2 Schutz vor IDOR (Insecure Direct Object References)

Sämtliche Aktionen, die auf spezifische IDs zugreifen (z.B. eine Karte sperren), verifizieren serverseitig, ob das Objekt dem eingeloggten Nutzer gehört. _Beispiel-SQL:_ `SELECT 1 FROM card_holders WHERE holder_id = ? AND created_by = ?` (Wobei `created_by` die ID des eingeloggten Nutzers ist).

### 3.3 Race Conditions & Transaktionssicherheit

In Finanzsystemen können zeitgleiche API-Anfragen dazu führen, dass Guthaben doppelt ausgegeben wird. Dies wird im MensaManager auf Datenbank-Ebene verhindert:

```
// Guthaben wird innerhalb der $pdo->beginTransaction() exklusiv gelockt
$stmt = $pdo->prepare("SELECT balance FROM accounts WHERE account_id = ? FOR UPDATE");

```

Durch `FOR UPDATE` müssen zeitgleiche Transaktionen warten, bis der aktuelle Prozess abgeschlossen und via `$pdo->commit()` bestätigt ist.

### 3.4 CORS & Input Validierung

-   **CORS Whitelist:** Die API beantwortet Anfragen nur, wenn der `Origin` explizit in einer Whitelist (z.B. `https://mensamanager.de`) hinterlegt ist. Wildcards (`*`) sind für Credentials deaktiviert.
    
-   **Prepared Statements:** Alle Queries nutzen `PDO prepare/execute`, was SQL-Injections ausschließt.
    
-   **Preis-Validierung:** Bei Käufen wird nicht der Betrag aus dem Frontend übernommen. Stattdessen berechnet das Backend die Kosten anhand der Tabelle `default_values` komplett neu.
    

🔙 [Zurück zur Hauptseite](/docs/README.md) | ➡️ [Weiter zu den API-Kernfunktionen](/docs/api.md)
