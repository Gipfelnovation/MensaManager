
# 🧑‍💻 Backend-Dokumentation: Benutzerinterface

Dieses Dokument beschreibt die Architektur, die Sicherheitskonzepte und die API-Endpunkte, die das Frontend der Eltern und Schüler (Userinterface) antreiben. Die Hauptdateien für diese API sind `data.php` (lesend/Auth) und `actions.php` (schreibend/Finanzen).

## 1. Architektur & Sicherheit (User API)

Das User-Backend ist nach dem **Zero Trust Prinzip** aufgebaut. Dem Client (Browser) wird bei Preisberechnungen oder Objektzuweisungen grundsätzlich nicht vertraut.

### 1.1 Sitzungsmanagement & Authentifizierung

-   **Cookies:** Die Session-Cookies (`mensa_login`) werden strikt mit `HttpOnly`, `SameSite=Strict` und `Secure` (unter HTTPS) gesetzt.
    
-   **Session-Fixation:** Bei jedem erfolgreichen Login (`data.php?action=login`) wird die Session-ID via `session_regenerate_id(true)` neu generiert.
    
-   **Brute-Force & Captcha:** Logins erfordern zwingend ein validiertes hCaptcha-Token. Fehlgeschlagene Logins werden pro IP geloggt; nach 5 Versuchen greift eine 15-minütige Sperre.
    

### 1.2 Schutz vor IDOR (Insecure Direct Object Reference)

Wenn Eltern Aktionen für ihre Kinder ausführen (z.B. Karte sperren, Abo kaufen), sendet das Frontend eine `holderId`. Die API vertraut dieser ID nicht blind, sondern verifiziert über ein SQL-Subselect zwingend die Besitzverhältnisse:

```
SELECT 1 FROM card_holders WHERE holder_id = ? AND created_by = ?

```

_(Wobei `created_by` der verifizierten `$accountId` aus der PHP-Session entspricht)._

### 1.3 Transaktionssicherheit (Race Conditions)

Zeitgleiche Kaufanfragen könnten das Konto ungewollt ins Minus treiben. Daher liest die User-API den Kontostand immer innerhalb einer Datenbank-Transaktion mit einem exklusiven Zeilen-Lock aus:

```
SELECT balance FROM accounts WHERE account_id = ? FOR UPDATE

```

Dadurch werden parallele API-Aufrufe blockiert, bis die erste Buchung via `commit()` bestätigt wurde.

## 2. API Endpunkte (User)

Alle Endpunkte prüfen initial, ob eine gültige `$_SESSION['userid']` existiert (außer Login/Register).

### 2.1 Authentifizierung (`data.php`)

-   `POST /api/data.php?action=login`
    
    -   **Payload:** `email`, `passwort`, `captchaToken`
        
    -   **Funktion:** Verifiziert das Captcha via hCaptcha-Server, prüft das Passwort (`password_verify`) und initialisiert die sichere Session.
        
-   `POST /api/data.php?action=register`
    
    -   **Payload:** `vorname`, `nachname`, `email`, `passwort`, `passwort2`
        
    -   **Funktion:** Erzwingt sichere Passwörter (Regex: mind. 8 Zeichen, Groß-/Klein, Zahl, Sonderzeichen). Hasht das Passwort (`password_hash`) und legt User + leeren Account an.
        

### 2.2 Datenabruf (`data.php`)

-   `GET /api/data.php?action=getData`
    
    -   **Funktion:** Liefert alle für das Dashboard nötigen Daten in einem großen JSON-Objekt zurück.
        
    -   **Rückgabe:** Kontostand (`balance`), Transaktionshistorie (`account_transactions`), gebuchte Abos (`subscriptions`), Schülerprofile & Chipkarten (`card_holders` + `chip_cards`) sowie Systemkonfigurationen (`default_values` wie IBAN und Preise).
        

### 2.3 Finanz- & Kaufaktionen (`actions.php`)

-   `POST /api/actions.php?action=topup`
    
    -   **Payload:** `amount`, `paymentMethod`
        
    -   **Funktion:** Leitet eine Guthabenaufladung ein. Bei "Überweisung" wird eine Zahlungs-PIN generiert und in `unpaid_transactions` geparkt.
        
-   `POST /api/actions.php?action=order_card` / `reorder_card`
    
    -   **Payload:** Schülerdaten, `useBalance`, `paymentMethod`
        
    -   **Funktion:** Berechnet den Kartenpfand serverseitig. Zieht Guthaben ab und leitet Restbeträge an PayPal/Klarna weiter. Bei `reorder_card` wird die alte `chip_cards`-Verknüpfung hart gelöscht.
        
-   `POST /api/actions.php?action=buy_abo`
    
    -   **Payload:** `type` (halb/ganz), `days` (Mo-Fr), `cardOption`, `useBalance`, `paymentMethod`
        
    -   **Funktion:** Berechnet den Abo-Preis tagesgenau auf Basis der `default_values` in der Datenbank. Schreibt das Abo in die `subscriptions` Tabelle.
        

### 2.4 Zahlungsdienstleister (`actions.php`)

-   **PayPal:** `create_paypal_order` & `capture_paypal_order`
    
-   **Klarna:** `create_klarna_session` & `place_klarna_order`
    
-   **Logik:** Die API baut einen "Intent" (Kaufabsicht) auf und speichert diesen in der `$_SESSION` (z.B. `$_SESSION['paypal_intent_ID']`). Erst wenn der Client das erfolgreiche Payment-Token an den "Capture"-Endpunkt sendet, prüft der Server die Zahlung bei PayPal/Klarna und führt die eigentliche Verbuchung (`executeBuyAbo`, etc.) aus. Manipulierte Frontend-Preise sind somit ausgeschlossen.
    

🔙 [Zurück zur Hauptseite](/docs/README.md)