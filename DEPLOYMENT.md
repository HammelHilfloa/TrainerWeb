# Deployment (Webspace)

## Konfiguration

Die PHP-API liest ihre Konfiguration aus Umgebungsvariablen. Optional können `.env` oder `.env.local` im Repo-Root abgelegt werden (wird beim Laden eingelesen). Ein Beispiel steht in `.env.example`.

Wichtige Werte:

- `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`
- `SESSION_TTL_SECONDS` (Session-Lebensdauer in Sekunden)
- `APP_TIMEZONE` (z. B. `Europe/Berlin`)
- Apps-Script-Konfiguration als ENV:
  - `SPREADSHEET_ID`
  - `SHEET_TRAINER`, `SHEET_TRAININGS`, `SHEET_EINTEILUNGEN`, `SHEET_ABMELDUNGEN`
  - `SHEET_TRAININGSPLAN`, `SHEET_ROLLEN_SAETZE`
  - `SHEET_TURNIERE`, `SHEET_TURNIER_EINSAETZE`, `SHEET_FAHRTEN`

## Benötigte Server-Module

- PHP 8.x
- Erweiterungen: `pdo_mysql`, `json`
- Empfohlen: `mbstring`, `openssl` (für sichere Token/PINs)

## Deployment-Checkliste

1. **Datenbank anlegen**
   - Lege eine MySQL/MariaDB-Datenbank an und setze Benutzer/Passwort.
   - Trage die DB-Zugangsdaten in die ENV-Konfiguration ein.
2. **Schema & Initialimport**
   - Importiere die Tabellen aus `database/schema.sql`.
   - Führe den Import der HTML-Tabellen mit `scripts/import_html_tables.py` aus (optional, falls Daten aus den HTML-Dateien übernommen werden sollen).
3. **Dateien hochladen**
   - Lege auf dem Webspace einen `public/`-Ordner an (Document Root).
   - Lade `public/` inklusive `public/api/` hoch. Dort liegen `index.php`, `style.html` und die `ui_*.html` Dateien.
4. **ENV konfigurieren**
   - Setze Umgebungsvariablen im Hosting-Panel oder lege `.env`/`.env.local` an.
   - Prüfe `APP_TIMEZONE` und `SESSION_TTL_SECONDS`.
5. **Zugriff testen**
   - Rufe `/api/bootstrap` auf und prüfe, ob die Verbindung zur DB klappt.
6. **Optional: Cronjobs**
   - Optionaler Cronjob zur Bereinigung alter Sessions (z. B. tägliches Löschen abgelaufener Einträge in `sessions`).
