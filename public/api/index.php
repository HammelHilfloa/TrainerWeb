<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/utils.php';
require_once __DIR__ . '/lib/auth.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = rtrim($path ?? '/', '/');
$path = preg_replace('#^/api#', '', $path ?? '');
$body = read_json_body();

route($method, $path, $body);

function route(string $method, string $path, array $body): void
{
    $routes = [
        ['GET', '#^/trainers/active$#', 'handle_list_active_trainers'],
        ['GET', '#^/trainers/active/names$#', 'handle_list_active_trainer_names'],
        ['POST', '#^/login$#', 'handle_login'],
        ['POST', '#^/login/name$#', 'handle_login_name'],
        ['POST', '#^/logout$#', 'handle_logout'],
        ['GET', '#^/bootstrap$#', 'handle_bootstrap'],
        ['GET', '#^/me$#', 'handle_me'],
        ['PUT', '#^/me$#', 'handle_update_me'],
        ['POST', '#^/me/pin$#', 'handle_change_pin'],
        ['GET', '#^/trainings/([^/]+)$#', 'handle_training_details'],
        ['POST', '#^/trainings/([^/]+)/enroll$#', 'handle_enroll'],
        ['POST', '#^/enrollments/([^/]+)/withdraw$#', 'handle_withdraw'],
        ['POST', '#^/enrollments/([^/]+)/checkin$#', 'handle_checkin'],
        ['POST', '#^/trainings/([^/]+)/status$#', 'handle_admin_training_status'],
        ['GET', '#^/turniere$#', 'handle_turniere_list'],
        ['GET', '#^/turniere/([^/]+)$#', 'handle_turnier_details'],
        ['POST', '#^/turniere/([^/]+)/enroll$#', 'handle_turnier_enroll'],
        ['POST', '#^/turnier-einsaetze/([^/]+)/withdraw$#', 'handle_turnier_withdraw'],
        ['POST', '#^/turniere/([^/]+)/unavailable$#', 'handle_turnier_unavailable'],
        ['DELETE', '#^/turniere/([^/]+)/unavailable$#', 'handle_turnier_unavailable_delete'],
        ['POST', '#^/turniere/([^/]+)/checkin$#', 'handle_turnier_checkin'],
        ['GET', '#^/admin/turniere$#', 'handle_admin_turniere_list'],
        ['POST', '#^/admin/turniere$#', 'handle_admin_turnier_upsert'],
        ['DELETE', '#^/admin/turniere/([^/]+)$#', 'handle_admin_turnier_delete'],
        ['POST', '#^/admin/trainings/([^/]+)/plan$#', 'handle_admin_training_plan_upsert'],
        ['DELETE', '#^/admin/trainings/plan/([^/]+)$#', 'handle_admin_training_plan_delete'],
        ['GET', '#^/billing/halfyear$#', 'handle_billing_halfyear'],
        ['POST', '#^/trainings/([^/]+)/unavailable$#', 'handle_training_unavailable'],
        ['DELETE', '#^/trainings/([^/]+)/unavailable$#', 'handle_training_unavailable_delete'],
        ['GET', '#^/admin/dashboard$#', 'handle_admin_dashboard'],
        ['GET', '#^/admin/trainings$#', 'handle_admin_trainings_list'],
        ['POST', '#^/admin/trainings$#', 'handle_admin_trainings_upsert'],
        ['DELETE', '#^/admin/trainings/([^/]+)$#', 'handle_admin_trainings_delete'],
        ['GET', '#^/admin/roles$#', 'handle_admin_roles_list'],
        ['GET', '#^/admin/trainers$#', 'handle_admin_trainers_list'],
        ['POST', '#^/admin/trainers$#', 'handle_admin_trainers_upsert'],
        ['POST', '#^/admin/trainers/([^/]+)/reset-pin$#', 'handle_admin_trainer_reset_pin'],
        ['GET', '#^/admin/billing/overview$#', 'handle_admin_billing_overview'],
        ['POST', '#^/admin/trainers/migrate-pins$#', 'handle_admin_migrate_pins'],
    ];

    foreach ($routes as [$verb, $pattern, $handler]) {
        if ($verb !== $method) {
            continue;
        }
        if (preg_match($pattern, $path, $matches)) {
            array_shift($matches);
            call_user_func($handler, $body, ...$matches);
            return;
        }
    }

    json_response(['ok' => false, 'error' => 'Endpoint nicht gefunden.'], 404);
}

function handle_list_active_trainers(array $body = []): void
{
    $pdo = db();
    $stmt = $pdo->query('SELECT trainer_id, name, email FROM trainer WHERE aktiv = 1 ORDER BY name');
    $items = array_map(fn($row) => [
        'trainer_id' => (string)$row['trainer_id'],
        'name' => (string)$row['name'],
        'email' => (string)($row['email'] ?? ''),
    ], $stmt->fetchAll());
    json_response(['ok' => true, 'items' => $items]);
}

function handle_list_active_trainer_names(array $body = []): void
{
    $pdo = db();
    $stmt = $pdo->query('SELECT name FROM trainer WHERE aktiv = 1 ORDER BY name');
    $rows = $stmt->fetchAll();
    $items = array_map(fn($row) => ['name' => (string)$row['name']], $rows);
    $names = array_map(fn($row) => (string)$row['name'], $rows);
    json_response(['ok' => true, 'items' => $items, 'names' => $names]);
}

function find_trainer_matches(array $trainers, string $identifier, bool $nameOnly): array
{
    $search = trim($identifier);
    if ($search === '') {
        return [];
    }

    if (!$nameOnly) {
        foreach ($trainers as $row) {
            if ((string)$row['trainer_id'] === $search) {
                return [$row];
            }
        }
    }

    $needle = normalize_name($search);
    $matches = [];
    foreach ($trainers as $row) {
        if (normalize_name((string)$row['name']) === $needle) {
            $matches[] = $row;
        }
    }
    return $matches;
}

function build_login_candidates(array $matches, bool $includeEmail): array
{
    return array_map(function ($row) use ($includeEmail) {
        $candidate = [
            'trainer_id' => (string)$row['trainer_id'],
            'name' => (string)$row['name'],
        ];
        if ($includeEmail) {
            $candidate['email'] = (string)($row['email'] ?? '');
        }
        return $candidate;
    }, $matches);
}

