
# MensaManager

Ein integriertes System zur Verwaltung der Schulverpflegung — Benutzerportal für Eltern/Schüler und Administrationsbereich für die Schule. Unterstützt Mensa-Abos, Prepaid-Guthaben und physische Chipkartenverwaltung.

---

## Inhaltsverzeichnis
- [Projektübersicht](#projektübersicht)  
- [Hauptfunktionen](#hauptfunktionen)  
  - [Benutzerportal](#benutzerportal)  
  - [Admin-Bereich](#admin-bereich)  
- [Tech-Stack](#tech-stack)  
- [Datenbank-Struktur](#datenbank-struktur)  
- [Sicherheitshinweise](#sicherheitshinweise)   
- [Contributing & Lizenz](#contributing--lizenz)

---

# Projektübersicht
MensaManager digitalisiert Bestellung und Bezahlung der Schulverpflegung. Eltern verwalten Guthaben und Abonnements über ein Portal; die Schule steuert Kartenvergabe, überwacht Transaktionen und pflegt zentrale Preis-Konfigurationen. Ziel: transparente Abrechnung, geringe Verwaltungsaufwände, nachvollziehbare Transaktionen.

➡️ [Zur vollständigen Dokumentation](/docs/README.md)

# Hauptfunktionen

## Benutzerportal
-   **Dashboard** — Echtzeit-Übersicht über das Familienguthaben und die letzten Transaktionen
-   **Abo-Verwaltung** — Buchung von Halbjahres- oder Ganzjahresabos für spezifische Wochentage
-   **Guthaben-Management** — Nahtlose Aufladung via PayPal, Klarna oder Banküberweisung (mit automatisiert generierter Verwendungszweck-PIN)
-   **Karten-Selbstverwaltung** — Beantragung neuer Karten, temporäres Sperren bei Verlust und Beantragung von Ersatzkarten inkl. Pfandverrechnung
-   **Nutzungshistorie** — Transparente Auflistung aller Käufe; Abo-Nutzungen werden direkt im Abo-Modul tagesaktuell gezählt.

## Admin-Bereich
- **Benutzerverwaltung** — Zentraler Überblick über registrierte Eltern, Schüler und Admins inklusive strikter Rollenverteilung und DSGVO-konformer Löschfunktion
- **Karten-Management** — Zuweisung physischer Chip-IDs über integrierten Barcode-Scanner und Kamera; Statusmanagement (Aktiv, Gesperrt, Bestellt) sowie automatische Pfanderstattung beim Einsammeln
- **Transaktions- & Zahlungsverwaltung** — Manuelle Bestätigung von Barzahlungen/Überweisungen via PIN, Bearbeitung unbezahlter Transaktionen und sichere Rückerstattungen
- **Konfiguration** — Systemweite Steuerung von Preisen, Bankverbindungen und rechtlichen Texten (via integriertem WYSIWYG-Editor) über die Tabelle `default_values`
- **Berichtswesen & Monitoring** — Detailliertes Dashboard, Zahlungseingänge, Kontostände, Wochentags-Auswertungen und exportfähige Excel/CSV-Reports für die Buchhaltung

# Tech-Stack
- Frontend: React.js, Tailwind CSS, Lucide React (Icons)  
- Backend: PHP (PDO für Datenbankzugriff)  
- Zahlungsabwicklung: PayPal Server-SDK & Klarna Payments API (Backend-verifiziert) 
- Datenbank: MySQL

# Datenbank-Struktur
Kerntabellen — Kurzbeschreibung und Verantwortlichkeiten:
- `users` — Authentifizierte Accounts für Eltern und Admins; Rollensteuerung sowie TOTP-Secrets für 2FA
- `accounts` — Familienkonten und Saldenverwaltung
- `card_holders` — Schülerprofile: Zuordnung zu Eltern-Accounts (`created_by`)
- `chip_cards` — Physische Karten (chip_id, status, issued_at)
- `subscriptions` — Aktive Abos mit Wochentagen, Gültigkeitszeiträumen und Rabattlogik
- `account_transactions` —Vollständiges Protokoll aller Geld- und Essensbewegungen (inklusive `admin_id` für Audit-Logs)
- `default_values` — Zentrale Parameter (z. B. `card_deposit`, `full_year_per_day`), Quelle für alle Preisvalidierungen

# Sicherheitshinweise  

Das System ist nach dem Prinzip "Zero Trust" (Backend vertraut keinen Client-Eingaben) und "Defense in Depth" aufgebaut:

-   **Authentifizierung, Sessions & Anti-Brute-Force:** - Zweistufiger Login mit obligatorischer **Zwei-Faktor-Authentifizierung (2FA/TOTP)** für Administratoren.
    
    -   Effektiver Schutz vor Brute-Force und Credential Stuffing durch ein **IP-basiertes Rate-Limiting** (Sperrung nach 5 Fehlversuchen für 15 Minuten), eine künstliche Latenz (`sleep(1)`) bei Fehlschlägen und ein vorgeschaltetes **hCaptcha**.
        
    -   Gehärtete Session-Cookies (`HttpOnly`, `Secure`, `SameSite=Strict`) und aktiver Schutz vor Session-Fixation via `session_regenerate_id(true)` bei jedem Login. Passwörter werden sicher mittels `password_hash()` (Bcrypt/Argon2) gespeichert und strenge Passwortrichtlinien (Regex) werden bereits bei der Registrierung erzwungen.
        
-   **API- & Daten-Sicherheit (CORS & IDOR):** - Striktes **CORS-Setup** (Whitelist exakter Domains statt Wildcards) verhindert das Abgreifen von Daten durch fremde Websites.
    
    -   Konsequenter Schutz vor **IDOR (Insecure Direct Object References)**: Bei jeder API-Anfrage (z.B. Kartensperrung, Abo-Kauf) wird serverseitig über SQL-Subselects (`WHERE created_by = ?`) verifiziert, ob die manipulierte Objekt-ID (z.B. `holder_id`) auch wirklich dem authentifizierten Benutzer gehört.
        
-   **Transaktionssicherheit & Race Conditions:** - Verhinderung von doppelten Guthabenabzügen (**Race Conditions**) durch strenge Zeilen-Sperren in der Datenbank (`SELECT ... FOR UPDATE`). Konkurrierende Anfragen auf denselben Kontostand werden blockiert, bis die laufende Transaktion abgeschlossen ist.
    
    -   Sichere Datenbank-Transaktionen (`$pdo->beginTransaction()`) nach dem ACID-Prinzip.
        
    -   Strikte Input-Validierung: Aufladebeträge werden serverseitig auf realistische Limits (0.01€ bis 1000€) geprüft, um Integer-Overflows oder das Einschleusen negativer Beträge auszuhebeln.
        
    -   Revisionssicheres **Audit-Log** (Speicherung der ausführenden Admin-ID bei jeder schreibenden Finanzaktion).
        
-   **Schutz vor Web-Schwachstellen (SQLi & XSS):** - Einsatz von CSRF-Prävention durch `SameSite=Strict`-Cookies und CORS-Richtlinien für alle schreibenden API-Anfragen.
    
    -   Vollständiger Schutz vor SQL-Injection durch ausnahmslose Verwendung von **PDO Prepared Statements**.
        
    -   Prävention von Cross-Site-Scripting (XSS) durch serverseitige Bereinigung von Payloads (`htmlspecialchars(strip_tags(...))`) sowie automatisches Output-Encoding durch das React-Frontend.
        
-   **Architektur (Zero Client-Side Trust):** - Alle Preise, Gebühren und Konditionen werden ausnahmslos serverseitig gegen die Tabelle `default_values` berechnet. Das Backend akzeptiert keine Preisvorgaben aus dem manipulierbaren Frontend. Zahlungs-Intents (PayPal/Klarna) werden sicher in der Backend-Session zwischengespeichert und verifiziert.

# Contributing & Lizenz
- Contributions: Issues und PRs über GitHub; Branch-Policy: `main` = produktiv, Feature-Branches nach `feature/<ticket>`.
- Dieses Projekt ist lizenziert unter der **GNU GPLv3**. Weitere Details findest du in der [LICENSE](LICENSE) Datei in diesem Repository.
