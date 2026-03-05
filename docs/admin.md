
# 🛡️ Admininterface (Schulverwaltung)

Das Admin-Portal ist die Steuerzentrale des MensaManager-Systems. Es ist durch eine Zwei-Faktor-Authentifizierung (2FA) geschützt und bietet umfassende Werkzeuge zur Verwaltung des gesamten Mensa-Betriebs.

## 1. Dashboard & Monitoring

Das Dashboard bietet eine Echtzeit-Übersicht über alle wichtigen Metriken:

-   **Finanzen:** Aktuelle Kontostände, Zahlungseingänge der letzten 30 Tage.
    
-   **Auslastung:** Wochentags-Auswertungen der Essensausgabe (Wie viele Abos/Schüler essen am Montag, Dienstag etc.?).
    
-   **Schnellaktionen:** Direkter Zugriff auf unbestätigte Überweisungen oder anstehende Kartenausgaben.
    

## 2. Benutzerverwaltung (RBAC)

Hier werden alle registrierten Eltern, Schülerprofile und Administratoren verwaltet.

-   **Eltern-Accounts:** Einsehen von hinterlegten Stammdaten, Sperren von problematischen Accounts.
    
-   **Datenschutz (DSGVO):** Löschfunktion für Accounts, deren Kinder die Schule verlassen haben (inkl. Anonymisierung der Transaktionshistorie).
    
-   **Admin-Rollen:** Zuweisung von Berechtigungen (z.B. Lese-Rechte vs. volle Kassen-Rechte).
    

## 3. Karten-Management

Verwaltung der physischen NFC/RFID-Chipkarten.

-   **Zuweisung:** Zuweisung einer physischen Chip-ID an ein Schülerprofil über einen integrierten Barcode-/NFC-Scanner (Kamera-Support für Tablets).
    
-   **Status-Verwaltung:** Karten manuell auf _Aktiv_, _Gesperrt_ oder _Ausstehend_ setzen.
    
-   **Rückgabe:** Wird eine Karte am Ende der Schulzeit unbeschadet eingesammelt, kann über das System automatisch eine Pfanderstattung (auf das virtuelle Familienguthaben) ausgelöst werden.
    

## 4. Transaktions- & Zahlungsverwaltung

Dieses Modul dient als Buchhaltungs-Backend.

-   **Überweisungen abgleichen:** Wenn Eltern die Zahlungsmethode "Überweisung" wählen, generiert das System eine PIN (z.B. `MENSA X7A9K`). Im Admin-Bereich kann das Schulsekretariat Zahlungseingänge auf dem echten Bankkonto mit dieser PIN abgleichen und die Buchung manuell auf _Bezahlt_ setzen.
    
-   **Rückerstattungen:** Sicheres Stornieren von Fehlbuchungen oder Abos mit automatischer Guthaben-Anpassung.
    
-   **Exporte:** Generierung von CSV/Excel-Reporten für die offizielle Schulbuchhaltung.
    

## 5. System-Konfiguration

Das System ist hochdynamisch. Unter den Einstellungen können zentrale Parameter (gespeichert in der `default_values` Datenbank-Tabelle) jederzeit angepasst werden, ohne den Code anfassen zu müssen:

-   **Preise:** Kartenpfand (z.B. 5,00 €), Preis pro Tag im Halbjahr (z.B. 80,00 €) oder Ganzjahr (z.B. 120,00 €).
    
-   **Bankdaten:** Die im System angezeigte Schul-IBAN und BIC.
    
-   **Rechtstexte:** Impressum und Datenschutzerklärung können über einen integrierten WYSIWYG-Editor gepflegt werden.
    

🔙 [Zurück zur Hauptseite](./docs/README.md) | ➡️ [Weiter zur Backend-Architektur](./docs/security.md)