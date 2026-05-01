# Web-Installer

Der Installer ist dafür gedacht, zusammen mit einer Build-ZIP auf einen PHP-Webserver hochgeladen zu werden.

## Erwartete Upload-Struktur

Folgende Dateien und Ordner müssen im Webroot liegen:

- `index.php`
- `installer/`
- `shared/php/mm_security.php`
- eine ZIP-Datei mit den drei Top-Level-Ordnern `user`, `lehrer`, `admin`

## ZIP-Struktur

Die ZIP-Datei muss nach dem Entpacken genau diese Ordner enthalten:

```text
user/
lehrer/
admin/
```

Ein zusätzlicher Wrapper-Ordner in der ZIP ist erlaubt, solange sich darin wieder genau diese drei Ordner befinden.

## Ablauf

1. Website aufrufen.
2. Der Installer entpackt die ZIP-Datei und prüft die Struktur.
3. Im Wizard Installationsart, URLs, Datenbank und Integrationen eintragen.
4. Der Installer schreibt `shared/.env`, schützt `/shared` und erzeugt die Runtime-Konfigurationen.

## Packaging-Skript

Zum Erzeugen des Upload-Pakets gibt es das Skript `scripts/package-release.ps1`.

Beispiel:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\package-release.ps1 -InstallDependencies
```

Das Skript erzeugt:

- ein inneres App-Bundle `mensamanager-apps.zip` mit den Ordnern `user`, `lehrer`, `admin`
- einen Upload-Ordner mit `index.php`, `installer/`, `shared/php/` und dem inneren Bundle
- optional ein äußeres Upload-ZIP für den kompletten Server-Upload

Nützliche Schalter:

- `-SkipBuild` wenn die drei `dist`-Ordner bereits vorhanden sind
- `-InstallDependencies` um fehlende `node_modules` per `npm ci` automatisch zu installieren
- `-SkipOuterZip` wenn nur der Upload-Ordner und das innere Bundle benötigt werden

## Installationsmodi

### Unterordner

- User-Portal im Root
- Lehrer unter `/lehrer/`
- Admin unter `/admin/`

Der Installer kopiert dafür die nötigen Root-Dateien aus `user/` nach oben und setzt die API-Basen auf:

- User: `/api`
- Lehrer: `/lehrer/api`
- Admin: `/admin/api`

### Subdomains

- User z. B. `https://www.xy.de`
- Lehrer z. B. `https://lehrer.xy.de`
- Admin z. B. `https://admin.xy.de`

Der Installer erzeugt zusätzlich eine Apache-`/.htaccess`, falls alle Subdomains auf denselben Webroot zeigen. Alternativ können die DocumentRoots direkt auf die Ordner `user/`, `lehrer/` und `admin/` zeigen.

## Wichtige Hinweise

- Für das Entpacken muss auf dem Server `ZipArchive` verfügbar sein.
- Der Installer sperrt sich nach erfolgreicher Einrichtung über `shared/.installer-lock.json`.
- Zum erneuten Ausführen muss diese Lock-Datei bewusst entfernt werden.
- Falls der Zielserver nicht Apache nutzt, sollten die Routing-Regeln für Subdomains gegebenenfalls manuell nachgezogen werden.
