<?php

declare(strict_types=1);

session_start();

$rootPath = dirname(__DIR__);
$settings = loadSettings($rootPath);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = $path ? rtrim($path, '/') : '/';
$path = $path === '' ? '/' : $path;

if ($path === '/logout') {
    logoutUser();
    redirect('/login');
}

if (!isLoggedIn() && $path !== '/login') {
    redirect('/login');
}

[$title, $content, $activeNav, $showMenu, $statusCode] = resolveRoute($path, $settings, $rootPath);

http_response_code($statusCode);

echo renderLayout($title, $content, $settings, $activeNav, $showMenu);

function resolveRoute(string $path, array $settings, string $rootPath): array
{
    $statusCode = 200;
    $activeNav = null;
    $showMenu = false;

    return match ($path) {
        '/login' => handleLogin($settings, $rootPath),
        '/uebersicht' => ['Übersicht', renderOverview(), 'uebersicht', true, $statusCode],
        '/einsaetze' => ['Einsätze', renderEinsaetze(), 'einsaetze', false, $statusCode],
        '/abrechnung' => ['Abrechnung', renderAbrechnung(), 'abrechnung', false, $statusCode],
        '/mehr' => ['Mehr', renderMehr(), 'mehr', true, $statusCode],
        '/admin' => renderAdmin($settings, $rootPath),
        '/' => ['Start', renderOverview(), 'uebersicht', true, $statusCode],
        default => renderNotFound(),
    };
}

function handleLogin(array $settings, string $rootPath): array
{
    $error = '';
    $name = '';
    $pin = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $pin = trim((string) ($_POST['pin'] ?? ''));

        if ($name === '' || $pin === '') {
            $error = 'Bitte gib deinen Namen und deine PIN ein.';
        } else {
            $trainers = loadTrainerRecords($rootPath);

            if ($trainers === []) {
                $error = 'Trainerdaten konnten nicht geladen werden.';
            } else {
                $matches = array_values(array_filter($trainers, static fn (array $trainer): bool => $trainer['name'] === $name));
                if (count($matches) > 1) {
                    $error = 'Der Name ist nicht eindeutig. Bitte Admin kontaktieren und den Namen eindeutig hinterlegen lassen.';
                } elseif (count($matches) === 0) {
                    $error = 'Name oder PIN ist ungültig.';
                } else {
                    $trainer = $matches[0];
                    if (!$trainer['aktiv']) {
                        $error = 'Nur aktive Trainer:innen dürfen sich anmelden. Bitte Admin kontaktieren.';
                    } elseif (!verifyPin($pin, $trainer['pin'])) {
                        $error = 'Name oder PIN ist ungültig.';
                    } else {
                        session_regenerate_id(true);
                        $_SESSION['user'] = [
                            'trainer_id' => $trainer['trainer_id'],
                            'name' => $trainer['name'],
                            'role' => $trainer['is_admin'] ? 'admin' : 'trainer',
                            'is_admin' => $trainer['is_admin'],
                        ];
                        redirect('/uebersicht');
                    }
                }
            }
        }
    }

    $content = renderLoginForm($settings, $error, $name);

    return ['Login', $content, null, false, 200];
}

function renderAdmin(array $settings, string $rootPath): array
{
    if (!isAdmin()) {
        $content = renderForbidden();
        return ['Admin', $content, null, false, 403];
    }

    $message = null;
    $messageType = 'success';
    $seriesSummary = [];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        [$message, $messageType, $seriesSummary] = handleTrainingAdminPost($rootPath);
    }

    return ['Admin', renderAdminPage($settings, $rootPath, $message, $messageType, $seriesSummary), null, true, 200];
}

function renderNotFound(): array
{
    return ['Seite nicht gefunden', renderNotFoundPage(), null, false, 404];
}

function isLoggedIn(): bool
{
    return isset($_SESSION['user']);
}

function isAdmin(): bool
{
    return isset($_SESSION['user']['role']) && $_SESSION['user']['role'] === 'admin';
}

function logoutUser(): void
{
    $_SESSION = [];
    if (session_id() !== '') {
        session_destroy();
    }
}

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function loadSettings(string $rootPath): array
{
    $settings = [];
    $configPath = $rootPath . '/storage/config.php';
    if (is_file($configPath)) {
        $config = require $configPath;
        if (is_array($config) && isset($config['settings']) && is_array($config['settings'])) {
            $settings = $config['settings'];
        }
    }

    $settingsPath = $rootPath . '/storage/settings.php';
    if (is_file($settingsPath)) {
        $custom = require $settingsPath;
        if (is_array($custom)) {
            $settings = array_merge($settings, $custom);
        }
    }

    $defaults = [
        'brand_name' => 'KSV Homberg Trainertool',
        'brand_color' => '#0a5d4a',
        'brand_color_soft' => '#e2f3ee',
        'logo' => null,
    ];

    return array_merge($defaults, $settings);
}

