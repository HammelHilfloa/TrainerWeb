# KSV Homberg Trainertool

Dieses Projekt wird komplett neu aufgebaut: ein internes Tool für die Organisation von Trainingseinheiten, die Einteilung von Trainer:innen zu Trainings und Turnieren, die Abrechnung der Einsätze sowie einen zentralen Kalender für alle Termine.

## Feature-Überblick

- **Trainingseinheiten verwalten** (Termine, Gruppen, Inhalte)
- **Trainer-Zuordnung** zu Trainings und Turnieren
- **Abrechnung der Einsätze** (Stunden, Pauschalen, Auswertungen)
- **Kalender** mit Trainings, Turnieren und sonstigen Terminen
- **Rollen & Berechtigungen** (Trainer, Admin, Abrechnung)

## Setup auf Netcup Shared Hosting

1. **Dateien per FTP hochladen**
   - Lade den kompletten Projektordner hoch.
   - Achte darauf, dass **nur** der Ordner `/public` web-öffentlich erreichbar ist.
2. **Document Root setzen**
   - In der Netcup-Verwaltung das Document Root auf `/public` legen.
3. **Installer im Browser öffnen**
   - Rufe `https://deine-domain.tld/tools/installer/` auf.
   - Der Installer wird später die Datenbank prüfen, das Schema importieren und den Admin anlegen.
4. **Konfiguration ablegen**
   - Konfigurationsdateien liegen unter `/storage/config` (nicht öffentlich).

## Ordnerstruktur

```
/public   # Einziger web-öffentlicher Ordner (index.php, assets, uploads)
/app      # Domain-Logik: Services, Repositories, Auth, Abrechnung
/views    # Templates für Seiten
/sql      # schema.sql, seed.sql
/tools    # Installer, Importer
/storage  # Nicht öffentlich: config, logs
```

## Nächste Schritte

- Authentifizierung & Rollenmodell definieren
- Datenbanktabellen in `/sql/schema.sql` aufbauen
- Erste Views und Services implementieren
