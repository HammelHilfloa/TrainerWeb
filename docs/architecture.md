# Architektur-Notizen

## Module & Verantwortlichkeiten
- **Auth & Rollen**: `roles`, `users`; Policies für Admin/Trainer. Login via Laravel-Auth (z. B. Breeze/Fortify). HTTPS & Session-Härtung (Secure/CORS/SameSite=Lax).
- **Trainerprofil**: `trainers`, `trainer_statuses`; Gehaltsstufen pro Status, IBAN/BIC für Abrechnung.
- **Trainingsverwaltung**: `training_groups`, `training_locations`, `training_sessions`; Admin erstellt/ändert Sessions und pflegt den Trainingsplan (`plan_details`), Trainer:innen sehen Slots und die dazugehörigen Pläne.
- **Einteilungen & Absagen**: `training_assignments`, `trainer_unavailabilities`, `cancellations`; Trainer:innen können sich einteilen oder Abwesenheit melden, Admin kann umbuchen.
- **Turniere & Fahrten**: `tournaments`, `tournament_teams`, `tournament_assignments`, `tournament_trips`, `tournament_trip_segments`.
- **Abrechnung**: `invoices`, `invoice_items`; Halbjahresfilter (H1/H2) erzeugt abrechenbare Einheiten, Summierung nach Lohnsatz.
- **Audit**: `audit_logs` für alle Admin-Aktionen und Abrechnungs-Events.

## Empfohlene Laravel-Umsetzung
- **Middleware**: `auth`, `verified`, `role:admin` für Verwaltungsrouten.
- **Policies**: `TrainingSessionPolicy`, `TrainerAvailabilityPolicy`, `InvoicePolicy`.
- **Trainingsplan**: Feld `plan_details` in `training_sessions` sowie Routen `trainings.show` (sichtbar für alle eingeloggten Nutzer:innen) und `trainings.plan` (nur Admin über Policy/Middleware). `planned_by` protokolliert die letzte Admin-Änderung.
- **Jobs/Queues**: Generierung der Halbjahresabrechnung als Queue Job (CSV/PDF Export in `storage/app/reports`).
- **Form Requests**: Validierung für Sessions, Turniere, Abwesenheiten.
- **Eager Loading**: `with(['group','location','assignments.trainer.user'])` bei Listen, um DB-Zugriffe gering zu halten.
- **Caching**: Wiederkehrende Einheiten und Tarife (Status -> hourly_rate) als Cache-Einträge mit invaliderung bei Updates.

## Mobil-optimiertes UI
- Basis-Layout in `resources/views/mobile.blade.php` mit flexiblen Karten, Touch-großen Buttons und Sticky-Navigation.
- Login-Section auf Landing Page (`public/index.php`) mit prominentem CTA "Jetzt einloggen".
- Tabellen durch Karten ersetzen, Filter/Tagging für Halbjahr, Status und Standort.
- Dark/Light-fähig dank CSS-Variablen, große Touch-Flächen (mind. 44x44 px).

## Deployment auf Shared Hosting
1. Repo per SFTP/SSH hochladen; `public` als Webroot konfigurieren.
2. `.env` setzen, `php artisan key:generate`, `php artisan migrate --force`.
3. Scheduler per Cron: `php artisan schedule:run` alle 5 Minuten, Queue per `php artisan queue:work --tries=3` (oder Sync Driver für Shared Hosting).
4. Backups: täglicher MySQL-Dump + Storage-Sicherung, z. B. via `mysqldump` + `tar`.

## Datenfluss (Kurz)
1. Admin legt Training an → Trainer:innen sehen Slot.
2. Trainer:in bucht sich ein oder meldet Abwesenheit → Einträge in `training_assignments` oder `trainer_unavailabilities`.
3. Absage/Änderung → `cancellations` + Push-Mail an gebuchte Trainer:innen.
4. Halbjahreslauf → Job sammelt `training_assignments` (Status `zugeteilt`, Zeitraum H1/H2) → `invoice_items` → Export/Versand.