function renderLayout(string $title, string $content, array $settings, ?string $activeNav, bool $showMenu): string
{
    $brandName = htmlspecialchars((string) $settings['brand_name']);
    $logoPath = $settings['logo'] ? htmlspecialchars((string) $settings['logo']) : '';
    $brandColor = htmlspecialchars((string) $settings['brand_color']);
    $brandSoft = htmlspecialchars((string) $settings['brand_color_soft']);
    $pageTitle = htmlspecialchars($title . ' · ' . $brandName);

    $navItems = [
        ['label' => 'Übersicht', 'path' => '/uebersicht', 'key' => 'uebersicht'],
        ['label' => 'Einsätze', 'path' => '/einsaetze', 'key' => 'einsaetze'],
        ['label' => 'Abrechnung', 'path' => '/abrechnung', 'key' => 'abrechnung'],
        ['label' => 'Mehr', 'path' => '/mehr', 'key' => 'mehr'],
    ];

    $navHtml = '';
    foreach ($navItems as $item) {
        $isActive = $activeNav === $item['key'];
        $class = $isActive ? 'nav-item active' : 'nav-item';
        $navHtml .= '<a class="' . $class . '" href="' . $item['path'] . '">' . htmlspecialchars($item['label']) . '</a>';
    }

    $menuHtml = '';
    if ($showMenu) {
        $menuHtml = '<button class="menu-button" type="button" aria-label="Menü öffnen">☰</button>';
    }

    $logoHtml = '';
    if ($logoPath !== '') {
        $logoHtml = '<img class="brand-logo" src="' . $logoPath . '" alt="' . $brandName . ' Logo">';
    }

    return <<<HTML
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$pageTitle}</title>
    <style>
        :root {
            --brand-color: {$brandColor};
            --brand-soft: {$brandSoft};
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "Inter", "Segoe UI", sans-serif;
            background: #f6f8f9;
            color: #1b1b1b;
        }
        .app {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1rem 1.25rem;
            background: var(--brand-color);
            color: #fff;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-weight: 600;
            font-size: 1.05rem;
        }
        .brand-logo {
            width: 36px;
            height: 36px;
            object-fit: contain;
            border-radius: 8px;
            background: #fff;
            padding: 4px;
        }
        .menu-button {
            background: transparent;
            border: 1px solid rgba(255, 255, 255, 0.6);
            color: #fff;
            border-radius: 8px;
            padding: 0.45rem 0.7rem;
            font-size: 1rem;
            cursor: pointer;
        }
        .content {
            flex: 1;
            padding: 1.5rem 1.25rem 5.5rem;
        }
        .card {
            background: #fff;
            border-radius: 16px;
            padding: 1.25rem;
            box-shadow: 0 8px 24px rgba(14, 30, 37, 0.08);
            margin-bottom: 1.25rem;
        }
        .bottom-nav {
            position: fixed;
            left: 0;
            right: 0;
            bottom: 0;
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            background: #fff;
            border-top: 1px solid #e5e7eb;
            padding: 0.25rem 0.75rem 0.8rem;
        }
        .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.35rem;
            text-decoration: none;
            color: #6b7280;
            font-size: 0.85rem;
            padding: 0.6rem 0.25rem;
            border-radius: 12px;
        }
        .nav-item.active {
            color: var(--brand-color);
            background: var(--brand-soft);
            font-weight: 600;
        }
        .chip {
            display: inline-flex;
            align-items: center;
            padding: 0.2rem 0.6rem;
            border-radius: 999px;
            background: var(--brand-soft);
            color: var(--brand-color);
            font-size: 0.78rem;
            font-weight: 600;
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
        }
        .tile-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 1rem;
        }
        .tile {
            background: #fff;
            border-radius: 16px;
            padding: 1rem;
            box-shadow: 0 6px 16px rgba(14, 30, 37, 0.08);
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
            min-height: 110px;
        }
        .tile-title {
            font-weight: 600;
            font-size: 0.98rem;
        }
        .tile-meta {
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #6b7280;
        }
        .helper {
            color: #4b5563;
            line-height: 1.5;
        }
        .login-form {
            display: grid;
            gap: 0.9rem;
        }
        label {
            font-weight: 600;
            margin-bottom: 0.3rem;
            display: block;
        }
        input, select {
            width: 100%;
            padding: 0.6rem 0.75rem;
            border-radius: 10px;
            border: 1px solid #d1d5db;
        }
        button.primary,
        .button-link.primary {
            background: var(--brand-color);
            color: #fff;
            border: none;
            border-radius: 10px;
            padding: 0.7rem;
            font-weight: 600;
            cursor: pointer;
        }
        button.secondary,
        .button-link.secondary {
            background: #eef2f7;
            color: #1f2937;
            border: none;
            border-radius: 10px;
            padding: 0.65rem 0.9rem;
            font-weight: 600;
            cursor: pointer;
        }
        button.danger,
        .button-link.danger {
            background: #b42318;
            color: #fff;
            border: none;
            border-radius: 10px;
            padding: 0.65rem 0.9rem;
            font-weight: 600;
            cursor: pointer;
        }
        .button-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
        }
        button[disabled] {
            cursor: not-allowed;
            opacity: 0.6;
        }
        textarea {
            width: 100%;
            min-height: 90px;
            border-radius: 10px;
            border: 1px solid #d1d5db;
            padding: 0.6rem 0.75rem;
            resize: vertical;
        }
        .form-grid {
            display: grid;
            gap: 0.9rem;
        }
        .form-grid.cols-2 {
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
        }
        .admin-list {
            display: grid;
            gap: 1rem;
        }
        .admin-item {
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            padding: 1rem;
            display: grid;
            gap: 0.6rem;
            background: #fdfdfd;
        }
        .admin-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 0.45rem;
            align-items: center;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 0.2rem 0.6rem;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 600;
            background: #f3f4f6;
            color: #374151;
        }
        .badge.success { background: #e7f7ef; color: #147d4b; }
        .badge.warning { background: #fef3c7; color: #b45309; }
        .badge.danger { background: #fee2e2; color: #b42318; }
        .admin-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        .flash {
            border-radius: 12px;
            padding: 0.8rem 1rem;
            font-weight: 600;
            margin-bottom: 1rem;
        }
        .flash.success { background: #e7f7ef; color: #0f6b3c; }
        .flash.error { background: #fee2e2; color: #b42318; }
        .section-title {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }
        .section-title h2 {
            margin: 0;
        }
        .error {
            color: #b42318;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="app">
        <header class="topbar">
            <div class="brand">{$logoHtml}{$brandName}</div>
            {$menuHtml}
        </header>
        <main class="content">
            {$content}
        </main>
        <nav class="bottom-nav">
            {$navHtml}
        </nav>
    </div>
</body>
</html>
HTML;
}

function renderOverview(): string
{
    $user = $_SESSION['user']['name'] ?? 'Trainer:in';
    $role = $_SESSION['user']['role'] ?? 'trainer';
    $roleLabel = $role === 'admin' ? 'Admin' : 'Trainer:in';

    return '<section class="card">'
        . '<h1>Übersicht</h1>'
        . '<p class="helper">Willkommen zurück, ' . htmlspecialchars($user) . '. Hier findest du deine nächsten Einsätze und den Status deiner Abrechnung.</p>'
        . '<div class="grid">'
        . '<div class="card"><span class="chip">' . htmlspecialchars($roleLabel) . '</span><h3>Nächste Einheit</h3><p class="helper">Heute 18:00 Uhr · Halle 2</p></div>'
        . '<div class="card"><span class="chip">Offen</span><h3>Abrechnungen</h3><p class="helper">2 Einsätze warten auf Freigabe.</p></div>'
        . '<div class="card"><span class="chip">Hinweis</span><h3>Team-Updates</h3><p class="helper">Neue Termine im Kalender prüfen.</p></div>'
        . '</div>'
        . '</section>';
}

function renderEinsaetze(): string
{
    return '<section class="card">'
        . '<h1>Einsätze</h1>'
        . '<p class="helper">Alle Trainingseinheiten und Turniere in einer Liste.</p>'
        . '<div class="card"><strong>Montag, 18:00</strong><p class="helper">U12 Training · Halle 1</p></div>'
        . '<div class="card"><strong>Mittwoch, 17:30</strong><p class="helper">U10 Training · Halle 3</p></div>'
        . '</section>';
}

function renderAbrechnung(): string
{
    return '<section class="card">'
        . '<h1>Abrechnung</h1>'
        . '<p class="helper">Stunden, Pauschalen und Auswertungen auf einen Blick.</p>'
        . '<div class="grid">'
        . '<div class="card"><h3>Diese Woche</h3><p class="helper">6,5 Stunden · 3 Einsätze</p></div>'
        . '<div class="card"><h3>Offene Freigaben</h3><p class="helper">1 Eintrag wartet auf Bestätigung.</p></div>'
        . '</div>'
        . '</section>';
}

function renderMehr(): string
{
    return '<section class="card">'
        . '<h1>Mehr</h1>'
        . '<p class="helper">Verwaltung, Profil und Einstellungen.</p>'
        . '<div class="card"><strong>Profil</strong><p class="helper">Persönliche Daten und Kontaktinformationen.</p></div>'
        . '<div class="card"><strong>Admin-Bereich</strong><p class="helper">Zugriff nur mit Admin-Rechten.</p></div>'
        . '<div class="card"><strong>Hilfe</strong><p class="helper">Kurzanleitungen und Support.</p></div>'
        . '</section>';
}

function renderAdminPage(array $settings, string $rootPath, ?string $message, string $messageType, array $seriesSummary): string
{
    $brandName = htmlspecialchars((string) $settings['brand_name']);
    $trainings = loadTrainingRecords($rootPath);
    $trainingGroups = collectTrainingGroups($trainings);
    $statusOptions = collectTrainingStatuses($trainings);
    $assignments = loadTrainingAssignments($rootPath);

    $filterStart = trim((string) ($_GET['filter_start'] ?? ''));
    $filterEnd = trim((string) ($_GET['filter_end'] ?? ''));
    $filterGroup = trim((string) ($_GET['filter_group'] ?? ''));
    $filterStatus = trim((string) ($_GET['filter_status'] ?? ''));
    $editId = trim((string) ($_GET['edit'] ?? ''));

    $filteredTrainings = array_values(array_filter($trainings, static function (array $training) use ($filterStart, $filterEnd, $filterGroup, $filterStatus): bool {
        if ($filterGroup !== '' && $training['gruppe'] !== $filterGroup) {
            return false;
        }
        if ($filterStatus !== '' && $training['status'] !== $filterStatus) {
            return false;
        }

        $trainingDate = parseDateToTimestamp($training['datum']);
        if ($trainingDate !== null) {
            $startDate = $filterStart !== '' ? parseDateToTimestamp($filterStart) : null;
            $endDate = $filterEnd !== '' ? parseDateToTimestamp($filterEnd) : null;

            if ($startDate !== null && $trainingDate < $startDate) {
                return false;
            }
            if ($endDate !== null && $trainingDate > $endDate) {
                return false;
            }
        }

        return true;
    }));

    usort($filteredTrainings, static function (array $a, array $b): int {
        $dateA = parseDateToTimestamp($a['datum']) ?? 0;
        $dateB = parseDateToTimestamp($b['datum']) ?? 0;
        if ($dateA === $dateB) {
            return strcmp($a['start'], $b['start']);
        }
        return $dateA <=> $dateB;
    });

    $flashHtml = '';
    if ($message !== null && $message !== '') {
        $flashHtml = '<div class="flash ' . htmlspecialchars($messageType) . '">' . htmlspecialchars($message) . '</div>';
    }
    $seriesSummaryHtml = $seriesSummary !== [] ? renderTrainingSeriesSummary($seriesSummary) : '';

    $filterGroupOptions = '<option value="">Alle Gruppen</option>';
    foreach ($trainingGroups as $group) {
        $selected = $filterGroup === $group ? ' selected' : '';
        $filterGroupOptions .= '<option value="' . htmlspecialchars($group) . '"' . $selected . '>' . htmlspecialchars($group) . '</option>';
    }

    $filterStatusOptions = '<option value="">Alle Status</option>';
    foreach ($statusOptions as $status) {
        $selected = $filterStatus === $status ? ' selected' : '';
        $filterStatusOptions .= '<option value="' . htmlspecialchars($status) . '"' . $selected . '>' . htmlspecialchars($status) . '</option>';
    }

    $trainingsHtml = '';
    foreach ($filteredTrainings as $training) {
        $trainingId = $training['training_id'];
        $statusBadge = trainingStatusBadge($training['status']);
        $editLink = '/admin?edit=' . rawurlencode($trainingId);
        $dateLabel = htmlspecialchars($training['datum']);
        $timeLabel = htmlspecialchars(trim($training['start'] . '–' . $training['ende']));
        $groupLabel = htmlspecialchars($training['gruppe']);
        $ortLabel = htmlspecialchars($training['ort']);
        $trainerCount = htmlspecialchars((string) $training['benoetigt_trainer']);
        $note = $training['bemerkung'] !== '' ? '<p class="helper">' . htmlspecialchars($training['bemerkung']) . '</p>' : '';
        $cancelReason = $training['ausfall_grund'] !== '' ? '<span class="badge danger">Ausfall: ' . htmlspecialchars($training['ausfall_grund']) . '</span>' : '';
        $durationLabel = $training['dauer_stunden'] !== '' ? '<span class="badge">Dauer ' . htmlspecialchars($training['dauer_stunden']) . ' h</span>' : '';

        $deleteDisabled = isset($assignments[$trainingId]);
        $deleteHelper = $deleteDisabled ? '<span class="badge warning">Löschen gesperrt: Einteilungen vorhanden</span>' : '<span class="badge">Soft-Delete möglich</span>';
        $deleteButton = $deleteDisabled
            ? '<button class="danger" type="submit" disabled>Training löschen</button>'
            : '<button class="danger" type="submit">Training löschen</button>';

        $trainingsHtml .= '<article class="admin-item">'
            . '<div class="admin-meta">'
            . '<span class="badge">' . $dateLabel . '</span>'
            . '<span class="badge">' . $timeLabel . '</span>'
            . '<span class="badge">' . $groupLabel . '</span>'
            . $statusBadge
            . $durationLabel
            . $cancelReason
            . '</div>'
            . '<p><strong>' . $groupLabel . '</strong> · ' . $ortLabel . '</p>'
            . '<p class="helper">Benötigt: ' . $trainerCount . ' Trainer:innen</p>'
            . $note
            . $deleteHelper
            . '<div class="admin-actions">'
            . '<a class="button-link primary" href="' . $editLink . '">Bearbeiten</a>'
            . '<form method="post" action="/admin" onsubmit="return confirm(\'Training wirklich löschen?\')">'
            . '<input type="hidden" name="action" value="delete">'
            . '<input type="hidden" name="training_id" value="' . htmlspecialchars($trainingId) . '">'
            . $deleteButton
            . '</form>'
            . '</div>'
            . '</article>';
    }

    if ($trainingsHtml === '') {
        $trainingsHtml = '<p class="helper">Keine Trainings gefunden. Filter anpassen oder neue Einheit anlegen.</p>';
    }

    $editTraining = $editId !== '' ? findTrainingById($trainings, $editId) : null;
    $editSection = '';
    if ($editTraining) {
        $editSection = renderTrainingForm('Training bearbeiten', 'update', $editTraining, $statusOptions);
    }

    $newTraining = [
        'training_id' => '',
        'datum' => '',
        'start' => '',
        'ende' => '',
        'gruppe' => '',
        'ort' => '',
        'benoetigt_trainer' => '',
        'status' => 'geplant',
        'ausfall_grund' => '',
        'bemerkung' => '',
    ];
    $createSection = renderTrainingForm('Neues Training anlegen', 'create', $newTraining, $statusOptions);
    $createSeriesSection = renderTrainingSeriesForm($statusOptions);

    return '<section class="card">'
        . '<h1>Admin</h1>'
        . '<p class="helper">Nur Administrator:innen können diese Seite sehen. Mandant: ' . $brandName . '.</p>'
        . '</section>'
        . '<section class="card">'
        . '<div class="section-title"><h2>Trainings verwalten</h2><span class="badge">CRUD</span></div>'
        . '<p class="helper">Trainings können nur gelöscht werden, wenn keine Einteilungen existieren. Löschungen werden als Soft-Delete gespeichert.</p>'
        . $flashHtml
        . $seriesSummaryHtml
        . '<form class="form-grid cols-2" method="get" action="/admin">'
        . '<div><label for="filter_start">Zeitraum von</label><input id="filter_start" name="filter_start" type="date" value="' . htmlspecialchars($filterStart) . '"></div>'
        . '<div><label for="filter_end">Zeitraum bis</label><input id="filter_end" name="filter_end" type="date" value="' . htmlspecialchars($filterEnd) . '"></div>'
        . '<div><label for="filter_group">Gruppe</label><select id="filter_group" name="filter_group">' . $filterGroupOptions . '</select></div>'
        . '<div><label for="filter_status">Status</label><select id="filter_status" name="filter_status">' . $filterStatusOptions . '</select></div>'
        . '<div class="admin-actions">'
        . '<button class="secondary" type="submit">Filter anwenden</button>'
        . '<a class="button-link secondary" href="/admin">Filter zurücksetzen</a>'
        . '</div>'
        . '</form>'
        . '</section>'
        . '<section class="card">'
        . '<h2>Liste</h2>'
        . '<div class="admin-list">' . $trainingsHtml . '</div>'
        . '</section>'
        . $editSection
        . $createSection
        . $createSeriesSection;
}

function handleTrainingAdminPost(string $rootPath): array
{
    $action = trim((string) ($_POST['action'] ?? ''));
    $store = loadTrainingStore($rootPath);
    $assignments = loadTrainingAssignments($rootPath);

    if ($action === 'create') {
        $input = normalizeTrainingInput($_POST);
        if ($input['datum'] === '' || $input['start'] === '' || $input['ende'] === '' || $input['gruppe'] === '') {
            return ['Bitte Datum, Start, Ende und Gruppe ausfüllen.', 'error', []];
        }

        $trainingId = generateTrainingId($rootPath, $input['datum']);
        $input['training_id'] = $trainingId;
        $input['dauer_stunden'] = calculateDuration($input['start'], $input['ende']);
        $input['updated_at'] = date('c');
        $input['created_at'] = $input['updated_at'];

        $store['trainings'][$trainingId] = $input;
        saveTrainingStore($rootPath, $store);

        return ['Training angelegt.', 'success', []];
    }

    if ($action === 'update') {
        $trainingId = trim((string) ($_POST['training_id'] ?? ''));
        if ($trainingId === '') {
            return ['Training-ID fehlt.', 'error', []];
        }

        $input = normalizeTrainingInput($_POST);
        $input['training_id'] = $trainingId;
        $input['dauer_stunden'] = calculateDuration($input['start'], $input['ende']);
        $input['updated_at'] = date('c');

        $store['trainings'][$trainingId] = $input;
        saveTrainingStore($rootPath, $store);

        return ['Training gespeichert.', 'success', []];
    }

    if ($action === 'delete') {
        $trainingId = trim((string) ($_POST['training_id'] ?? ''));
        if ($trainingId === '') {
            return ['Training-ID fehlt.', 'error', []];
        }
        if (isset($assignments[$trainingId])) {
            return ['Löschen nicht möglich: Einteilungen existieren.', 'error', []];
        }

        $store['deleted'][$trainingId] = date('c');
        saveTrainingStore($rootPath, $store);

        return ['Training gelöscht (Soft-Delete).', 'success', []];
    }

    if ($action === 'create_series') {
        $seriesInput = normalizeTrainingSeriesInput($_POST);
        if ($seriesInput['start_date'] === '' || $seriesInput['weekday'] === '' || $seriesInput['start_time'] === '' || $seriesInput['end_time'] === '' || $seriesInput['gruppe'] === '') {
            return ['Bitte Wochentag, Startdatum, Start, Ende und Gruppe ausfüllen.', 'error', []];
        }
        if ($seriesInput['count'] < 1) {
            return ['Bitte eine Anzahl an Terminen angeben.', 'error', []];
        }

        $seriesStart = DateTime::createFromFormat('Y-m-d', $seriesInput['start_date']);
        if (!$seriesStart instanceof DateTime) {
            return ['Startdatum ist ungültig.', 'error', []];
        }

        $targetWeekday = weekdayNameToIsoNumber($seriesInput['weekday']);
        if ($targetWeekday === null) {
            return ['Wochentag ist ungültig.', 'error', []];
        }

        $currentWeekday = (int) $seriesStart->format('N');
        $daysToAdd = ($targetWeekday - $currentWeekday + 7) % 7;
        if ($daysToAdd > 0) {
            $seriesStart->modify('+' . $daysToAdd . ' days');
        }

        $existingIds = collectExistingTrainingIds($rootPath);
        $yearCounters = [];
        $summary = [];

        for ($i = 0; $i < $seriesInput['count']; $i++) {
            $trainingDate = clone $seriesStart;
            if ($i > 0) {
                $trainingDate->modify('+' . $i . ' weeks');
            }

            $trainingDateStore = $trainingDate->format('d.m.Y');
            $year = (int) $trainingDate->format('Y');
            $trainingId = nextTrainingId($year, $existingIds, $yearCounters);

            $record = [
                'training_id' => $trainingId,
                'datum' => $trainingDateStore,
                'start' => $seriesInput['start_time'],
                'ende' => $seriesInput['end_time'],
                'gruppe' => $seriesInput['gruppe'],
                'ort' => $seriesInput['ort'],
                'benoetigt_trainer' => $seriesInput['benoetigt_trainer'],
                'status' => $seriesInput['status'],
                'ausfall_grund' => '',
                'bemerkung' => '',
            ];
            $record['dauer_stunden'] = calculateDuration($record['start'], $record['ende']);
            $record['updated_at'] = date('c');
            $record['created_at'] = $record['updated_at'];

            $store['trainings'][$trainingId] = $record;
            $summary[] = $record;
        }

        saveTrainingStore($rootPath, $store);

        return ['Serie mit ' . count($summary) . ' Terminen angelegt.', 'success', $summary];
    }

    return ['Unbekannte Aktion.', 'error', []];
}

function renderTrainingForm(string $title, string $action, array $training, array $statusOptions): string
{
    $trainingId = htmlspecialchars((string) ($training['training_id'] ?? ''));
    $datumIso = normalizeDate($training['datum'] ?? '');
    $startValue = htmlspecialchars((string) ($training['start'] ?? ''));
    $endeValue = htmlspecialchars((string) ($training['ende'] ?? ''));
    $gruppeValue = htmlspecialchars((string) ($training['gruppe'] ?? ''));
    $ortValue = htmlspecialchars((string) ($training['ort'] ?? ''));
    $benoetigtValue = htmlspecialchars((string) ($training['benoetigt_trainer'] ?? ''));
    $statusValue = (string) ($training['status'] ?? '');
    $ausfallValue = htmlspecialchars((string) ($training['ausfall_grund'] ?? ''));
    $bemerkungValue = htmlspecialchars((string) ($training['bemerkung'] ?? ''));

    $statusOptionsHtml = '';
    foreach ($statusOptions as $status) {
        $selected = $status === $statusValue ? ' selected' : '';
        $statusOptionsHtml .= '<option value="' . htmlspecialchars($status) . '"' . $selected . '>' . htmlspecialchars($status) . '</option>';
    }

    $titleHtml = htmlspecialchars($title);

    return '<section class="card">'
        . '<div class="section-title"><h2>' . $titleHtml . '</h2></div>'
        . '<form class="form-grid" method="post" action="/admin">'
        . '<input type="hidden" name="action" value="' . htmlspecialchars($action) . '">'
        . ($trainingId !== '' ? '<input type="hidden" name="training_id" value="' . $trainingId . '">' : '')
        . '<div class="form-grid cols-2">'
        . '<div><label for="datum_' . $action . '">Datum</label><input id="datum_' . $action . '" name="datum" type="date" value="' . htmlspecialchars($datumIso) . '" required></div>'
        . '<div><label for="gruppe_' . $action . '">Gruppe</label><input id="gruppe_' . $action . '" name="gruppe" value="' . $gruppeValue . '" required></div>'
        . '<div><label for="start_' . $action . '">Start</label><input id="start_' . $action . '" name="start" type="time" value="' . $startValue . '" required></div>'
        . '<div><label for="ende_' . $action . '">Ende</label><input id="ende_' . $action . '" name="ende" type="time" value="' . $endeValue . '" required></div>'
        . '<div><label for="ort_' . $action . '">Ort</label><input id="ort_' . $action . '" name="ort" value="' . $ortValue . '"></div>'
        . '<div><label for="benoetigt_' . $action . '">Benötigt Trainer:innen</label><input id="benoetigt_' . $action . '" name="benoetigt_trainer" type="number" min="0" value="' . $benoetigtValue . '"></div>'
        . '<div><label for="status_' . $action . '">Status</label><select id="status_' . $action . '" name="status">' . $statusOptionsHtml . '</select></div>'
        . '<div><label for="ausfall_' . $action . '">Ausfallgrund</label><input id="ausfall_' . $action . '" name="ausfall_grund" value="' . $ausfallValue . '"></div>'
        . '</div>'
        . '<div><label for="bemerkung_' . $action . '">Bemerkung</label><textarea id="bemerkung_' . $action . '" name="bemerkung">' . $bemerkungValue . '</textarea></div>'
        . '<button class="primary" type="submit">Speichern</button>'
        . '</form>'
        . '</section>';
}

function renderTrainingSeriesForm(array $statusOptions): string
{
    $weekdayOptions = renderWeekdayOptions('Montag');
    $today = date('Y-m-d');
    $statusOptionsHtml = '';
    foreach ($statusOptions as $status) {
        $selected = $status === 'geplant' ? ' selected' : '';
        $statusOptionsHtml .= '<option value="' . htmlspecialchars($status) . '"' . $selected . '>' . htmlspecialchars($status) . '</option>';
    }

    return '<section class="card">'
        . '<div class="section-title"><h2>Serie anlegen</h2><span class="badge">Mehrere Termine</span></div>'
        . '<p class="helper">Erstellt mehrere Trainings auf Basis eines Wochentags und Startdatums. Alle Termine sind danach einzeln editierbar.</p>'
        . '<form class="form-grid" method="post" action="/admin">'
        . '<input type="hidden" name="action" value="create_series">'
        . '<div class="form-grid cols-2">'
        . '<div><label for="series_weekday">Wochentag</label><select id="series_weekday" name="series_weekday">' . $weekdayOptions . '</select></div>'
        . '<div><label for="series_start_date">Startdatum</label><input id="series_start_date" name="series_start_date" type="date" value="' . htmlspecialchars($today) . '" required></div>'
        . '<div><label for="series_count">Anzahl Termine</label><input id="series_count" name="series_count" type="number" min="1" value="10" required></div>'
        . '<div><label for="series_group">Gruppe</label><input id="series_group" name="series_group" required></div>'
        . '<div><label for="series_start_time">Startzeit</label><input id="series_start_time" name="series_start_time" type="time" required></div>'
        . '<div><label for="series_end_time">Endzeit</label><input id="series_end_time" name="series_end_time" type="time" required></div>'
        . '<div><label for="series_location">Ort</label><input id="series_location" name="series_location"></div>'
        . '<div><label for="series_needed">Benötigt Trainer:innen</label><input id="series_needed" name="series_needed" type="number" min="0" value="0"></div>'
        . '<div><label for="series_status">Standard-Status</label><select id="series_status" name="series_status">' . $statusOptionsHtml . '</select></div>'
        . '</div>'
        . '<button class="primary" type="submit">Serie erstellen</button>'
        . '</form>'
        . '</section>';
}

function renderTrainingSeriesSummary(array $summary): string
{
    $itemsHtml = '';
    foreach ($summary as $training) {
        $date = htmlspecialchars($training['datum']);
        $time = htmlspecialchars(trim($training['start'] . '–' . $training['ende']));
        $group = htmlspecialchars($training['gruppe']);
        $ort = htmlspecialchars($training['ort']);
        $trainingId = htmlspecialchars($training['training_id']);

        $itemsHtml .= '<li><strong>' . $date . '</strong> · ' . $time . ' · ' . $group . ' · ' . $ort . ' <span class="badge">' . $trainingId . '</span></li>';
    }

    return '<section class="card">'
        . '<div class="section-title"><h2>Zusammenfassung der Serie</h2><span class="badge success">Neu</span></div>'
        . '<ul class="helper">' . $itemsHtml . '</ul>'
        . '</section>';
}

function renderWeekdayOptions(string $selected): string
{
    $weekdays = ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];
    $options = '';
    foreach ($weekdays as $weekday) {
        $isSelected = $weekday === $selected ? ' selected' : '';
        $options .= '<option value="' . htmlspecialchars($weekday) . '"' . $isSelected . '>' . htmlspecialchars($weekday) . '</option>';
    }
    return $options;
}

function normalizeTrainingInput(array $input): array
{
    $datum = normalizeDate($input['datum'] ?? '');
    $dateForStore = $datum !== '' ? formatDateForStore($datum) : '';

    return [
        'datum' => $dateForStore,
        'start' => normalizeTime($input['start'] ?? ''),
        'ende' => normalizeTime($input['ende'] ?? ''),
        'gruppe' => trim((string) ($input['gruppe'] ?? '')),
        'ort' => trim((string) ($input['ort'] ?? '')),
        'benoetigt_trainer' => max(0, (int) ($input['benoetigt_trainer'] ?? 0)),
        'status' => trim((string) ($input['status'] ?? 'geplant')),
        'ausfall_grund' => trim((string) ($input['ausfall_grund'] ?? '')),
        'bemerkung' => trim((string) ($input['bemerkung'] ?? '')),
    ];
}

function normalizeTrainingSeriesInput(array $input): array
{
    return [
        'weekday' => trim((string) ($input['series_weekday'] ?? '')),
        'start_date' => normalizeDate($input['series_start_date'] ?? ''),
        'count' => max(0, (int) ($input['series_count'] ?? 0)),
        'start_time' => normalizeTime($input['series_start_time'] ?? ''),
        'end_time' => normalizeTime($input['series_end_time'] ?? ''),
        'gruppe' => trim((string) ($input['series_group'] ?? '')),
        'ort' => trim((string) ($input['series_location'] ?? '')),
        'benoetigt_trainer' => max(0, (int) ($input['series_needed'] ?? 0)),
        'status' => trim((string) ($input['series_status'] ?? 'geplant')),
    ];
}

function weekdayNameToIsoNumber(string $weekday): ?int
{
    $map = [
        'montag' => 1,
        'dienstag' => 2,
        'mittwoch' => 3,
        'donnerstag' => 4,
        'freitag' => 5,
        'samstag' => 6,
        'sonntag' => 7,
    ];
    $normalized = strtolower(trim($weekday));
    return $map[$normalized] ?? null;
}

function loadTrainingRecords(string $rootPath): array
{
    $rows = loadHtmlRows($rootPath . '/database/TRAININGS.html');
    if (!$rows) {
        return [];
    }

    [$header, $dataRows] = $rows;
    $header = normalizeHeaderCells($header);

    $records = [];
    foreach ($dataRows as $row) {
        $row = normalizeRowCells($header, $row);
        $assoc = array_combine($header, $row);
        if (!$assoc) {
            continue;
        }

        $trainingId = trim((string) ($assoc['training_id'] ?? ''));
        if ($trainingId === '') {
            continue;
        }

        $records[$trainingId] = [
            'training_id' => $trainingId,
            'datum' => trim((string) ($assoc['datum'] ?? '')),
            'start' => normalizeTime($assoc['start'] ?? ''),
            'ende' => normalizeTime($assoc['ende'] ?? ''),
            'dauer_stunden' => trim((string) ($assoc['dauer_stunden'] ?? '')),
            'gruppe' => trim((string) ($assoc['gruppe'] ?? '')),
            'ort' => trim((string) ($assoc['ort'] ?? '')),
            'benoetigt_trainer' => trim((string) ($assoc['benoetigt_trainer'] ?? '')),
            'status' => trim((string) ($assoc['status'] ?? '')),
            'ausfall_grund' => trim((string) ($assoc['ausfall_grund'] ?? '')),
            'bemerkung' => trim((string) ($assoc['bemerkung'] ?? '')),
        ];
    }

    $store = loadTrainingStore($rootPath);
    $merged = [];
    foreach ($records as $id => $record) {
        if (isset($store['deleted'][$id])) {
            continue;
        }
        if (isset($store['trainings'][$id])) {
            $record = array_merge($record, $store['trainings'][$id]);
        }
        $merged[$id] = $record;
    }

    foreach ($store['trainings'] as $id => $record) {
        if (isset($merged[$id]) || isset($store['deleted'][$id])) {
            continue;
        }
        $merged[$id] = array_merge([
            'training_id' => $id,
            'datum' => '',
            'start' => '',
            'ende' => '',
            'dauer_stunden' => '',
            'gruppe' => '',
            'ort' => '',
            'benoetigt_trainer' => '',
            'status' => '',
            'ausfall_grund' => '',
            'bemerkung' => '',
        ], $record);
    }

    $assignmentCounts = collectActiveAssignmentCounts($rootPath);
    foreach ($merged as $id => $record) {
        $needed = max(0, (int) ($record['benoetigt_trainer'] ?? 0));
        $assigned = $assignmentCounts[$id] ?? 0;
        $record['eingeteilt'] = $assigned;
        $record['offen'] = max(0, $needed - $assigned);
        $merged[$id] = $record;
    }

    return array_values($merged);
}

function collectTrainingGroups(array $trainings): array
{
    $groups = [];
    foreach ($trainings as $training) {
        $group = trim((string) ($training['gruppe'] ?? ''));
        if ($group !== '') {
            $groups[$group] = true;
        }
    }
    $groupList = array_keys($groups);
    sort($groupList, SORT_NATURAL | SORT_FLAG_CASE);
    return $groupList;
}

function collectTrainingStatuses(array $trainings): array
{
    $defaults = ['geplant', 'stattgefunden', 'ausgefallen'];
    $statuses = array_fill_keys($defaults, true);
    foreach ($trainings as $training) {
        $status = trim((string) ($training['status'] ?? ''));
        if ($status !== '') {
            $statuses[$status] = true;
        }
    }
    $list = array_keys($statuses);
    sort($list, SORT_NATURAL | SORT_FLAG_CASE);
    return $list;
}

function trainingStatusBadge(string $status): string
{
    $normalized = strtolower($status);
    $class = 'badge';
    if ($normalized === 'stattgefunden') {
        $class .= ' success';
    } elseif ($normalized === 'ausgefallen') {
        $class .= ' danger';
    } elseif ($normalized === 'geplant') {
        $class .= ' warning';
    }

    return '<span class="' . $class . '">' . htmlspecialchars($status) . '</span>';
}

function findTrainingById(array $trainings, string $trainingId): ?array
{
    foreach ($trainings as $training) {
        if ($training['training_id'] === $trainingId) {
            return $training;
        }
    }
    return null;
}

function loadTrainingAssignments(string $rootPath): array
{
    $rows = loadHtmlRows($rootPath . '/database/EINTEILUNGEN.html');
    if (!$rows) {
        return [];
    }

    [$header, $dataRows] = $rows;
    $header = normalizeHeaderCells($header);

    $trainingIndex = array_search('training_id', $header, true);
    if ($trainingIndex === false) {
        return [];
    }

    $assignments = [];
    foreach ($dataRows as $row) {
        $row = normalizeRowCells($header, $row);
        $trainingId = trim((string) ($row[$trainingIndex] ?? ''));
        if ($trainingId !== '') {
            $assignments[$trainingId] = true;
        }
    }

    return $assignments;
}

function collectActiveAssignmentCounts(string $rootPath): array
{
    $assignments = loadAssignmentRecords($rootPath);
    if ($assignments === []) {
        return [];
    }

    $abmeldungen = loadAbmeldungRecords($rootPath);
    $activeAbmeldungen = [];
    foreach ($abmeldungen as $abmeldung) {
        if ($abmeldung['deleted_at'] !== '') {
            continue;
        }
        if ($abmeldung['training_id'] === '' || $abmeldung['trainer_id'] === '') {
            continue;
        }
        $activeAbmeldungen[$abmeldung['training_id'] . '|' . $abmeldung['trainer_id']] = true;
    }

    $counts = [];
    foreach ($assignments as $assignment) {
        if ($assignment['training_id'] === '' || $assignment['trainer_id'] === '') {
            continue;
        }
        if ($assignment['ausgetragen_am'] !== '') {
            continue;
        }
        $abmeldungKey = $assignment['training_id'] . '|' . $assignment['trainer_id'];
        if (isset($activeAbmeldungen[$abmeldungKey])) {
            continue;
        }
        if (!isset($counts[$assignment['training_id']])) {
            $counts[$assignment['training_id']] = 0;
        }
        $counts[$assignment['training_id']]++;
    }

    return $counts;
}

function loadAssignmentRecords(string $rootPath): array
{
    $rows = loadHtmlRows($rootPath . '/database/EINTEILUNGEN.html');
    if (!$rows) {
        return [];
    }

    [$header, $dataRows] = $rows;
    $header = normalizeHeaderCells($header);

    $records = [];
    foreach ($dataRows as $row) {
        $row = normalizeRowCells($header, $row);
        $assoc = array_combine($header, $row);
        if (!$assoc) {
            continue;
        }

        $records[] = [
            'training_id' => trim((string) ($assoc['training_id'] ?? '')),
            'trainer_id' => trim((string) ($assoc['trainer_id'] ?? '')),
            'ausgetragen_am' => trim((string) ($assoc['ausgetragen_am'] ?? '')),
        ];
    }

    return $records;
}

function loadAbmeldungRecords(string $rootPath): array
{
    $rows = loadHtmlRows($rootPath . '/database/ABMELDUNGEN.html');
    if (!$rows) {
        return [];
    }

    [$header, $dataRows] = $rows;
    $header = normalizeHeaderCells($header);

    $records = [];
    foreach ($dataRows as $row) {
        $row = normalizeRowCells($header, $row);
        $assoc = array_combine($header, $row);
        if (!$assoc) {
            continue;
        }

        $records[] = [
            'training_id' => trim((string) ($assoc['training_id'] ?? '')),
            'trainer_id' => trim((string) ($assoc['trainer_id'] ?? '')),
            'deleted_at' => trim((string) ($assoc['deleted_at'] ?? '')),
        ];
    }

    return $records;
}

function loadTrainingStore(string $rootPath): array
{
    $path = $rootPath . '/storage/trainings.json';
    if (!is_file($path)) {
        return ['trainings' => [], 'deleted' => []];
    }

    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data)) {
        return ['trainings' => [], 'deleted' => []];
    }

    $trainings = isset($data['trainings']) && is_array($data['trainings']) ? $data['trainings'] : [];
    $deleted = isset($data['deleted']) && is_array($data['deleted']) ? $data['deleted'] : [];

    return ['trainings' => $trainings, 'deleted' => $deleted];
}

function saveTrainingStore(string $rootPath, array $store): void
{
    $path = $rootPath . '/storage/trainings.json';
    $payload = json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    file_put_contents($path, $payload === false ? '{}' : $payload);
}

function generateTrainingId(string $rootPath, string $dateValue): string
{
    $timestamp = parseDateToTimestamp($dateValue) ?? time();
    $year = (int) date('Y', $timestamp);
    $existingIds = collectExistingTrainingIds($rootPath);
    $yearCounters = [];

    return nextTrainingId($year, $existingIds, $yearCounters);
}

function collectExistingTrainingIds(string $rootPath): array
{
    $ids = [];
    $records = loadTrainingRecords($rootPath);
    foreach ($records as $training) {
        $trainingId = trim((string) ($training['training_id'] ?? ''));
        if ($trainingId !== '') {
            $ids[$trainingId] = true;
        }
    }

    $store = loadTrainingStore($rootPath);
    foreach ($store['trainings'] as $trainingId => $_record) {
        if ($trainingId !== '') {
            $ids[$trainingId] = true;
        }
    }
    foreach ($store['deleted'] as $trainingId => $_timestamp) {
        if ($trainingId !== '') {
            $ids[$trainingId] = true;
        }
    }

    return $ids;
}

function nextTrainingId(int $year, array &$existingIds, array &$yearCounters): string
{
    if (!isset($yearCounters[$year])) {
        $max = 0;
        foreach ($existingIds as $trainingId => $_value) {
            if (preg_match('/^TR-(\\d{4})-(\\d{3})$/', $trainingId, $matches)) {
                if ((int) $matches[1] === $year) {
                    $max = max($max, (int) $matches[2]);
                }
            }
        }
        $yearCounters[$year] = $max + 1;
    }

    do {
        $candidate = sprintf('TR-%d-%03d', $year, $yearCounters[$year]);
        $yearCounters[$year]++;
    } while (isset($existingIds[$candidate]));

    $existingIds[$candidate] = true;
    return $candidate;
}

function normalizeHeaderCells(array $header): array
{
    $normalized = array_map(static fn (string $cell): string => strtolower(trim($cell)), $header);
    if ($normalized !== [] && ($normalized[0] === '' || ctype_digit($normalized[0]))) {
        array_shift($normalized);
    }
    return $normalized;
}

function normalizeRowCells(array $header, array $row): array
{
    if ($header !== [] && isset($row[0]) && ctype_digit(trim((string) $row[0]))) {
        array_shift($row);
    }
    return array_pad($row, count($header), '');
}

function normalizeDate(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $value)) {
        return $value;
    }

    $date = DateTime::createFromFormat('d.m.Y', $value);
    if ($date instanceof DateTime) {
        return $date->format('Y-m-d');
    }

    return '';
}