function handle_login(array $body): void
{
    $identifier = trim((string)param($body, 'identifier', ''));
    $pin = (string)param($body, 'pin', '');
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM trainer');
    $stmt->execute();
    $trainers = $stmt->fetchAll();

    $matches = find_trainer_matches($trainers, $identifier, false);

    if (!$matches) {
        json_response(['ok' => false, 'error' => 'Trainer nicht gefunden.']);
    }
    if (count($matches) > 1) {
        json_response(['ok' => false, 'error' => 'Name nicht eindeutig.', 'candidates' => build_login_candidates($matches, false)]);
    }

    $trainer = $matches[0];
    json_response(perform_login($trainer, $pin));
}

function handle_login_name(array $body): void
{
    $name = trim((string)param($body, 'name', ''));
    $pin = (string)param($body, 'pin', '');
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM trainer WHERE aktiv = 1');
    $stmt->execute();
    $trainers = $stmt->fetchAll();

    $matches = find_trainer_matches($trainers, $name, true);

    if (!$matches) {
        json_response(['ok' => false, 'error' => 'Trainer nicht gefunden.']);
    }
    if (count($matches) > 1) {
        json_response(['ok' => false, 'error' => 'Name nicht eindeutig.', 'candidates' => build_login_candidates($matches, true)]);
    }

    json_response(perform_login($matches[0], $pin));
}

function perform_login(array $trainer, string $pin): array
{
    if (!truthy($trainer['aktiv'])) {
        return ['ok' => false, 'error' => 'Trainer ist nicht aktiv.'];
    }
    $storedPin = trim((string)($trainer['pin'] ?? ''));
    if (!verify_pin($pin, $storedPin)) {
        return ['ok' => false, 'error' => 'PIN falsch.'];
    }

    $pdo = db();
    if ($pin !== '' && $storedPin !== '' && !is_hashed_pin($storedPin)) {
        $stmt = $pdo->prepare('UPDATE trainer SET pin = :pin WHERE trainer_id = :trainer_id');
        $stmt->execute([
            ':pin' => hash_pin($pin),
            ':trainer_id' => $trainer['trainer_id'],
        ]);
    }
    $stmt = $pdo->prepare('UPDATE trainer SET last_login = CURRENT_DATE WHERE trainer_id = :trainer_id');
    $stmt->execute([':trainer_id' => $trainer['trainer_id']]);

    $roleRates = load_role_rates();
    $rolle = (string)($trainer['rolle_standard'] ?? 'Trainer');
    $rate = $roleRates[$rolle]['rate'] ?? (float)($trainer['stundensatz_eur'] ?? 0);

    $token = create_token();
    $session = [
        'trainer_id' => (string)$trainer['trainer_id'],
        'email' => (string)($trainer['email'] ?? ''),
        'name' => (string)($trainer['name'] ?? ''),
        'is_admin' => truthy($trainer['is_admin'] ?? false),
        'rolle_standard' => $rolle,
        'stundensatz' => (float)$rate,
        'notizen' => (string)($trainer['notizen'] ?? ''),
    ];
    save_session($token, $session);
    return ['ok' => true, 'token' => $token, 'user' => $session];
}

function load_role_rates(): array
{
    $pdo = db();
    $stmt = $pdo->query('SELECT rolle, stundensatz_eur, abrechenbar FROM rollen_saetze');
    $rows = $stmt->fetchAll();
    $map = [];
    foreach ($rows as $row) {
        $map[(string)$row['rolle']] = [
            'role' => (string)$row['rolle'],
            'rate' => (float)$row['stundensatz_eur'],
            'billable' => truthy($row['abrechenbar']),
        ];
    }
    return $map;
}

function handle_logout(array $body): void
{
    $token = (string)param($body, 'token', '');
    clear_session($token);
    json_response(['ok' => true]);
}

function handle_bootstrap(array $body): void
{
    $token = (string)param($body, 'token', '');
    $user = require_session($token);
    $pdo = db();

    $trainings = $pdo->query('SELECT * FROM trainings')->fetchAll();
    $einteilungen = $pdo->query('SELECT * FROM einteilungen')->fetchAll();
    $abmeldungen = $pdo->query('SELECT * FROM abmeldungen')->fetchAll();

    $myAbm = array_values(array_filter($abmeldungen, fn($row) => (string)$row['trainer_id'] === (string)$user['trainer_id'] && empty($row['deleted_at'])));
    $unavailSet = array_flip(array_map(fn($row) => (string)$row['training_id'], $myAbm));

    $today = new DateTimeImmutable('today');
    $upcoming = [];
    $allTrainings = [];
    foreach ($trainings as $row) {
        $datum = parse_date($row['datum'] ?? null);
        if (!$datum) {
            continue;
        }
        $enriched = enrich_training($row, $einteilungen);
        $enriched['is_unavailable'] = isset($unavailSet[(string)$row['training_id']]);
        $allTrainings[] = $enriched;
        if (($row['status'] ?? '') === 'geplant' && $datum >= $today) {
            $upcoming[] = $enriched;
        }
    }
    usort($upcoming, fn($a, $b) => $a['datumTs'] <=> $b['datumTs']);
    usort($allTrainings, fn($a, $b) => $a['datumTs'] <=> $b['datumTs']);

    $mineActive = array_values(array_filter($einteilungen, fn($row) => (string)$row['trainer_id'] === (string)$user['trainer_id'] && empty($row['ausgetragen_am'])));
    $mineActive = array_map(fn($row) => enrich_einteilung($row, $trainings), $mineActive);
    usort($mineActive, fn($a, $b) => $a['trainingDatumTs'] <=> $b['trainingDatumTs']);

    $myUnavailable = array_map(function ($row) use ($trainings) {
        $tr = null;
        foreach ($trainings as $t) {
            if ((string)$t['training_id'] === (string)$row['training_id']) {
                $tr = $t;
                break;
            }
        }
        if (!$tr) {
            return ['training_id' => (string)$row['training_id'], 'label' => (string)$row['training_id']];
        }
        $date = parse_date($tr['datum'] ?? null);
        $label = sprintf('%s · %s–%s · %s', format_date($date), format_time($tr['start'] ?? ''), format_time($tr['ende'] ?? ''), (string)($tr['gruppe'] ?? ''));
        return ['training_id' => (string)$row['training_id'], 'label' => $label];
    }, $myAbm);

    $turniereRaw = $pdo->query('SELECT * FROM turniere')->fetchAll();
    $einsaetze = $pdo->query('SELECT * FROM turnier_einsaetze')->fetchAll();
    $fahrten = $pdo->query('SELECT * FROM fahrten')->fetchAll();

    $turniere = array_map('map_turnier_row', $turniereRaw);
    $todayTs = start_of_day_ts(new DateTimeImmutable());
    $turniereUpcoming = array_values(array_filter($turniere, fn($t) => $t['datumVonTs'] >= $todayTs));
    $turnierePast = array_values(array_filter($turniere, fn($t) => $t['datumVonTs'] < $todayTs));
    usort($turniereUpcoming, fn($a, $b) => $a['datumVonTs'] <=> $b['datumVonTs']);
    usort($turnierePast, fn($a, $b) => $b['datumVonTs'] <=> $a['datumVonTs']);

    $myTurniere = array_values(array_filter($einsaetze, fn($row) => (string)$row['trainer_id'] === (string)$user['trainer_id']));
    $myTurniere = array_map(fn($row) => [
        'turnier_einsatz_id' => (string)($row['turnier_einsatz_id'] ?? ''),
        'turnier_id' => (string)($row['turnier_id'] ?? ''),
        'datum' => $row['datum'] ?? null,
        'rolle' => (string)($row['rolle'] ?? ''),
        'anwesend' => (string)($row['anwesend'] ?? ''),
        'pauschale_tag_eur' => $row['pauschale_tag_eur'] ?? null,
        'freigegeben' => $row['freigegeben'] ?? null,
        'kommentar' => (string)($row['kommentar'] ?? ''),
    ], $myTurniere);

    $myFahrten = array_values(array_filter($fahrten, fn($row) => (string)($row['fahrer_trainer_id'] ?? $row['fahrer_id'] ?? '') === (string)$user['trainer_id']));
    $myFahrten = array_map(fn($row) => [
        'fahrt_id' => (string)($row['fahrt_id'] ?? ''),
        'turnier_id' => (string)($row['turnier_id'] ?? ''),
        'datum' => $row['datum'] ?? null,
        'km_gesamt' => $row['km_gesamt'] ?? null,
        'km_satz_eur' => $row['km_satz_eur'] ?? null,
        'km_betrag_eur' => $row['km_betrag_eur'] ?? null,
        'freigegeben' => $row['freigegeben'] ?? null,
        'kommentar' => (string)($row['kommentar'] ?? ''),
    ], $myFahrten);

    json_response([
        'ok' => true,
        'user' => $user,
        'upcoming' => $upcoming,
        'allTrainings' => $allTrainings,
        'mineActive' => $mineActive,
        'myUnavailable' => $myUnavailable,
        'turniere_upcoming' => $turniereUpcoming,
        'turniere_past' => $turnierePast,
        'my_turniere' => $myTurniere,
        'my_fahrten' => $myFahrten,
    ]);
}

