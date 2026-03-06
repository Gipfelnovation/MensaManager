
# 🎓 Frontend-Dokumentation: Lehrerinterface (Karten-Ausgabe)

Dieses Interface ist eine React-basierte Single-Page-Application (SPA), die sich an Lehrkräfte und das Verwaltungspersonal richtet. Es dient der sicheren Ausgabe und Rücknahme der physischen Chipkarten/Barcodes für die Schulmensa.

> **Wichtiger Hinweis:** Das Lehrerinterface erfordert spezielle Berechtigungen (Rolle: `TEACHER` oder `ADMIN`). Die Applikation kommuniziert über strikt abgesicherte Session-Cookies (`credentials: 'include'`) mit dem Backend.

## 1. Login & Authentifizierung

Der Zugang zum System ist stark reglementiert:

-   **Bot-Schutz:** Der Login ist durch **hCaptcha** geschützt.
    
-   **Brute-Force-Schutz:** Nach 5 Fehlversuchen wird die IP für 15 Minuten gesperrt (mit visuellem Feedback im UI).
    
-   **Session-Handling:** Das Frontend sendet Formulardaten als `FormData`. Bei Erfolg wird die Sitzung im Hintergrund über moderne `SameSite=Strict` Cookies gehalten.
    

## 2. Modul: Kartenausgabe (Ersteinrichtung)

In diesem Tab ("Ausgeben") werden alle Schüler aufgelistet, die im System registriert sind, aber aktuell **keine** physische Karte besitzen.

**Ablauf der Ausgabe (Kamera-gestützter Workflow):**

1.  Die Lehrkraft sucht das Schülerprofil über die integrierte Such- oder Klassen-Filterfunktion.
    
2.  Ein Klick auf das Kamera-Icon öffnet das **Assign-Modal**.
    
3.  **Schritt 1 (Fotoaufnahme):** Die Frontkamera des Geräts wird aktiviert. Die Lehrkraft macht ein Foto des Schülers für die spätere Identifikation in der Mensa. Das Foto kann vor der Bestätigung überprüft und bei Bedarf neu aufgenommen werden.
    
4.  **Schritt 2 (Karten-Scan):** Die Rückkamera (oder ein externer Scanner) wird aktiviert. Die Lehrkraft hält den Barcode/QR-Code der physischen Mensakarte in die Kamera. Alternativ ist eine manuelle Eingabe der Kartennummer möglich.
    
5.  **Schritt 3 (Zuweisung):** Das System zeigt eine Zusammenfassung (Foto, Name, Karten-UID). Nach Klick auf "Zuweisen" wird der Button gesperrt (Ladeanimation), um doppelte Zuweisungen zu verhindern.
    
6.  Das Profil verschwindet aus der Ausgaben-Liste und wandert in den Tab "Einsammeln".
    

## 3. Modul: Kartenrücknahme (Einsammeln)

In diesem Tab ("Einsammeln") werden alle Schüler gelistet, die aktuell eine **aktive** Karte besitzen.

**Ablauf der Rücknahme & Besonderheiten:**

1.  Die Lehrkraft sucht das Profil und klickt auf das Häkchen-Icon.
    
2.  Es öffnet sich das Rücknahme-Modal mit wichtigen Warn- und Steuerungsfunktionen.
    
3.  **Prüfung auf aktive Abonnements:** Das System prüft in Echtzeit, ob der Schüler noch ein bezahltes, in der Zukunft liegendes Abo besitzt (`hasActiveAbo`).
    
    -   _Falls ja:_ Es erscheint eine dicke, rote Warnung. Die Lehrkraft wird angewiesen, die Eltern auf das laufende Abo hinzuweisen.
        
4.  **Schüler komplett löschen:** Standardmäßig ist eine Checkbox aktiviert, die den Schüler beim Einsammeln der Karte komplett aus der Datenbank löscht (inkl. aller alten Abos).
    
    -   _Sicherheits-Sperre:_ Hat der Schüler noch ein aktives Abo, wird diese Checkbox automatisch **deaktiviert und gesperrt**.
        
5.  **Transaktion:** Durch Klick auf "Einsammeln" (mit Ladesperre) wird die Karte im System gelöscht und das Kartenpfand wird serverseitig automatisch dem Elternkonto als Guthaben gutgeschrieben.
    

## 4. UI/UX & Feedback-System

-   **Echtzeit-Aktualisierung:** Nach jeder Aktion (Ausgeben/Einsammeln) lädt die App die Datensätze nahtlos neu, um fehlerhafte State-Manipulationen (z.B. falsche IDs) zu vermeiden.
    
-   **Toasts (Notifications):** Erfolgs- und Fehlermeldungen (z.B. "Karte erfolgreich zugewiesen" oder serverseitige SQL-Fehler) werden als kleine Popups am unteren Bildschirmrand eingeblendet.


🔙 [Zurück zur Hauptseite](/docs/README.md)