function formatDateForStore(string $value): string
{
    $date = DateTime::createFromFormat('Y-m-d', $value);
    if ($date instanceof DateTime) {
        return $date->format('d.m.Y');
    }
    return '';
}

function normalizeTime(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    if (preg_match('/^\\d{1,2}:\\d{2}$/', $value)) {
        [$hours, $minutes] = explode(':', $value);
        return sprintf('%02d:%02d', (int) $hours, (int) $minutes);
    }

    return '';
}

function parseDateToTimestamp(string $value): ?int
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $value)) {
        $date = DateTime::createFromFormat('Y-m-d', $value);
    } else {
        $date = DateTime::createFromFormat('d.m.Y', $value);
    }

    if (!$date instanceof DateTime) {
        return null;
    }

    return (int) $date->format('U');
}

function calculateDuration(string $start, string $end): string
{
    if ($start === '' || $end === '') {
        return '';
    }

    $startDate = DateTime::createFromFormat('H:i', $start);
    $endDate = DateTime::createFromFormat('H:i', $end);
    if (!$startDate instanceof DateTime || !$endDate instanceof DateTime) {
        return '';
    }

    $interval = $startDate->diff($endDate);
    if ($interval->invert === 1) {
        return '';
    }

    $hours = str_pad((string) $interval->h, 2, '0', STR_PAD_LEFT);
    $minutes = str_pad((string) $interval->i, 2, '0', STR_PAD_LEFT);
    return $hours . ':' . $minutes;
}

