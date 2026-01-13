# HTML-Import-Tool

Das Tool `tools/import_html_seeds.php` importiert die HTML-Sheets aus `database/` in die Datenbank. Die erste Zeile der Tabelle wird als Spaltennamen verwendet. Du kannst das Tool per Browser (Admin-Login erforderlich) oder per CLI ausführen. Im Installer kann das Tool durch Setzen von `INSTALLER_MODE` eingebunden werden.

## Dateien und Tabellen-Mapping

| HTML-Datei | Tabelle | Hinweise |
| --- | --- | --- |
| `TRAINER.html` | `trainer` | Primärschlüssel `trainer_id`. |
| `ROLLEN_SAETZE.html` | `rollen_saetze` | Primärschlüssel `rolle`. |
| `TRAININGS.html` | `trainings` | Primärschlüssel `training_id`. |
| `EINTEILUNGEN.html` | `einteilungen` | Primärschlüssel `einteilung_id`, prüft `training_id` & `trainer_id`. |
| `ABMELDUNGEN.html` | `abmeldungen` | Primärschlüssel `abmeldung_id`, prüft `training_id` & `trainer_id`. |
| `TRAININGSPLAN.html` | `trainingsplan` | Primärschlüssel `plan_id`, prüft `training_id`, `created_by`, `updated_by`. |
| `TURNIERE.html` | `turniere` | Primärschlüssel `turnier_id`. |
| `TURNIER_EINSAETZE.html` | `turnier_einsaetze` | Primärschlüssel `turnier_einsatz_id`, prüft `turnier_id` & `trainer_id`. |
| `FAHRTEN.html` | `fahrten` | Primärschlüssel `fahrt_id`, prüft `turnier_id` & `fahrer_trainer_id`. |
| `MITFAHRER.html` | `mitfahrer` | Primärschlüssel `mitfahrer_id`, prüft `fahrt_id` & `trainer_id`. |
| `DASHBOARD.html` | – | Kein Import (Übersicht, keine Tabelle). |
| `ABRECHNUNG_TRAINING.html` | – | Kein Import (Abrechnung ist eine Auswertung). |
| `ABRECHNUNG_TURNIER.html` | – | Kein Import (Abrechnung ist eine Auswertung). |

## Idempotenz

Bei identischen Primärschlüsseln werden bestehende Datensätze aktualisiert (Upsert via `ON DUPLICATE KEY UPDATE`). Leere oder unvollständige Zeilen werden übersprungen.

## Dry-Run

Mit `--dry-run` (CLI) oder dem Formularfeld `dry_run` (Web) wird nur angezeigt, wie viele Zeilen importiert oder aktualisiert würden, ohne Änderungen zu speichern.

## Voraussetzungen

* Datenbankzugang über `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`.
* Admin-Login erforderlich (Trainer mit `is_admin = 1`).

## Beispiele

CLI:

```bash
php tools/import_html_seeds.php --trainer-id=T001 --pin=1234 --dry-run
```

Installer (Beispiel für Einbindung):

```php
define('INSTALLER_MODE', true);
require __DIR__ . '/../import_html_seeds.php';
```