function enrich_training(array $tr, array $einteilungen): array
{
    $trainingId = (string)($tr['training_id'] ?? '');
    $activeCount = 0;
    foreach ($einteilungen as $e) {
        if ((string)$e['training_id'] === $trainingId && empty($e['ausgetragen_am'])) {
            $activeCount++;
        }
    }
    $needed = (int)($tr['benoetigt_trainer'] ?? 0);
    $offen = max(0, $needed - $activeCount);
    $datum = parse_date($tr['datum'] ?? null);
    return [
        'training_id' => $trainingId,
        'datum' => $datum ? format_date($datum) : '',
        'datumTs' => $datum ? start_of_day_ts($datum) : 0,
        'start' => format_time($tr['start'] ?? ''),
        'ende' => format_time($tr['ende'] ?? ''),
        'gruppe' => (string)($tr['gruppe'] ?? ''),
        'ort' => (string)($tr['ort'] ?? ''),
        'status' => (string)($tr['status'] ?? ''),
        'benoetigt_trainer' => $needed,
        'eingeteilt' => $activeCount,
        'offen' => $offen,
        'offen_text' => sprintf('Noch %d Trainer', $offen),
        'ausfall_grund' => (string)($tr['ausfall_grund'] ?? ''),
    ];
}

function enrich_einteilung(array $e, array $trainings): array
{
    $tr = null;
    foreach ($trainings as $row) {
        if ((string)$row['training_id'] === (string)($e['training_id'] ?? '')) {
            $tr = $row;
            break;
        }
    }
    $datum = $tr ? parse_date($tr['datum'] ?? null) : null;
    $start = $tr ? format_time($tr['start'] ?? '') : '';
    $ende = $tr ? format_time($tr['ende'] ?? '') : '';
    $label = $tr && $datum ? sprintf('%s · %s–%s · %s', format_date($datum), $start, $ende, (string)($tr['gruppe'] ?? '')) : (string)($e['training_id'] ?? '');

    return [
        'einteilung_id' => (string)($e['einteilung_id'] ?? ''),
        'training_id' => (string)($e['training_id'] ?? ''),
        'datum' => $datum ? format_date($datum) : '',
        'training_datum' => $datum ? format_date($datum) : '',
        'trainingDatumTs' => $datum ? start_of_day_ts($datum) : 0,
        'start' => $start,
        'ende' => $ende,
        'gruppe' => $tr ? (string)($tr['gruppe'] ?? '') : '',
        'ort' => $tr ? (string)($tr['ort'] ?? '') : '',
        'training_status' => $tr ? (string)($tr['status'] ?? '') : '',
        'training_label' => $label,
        'rolle' => (string)($e['rolle'] ?? ''),
        'attendance' => (string)($e['attendance'] ?? ''),
        'checkin_am' => !empty($e['checkin_am']) ? format_datetime(parse_date($e['checkin_am'])) : '',
        'ausgetragen' => !empty($e['ausgetragen_am']),
    ];
}

function map_turnier_row(array $row): array
{
    $von = parse_date($row['datum_von'] ?? null);
    $bis = parse_date($row['datum_bis'] ?? null);
    return [
        'turnier_id' => (string)($row['turnier_id'] ?? ''),
        'name' => (string)($row['name'] ?? ''),
        'datum_von' => $von ? format_date($von) : (string)($row['datum_von'] ?? ''),
        'datum_bis' => $bis ? format_date($bis) : (string)($row['datum_bis'] ?? ''),
        'datumVonTs' => $von ? start_of_day_ts($von) : 0,
        'datumBisTs' => $bis ? start_of_day_ts($bis) : 0,
        'ort' => (string)($row['ort'] ?? ''),
        'pauschale_tag_eur' => $row['pauschale_tag_eur'] ?? null,
        'km_satz_eur' => $row['km_satz_eur'] ?? null,
        'bemerkung' => (string)($row['bemerkung'] ?? ''),
    ];
}