function renderForbidden(): string
{
    return '<section class="card">'
        . '<h1>Zugriff verweigert</h1>'
        . '<p class="helper">Diese Seite ist nur für Admins verfügbar.</p>'
        . '</section>';
}

function renderNotFoundPage(): string
{
    return '<section class="card">'
        . '<h1>Seite nicht gefunden</h1>'
        . '<p class="helper">Die gewünschte Seite existiert nicht. Nutze die Navigation unten.</p>'
        . '</section>';
}

function renderLoginForm(array $settings, string $error, string $name): string
{
    $brandName = htmlspecialchars((string) $settings['brand_name']);
    $errorHtml = $error !== '' ? '<p class="error">' . htmlspecialchars($error) . '</p>' : '';
    $nameValue = htmlspecialchars($name);

    return '<section class="card">'
        . '<h1>Login</h1>'
        . '<p class="helper">Melde dich an, um das ' . $brandName . ' zu nutzen.</p>'
        . '<p class="helper">Der Name muss exakt wie im Trainerprofil hinterlegt sein und eindeutig sein.</p>'
        . $errorHtml
        . '<form class="login-form" method="post" action="/login">'
        . '<div><label for="name">Name</label><input id="name" name="name" placeholder="Vorname Nachname" value="' . $nameValue . '" required></div>'
        . '<div><label for="pin">PIN</label><input id="pin" name="pin" type="password" inputmode="numeric" autocomplete="one-time-code" required></div>'
        . '<button class="primary" type="submit">Weiter</button>'
        . '<p class="helper">PIN vergessen? Bitte Admin kontaktieren. PIN-Reset folgt später im Adminbereich.</p>'
        . '</form>'
        . '</section>';
}

