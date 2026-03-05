
# 🛡️ Backend-Dokumentation: Admininterface

Das Admin-Backend (`actions.php`, `data.php` und `login.php`) ist die weitreichendste API des MensaManagers. Sie verarbeitet sensible Nutzerdaten, verändert globale Preise und schließt Finanzbuchungen ab.

## 1. Architektur & Sicherheit (Admin API)

Aufgrund der hohen Privilegien ist die Admin-API durch modernste, mehrschichtige Sicherheitsmaßnahmen (nach OWASP Top 10 Standards) geschützt.

### 1.1 Strikte Authentifizierung (2FA) & Session-Sicherheit

-   **Zwei-Faktor-Authentifizierung (TOTP):** Der Login erfolgt zweistufig. Nach der Passwort-Prüfung ist zwingend ein 6-stelliger Authenticator-Code erforderlich.
    
-   **Bot- & Brute-Force-Schutz:** Der Login ist durch **hCaptcha** geschützt und verfügt über ein strenges Rate-Limiting (max. 5 Fehlversuche, 15 Minuten Sperre), welches IP-Spoofing-sicher implementiert ist.
    
-   **Session-Härtung:** Sessions nutzen `session_regenerate_id(true)` (Schutz vor Session-Fixation) und setzen strikte Cookies (`HttpOnly`, `Secure`, `SameSite=Strict`).
    

### 1.2 Audit Logging & Transaktionssicherheit (ACID)

Jede Aktion, die Geldbeträge verändert, ist revisionssicher und transaktional abgesichert:

-   **Audit-Log:** Finanzielle Manuelleingriffe speichern in der Tabelle `account_transactions` nicht nur den Betrag, sondern in einer dedizierten Spalte `admin_id` auch die ID des ausführenden Administrators.
    
-   **Anti-Race-Conditions:** Alle schreibenden API-Calls verwenden Idempotency-Hashes. Schnelle Doppelklicks (innerhalb von 2 Sekunden) werden verworfen, um Doppelbuchungen zu verhindern.
    
-   **Strikte Input-Validierung:** Bei Einzahlungen werden serverseitig ausschließlich strikt positive Beträge (`abs()`, `> 0`) akzeptiert. Stornierungen erfolgen als dokumentierte Gegenbuchung (Typ `REFUND`).
    

### 1.3 API-Schutz: CSRF, CORS & IDOR

-   **CSRF-Schutz via Tokens:** Jeder schreibende POST-Request an `actions.php` muss zwingend ein valides CSRF-Token im HTTP-Header (`X-CSRF-Token`) übergeben.
    
-   **CORS-Whitelist:** Wildcards (`*`) sind deaktiviert. Die API antwortet nur auf Anfragen der exakt zugelassenen Frontend-Domains.
    
-   **Schutz vor IDOR:** Jede API-Route prüft serverseitig die Session und das Admin-Flag (`status === 'ADMIN'`). Das Manipulieren von IDs durch den Client (z. B. `userId=5`) ist wirkungslos, wenn die Rechte fehlen. Selbst-Aussperrungen sind programmatisch blockiert.
    

## 2. API Endpunkte (Admin)

### 2.1 Lesende Endpunkte (`GET /api/data.php`)

Der zentrale Lese-Endpoint lädt gebündelt Daten für das React-Frontend. Er verfügt über Rate-Limiting für aufwendige Abfragen (wie die Suche).

-   `?action=dashboard`: Liefert System-Metriken (KPIs), die 5 letzten Transaktionen und alle `default_values` (Preise, Bankdaten, Rechtstexte).
    
-   `?action=parent&id=...`: Liefert alle verknüpften Schüler, Abos, Chipkarten und die Transaktionshistorie eines bestimmten Eltern-Accounts.
    
-   `?action=search&q=...`: Globale AJAX-Suche über alle Eltern, Schüler, Klassen und Kartennummern.
    
-   `?action=active_cards` / `?action=pending`: Liefert Listen aller aktiven Chipkarten bzw. anstehenden Kartenbestellungen.
    
-   `?action=unpaid`: Liefert ausstehende Zahlungen aus `unpaid_transactions` (inkl. PIN).
    
-   `?action=accounting`: Aggregiert Finanzdaten für einen bestimmten Zeitraum (Umsätze, Pfand, Abo-Verteilungen nach Wochentag).
    
-   `?action=accounting_export`: Liefert Detaildaten für den Excel/CSV-Export.
    

### 2.2 Schreibende Endpunkte (`POST /api/actions.php`)

Dieser Endpoint verarbeitet alle administrativen Mutationen über einen strukturierten JSON-Body (`{ "action": "...", "data": {...} }`).

**Finanz- & Zahlungsverwaltung:**

-   `deposit`: Manuelle Guthaben-Aufladung (erfordert positive Beträge, loggt `admin_id`).
    
-   `markTransactionPaid`: Verarbeitet Geldeingänge (Überweisung) via PIN. Schreibt das Geld gut, aktiviert Abos, löscht den Vorkasse-Eintrag und setzt das Audit-Log.
    
-   `markAboPaid`: Setzt den Status eines Abos auf bezahlt und hinterlegt die Belegnummer.
    
-   `refundTransaction`: Storniert eine Abbuchung sicher und bucht den Betrag als Gegenbuchung (mit `admin_id` und Prefix "Erstattung") zurück aufs Familienkonto.
    

**Systemkonfiguration (`default_values`):**

-   `updateSettings`: Verarbeitet dynamische Schlüssel-Wert-Paare (Preise, Bankdaten, Impressum/Datenschutz) und überschreibt diese in der Datenbank. HTML wird strikt gefiltert, außer bei den rechtlichen WYSIWYG-Texten.
    

**Nutzer- & Kartenverwaltung:**

-   `updateUserRole`: Ändert die RBAC-Rolle eines Nutzers (`USER`, `TEACHER`, `ADMIN`).
    
-   `deleteUserAccount`: DSGVO-konforme Löschung. Verknüpfte personenbezogene Daten werden gelöscht, Finanzdaten bleiben buchhalterisch als verwaiste Buchungen erhalten.
    
-   `assignCardNumber`: Zuweisung einer RFID/Barcode-ID zu einem Schüler.
    
-   `collectCard`: Sammelt eine Karte ein, ändert den Status und löst **automatisch** eine Pfanderstattung auf das Elternkonto aus.
    
-   `editStudent` / `updateCardStatus` / `deleteAbo`: Hilfsfunktionen zur Verwaltung der Entitäten.
    

🔙 [Zurück zur Hauptseite](/docs/README.md)