function handle_me(array $body): void
{
    $token = (string)param($body, 'token', '');
    $user = require_session($token);
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM trainer WHERE trainer_id = :trainer_id');
    $stmt->execute([':trainer_id' => $user['trainer_id']]);
    $t = $stmt->fetch();
    if (!$t) {
        json_response(['ok' => false, 'error' => 'Trainer nicht gefunden.']);
    }
    json_response([
        'ok' => true,
        'trainer' => [
            'trainer_id' => (string)$t['trainer_id'],
            'name' => (string)$t['name'],
            'email' => (string)($t['email'] ?? ''),
            'rolle_standard' => (string)($t['rolle_standard'] ?? 'Trainer'),
            'stundensatz' => (float)($t['stundensatz_eur'] ?? 0),
            'is_admin' => truthy($t['is_admin'] ?? false),
            'notizen' => (string)($t['notizen'] ?? ''),
        ],
    ]);
}

function handle_update_me(array $body): void
{
    $token = (string)param($body, 'token', '');
    $user = require_session($token);
    $pdo = db();
    $fields = [];
    $params = [':trainer_id' => $user['trainer_id']];
    if (array_key_exists('email', $body)) {
        $fields[] = 'email = :email';
        $params[':email'] = (string)$body['email'];
    }
    if (array_key_exists('notizen', $body)) {
        $fields[] = 'notizen = :notizen';
        $params[':notizen'] = (string)$body['notizen'];
    }
    if ($fields) {
        $stmt = $pdo->prepare('UPDATE trainer SET ' . implode(', ', $fields) . ' WHERE trainer_id = :trainer_id');
        $stmt->execute($params);
        $session = get_session($token);
        if ($session) {
            $session['email'] = (string)($body['email'] ?? $session['email']);
            $session['notizen'] = (string)($body['notizen'] ?? $session['notizen']);
            save_session($token, $session);
        }
    }
    json_response(['ok' => true]);
}

function handle_change_pin(array $body): void
{
    $token = (string)param($body, 'token', '');
    $oldPin = (string)param($body, 'oldPin', '');
    $newPin = (string)param($body, 'newPin', '');
    $user = require_session($token);
    $pdo = db();
    $stmt = $pdo->prepare('SELECT pin FROM trainer WHERE trainer_id = :trainer_id');
    $stmt->execute([':trainer_id' => $user['trainer_id']]);
    $row = $stmt->fetch();
    $cur = $row ? (string)($row['pin'] ?? '') : '';
    if (!verify_pin($oldPin, $cur)) {
        json_response(['ok' => false, 'error' => 'Aktuelle PIN ist falsch.']);
    }
    if (!preg_match('/^\d{4,8}$/', $newPin)) {
        json_response(['ok' => false, 'error' => 'Neue PIN muss 4–8 Ziffern haben.']);
    }
    $stmt = $pdo->prepare('UPDATE trainer SET pin = :pin WHERE trainer_id = :trainer_id');
    $stmt->execute([':pin' => hash_pin($newPin), ':trainer_id' => $user['trainer_id']]);
    json_response(['ok' => true]);
}

function handle_training_details(array $body, string $trainingId): void
{
    $token = (string)param($body, 'token', '');
    require_session($token);
    $pdo = db();
    $trainings = $pdo->query('SELECT * FROM trainings')->fetchAll();
    $einteilungen = $pdo->query('SELECT * FROM einteilungen')->fetchAll();
    $trainers = $pdo->query('SELECT * FROM trainer')->fetchAll();

    $tr = null;
    foreach ($trainings as $row) {
        if ((string)$row['training_id'] === $trainingId) {
            $tr = $row;
            break;
        }
    }
    if (!$tr) {
        json_response(['ok' => false, 'error' => 'Training nicht gefunden.']);
    }
    $enriched = enrich_training($tr, $einteilungen);
    $signups = [];
    foreach ($einteilungen as $e) {
        if ((string)$e['training_id'] === $trainingId && empty($e['ausgetragen_am'])) {
            $tt = null;
            foreach ($trainers as $t) {
                if ((string)$t['trainer_id'] === (string)$e['trainer_id']) {
                    $tt = $t;
                    break;
                }
            }
            $signups[] = [
                'trainer_id' => (string)$e['trainer_id'],
                'name' => (string)($tt['name'] ?? $e['trainer_id']),
                'rolle' => (string)($e['rolle'] ?? ($tt['rolle_standard'] ?? 'Trainer')),
                'checkin_am' => !empty($e['checkin_am']) ? format_datetime(parse_date($e['checkin_am'])) : '',
                'attendance' => (string)($e['attendance'] ?? ''),
            ];
        }
    }
    json_response(['ok' => true, 'training' => $enriched, 'signups' => $signups]);
}

function handle_enroll(array $body, string $trainingId): void
{
    $token = (string)param($body, 'token', '');
    $user = require_session($token);
    $pdo = db();

    $stmt = $pdo->prepare('SELECT * FROM einteilungen WHERE training_id = :training_id AND trainer_id = :trainer_id AND ausgetragen_am IS NULL');
    $stmt->execute([':training_id' => $trainingId, ':trainer_id' => $user['trainer_id']]);
    if ($stmt->fetch()) {
        json_response(['ok' => false, 'error' => 'Schon eingeteilt.']);
    }

    $einteilungId = bin2hex(random_bytes(4));
    $stmt = $pdo->prepare('INSERT INTO einteilungen (einteilung_id, training_id, trainer_id, rolle, eingetragen_am) VALUES (:id, :training_id, :trainer_id, :rolle, NOW())');
    $stmt->execute([
        ':id' => $einteilungId,
        ':training_id' => $trainingId,
        ':trainer_id' => $user['trainer_id'],
        ':rolle' => $user['rolle_standard'],
    ]);
    json_response(['ok' => true, 'einteilung_id' => $einteilungId]);
}