function verifyPin(string $inputPin, string $storedPin): bool
{
    if ($storedPin === '') {
        return false;
    }

    if (str_starts_with($storedPin, 'sha256:')) {
        $hash = base64_encode(hash('sha256', $inputPin, true));
        return hash_equals(substr($storedPin, 7), $hash);
    }

    return hash_equals($storedPin, $inputPin);
}

function loadTrainerRecords(string $rootPath): array
{
    $rows = loadHtmlRows($rootPath . '/database/TRAINER.html');
    if (!$rows) {
        return [];
    }

    [$header, $dataRows] = $rows;
    $normalizedHeader = array_map(static fn (string $cell): string => strtolower(trim($cell)), $header);

    $records = [];
    foreach ($dataRows as $row) {
        $row = array_pad($row, count($normalizedHeader), '');
        $assoc = array_combine($normalizedHeader, $row);
        if (!$assoc) {
            continue;
        }

        $name = trim((string) ($assoc['name'] ?? ''));
        if ($name === '') {
            continue;
        }

        $records[] = [
            'trainer_id' => trim((string) ($assoc['trainer_id'] ?? '')),
            'name' => $name,
            'aktiv' => parseBoolean($assoc['aktiv'] ?? ''),
            'pin' => trim((string) ($assoc['pin'] ?? '')),
            'is_admin' => parseBoolean($assoc['is_admin'] ?? ''),
        ];
    }

    return $records;
}

function parseBoolean(string $value): bool
{
    $normalized = strtolower(trim($value));
    return in_array($normalized, ['1', 'true', 'wahr', 'yes', 'ja'], true);
}

function loadHtmlRows(string $path): ?array
{
    $html = file_get_contents($path);
    if ($html === false) {
        return null;
    }

    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $doc->loadHTML($html);
    libxml_clear_errors();

    $xpath = new DOMXPath($doc);
    $table = $xpath->query('//table')->item(0);
    if (!$table) {
        return null;
    }

    $rows = [];
    foreach ($xpath->query('.//tr', $table) as $row) {
        $cells = [];
        foreach ($xpath->query('./th|./td', $row) as $cell) {
            $cells[] = trim($cell->textContent);
        }
        if ($cells) {
            $rows[] = $cells;
        }
    }

    if (count($rows) < 2) {
        return null;
    }

    $header = $rows[0];
    $dataRows = array_slice($rows, 1);

    return [$header, $dataRows];
}
