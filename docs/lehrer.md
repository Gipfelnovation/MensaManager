
# 🎓 Lehrerinterface (Mensa-Ausgabe & Kartenverwaltung)

Dieses Interface richtet sich an Lehrkräfte und das Verwaltungspersonal, welches für die Ausgabe und Rücknahme der physischen Chipkarten (RFID/NFC) an die Schüler zuständig ist.

> **Wichtiger Hinweis:** Das Lehrerinterface ist eine reduzierte Ansicht des Admin-Portals und erfordert spezielle Berechtigungen (Rolle: "TEACHER").

## 1. Kartenausgabe (Ersteinrichtung & Ersatzkarten)

Wenn Eltern im Benutzerportal ein neues Schülerprofil anlegen (oder eine Ersatzkarte beantragen), wird der Status des Schülers im System auf **"Karte ausstehend"** gesetzt. Die physische Zuordnung der Karte erfolgt durch die Lehrkraft.

**Ablauf der Ausgabe:**

1.  Der Schüler meldet sich bei der Lehrkraft (z.B. im Sekretariat oder an der Essensausgabe) und nennt seinen Namen/Klasse.
    
2.  Die Lehrkraft sucht das Profil im Lehrerinterface über die Suchleiste.
    
3.  Klick auf den Button **`Karte zuweisen`**.
    
4.  **Scannen:** Die Lehrkraft nimmt eine unprogrammierte/freie Chipkarte aus dem Schulbestand und scannt den Barcode oder RFID-Chip der Karte (via angeschlossenem Scanner oder Tablet-Kamera).
    
5.  Das System verknüpft die `chip_id` mit dem Schülerprofil. Der Status springt auf **"Aktiv"**. Der Schüler kann ab sofort mit der Karte in der Mensa essen.
    

## 2. Einsammeln und Rückgabe von Karten

Wenn ein Schüler die Schule verlässt oder nicht mehr in der Mensa essen möchte, muss die Karte zurückgegeben werden, damit die Eltern ihr hinterlegtes Kartenpfand zurückerhalten.

**Ablauf der Rücknahme:**

1.  Der Schüler gibt die physische Karte bei der Lehrkraft ab.
    
2.  Die Lehrkraft öffnet das Modul **`Karte einsammeln`** im Lehrerinterface.
    
3.  **Scannen:** Die zurückgegebene Karte wird gescannt.
    
4.  Das System erkennt den Besitzer der Karte automatisch und zeigt die Details an (Name, Klasse, gezahltes Pfand).
    
5.  Durch Klick auf **`Rücknahme bestätigen`** passieren zwei Dinge gleichzeitig:
    
    -   Die Chipkarte wird vom Schülerprofil getrennt und als "Frei" markiert (sie kann für den nächsten Schüler wiederverwendet werden).
        
    -   Das System verbucht das Kartenpfand (z.B. 5,00 €) automatisch als Guthaben-Rückerstattung auf das Account-Guthaben der Eltern.
        

🔙 [Zurück zur Hauptseite](/docs/README.md)
