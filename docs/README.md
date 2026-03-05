
# MensaPay & MensaManager - Dokumentation

Willkommen in der offiziellen Dokumentation für das **MensaPay & MensaManager** Projekt. Diese Dokumentation richtet sich sowohl an Endanwender und Administratoren (Frontend-Nutzung) als auch an Entwickler (technische Backend-Details).

Das System digitalisiert die Bestellung und Bezahlung der Schulverpflegung am Gymnasium Hohenschwangau. Es bietet ein intuitives Portal für Eltern zur Verwaltung von Guthaben und Abonnements sowie einen umfassenden Administrationsbereich für die Schule.

## 📚 Inhaltsverzeichnis

### 1. Frontend-Dokumentation (Bedienungsanleitung)

Hier findest du Anleitungen zur Bedienung der verschiedenen Benutzeroberflächen.

-   [🧑‍💻 Benutzerinterface (Eltern & Schüler)](./user/frontend.md)
    
-   [🎓 Lehrerinterface (Mensa-Ausgabe)](./lehrer/frontend.md)
    
-   [🛡️ Admininterface (Schulverwaltung)](./admin/frontend.md)
    

### 2. Technische Dokumentation (Backend & API)

Das Backend ist modular aufgebaut. Da jedes Interface über eigene Berechtigungsstrukturen und Endpunkte verfügt, ist die technische Dokumentation (Architektur & API) nach den drei Systembereichen getrennt:

-   [🧑‍💻 Backend: Benutzerinterface](./user/backend.md) _(Eltern/Schüler API)_
    
-   [🎓 Backend: Lehrerinterface](./lehrer/backend.md) _(Mensa-Ausgabe API)_
    
-   [🛡️ Backend: Admininterface](./admin/backend.md) _(Schulverwaltung API)_
    

## 🛠️ Tech-Stack Übersicht

-   **Frontend:** React.js, Tailwind CSS, Lucide React (Icons)
    
-   **Backend:** PHP 8+ (PDO)
    
-   **Datenbank:** MySQL / MariaDB
    
-   **Zahlungsanbieter:** PayPal Server-SDK, Klarna Payments API
    

_Erstellt für das Gymnasium Hohenschwangau._