
# ⚙️ API & Kernfunktionen (Code-Doku)

Dieses Dokument erläutert die internen PHP-Funktionen der `actions.php`, die die Geschäftslogik des Mensa-Systems abbilden. Alle Funktionen gehen davon aus, dass eine gültige PDO-Verbindung, eine verifizierte Session (`$accountId`) und ein durch `FOR UPDATE` gelockter Kontostand (`$currentBalance`) vorliegen.

## 1. Finanzierungs-Kernfunktionen

### `executeTopup()`

Verantwortlich für das Aufladen von Familienguthaben.

-   **Validierung:** Prüft strikt, ob der Betrag zwischen 5 € und 1000 € liegt.
    
-   **Logik:** * Bei _Überweisung_: Es wird noch **kein** Guthaben gutgeschrieben. Stattdessen wird die Transaktion unter `unpaid_transactions` mit einer generierten PIN (`generatePaymentPin()`) geparkt.
    
    -   Bei _PayPal/Klarna_: Das `balance` Feld in der Tabelle `accounts` wird direkt erhöht.
        
-   **Logging:** Trägt die Aktion in `account_transactions` ein (inklusive Zahlungsdienstleister-TAN).
    

### `executeOrderCard()`

Zuständig für die Erstellung eines neuen Schülerprofils inkl. Kartengebühr.

-   **Preisermittlung:** Holt den `card_deposit` Preis aus `default_values`.
    
-   **Guthaben-Verrechnung:** Wenn der Nutzer das Häkchen `useBalance` gesetzt hat und Guthaben besitzt, wird das Guthaben anteilig (oder voll) abgezogen. Der Restbetrag (`$remainingToPay`) muss via Klarna/PayPal bezahlt werden.
    
-   **Logik:** Fügt den neuen Schüler in `card_holders` ein und erstellt Ein-/Ausgänge in der Transaktionshistorie.
    

### `executeBuyAbo()`

Die komplexeste Funktion des Systems. Bucht ein Abonnement für ein (neues oder bestehendes) Schülerprofil.

-   **IDOR-Schutz:** Prüft bei bestehenden Profilen zwingend, ob die `holderId` dem aktiven Account gehört.
    
-   **Preisermittlung:** Multipliziert die validierten Wochentage (Mo-Fr) mit dem Preis für ein Halb- oder Ganzjahresabo aus der Datenbank. Addiert bei Bedarf den Kartenpfand hinzu.
    
-   **Logik:**
    
    -   Schreibt den neuen Zeitraum inkl. Wochentagen in die `subscriptions` Tabelle.
        
    -   Legt das Enddatum automatisch auf den 31.01. (Halbjahr) oder 31.07. (Ganzjahr) fest.
        
    -   Verrechnet auch hier bestehendes Guthaben mit dem zu zahlenden Gesamtbetrag.
        

### `executeReorderCard()`

Beantragt eine kostenpflichtige Ersatzkarte für eine verlorene Karte.

-   **Logik:** * Löscht die alte Chipkarten-Zuordnung hart aus der Datenbank (`DELETE FROM chip_cards WHERE holder_id = ?`). Dadurch ist die alte Karte sofort physisch unbrauchbar.
    
    -   Erstellt eine neue Transaktion für den Kartenpfand (analog zu `executeOrderCard`).
        

### `executeBlockCard()`

-   **Logik:** Eine rein administrative Funktion, die kein Geld bewegt. Sie setzt den Status in `chip_cards` auf `active = 0` und setzt das Feld `deactivated_at`. Verifiziert auch hier via Subselect strikt die Besitzrechte.
    

## 2. API-Flows für Zahlungsdienstleister

Die Anbindung von Klarna und PayPal folgt einem strikten **Zwei-Schritt-Verfahren (Intent -> Capture)**, um Manipulationen zu vermeiden.

1.  **Create Session/Order (`create_klarna_session` / `create_paypal_order`)**
    
    -   Das Frontend teilt dem Backend mit, was gekauft werden soll.
        
    -   Das Backend berechnet den Preis _selbstständig_ und meldet diesen an PayPal/Klarna.
        
    -   **Wichtig:** Die Kaufabsicht (z.B. "Abo für Max an 3 Tagen") wird kryptografisch sicher im Server-Speicher unter `$_SESSION['paypal_intent_...']` oder `$_SESSION['klarna_intent']` abgelegt. Dem Client wird nur eine Order-ID/Token zurückgegeben.
        
2.  **Capture Order (`capture_paypal_order` / `place_klarna_order`)**
    
    -   Das Frontend meldet: "Der Nutzer hat bezahlt, hier ist die Autorisierungs-Token".
        
    -   Das Backend kontaktiert die PayPal/Klarna API serverseitig (Server-to-Server) und fragt, ob das Geld wirklich eingegangen ist.
        
    -   Wenn `COMPLETED` / `APPROVED` zurückkommt, liest das Backend die Kaufabsicht aus der `$_SESSION` wieder aus und führt _jetzt erst_ die eigentlichen Kernfunktionen (`executeBuyAbo` etc.) innerhalb einer sicheren Datenbank-Transaktion aus.
        

Dieses Vorgehen garantiert, dass ein Hacker den Warenkorbinhalt zwischen dem Checkout und der Bezahlung nicht manipulieren kann.

🔙 [Zurück zur Hauptseite](./docs/README.md)