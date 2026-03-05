
# 🛡️ Backend-Dokumentation: Admininterface

Das Admin-Backend (`admin_actions.php` / `admin_data.php`) ist die weitreichendste API des MensaManagers. Sie verarbeitet sensible Nutzerdaten, verändert globale Preise und schließt Finanzbuchungen ab.

## 1. Architektur & Sicherheit (Admin API)

Aufgrund der hohen Privilegien ist die Admin-API durch zusätzliche, mehrschichtige Sicherheitsmaßnahmen geschützt.

### 1.1 Strikte Authentifizierung (2FA)

Neben dem herkömmlichen Session-Check erfordert die API zwingend den Nachweis eines erfolgreich absolvierten Zwei-Faktor-Logins (TOTP). Ist das Flag `$_SESSION['2fa_verified']` nicht gesetzt, wird der Zugriff blockiert.

### 1.2 Audit Logging & Revisionssicherheit

Jede Aktion, die Geldbeträge oder Systemkonfigurationen verändert, muss revisionssicher sein.

-   Finanzielle Manuelleingriffe speichern in der Tabelle `account_transactions` nicht nur den Betrag, sondern in einer speziellen Spalte `admin_id` auch die ID des ausführenden Sekretariats-Mitarbeiters.
    
-   Stornierungen löschen keine Datensätze, sondern erzeugen eine dokumentierte Gegenbuchung (Storno).
    

### 1.3 CSRF-Schutz via Tokens

Da Admins weitreichende Rechte haben (z.B. "User löschen"), müssen sie besonders vor Cross-Site Request Forgery (CSRF) geschützt werden. Jeder schreibende POST-Request an die Admin-API muss zwingend ein CSRF-Token im HTTP-Header (`X-CSRF-Token`) übergeben, das bei der Dashboard-Initialisierung generiert wurde.

## 2. API Endpunkte (Admin)

### 2.1 Finanz- & Zahlungsverwaltung

-   `GET /api/admin_actions.php?action=getUnpaidTransactions`
    
    -   **Funktion:** Liefert alle Datensätze aus `unpaid_transactions`. Dies sind Vorkasse-Bestellungen (Überweisung) mit der generierten Zahlungs-PIN.
        
-   `POST /api/admin_actions.php?action=confirmBankTransfer`
    
    -   **Payload:** `transactionId`, `paymentPin`
        
    -   **Funktion:** Bestätigt den manuellen Geldeingang auf dem echten Bankkonto.
        
    -   **Logik:** Löscht die Buchung aus `unpaid_transactions` und führt die ursprünglich geparkte Aktion (Guthaben erhöhen, Abo aktivieren, Karte bestellen) nun final in der Datenbank aus.
        

### 2.2 Systemkonfiguration (`default_values`)

-   `GET /api/admin_actions.php?action=getConfig`
    
    -   **Funktion:** Liest alle Parameter (Preise, IBAN, Rechtstexte) aus der Datenbank aus.
        
-   `POST /api/admin_actions.php?action=updateConfig`
    
    -   **Payload:** Key-Value-Paare (z.B. `half_year_per_day: 85.00`)
        
    -   **Funktion:** Überschreibt die Konfiguration in der Datenbank. Betrifft **nur** zukünftige Buchungen. Laufende Abos bleiben unberührt.
        

### 2.3 Nutzerverwaltung & DSGVO

-   `GET /api/admin_data.php?action=getAllUsers`
    
    -   **Funktion:** Liefert eine Liste aller Accounts inklusive aller untergeordneten `card_holders` und verknüpften `chip_cards` sowie dem aktuellen Guthaben-Saldo.
        
-   `POST /api/admin_actions.php?action=deleteUser`
    
    -   **Payload:** `userId`, `deletionReason`
        
    -   **Funktion:** Führt eine DSGVO-konforme Account-Löschung aus. Personenbezogene Daten (Name, E-Mail) in `users` und `card_holders` werden genullt/anonymisiert. Transaktionslogs bleiben aus buchhalterischen Gründen (steuerrechtliche Aufbewahrungsfrist) anonymisiert in `account_transactions` bestehen (z.B. verknüpft mit `account_id = deleted_123`).
        

### 2.4 Berichte & Exporte

-   `GET /api/admin_data.php?action=generateFinancialReport`
    
    -   **Parameter:** `startDate`, `endDate`
        
    -   **Funktion:** Aggregiert alle Einträge aus `account_transactions` für den gewählten Zeitraum. Trennt Umsätze in Kategorien (Prepaid-Aufladungen vs. Abo-Käufe) auf und liefert sie als JSON zur Weiterverarbeitung (z.B. Excel-Export) an das Frontend.
        

🔙 [Zurück zur Hauptseite](/docs/README.md)