function handle_withdraw(array $body, string $einteilungId): void
{
    $token = (string)param($body, 'token', '');
    $user = require_session($token);
    $pdo = db();
    $stmt = $pdo->prepare('SELECT trainer_id FROM einteilungen WHERE einteilung_id = :id');
    $stmt->execute([':id' => $einteilungId]);
    $row = $stmt->fetch();
    if (!$row) {
        json_response(['ok' => false, 'error' => 'Einteilung nicht gefunden.']);
    }
    if ((string)$row['trainer_id'] !== (string)$user['trainer_id'] && empty($user['is_admin'])) {
        json_response(['ok' => false, 'error' => 'Nicht berechtigt.']);
    }
    $stmt = $pdo->prepare('UPDATE einteilungen SET ausgetragen_am = NOW() WHERE einteilung_id = :id');
    $stmt->execute([':id' => $einteilungId]);
    json_response(['ok' => true]);
}

function handle_checkin(array $body, string $einteilungId): void
{
    $token = (string)param($body, 'token', '');
    $user = require_session($token);
    $pdo = db();
    $stmt = $pdo->prepare('SELECT trainer_id FROM einteilungen WHERE einteilung_id = :id');
    $stmt->execute([':id' => $einteilungId]);
    $row = $stmt->fetch();
    if (!$row) {
        json_response(['ok' => false, 'error' => 'Einteilung nicht gefunden.']);
    }
    if ((string)$row['trainer_id'] !== (string)$user['trainer_id'] && empty($user['is_admin'])) {
        json_response(['ok' => false, 'error' => 'Nicht berechtigt.']);
    }
    $stmt = $pdo->prepare('UPDATE einteilungen SET attendance = :attendance, checkin_am = NOW() WHERE einteilung_id = :id');
    $stmt->execute([':attendance' => 'JA', ':id' => $einteilungId]);
    json_response(['ok' => true]);
}

function handle_admin_training_status(array $body, string $trainingId): void
{
    $token = (string)param($body, 'token', '');
    require_admin($token);
    $status = (string)param($body, 'status', '');
    $reason = (string)param($body, 'reason', '');
    if (!in_array($status, ['geplant', 'stattgefunden', 'ausgefallen'], true)) {
        json_response(['ok' => false, 'error' => 'Ungültiger Status.']);
    }
    $pdo = db();
    $stmt = $pdo->prepare('UPDATE trainings SET status = :status, ausfall_grund = :reason WHERE training_id = :id');
    $stmt->execute([':status' => $status, ':reason' => $status === 'ausgefallen' ? $reason : '', ':id' => $trainingId]);
    json_response(['ok' => true]);
}

function handle_turniere_list(array $body): void
{
    $token = (string)param($body, 'token', '');
    require_session($token);
    $pdo = db();
    $rows = $pdo->query('SELECT * FROM turniere')->fetchAll();
    $items = array_map('map_turnier_row', $rows);
    json_response(['ok' => true, 'items' => $items]);
}

function handle_turnier_details(array $body, string $turnierId): void
{
    $token = (string)param($body, 'token', '');
    $user = require_session($token);
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM turniere WHERE turnier_id = :id');
    $stmt->execute([':id' => $turnierId]);
    $turnier = $stmt->fetch();
    if (!$turnier) {
        json_response(['ok' => false, 'error' => 'Turnier nicht gefunden.']);
    }
    $turnier = map_turnier_row($turnier);

    $stmt = $pdo->prepare('SELECT * FROM turnier_einsaetze WHERE turnier_id = :id');
    $stmt->execute([':id' => $turnierId]);
    $einsaetze = $stmt->fetchAll();
    $mine = array_values(array_filter($einsaetze, fn($row) => (string)$row['trainer_id'] === (string)$user['trainer_id']));
    json_response(['ok' => true, 'turnier' => $turnier, 'einsaetze' => $einsaetze, 'mine' => $mine]);
}

function handle_turnier_enroll(array $body, string $turnierId): void
{
    $token = (string)param($body, 'token', '');
    $user = require_session($token);
    $pdo = db();
    $einsatzId = bin2hex(random_bytes(4));
    $stmt = $pdo->prepare('INSERT INTO turnier_einsaetze (turnier_einsatz_id, turnier_id, trainer_id, rolle, datum) VALUES (:id, :turnier_id, :trainer_id, :rolle, CURDATE())');
    $stmt->execute([
        ':id' => $einsatzId,
        ':turnier_id' => $turnierId,
        ':trainer_id' => $user['trainer_id'],
        ':rolle' => $user['rolle_standard'],
    ]);
    json_response(['ok' => true, 'turnier_einsatz_id' => $einsatzId]);
}

function handle_turnier_withdraw(array $body, string $turnierEinsatzId): void
{
    $token = (string)param($body, 'token', '');
    $user = require_session($token);
    $pdo = db();
    $stmt = $pdo->prepare('SELECT trainer_id FROM turnier_einsaetze WHERE turnier_einsatz_id = :id');
    $stmt->execute([':id' => $turnierEinsatzId]);
    $row = $stmt->fetch();
    if (!$row) {
        json_response(['ok' => false, 'error' => 'Einsatz-Zeile nicht gefunden.']);
    }
    if ((string)$row['trainer_id'] !== (string)$user['trainer_id'] && empty($user['is_admin'])) {
        json_response(['ok' => false, 'error' => 'Nicht berechtigt.']);
    }
    $stmt = $pdo->prepare('UPDATE turnier_einsaetze SET anwesend = :status WHERE turnier_einsatz_id = :id');
    $stmt->execute([':status' => 'AUSGETRAGEN', ':id' => $turnierEinsatzId]);
    json_response(['ok' => true]);
}

function handle_turnier_unavailable(array $body, string $turnierId): void
{
    $token = (string)param($body, 'token', '');
    $user = require_session($token);
    $grund = (string)param($body, 'grund', '');
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM turnier_einsaetze WHERE turnier_id = :turnier_id AND trainer_id = :trainer_id');
    $stmt->execute([':turnier_id' => $turnierId, ':trainer_id' => $user['trainer_id']]);
    $existing = $stmt->fetch();
    if ($existing && ($existing['anwesend'] ?? '') === 'JA') {
        json_response(['ok' => false, 'error' => 'Du bist bereits eingetragen. Bitte erst austragen.']);
    }
    $einsatzId = $existing['turnier_einsatz_id'] ?? bin2hex(random_bytes(4));
    $stmt = $pdo->prepare(
        'INSERT INTO turnier_einsaetze (turnier_einsatz_id, turnier_id, trainer_id, datum, rolle, anwesend, kommentar, pauschale_tag_eur, freigegeben)
         VALUES (:id, :turnier_id, :trainer_id, CURDATE(), :rolle, :anwesend, :kommentar, :pauschale, :freigegeben)
         ON DUPLICATE KEY UPDATE anwesend = VALUES(anwesend), kommentar = VALUES(kommentar)'
    );
    $stmt->execute([
        ':id' => $einsatzId,
        ':turnier_id' => $turnierId,
        ':trainer_id' => $user['trainer_id'],
        ':rolle' => $user['rolle_standard'],
        ':anwesend' => 'NICHT_VERFUEGBAR',
        ':kommentar' => $grund,
        ':pauschale' => null,
        ':freigegeben' => null,
    ]);
    json_response(['ok' => true]);
}

