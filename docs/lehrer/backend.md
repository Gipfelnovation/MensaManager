
# 🎓 Backend-Dokumentation: Lehrerinterface

Dieses Dokument beschreibt die Architektur und die API für das Personal an der Essensausgabe und im Sekretariat. Diese API (`teacher_actions.php`) ist minimal gehalten und stark in ihren Rechten beschnitten.

## 1. Architektur & Sicherheit (Teacher API)

Das Lehrerinterface ist physisch auf Endgeräte in der Schule beschränkt bzw. durch spezifische Rollen gesichert.

### 1.1 Rollenbasierte Zugriffskontrolle (RBAC)

Ein eingeloggter User muss zwingend über die Rolle `teacher` oder `card_issuer` verfügen. Die API blockiert Aufrufe von normalen Eltern-Accounts:

```
if ($_SESSION['role'] !== 'teacher' && $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    exit(json_encode(['error' => 'Keine Berechtigung zur Kartenausgabe.']));
}

```

### 1.2 Verhinderung von Pfand-Betrug (Refund-Lock)

Beim Einsammeln von Karten wird das Kartenpfand automatisch an die Eltern zurückerstattet. Die API prüft strikt, ob die zurückgegebene Karte den Status `Aktiv` hatte. Eine Karte kann nicht zweimal "eingesammelt" werden, um mehrfach Pfand zu generieren.

## 2. API Endpunkte (Teacher)

Die API für Lehrkräfte beschränkt sich primär auf das Auslesen von Schülerprofilen und das Zuordnen/Trennen von Chipkarten (Hardware-IDs).

### 2.1 Kartenausgabe

-   `GET /api/teacher_actions.php?action=getPendingCards`
    
    -   **Funktion:** Liefert eine Liste aller Schüler (`card_holders`), für die im Userportal ein Profil oder eine Ersatzkarte bestellt, aber noch keine physische Karte zugewiesen wurde.
        
-   `POST /api/teacher_actions.php?action=assignCard`
    
    -   **Payload:** `holderId` (Schüler-ID), `chipUid` (Gescannter Hardware-Code der RFID-Karte)
        
    -   **Funktion:** Prüft, ob die `chipUid` noch frei ist. Verknüpft die Karte mit dem Schüler und setzt den Status auf `Aktiv` (`active = 1`, `issued_at = NOW()`).
        

### 2.2 Kartenrücknahme

-   `POST /api/teacher_actions.php?action=returnCard`
    
    -   **Payload:** `chipUid` (Gescannter Hardware-Code der zurückgegebenen Karte)
        
    -   **Funktion (Atomare Transaktion):**
        
        1.  Liest den Besitzer der Karte aus.
            
        2.  Hebt die Verknüpfung auf (Löscht den Datensatz in `chip_cards` oder setzt `active = 0`).
            
        3.  Ermittelt den `account_id` der Eltern.
            
        4.  Bucht den in `default_values` hinterlegten Kartenpfand (z.B. 5,00 €) als `REFUND` auf das Familienguthaben in `accounts` zurück.
            
        5.  Erzeugt ein Audit-Log in `account_transactions` mit der ID der ausführenden Lehrkraft.
            

### 2.3 Essensausgabe (Terminal-Validierung)

-   `POST /api/teacher_actions.php?action=validateMeal`
    
    -   **Payload:** `chipUid`
        
    -   **Funktion:** Wird aufgerufen, wenn ein Schüler die Karte an das Terminal in der Mensa hält.
        
    -   **Logik:**
        
        1.  Prüft, ob die Karte existiert und `Aktiv` ist.
            
        2.  Prüft, ob für den heutigen Wochentag (z.B. Dienstag) ein gültiges Abo in `subscriptions` existiert. Falls ja: Rückgabe `Zulassung erteilt (Abo)`.
            
        3.  Falls kein Abo existiert: Prüft, ob das Guthaben der Eltern in `accounts` ausreicht, um den regulären Essenspreis zu bezahlen. Falls ja: Zieht Guthaben ab und gibt `Zulassung erteilt (Prepaid)` zurück. Falls nein: `Abgelehnt (Guthaben leer)`.
            

🔙 [Zurück zur Hauptseite](/docs/README.md)