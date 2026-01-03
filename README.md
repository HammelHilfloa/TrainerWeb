# Trainerabrechnung (Laravel-Blueprint für Shared Hosting)

Dieses Verzeichnis enthält ein schlankes, auf Laravel ausgerichtetes Startpaket für eine Trainer-Abrechnungs- und Einsatzplanung. Ziel ist eine mobil-optimierte Oberfläche für Trainer:innen, Administrator:innen und Turnierleitungen. Die Dateien sind so vorbereitet, dass sie in einer klassischen Shared-Hosting-Umgebung mit PHP 8.2+ und MySQL lauffähig sind.

## Schnellstart
1. **Neues Laravel-Projekt anlegen** (z. B. lokal):
   ```bash
   composer create-project laravel/laravel trainerabrechnung
   ```
2. **Dieses Verzeichnis kopieren** und die Inhalte in dein neues Laravel-Projekt übernehmen (bestehende Dateien überschreiben oder zusammenführen):
   - `database/schema.sql`
   - `routes/web.php`
   - `resources/views/mobile.blade.php`
   - `public/index.php` (Landing Page mit Login-Mockup)
   - `docs/architecture.md`
3. **Umgebungsvariablen setzen** (`.env`): DB-Verbindung, `APP_KEY`, Mailer, ggf. `SESSION_DRIVER` auf `database`.
4. **Migrations/Seed ausführen** (falls du die Tabellen nicht direkt per `schema.sql` importierst):
   ```bash
   php artisan migrate
   php artisan db:seed
   ```
5. **Deployment auf Webspace**: Den `public`-Ordner als Webroot nutzen, `storage` beschreibbar machen, und Cronjobs für Abrechnungs-Exports (halbjährlich) einrichten.

## Kernfunktionen
- **Login nur für registrierte Nutzer:innen**; Admin-Rolle ermöglicht Benutzer- und Trainingsverwaltung.
- **Trainingsplanung** mit eigenen Status/Gehältern pro Einheit, Einteilung durch Trainer:innen, Abwesenheitsmeldungen und einsehbaren Trainingsdetails pro Einheit.
- **Abrechnung pro Halbjahr** mit abrechenbaren Einheiten pro Trainer:in (Summen je Status und Lohnsatz).
- **Turnier- und Fahrtplanung** inklusive Teams, Zuweisungen, Reisekosten und Absagen.
- **Mobil optimierte Oberfläche** (Landing Page + Layout-Template) für Smartphones.

## Anpassung an deinen Verein
- Passe im `schema.sql` die Status, Gehaltsstufen und Turnierarten an.
- Ergänze in `web.php` weitere Routen/Controller-Namen, wenn du Features ausbauen möchtest (z. B. API-Routen, Export-Downloads).
- Nutze `docs/architecture.md` als technische Orientierung, um deine eigene Geschäftslogik in Laravel-Controller und Policies zu gießen.

## Sicherheitshinweise
- Für Login/Auth die Laravel-Authentifizierung (z. B. Breeze/Jetstream/Fortify) nutzen.
- HTTPS auf dem Webspace erzwingen, `SESSION_SECURE_COOKIE=true` setzen und lange Session-Lebensdauer vermeiden.
- Backups für DB und Storage einplanen (mind. täglich) und Admin-Aktionen auditieren (siehe `audit_logs`).