function handle_turnier_unavailable_delete(array $body, string $turnierId): void
{
    $token = (string)param($body, 'token', '');
    $user = require_session($token);
    $pdo = db();
    $stmt = $pdo->prepare('SELECT turnier_einsatz_id FROM turnier_einsaetze WHERE turnier_id = :turnier_id AND trainer_id = :trainer_id AND anwesend = :status');
    $stmt->execute([':turnier_id' => $turnierId, ':trainer_id' => $user['trainer_id'], ':status' => 'NICHT_VERFUEGBAR']);
    $row = $stmt->fetch();
    if (!$row) {
        json_response(['ok' => false, 'error' => 'Keine Nicht-Verfügbar-Meldung gefunden.']);
    }
    $stmt = $pdo->prepare('UPDATE turnier_einsaetze SET anwesend = :status WHERE turnier_einsatz_id = :id');
    $stmt->execute([':status' => 'AUSGETRAGEN', ':id' => $row['turnier_einsatz_id']]);
    json_response(['ok' => true]);
}

function handle_turnier_checkin(array $body, string $turnierId): void
{
    $token = (string)param($body, 'token', '');
    $user = require_session($token);
    $kmGesamt = param($body, 'kmGesamt', null);
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM turnier_einsaetze WHERE turnier_id = :turnier_id AND trainer_id = :trainer_id');
    $stmt->execute([':turnier_id' => $turnierId, ':trainer_id' => $user['trainer_id']]);
    $einsatz = $stmt->fetch();
    if (!$einsatz) {
        json_response(['ok' => false, 'error' => 'Keine aktive Eintragung gefunden.']);
    }
    $stmt = $pdo->prepare('UPDATE turnier_einsaetze SET anwesend = :status WHERE turnier_einsatz_id = :id');
    $stmt->execute([':status' => 'JA', ':id' => $einsatz['turnier_einsatz_id']]);

    $km = (float)($kmGesamt ?? 0);
    if ($km > 0) {
        $stmt = $pdo->prepare('SELECT * FROM fahrten WHERE turnier_id = :turnier_id AND fahrer_trainer_id = :trainer_id');
        $stmt->execute([':turnier_id' => $turnierId, ':trainer_id' => $user['trainer_id']]);
        $fahrt = $stmt->fetch();
        $fahrtId = $fahrt['fahrt_id'] ?? bin2hex(random_bytes(4));
        $stmt = $pdo->prepare(
            'INSERT INTO fahrten (fahrt_id, turnier_id, datum, fahrer_trainer_id, km_gesamt, km_satz_eur, freigegeben, kommentar)
             VALUES (:id, :turnier_id, CURDATE(), :trainer_id, :km, :km_satz, :freigegeben, :kommentar)
             ON DUPLICATE KEY UPDATE km_gesamt = VALUES(km_gesamt), km_satz_eur = VALUES(km_satz_eur)'
        );
        $stmt->execute([
            ':id' => $fahrtId,
            ':turnier_id' => $turnierId,
            ':trainer_id' => $user['trainer_id'],
            ':km' => $km,
            ':km_satz' => null,
            ':freigegeben' => null,
            ':kommentar' => $fahrt['kommentar'] ?? '',
        ]);
    }
    json_response(['ok' => true]);
}

function handle_admin_turniere_list(array $body): void
{
    $token = (string)param($body, 'token', '');
    require_admin($token);
    $pdo = db();
    $rows = $pdo->query('SELECT * FROM turniere')->fetchAll();
    $items = array_map('map_turnier_row', $rows);
    json_response(['ok' => true, 'items' => $items]);
}

function handle_admin_turnier_upsert(array $body): void
{
    $token = (string)param($body, 'token', '');
    require_admin($token);
    $payload = $body['payload'] ?? $body;
    $pdo = db();
    $turnierId = trim((string)($payload['turnier_id'] ?? '')) ?: bin2hex(random_bytes(4));
    $stmt = $pdo->prepare(
        'INSERT INTO turniere (turnier_id, name, datum_von, datum_bis, ort, pauschale_tag_eur, km_satz_eur, bemerkung)
         VALUES (:id, :name, :von, :bis, :ort, :pauschale, :km, :bemerkung)
         ON DUPLICATE KEY UPDATE name = VALUES(name), datum_von = VALUES(datum_von), datum_bis = VALUES(datum_bis),
         ort = VALUES(ort), pauschale_tag_eur = VALUES(pauschale_tag_eur), km_satz_eur = VALUES(km_satz_eur), bemerkung = VALUES(bemerkung)'
    );
    $stmt->execute([
        ':id' => $turnierId,
        ':name' => $payload['name'] ?? '',
        ':von' => $payload['datum_von'] ?? null,
        ':bis' => $payload['datum_bis'] ?? null,
        ':ort' => $payload['ort'] ?? '',
        ':pauschale' => $payload['pauschale_tag_eur'] ?? null,
        ':km' => $payload['km_satz_eur'] ?? null,
        ':bemerkung' => $payload['bemerkung'] ?? '',
    ]);
    json_response(['ok' => true, 'turnier_id' => $turnierId]);
}

function handle_admin_turnier_delete(array $body, string $turnierId): void
{
    $token = (string)param($body, 'token', '');
    require_admin($token);
    $pdo = db();
    $stmt = $pdo->prepare('DELETE FROM turniere WHERE turnier_id = :id');
    $stmt->execute([':id' => $turnierId]);
    json_response(['ok' => true]);
}

