
# 🛡️ Backend-Dokumentation: Lehrerinterface (API & Sicherheit)

Das Backend des Lehrerinterfaces besteht aus drei hochspezialisierten PHP-Endpunkten (`login.php`, `data.php`, `actions.php`). Diese Architektur ist streng nach dem Prinzip "Security by Design" aufgebaut und schützt sensible Finanz- und Schülerdaten vor Angriffen (OWASP Top 10).

## 1. Sicherheitsarchitektur (Security by Design)

Jeder API-Endpunkt erzwingt vor der Ausführung der Logik folgende Sicherheitsstandards:

### 1.1 Strikte CORS-Konfiguration (Cross-Origin Resource Sharing)

Es werden keine Wildcards (`*`) akzeptiert. Die API gleicht den `HTTP_ORIGIN` gegen ein hartkodiertes Array (`$allowed_origins`) ab. Nur exakt definierte Schul-Domains dürfen mit der API kommunizieren. Dies verhindert das Auslesen von Daten durch bösartige Dritt-Webseiten.

### 1.2 Modernes Session- & Cookie-Management

-   **HttpOnly:** Cookies können nicht durch JavaScript (XSS-Angriffe) ausgelesen werden.
    
-   **Secure:** Sessions werden ausschließlich über verschlüsselte HTTPS-Verbindungen gesendet.
    
-   **SameSite=Strict:** Der Browser sendet Session-Cookies nur, wenn die Anfrage direkt von der Schul-Domain kommt. Dies blockiert Cross-Site Request Forgery (CSRF) zu 100%.
    

### 1.3 Schutz vor Session-Fixation

Bei jedem erfolgreichen Login wird zwingend `session_regenerate_id(true);` aufgerufen. Einem Angreifer, der dem Opfer zuvor eine manipulierte Session-ID unterjubeln wollte, wird dadurch die Grundlage entzogen.

### 1.4 SQL-Injection Prävention

Sämtliche Datenbankinteraktionen erfolgen ausnahmslos über **PDO Prepared Statements** (`$pdo->prepare()`). Parameter werden strikt vom SQL-Code getrennt ausgeführt.

### 1.5 Race-Condition & Transaktions-Schutz

Beim Einsammeln von Karten (Pfandrückerstattung) wird `$pdo->beginTransaction()` genutzt. _Entscheidendes Feature:_ Der SQL-Befehl nutzt **`FOR UPDATE`** (`SELECT ... FOR UPDATE`). Dies sperrt die betroffene Datenbankzeile für den Bruchteil einer Sekunde. Ein durch schlechtes Internet verursachter "Doppel-Klick" des Lehrers kann somit niemals zu einer doppelten Auszahlung des Kartenpfands führen.

## 2. API Endpunkte

### 2.1 Authentifizierung (`/api/login.php`)

-   **GET `?check=1`:** Prüft, ob eine gültige Session oder ein gültiges "Angemeldet bleiben"-Cookie (Security-Token) existiert. Erneuert bei Cookie-Logins direkt die Session-ID.
    
-   **POST:** Verarbeitet den Login.
    
    -   Prüft hCaptcha via serverseitigem POST-Request.
        
    -   Sichert gegen Brute-Force: Sperrt die IP-Adresse für 15 Minuten, wenn mehr als 5 Fehlversuche (`login_attempts`) registriert wurden.
        
    -   Verifiziert das Passwort via `password_verify` und prüft zwingend auf die Rollen `TEACHER` oder `ADMIN`.
        

### 2.2 Datenabruf (`/api/data.php`)

Liest die Schülerlisten aus. Verwendet `LEFT JOIN` für Elterndaten, um zu garantieren, dass Schüler auch dann angezeigt werden, wenn das verknüpfte Elternkonto beschädigt/gelöscht ist.

-   **GET `?action=pending`:** Gibt alle Schüler (`card_holders`) zurück, die keinen Eintrag in `chip_cards` haben.
    
-   **GET `?action=active_cards`:** Gibt alle Schüler mit aktiver Karte zurück.
    
    -   _Sub-Query Feature:_ Enthält eine verschachtelte SQL-Abfrage, die in Echtzeit berechnet, ob der Schüler ein noch in der Zukunft endendes Abonnement besitzt (`hasActiveAbo` = Boolean).
        

### 2.3 Aktions-Ausführung (`/api/actions.php`)

-   **POST `action=assignCardNumber`:** - Prüft, ob die `cardNumber` (UID) bereits vergeben ist.
    
    -   Konvertiert das Base64-Gesichtsfoto (`faceData`) in ein Binary-BLOB und speichert es in `card_holders`.
        
    -   Verknüpft die neue `card_uid`, die `account_id` der Eltern und die `holder_id` des Schülers in der Tabelle `chip_cards`.
        
-   **POST `action=collectCard`:**
    
    -   Startet eine Transaktion mit `FOR UPDATE`-Sperre auf die Karte.
        
    -   Ermittelt die `account_id` und den aktuellen Pfandwert aus `default_values`.
        
    -   Bucht das Pfand als Guthaben auf den Eltern-Account (`accounts`) und schreibt ein Audit-Log in `account_transactions` (Typ: `REFUND`).
        
    -   Löscht die Karte aus `chip_cards`.
        
    -   Falls der Parameter `deleteStudent=1` gesetzt ist: Löscht zusätzlich alle alten Abos (`subscriptions`) und den Schüler (`card_holders`) komplett aus dem System.


🔙 [Zurück zur Hauptseite](/docs/README.md)