
# 🛡️ Admininterface (Schulverwaltung)

Das Admin-Portal ist die von React angetriebene Steuerzentrale des MensaManager-Systems. Es ist als Single-Page-Application (SPA) konzipiert, durch eine Zwei-Faktor-Authentifizierung (2FA) geschützt und bietet umfassende Werkzeuge zur Verwaltung des gesamten Mensa-Betriebs.

## 1. Dashboard, Monitoring & Buchhaltung

Das System bietet eine Echtzeit-Übersicht über alle wichtigen Metriken und Finanzströme:

-   **KPI-Dashboard:** Schneller Überblick über das Gesamtguthaben im System, aktive Chipkarten, unbezahlte Transaktionen und ausstehende Karten.
    
-   **Buchhaltung & Auswertung (`AccountingView`):**
    
    -   Aufschlüsselung des Kapitals (Gesamteinzahlungen, Wert externer Abokäufe, Wert Kartenpfand).
        
    -   Wochentags-Auswertungen (Balkendiagramm: Wie viele Abos sind für Montag, Dienstag etc. gebucht?).
        
    -   Frei wählbare Datumsfilter für Auswertungen.
        
    -   **Exporte:** Mit einem Klick können Buchhaltungsdaten (CSV/Excel) für den gewählten Zeitraum exportiert werden.
        

## 2. Benutzerverwaltung (RBAC) & Accounts

Hier werden alle registrierten Eltern, Schülerprofile und Administratoren verwaltet. Die globale Suche erlaubt das schnelle Auffinden über Namen oder Kartennummern.

-   **Eltern-Dashboard:** Detailansicht eines Accounts mit aktuellem Kontostand, verknüpften Schülern, Karten, aktiven Abos und einer lückenlosen Transaktionshistorie.
    
-   **Manuelle Buchungen:** Schulsekretariate können über Modal-Dialoge Guthaben manuell aufladen (z.B. bei Barzahlungen).
    
-   **Admin-Rollen:** Zuweisung von Berechtigungen (Elternaccount, Lehrer, Admin) über die Account-Einstellungen.
    
-   **Datenschutz (DSGVO):** Tiefe Integration für Account-Löschungen, falls Familien die Schule verlassen. Ein Schutzmechanismus verhindert, dass sich Admins versehentlich selbst löschen oder aussperren.
    

## 3. Karten-Management & Hardware-Integration

Umfassende Verwaltung der physischen NFC/RFID/Barcode-Chipkarten.

-   **Listen-Management:** Separate, filterbare Ansichten für _Aktive Karten_ und _Ausstehende Bestellungen_ (inkl. Klassen-Filter und Excel-Export).
    
-   **Zuweisung (Scanner-Modal):** Innovatives Zuweisungs-Interface für neue Karten. Unterstützt Geräte-Kameras für die **Aufnahme von Schülerfotos** (zur Identifikation an der Essensausgabe) und integrierte Erkennung von **Barcodes via Camera-Stream**.
    
-   **Status-Verwaltung:** Karten lassen sich manuell sperren, entsperren oder einziehen.
    
-   **Karten-Rückgabe (Pfand):** Wird eine Karte am Ende der Schulzeit eingesammelt, erstattet das System das in den Einstellungen definierte Kartenpfand automatisch mit einem Klick auf das jeweilige Familienguthaben zurück.
    

## 4. Transaktions- & Zahlungsverwaltung

Dieses Modul dient der sicheren Abwicklung von Offline-Zahlungen und Support-Fällen.

-   **Unbezahlte Transaktionen:** Eine durchsuchbare Liste (nach Betrag oder PIN) für Vorkasse-Zahlungen.
    
-   **Überweisungen abgleichen:** Wenn Eltern per "Überweisung" zahlen, generiert das System eine PIN (z.B. `123456`). Das Schulsekretariat gleicht Zahlungseingänge auf dem echten Bankkonto ab und markiert sie via Modal als _Bezahlt_. Die PIN und das heutige Datum werden vom System automatisch als Belegnummer vorausgefüllt.
    
-   **Rückerstattungen (Refunds):** Sicheres Stornieren von Fehlbuchungen direkt aus der Transaktionshistorie eines Users heraus. Das System korrigiert das Guthaben und erzeugt transparente Stornobuchungen.
    

## 5. System-Konfiguration

Das System ist hochdynamisch. Unter den Einstellungen im Dashboard können zentrale Parameter in isolierten Blöcken mit eigenen "Speichern"-Buttons angepasst werden, ohne den Quellcode zu verändern:

-   **Preise:** Kartenpfand (z.B. 5,00 €), Preis pro Tag im Halbjahr, Ganzjahr sowie Preise für Einzeleintritte und Nachschlag.
    
-   **Bankdaten:** Konfiguration des angezeigten Überweisungs-Empfängers (Name der Schule/Träger, IBAN und BIC).
    
-   **Rechtstexte (WYSIWYG):** Ein vollwertiger HTML-Editor (mit Formatierungs-Tools für Fett, Kursiv, Listen, Überschriften) zur Pflege von _Impressum_ und _Datenschutzerklärung_ direkt aus dem Dashboard heraus.
    

🔙 [Zurück zur Hauptseite](/docs/README.md)