function handle_admin_training_plan_upsert(array $body, string $trainingId): void
{
    $token = (string)param($body, 'token', '');
    $user = require_admin($token);
    $payload = $body['payload'] ?? $body;
    $pdo = db();
    $planId = trim((string)($payload['plan_id'] ?? '')) ?: bin2hex(random_bytes(4));
    $stmt = $pdo->prepare(
        'INSERT INTO trainingsplan (plan_id, training_id, titel, inhalt, link, created_at, created_by, updated_at, updated_by)
         VALUES (:plan_id, :training_id, :titel, :inhalt, :link, CURDATE(), :created_by, CURDATE(), :updated_by)
         ON DUPLICATE KEY UPDATE titel = VALUES(titel), inhalt = VALUES(inhalt), link = VALUES(link),
         updated_at = CURDATE(), updated_by = VALUES(updated_by)'
    );
    $stmt->execute([
        ':plan_id' => $planId,
        ':training_id' => $trainingId,
        ':titel' => $payload['titel'] ?? '',
        ':inhalt' => $payload['inhalt'] ?? '',
        ':link' => $payload['link'] ?? '',
        ':created_by' => $user['trainer_id'],
        ':updated_by' => $user['trainer_id'],
    ]);
    json_response(['ok' => true, 'plan_id' => $planId]);
}

function handle_admin_training_plan_delete(array $body, string $planId): void
{
    $token = (string)param($body, 'token', '');
    require_admin($token);
    $pdo = db();
    $stmt = $pdo->prepare('DELETE FROM trainingsplan WHERE plan_id = :id');
    $stmt->execute([':id' => $planId]);
    json_response(['ok' => true]);
}

function handle_billing_halfyear(array $body): void
{
    $token = (string)param($body, 'token', '');
    require_session($token);
    $year = (int)param($body, 'year', date('Y'));
    $half = (int)param($body, 'half', 1);
    $pdo = db();
    $stmt = $pdo->query('SELECT * FROM abrechnung_training');
    $training = $stmt->fetchAll();
    $stmt = $pdo->query('SELECT * FROM abrechnung_turnier');
    $turnier = $stmt->fetchAll();
    json_response([
        'ok' => true,
        'year' => $year,
        'half' => $half,
        'training' => $training,
        'turnier' => $turnier,
    ]);
}

function handle_training_unavailable(array $body, string $trainingId): void
{
    $token = (string)param($body, 'token', '');
    $user = require_session($token);
    $grund = (string)param($body, 'grund', '');
    $pdo = db();
    $stmt = $pdo->prepare('INSERT INTO abmeldungen (abmeldung_id, training_id, trainer_id, grund, created_at) VALUES (:id, :training_id, :trainer_id, :grund, NOW())');
    $stmt->execute([
        ':id' => bin2hex(random_bytes(4)),
        ':training_id' => $trainingId,
        ':trainer_id' => $user['trainer_id'],
        ':grund' => $grund,
    ]);
    json_response(['ok' => true]);
}

function handle_training_unavailable_delete(array $body, string $trainingId): void
{
    $token = (string)param($body, 'token', '');
    $user = require_session($token);
    $pdo = db();
    $stmt = $pdo->prepare('UPDATE abmeldungen SET deleted_at = NOW() WHERE training_id = :id AND trainer_id = :trainer_id');
    $stmt->execute([':id' => $trainingId, ':trainer_id' => $user['trainer_id']]);
    json_response(['ok' => true]);
}

function handle_admin_dashboard(array $body): void
{
    $token = (string)param($body, 'token', '');
    require_admin($token);
    $pdo = db();
    $trainings = $pdo->query('SELECT * FROM trainings')->fetchAll();
    $einteilungen = $pdo->query('SELECT * FROM einteilungen')->fetchAll();
    $today = new DateTimeImmutable('today');
    $upcoming = array_values(array_filter($trainings, function ($tr) use ($today) {
        $datum = parse_date($tr['datum'] ?? null);
        return $datum && $datum >= $today;
    }));
    $openSlotsCount = 0;
    foreach ($trainings as $tr) {
        $openSlotsCount += max(0, (int)($tr['benoetigt_trainer'] ?? 0) - count(array_filter($einteilungen, fn($e) => (string)$e['training_id'] === (string)$tr['training_id'] && empty($e['ausgetragen_am']))));
    }
    json_response([
        'ok' => true,
        'upcomingCount' => count($upcoming),
        'openSlotsCount' => $openSlotsCount,
        'openCheckinsCount' => 0,
        'trainingsThisWeekCount' => count($upcoming),
    ]);
}

function handle_admin_trainings_list(array $body): void
{
    $token = (string)param($body, 'token', '');
    require_admin($token);
    $pdo = db();
    $filters = $body['filters'] ?? [];
    $monthFilter = trim((string)($filters['month'] ?? param($body, 'month', '')));
    $statusFilter = trim((string)($filters['status'] ?? param($body, 'status', '')));
    $trainings = $pdo->query('SELECT * FROM trainings')->fetchAll();
    $items = [];
    foreach ($trainings as $tr) {
        if ($statusFilter && (string)$tr['status'] !== $statusFilter) {
            continue;
        }
        $datum = parse_date($tr['datum'] ?? null);
        $monthKey = $datum ? $datum->format('Y-m') : '';
        if ($monthFilter && $monthKey !== $monthFilter) {
            continue;
        }
        $iso = $datum ? $datum->format('Y-m-d') : (string)($tr['datum'] ?? '');
        $items[] = [
            'training_id' => (string)($tr['training_id'] ?? ''),
            'datum' => $datum ? format_date($datum) : (string)($tr['datum'] ?? ''),
            'datum_iso' => $iso,
            'datumTs' => $datum ? start_of_day_ts($datum) : 0,
            'start' => format_time($tr['start'] ?? ''),
            'ende' => format_time($tr['ende'] ?? ''),
            'start_raw' => format_time($tr['start'] ?? ''),
            'ende_raw' => format_time($tr['ende'] ?? ''),
            'gruppe' => (string)($tr['gruppe'] ?? ''),
            'ort' => (string)($tr['ort'] ?? ''),
            'status' => (string)($tr['status'] ?? ''),
            'benoetigt_trainer' => $tr['benoetigt_trainer'] ?? null,
            'ausfall_grund' => (string)($tr['ausfall_grund'] ?? ''),
        ];
    }
    usort($items, fn($a, $b) => $a['datumTs'] <=> $b['datumTs']);
    json_response(['ok' => true, 'items' => $items]);
}

