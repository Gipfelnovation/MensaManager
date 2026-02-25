# MensaPay & MensaManager

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
- [Entwickelt für](#entwickelt-für)  
- [Contributing & Lizenz](#contributing--lizenz)

---

# Projektübersicht
MensaPay digitalisiert Bestellung und Bezahlung der Schulverpflegung. Eltern verwalten Guthaben und Abonnements über ein Portal; die Schule steuert Kartenvergabe, überwacht Transaktionen und pflegt zentrale Preis-Konfigurationen. Ziel: transparente Abrechnung, geringe Verwaltungsaufwände, nachvollziehbare Transaktionen.

# Hauptfunktionen

## Benutzerportal
- **Dashboard** — Echtzeit-Übersicht über Familienguthaben und letzte Transaktionen.  
- **Abo-Verwaltung** — Buchung von Halbjahres- oder Ganzjahresabos für spezifische Wochentage.  
- **Guthaben-Management** — Aufladen via PayPal, Klarna oder Banküberweisung.  
- **Karten-Selbstverwaltung** — Sperren verlorener Karten und Beantragung von Ersatzkarten.  
- **Nutzungshistorie** — Transparente Auflistung aller Käufe; Abo-Nutzungen werden direkt im Abo-Modul gezählt.

## Admin-Bereich
- **Benutzerverwaltung** — Zentraler Überblick über registrierte Eltern, Schüler und Admins.  
- **Karten-Management** — Zuweisung physischer Chip-IDs zu Card-Holder-Profilen; Statusmanagement (`active`/`inactive`).  
- **Konfiguration** — Systemweite Preissteuerung über die Tabelle `default_values` (z. B. Kartenpfand, Abo-Preise).  
- **Berichtswesen & Monitoring** — Zahlungseingänge, Kontostände, Audit-Logs; Exportfähige Reports für Buchhaltung und Verein.

# Tech-Stack
- Frontend: React.js, Tailwind CSS, Lucide React (Icons)  
- Backend: PHP (PDO für Datenbankzugriff)  
- Zahlungsabwicklung: Server-SDK-Integration (Backend-Modul)  
- Datenbank: MySQL

# Datenbank-Struktur
Kerntabellen — Kurzbeschreibung und Verantwortlichkeiten:
- `users` — Authentifizierte Accounts für Eltern und Admins; Rollensteuerung.  
- `accounts` — Familienkonten und Saldenverwaltung.  
- `card_holders` — Schülerprofile: Zuordnung zu Accounts, Allergien/Notizen.  
- `chip_cards` — Physische Karten (chip_id, status, issued_at).  
- `subscriptions` — Aktive Abos mit Wochentagen, Gültigkeitszeiträumen und Rabattlogik.  
- `account_transactions` — Vollständiges Protokoll aller Geld- und Essensbewegungen (Audit-ready).  
- `default_values` — Zentrale Parameter (z. B. `card_deposit`, `full_year_per_day`), Quelle für alle Preisvalidierungen.

# Sicherheitshinweise  
- Alle Preise und Konditionen werden serverseitig gegen `default_values` verifiziert — Einschränkung Client-seitiger Manipulation.  
- Passwörter werden mittels `password_hash` sicher gespeichert; sensible Endpunkte erfordern Rollen-/Rechteprüfung.  
- Zahlungs- und Kontodaten sind nur über verschlüsselte Verbindungen (TLS) zu übertragen; Alle Zugriffe auf den Backend-API werden auf Rechte überprüft.

# Entwickelt für
Entwickelt für das Gymnasium Hohenschwangau.

# Contributing & Lizenz
- Contributions: Issues und PRs über GitHub; Branch-Policy: `main` = produktiv, Feature-Branches nach `feature/<ticket>`.
- Lizenz: Proprietäre Lizenz (Closed Source).

---

## Executive Summary
Produktionsreifes Konzept für digitales Mensa-Management: klare Trennung Benutzer/Admin, serverseitige Preisvalidierung, nachvollziehbare Transaktionen und zentrale Konfigurationsbasis. Weiterer Fokus: Datenschutz/DSGVO-Compliance und regelmäßige Sicherheitsupdates.