function handle_admin_trainings_upsert(array $body): void
{
    $token = (string)param($body, 'token', '');
    $user = require_admin($token);
    $payload = $body['payload'] ?? $body;
    $pdo = db();
    $trainingId = trim((string)($payload['training_id'] ?? '')) ?: bin2hex(random_bytes(4));
    $stmt = $pdo->prepare(
        'INSERT INTO trainings (training_id, datum, start, ende, gruppe, ort, status, benoetigt_trainer, ausfall_grund)
         VALUES (:id, :datum, :start, :ende, :gruppe, :ort, :status, :benoetigt, :grund)
         ON DUPLICATE KEY UPDATE datum = VALUES(datum), start = VALUES(start), ende = VALUES(ende), gruppe = VALUES(gruppe),
         ort = VALUES(ort), status = VALUES(status), benoetigt_trainer = VALUES(benoetigt_trainer), ausfall_grund = VALUES(ausfall_grund)'
    );
    $stmt->execute([
        ':id' => $trainingId,
        ':datum' => $payload['datum'] ?? null,
        ':start' => $payload['start'] ?? null,
        ':ende' => $payload['ende'] ?? null,
        ':gruppe' => $payload['gruppe'] ?? '',
        ':ort' => $payload['ort'] ?? '',
        ':status' => $payload['status'] ?? 'geplant',
        ':benoetigt' => $payload['benoetigt_trainer'] ?? 0,
        ':grund' => $payload['ausfall_grund'] ?? '',
    ]);
    json_response(['ok' => true, 'training_id' => $trainingId, 'updated_by' => $user['trainer_id']]);
}

function handle_admin_trainings_delete(array $body, string $trainingId): void
{
    $token = (string)param($body, 'token', '');
    require_admin($token);
    $pdo = db();
    $stmt = $pdo->prepare('DELETE FROM trainings WHERE training_id = :id');
    $stmt->execute([':id' => $trainingId]);
    json_response(['ok' => true]);
}

function handle_admin_roles_list(array $body): void
{
    $token = (string)param($body, 'token', '');
    require_admin($token);
    $pdo = db();
    $rows = $pdo->query('SELECT * FROM rollen_saetze')->fetchAll();
    json_response(['ok' => true, 'items' => $rows]);
}

function handle_admin_trainers_list(array $body): void
{
    $token = (string)param($body, 'token', '');
    require_admin($token);
    $pdo = db();
    $rows = $pdo->query('SELECT * FROM trainer')->fetchAll();
    $items = array_map(fn($row) => [
        'trainer_id' => (string)$row['trainer_id'],
        'name' => (string)$row['name'],
        'email' => (string)($row['email'] ?? ''),
        'aktiv' => truthy($row['aktiv'] ?? false),
        'rolle_standard' => (string)($row['rolle_standard'] ?? 'Trainer'),
        'stundensatz' => $row['stundensatz_eur'] ?? null,
        'notizen' => (string)($row['notizen'] ?? ''),
        'is_admin' => truthy($row['is_admin'] ?? false),
    ], $rows);
    json_response(['ok' => true, 'items' => $items]);
}

function handle_admin_trainers_upsert(array $body): void
{
    $token = (string)param($body, 'token', '');
    require_admin($token);
    $payload = $body['payload'] ?? $body;
    $pdo = db();
    $trainerId = trim((string)($payload['trainer_id'] ?? '')) ?: bin2hex(random_bytes(4));
    $pin = $payload['pin'] ?? null;
    $pinValue = $pin !== null ? hash_pin((string)$pin) : null;
    $stmt = $pdo->prepare(
        'INSERT INTO trainer (trainer_id, name, email, aktiv, stundensatz_eur, rolle_standard, notizen, pin, is_admin)
         VALUES (:id, :name, :email, :aktiv, :stundensatz, :rolle, :notizen, :pin, :is_admin)
         ON DUPLICATE KEY UPDATE name = VALUES(name), email = VALUES(email), aktiv = VALUES(aktiv), stundensatz_eur = VALUES(stundensatz_eur),
         rolle_standard = VALUES(rolle_standard), notizen = VALUES(notizen), pin = COALESCE(VALUES(pin), pin), is_admin = VALUES(is_admin)'
    );
    $stmt->execute([
        ':id' => $trainerId,
        ':name' => $payload['name'] ?? '',
        ':email' => $payload['email'] ?? '',
        ':aktiv' => truthy($payload['aktiv'] ?? true) ? 1 : 0,
        ':stundensatz' => $payload['stundensatz'] ?? $payload['stundensatz_eur'] ?? null,
        ':rolle' => $payload['rolle_standard'] ?? 'Trainer',
        ':notizen' => $payload['notizen'] ?? '',
        ':pin' => $pinValue,
        ':is_admin' => truthy($payload['is_admin'] ?? false) ? 1 : 0,
    ]);
    json_response(['ok' => true, 'trainer_id' => $trainerId]);
}

function handle_admin_trainer_reset_pin(array $body, string $trainerId): void
{
    $token = (string)param($body, 'token', '');
    require_admin($token);
    $newPin = (string)param($body, 'newPin', '');
    if ($newPin === '') {
        $newPin = generate_pin();
    }
    if (!preg_match('/^\d{4,8}$/', $newPin)) {
        json_response(['ok' => false, 'error' => 'Neue PIN muss 4–8 Ziffern haben.']);
    }
    $pdo = db();
    $stmt = $pdo->prepare('UPDATE trainer SET pin = :pin WHERE trainer_id = :id');
    $stmt->execute([':pin' => hash_pin($newPin), ':id' => $trainerId]);
    json_response(['ok' => true, 'pin' => $newPin]);
}

function handle_admin_billing_overview(array $body): void
{
    $token = (string)param($body, 'token', '');
    require_admin($token);
    $year = (int)param($body, 'year', date('Y'));
    $half = (int)param($body, 'half', 1);
    $pdo = db();
    $training = $pdo->query('SELECT * FROM abrechnung_training')->fetchAll();
    $turnier = $pdo->query('SELECT * FROM abrechnung_turnier')->fetchAll();
    json_response(['ok' => true, 'year' => $year, 'half' => $half, 'training' => $training, 'turnier' => $turnier]);
}

function handle_admin_migrate_pins(array $body): void
{
    $token = (string)param($body, 'token', '');
    require_admin($token);
    $pdo = db();
    $rows = $pdo->query('SELECT trainer_id, pin FROM trainer')->fetchAll();
    foreach ($rows as $row) {
        $pin = (string)($row['pin'] ?? '');
        if ($pin === '' || is_hashed_pin($pin)) {
            continue;
        }
        $stmt = $pdo->prepare('UPDATE trainer SET pin = :pin WHERE trainer_id = :id');
        $stmt->execute([':pin' => hash_pin($pin), ':id' => $row['trainer_id']]);
    }
    json_response(['ok' => true]);
}
