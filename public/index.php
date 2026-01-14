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
        '/einsaetze' => ['Einsätze', renderEinsaetze($rootPath), 'einsaetze', false, $statusCode],
        '/training' => renderTrainingRoute($rootPath, $statusCode),
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

function renderTrainingRoute(string $rootPath, int $statusCode): array
{
    $message = null;
    $messageType = 'success';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        [$message, $messageType] = handleTrainingDetailPost($rootPath);
    }

    return ['Training', renderTrainingDetail($rootPath, $message, $messageType), 'einsaetze', false, $statusCode];
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
        .tab-bar {
            display: flex;
            gap: 0.5rem;
            background: #fff;
            padding: 0.4rem;
            border-radius: 14px;
            box-shadow: 0 6px 16px rgba(14, 30, 37, 0.06);
            margin-bottom: 1rem;
        }
        .tab {
            flex: 1;
            text-align: center;
            padding: 0.55rem 0.6rem;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 600;
            color: #6b7280;
        }
        .tab.active {
            background: var(--brand-soft);
            color: var(--brand-color);
        }
        .einsatz-list {
            display: grid;
            gap: 0.8rem;
        }
        .einsatz-card {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            padding: 1rem;
            border-radius: 14px;
            border: 1px solid #e5e7eb;
            background: #fff;
            text-decoration: none;
            color: inherit;
            box-shadow: 0 6px 16px rgba(14, 30, 37, 0.06);
        }
        .einsatz-card:hover {
            border-color: #cbd5f5;
        }
        .einsatz-card-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 0.75rem;
        }
        .einsatz-time {
            font-weight: 600;
            font-size: 1rem;
        }
        .einsatz-main {
            display: flex;
            flex-direction: column;
            gap: 0.15rem;
        }
        .einsatz-group {
            font-weight: 600;
        }
        .einsatz-date {
            color: #6b7280;
            font-size: 0.9rem;
        }
        .detail-list {
            display: grid;
            gap: 0.6rem;
            margin-top: 1rem;
        }
        .detail-row {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            padding-bottom: 0.4rem;
            border-bottom: 1px solid #eef2f7;
            font-size: 0.95rem;
        }
        .detail-label {
            color: #6b7280;
        }
        .detail-value {
            font-weight: 600;
            text-align: right;
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
        .plan-card {
            margin-top: 1rem;
        }
        .plan-title {
            margin: 0;
            font-weight: 700;
            font-size: 1.05rem;
        }
        .plan-meta {
            margin-top: 0.35rem;
            color: #6b7280;
            font-size: 0.85rem;
        }
        .plan-content {
            margin-top: 0.6rem;
            line-height: 1.6;
            color: #1f2937;
        }
        .plan-content p {
            margin: 0 0 0.75rem;
        }
        .plan-list {
            margin: 0.5rem 0 0.75rem 1.2rem;
            padding: 0;
        }
        .plan-link {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            margin-top: 0.6rem;
            text-decoration: none;
            color: var(--brand-color);
            font-weight: 600;
        }
        .detail-header {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            align-items: center;
            justify-content: space-between;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid #e5e7eb;
            margin-bottom: 1rem;
        }
        .detail-title {
            font-size: 1.1rem;
            font-weight: 700;
        }
        .detail-subtitle {
            color: #6b7280;
            font-size: 0.9rem;
        }
        .detail-meta {
            color: #374151;
            font-weight: 600;
        }
        .toggle-field {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 600;
        }
        .form-note {
            font-size: 0.85rem;
            color: #6b7280;
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
        input[type="checkbox"] {
            width: auto;
            padding: 0;
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
        .assignment-grid,
        .trainer-list {
            display: grid;
            gap: 0.75rem;
        }
        .assignment-row,
        .trainer-card {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            align-items: center;
            justify-content: space-between;
            padding: 0.75rem;
            border-radius: 12px;
            border: 1px solid #e5e7eb;
            background: #fff;
        }
        .assignment-main,
        .trainer-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            align-items: center;
        }
        .assignment-actions,
        .trainer-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            align-items: center;
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
        .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
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

function renderEinsaetze(string $rootPath): string
{
    $trainings = loadTrainingRecords($rootPath);
    $tab = strtolower(trim((string) ($_GET['tab'] ?? 'kommend')));
    $tab = $tab === 'vergangen' ? 'vergangen' : 'kommend';

    $today = (int) (new DateTime('today'))->format('U');
    $upcoming = [];
    $past = [];

    foreach ($trainings as $training) {
        $status = strtolower(trim((string) ($training['status'] ?? '')));
        $isPastStatus = in_array($status, ['stattgefunden', 'ausgefallen'], true);
        $dateTimestamp = parseDateToTimestamp((string) ($training['datum'] ?? ''));
        $isPastDate = $dateTimestamp !== null && $dateTimestamp < $today;
        if ($isPastStatus || $isPastDate) {
            $past[] = $training;
        } else {
            $upcoming[] = $training;
        }
    }

    usort($upcoming, static function (array $a, array $b): int {
        $dateA = parseDateToTimestamp((string) ($a['datum'] ?? '')) ?? PHP_INT_MAX;
        $dateB = parseDateToTimestamp((string) ($b['datum'] ?? '')) ?? PHP_INT_MAX;
        if ($dateA === $dateB) {
            return strcmp((string) ($a['start'] ?? ''), (string) ($b['start'] ?? ''));
        }
        return $dateA <=> $dateB;
    });

    usort($past, static function (array $a, array $b): int {
        $dateA = parseDateToTimestamp((string) ($a['datum'] ?? '')) ?? 0;
        $dateB = parseDateToTimestamp((string) ($b['datum'] ?? '')) ?? 0;
        if ($dateA === $dateB) {
            return strcmp((string) ($b['start'] ?? ''), (string) ($a['start'] ?? ''));
        }
        return $dateB <=> $dateA;
    });

    $activeList = $tab === 'vergangen' ? $past : $upcoming;
    $emptyText = $tab === 'vergangen'
        ? 'Keine vergangenen Einsätze gefunden.'
        : 'Keine kommenden Einsätze ab heute geplant.';

    $cardsHtml = '';
    foreach ($activeList as $training) {
        $trainingId = (string) ($training['training_id'] ?? '');
        $timeLabel = formatTrainingTimeLabel($training);
        $timeLabel = $timeLabel !== '' ? $timeLabel : 'Uhrzeit offen';
        $dateLabel = formatTrainingDateLabel((string) ($training['datum'] ?? ''));
        $dateLabel = $dateLabel !== '' ? $dateLabel : 'Datum offen';
        $groupLabel = trim((string) ($training['gruppe'] ?? ''));
        $groupLabel = $groupLabel !== '' ? $groupLabel : 'Gruppe offen';
        $statusBadge = renderEinsatzStatusBadge((string) ($training['status'] ?? ''));
        $location = trim((string) ($training['ort'] ?? ''));
        $locationHtml = $location !== '' ? '<p class="helper">' . htmlspecialchars($location) . '</p>' : '';
        $detailLink = $trainingId !== '' ? '/training?training_id=' . rawurlencode($trainingId) : '/training';

        $cardsHtml .= '<a class="einsatz-card" href="' . $detailLink . '">'
            . '<div class="einsatz-card-top"><span class="einsatz-time">' . htmlspecialchars($timeLabel) . '</span>' . $statusBadge . '</div>'
            . '<div class="einsatz-main">'
            . '<span class="einsatz-group">' . htmlspecialchars($groupLabel) . '</span>'
            . '<span class="einsatz-date">' . htmlspecialchars($dateLabel) . '</span>'
            . '</div>'
            . $locationHtml
            . '</a>';
    }

    if ($cardsHtml === '') {
        $cardsHtml = '<p class="helper">' . htmlspecialchars($emptyText) . '</p>';
    }

    $tabs = [
        'kommend' => 'Kommend',
        'vergangen' => 'Vergangene',
    ];

    $tabHtml = '<div class="tab-bar" role="tablist" aria-label="Einsatzübersicht">';
    foreach ($tabs as $key => $label) {
        $isActive = $tab === $key;
        $class = $isActive ? 'tab active' : 'tab';
        $tabHtml .= '<a class="' . $class . '" href="/einsaetze?tab=' . $key . '" role="tab" aria-selected="' . ($isActive ? 'true' : 'false') . '">' . htmlspecialchars($label) . '</a>';
    }
    $tabHtml .= '</div>';

    $helperText = $tab === 'vergangen'
        ? 'Vor heute oder bereits als stattgefunden/ausgefallen markiert.'
        : 'Ab heute geplante Einsätze.';

    return '<section class="card">'
        . '<h1>Einsätze</h1>'
        . '<p class="helper">' . htmlspecialchars($helperText) . '</p>'
        . '</section>'
        . $tabHtml
        . '<section class="einsatz-list">' . $cardsHtml . '</section>';
}

function renderTrainingDetail(string $rootPath, ?string $message, string $messageType): string
{
    $trainingId = trim((string) ($_GET['training_id'] ?? ''));
    $training = $trainingId !== '' ? findTrainingById(loadTrainingRecords($rootPath), $trainingId) : null;

    if (!$training) {
        return '<section class="card">'
            . '<h1>Training nicht gefunden</h1>'
            . '<p class="helper">Der Einsatz konnte nicht geladen werden. Bitte wähle einen Eintrag aus der Übersicht.</p>'
            . '<a class="button-link secondary" href="/einsaetze">Zurück zu Einsätzen</a>'
            . '</section>';
    }

    $dateLabel = formatTrainingDateLabel((string) ($training['datum'] ?? ''));
    $dateLabel = $dateLabel !== '' ? $dateLabel : 'Datum offen';
    $timeLabel = formatTrainingTimeLabel($training);
    $timeLabel = $timeLabel !== '' ? $timeLabel : 'Uhrzeit offen';
    $groupLabel = trim((string) ($training['gruppe'] ?? ''));
    $groupLabel = $groupLabel !== '' ? $groupLabel : 'Gruppe offen';
    $location = trim((string) ($training['ort'] ?? ''));
    $location = $location !== '' ? $location : 'Ort offen';
    $statusBadge = renderEinsatzStatusBadge((string) ($training['status'] ?? ''));
    $note = trim((string) ($training['bemerkung'] ?? ''));
    $cancelReason = trim((string) ($training['ausfall_grund'] ?? ''));
    $trainingPlans = loadTrainingPlanRecords($rootPath);
    $trainingPlan = $trainingPlans[$trainingId] ?? null;

    $trainerId = (string) ($_SESSION['user']['trainer_id'] ?? '');
    $trainer = $trainerId !== '' ? findTrainerById(loadTrainerRecords($rootPath), $trainerId) : null;
    $assignment = null;
    if ($trainerId !== '') {
        $assignmentRecords = loadAssignmentRecords($rootPath);
        foreach ($assignmentRecords as $assignmentRecord) {
            if ($assignmentRecord['training_id'] !== $trainingId) {
                continue;
            }
            if ($assignmentRecord['trainer_id'] !== $trainerId) {
                continue;
            }
            if ($assignmentRecord['ausgetragen_am'] !== '') {
                continue;
            }
            $assignment = $assignmentRecord;
            break;
        }
    }

    $activeAbmeldungen = collectActiveAbmeldungen($rootPath);
    $abmeldungKey = buildAbmeldungKey($trainingId, $trainerId);
    $activeAbmeldung = $abmeldungKey !== '' ? ($activeAbmeldungen[$abmeldungKey] ?? null) : null;
    $assignmentAbgemeldetAm = $assignment !== null ? trim((string) ($assignment['abgemeldet_am'] ?? '')) : '';
    $isAbgemeldet = $activeAbmeldung !== null || $assignmentAbgemeldetAm !== '';

    $monthStatus = resolveTrainingMonthStatus($rootPath, (string) ($training['datum'] ?? ''));
    $isMonthLocked = isTrainingMonthLocked($monthStatus);
    $isEditable = $assignment !== null && !$isMonthLocked && !$isAbgemeldet;
    $disabledAttr = $isEditable ? '' : ' disabled';

    $details = '<div class="detail-list">'
        . '<div class="detail-row"><span class="detail-label">Ort</span><span class="detail-value">' . htmlspecialchars($location) . '</span></div>'
        . '</div>';

    $noteHtml = '';
    if ($cancelReason !== '') {
        $noteHtml .= '<p class="helper"><strong>Absagegrund:</strong> ' . htmlspecialchars($cancelReason) . '</p>';
    }
    if ($note !== '') {
        $noteHtml .= '<p class="helper"><strong>Hinweis:</strong> ' . htmlspecialchars($note) . '</p>';
    }

    $flashHtml = '';
    if ($message !== null && $message !== '') {
        $flashHtml = '<div class="flash ' . htmlspecialchars($messageType) . '">' . htmlspecialchars($message) . '</div>';
    }

    $roleRates = loadRoleRates($rootPath);
    $roleValue = $assignment['rolle'] ?? '';
    $roleLabel = $roleValue !== '' ? $roleValue : (string) ($trainer['rolle_standard'] ?? '');
    $canEditRole = isAdmin();

    $roleOptions = '';
    foreach ($roleRates as $role => $_rate) {
        $selected = $role === $roleValue ? ' selected' : '';
        $roleOptions .= '<option value="' . htmlspecialchars($role) . '"' . $selected . '>' . htmlspecialchars($role) . '</option>';
    }
    if ($roleOptions === '' && $roleLabel !== '') {
        $roleOptions = '<option value="' . htmlspecialchars($roleLabel) . '">' . htmlspecialchars($roleLabel) . '</option>';
    }

    $attendance = strtoupper(trim((string) ($assignment['attendance'] ?? 'NEIN')));
    $attendanceChecked = $attendance === 'JA';
    $commentValue = trim((string) ($assignment['kommentar'] ?? ''));
    $startValue = trim((string) ($assignment['einsatz_start'] ?? ''));
    $endValue = trim((string) ($assignment['einsatz_ende'] ?? ''));

    $monthLockHtml = '';
    if ($isMonthLocked) {
        $monthLockHtml = '<p class="helper"><strong>Monatsstatus:</strong> ' . htmlspecialchars($monthStatus)
            . ' · Änderungen sind gesperrt.</p>';
    }

    $assignmentForm = '';
    if ($assignment !== null) {
        $roleField = '';
        if ($canEditRole) {
            $roleField = '<div><label for="rolle">Rolle</label><select id="rolle" name="rolle"' . $disabledAttr . '>'
                . $roleOptions
                . '</select></div>';
        } elseif ($roleLabel !== '') {
            $roleField = '<div><label>Rolle</label><div class="helper">' . htmlspecialchars($roleLabel) . '</div></div>';
        }

        $assignmentForm = '<form class="form-grid" method="post" action="/training?training_id=' . rawurlencode($trainingId) . '">'
            . '<input type="hidden" name="action" value="update_training_detail">'
            . '<input type="hidden" name="training_id" value="' . htmlspecialchars($trainingId) . '">'
            . '<input type="hidden" name="einteilung_id" value="' . htmlspecialchars($assignment['einteilung_id']) . '">'
            . $roleField
            . '<div class="toggle-field"><input id="attendance" type="checkbox" name="attendance" value="JA"' . ($attendanceChecked ? ' checked' : '') . $disabledAttr . '>'
            . '<label for="attendance">Teilnahme bestätigt</label></div>'
            . '<div><label for="kommentar">Kommentar / Notiz</label><textarea id="kommentar" name="kommentar"' . $disabledAttr . '>'
            . htmlspecialchars($commentValue) . '</textarea></div>'
            . '<div class="form-grid cols-2">'
            . '<div><label for="einsatz_start">Start (optional)</label><input id="einsatz_start" name="einsatz_start" type="time" value="' . htmlspecialchars($startValue) . '"' . $disabledAttr . '></div>'
            . '<div><label for="einsatz_ende">Ende (optional)</label><input id="einsatz_ende" name="einsatz_ende" type="time" value="' . htmlspecialchars($endValue) . '"' . $disabledAttr . '></div>'
            . '</div>'
            . '<div class="form-note">Hinweis: Abrechnung erfolgt pro Einheit, Zeiten sind optional.</div>'
            . '<button class="primary" type="submit"' . ($isEditable ? '' : ' disabled') . '>Speichern</button>'
            . '</form>';
    } else {
        $assignmentForm = '<p class="helper">Für dieses Training bist du nicht eingeteilt. Änderungen sind nicht möglich.</p>';
    }

    $abmeldungHtml = '';
    if ($assignment !== null) {
        if ($isAbgemeldet) {
            $abmeldungGrund = trim((string) ($activeAbmeldung['grund'] ?? ''));
            $abmeldungZeit = trim((string) ($activeAbmeldung['abgemeldet_am'] ?? ''));
            $abmeldungInfo = 'Du hast dich bereits abgemeldet.';
            if ($abmeldungZeit !== '') {
                $abmeldungInfo .= ' (' . htmlspecialchars($abmeldungZeit) . ')';
            }
            $abmeldungHtml = '<div class="card">'
                . '<strong>' . $abmeldungInfo . '</strong>'
                . ($abmeldungGrund !== '' ? '<p class="helper">Grund: ' . htmlspecialchars($abmeldungGrund) . '</p>' : '')
                . '</div>';
        } elseif ($isMonthLocked) {
            $abmeldungHtml = '<p class="helper">Abmeldungen sind in gesperrten Monaten nicht möglich.</p>';
        } else {
            $abmeldungHtml = '<form class="form-grid" method="post" action="/training?training_id=' . rawurlencode($trainingId) . '" onsubmit="return confirm(\'Abmeldung wirklich absenden?\')">'
                . '<input type="hidden" name="action" value="cancel_training_assignment">'
                . '<input type="hidden" name="training_id" value="' . htmlspecialchars($trainingId) . '">'
                . '<input type="hidden" name="einteilung_id" value="' . htmlspecialchars($assignment['einteilung_id']) . '">'
                . '<div><label for="abmeldung_grund">Abmeldung – Grund</label><textarea id="abmeldung_grund" name="abmeldung_grund" required></textarea></div>'
                . '<button class="danger" type="submit">Abmelden</button>'
                . '</form>';
        }
    }

    $headerHtml = '<div class="detail-header">'
        . '<div><div class="detail-title">' . htmlspecialchars($timeLabel) . '</div>'
        . '<div class="detail-subtitle">' . htmlspecialchars($groupLabel) . '</div></div>'
        . '<div class="detail-meta">' . htmlspecialchars($dateLabel) . '</div>'
        . $statusBadge
        . '</div>';

    $planHtml = '';
    if ($trainingPlan !== null) {
        $planTitle = trim((string) ($trainingPlan['titel'] ?? ''));
        $planContent = trim((string) ($trainingPlan['inhalt'] ?? ''));
        $planLink = trim((string) ($trainingPlan['link'] ?? ''));
        $planUpdated = trim((string) ($trainingPlan['updated_at'] ?? ''));
        $planMeta = $planUpdated !== '' ? '<div class="plan-meta">Zuletzt aktualisiert: ' . htmlspecialchars($planUpdated) . '</div>' : '';

        if ($planTitle !== '' || $planContent !== '' || $planLink !== '') {
            $planContentHtml = $planContent !== '' ? renderTrainingPlanContent($planContent) : '<p class="helper">Kein Inhalt hinterlegt.</p>';
            $planTitleHtml = $planTitle !== '' ? '<h2 class="plan-title">' . htmlspecialchars($planTitle) . '</h2>' : '<h2 class="plan-title">Trainingsplan</h2>';
            $planLinkHtml = $planLink !== '' ? '<a class="plan-link" href="' . htmlspecialchars($planLink) . '" target="_blank" rel="noopener">Material/Link öffnen →</a>' : '';
            $planHtml = '<section class="card plan-card">'
                . $planTitleHtml
                . $planMeta
                . '<div class="plan-content">' . $planContentHtml . '</div>'
                . $planLinkHtml
                . '</section>';
        }
    }

    return '<section class="card">'
        . '<a class="button-link secondary" href="/einsaetze">Zurück zu Einsätzen</a>'
        . $flashHtml
        . '<h1>Training-Detail</h1>'
        . $headerHtml
        . $details
        . $noteHtml
        . $monthLockHtml
        . $assignmentForm
        . $abmeldungHtml
        . '</section>'
        . $planHtml;
}

function renderTrainingPlanContent(string $content): string
{
    $normalized = normalizeTrainingPlanContent($content);
    if ($normalized === '') {
        return '<p class="helper">Kein Inhalt hinterlegt.</p>';
    }

    if (looksLikeJson($normalized)) {
        $decoded = json_decode($normalized, true);
        if (is_array($decoded)) {
            return renderTrainingPlanJson($decoded);
        }
    }

    $paragraphs = preg_split("/\\n\\s*\\n/", $normalized) ?: [];
    $html = '';
    foreach ($paragraphs as $paragraph) {
        $paragraph = trim($paragraph);
        if ($paragraph === '') {
            continue;
        }
        $lines = array_values(array_filter(array_map('trim', explode("\n", $paragraph)), static fn (string $line): bool => $line !== ''));
        $safeLines = array_map(static fn (string $line): string => htmlspecialchars($line), $lines);
        $html .= '<p>' . implode('<br>', $safeLines) . '</p>';
    }

    return $html !== '' ? $html : '<p class="helper">Kein Inhalt hinterlegt.</p>';
}

function normalizeTrainingPlanContent(string $content): string
{
    $normalized = preg_replace('/<br\\s*\\/?>/i', "\n", $content);
    $normalized = str_replace(["\r\n", "\r"], "\n", $normalized ?? '');
    return trim($normalized);
}

function looksLikeJson(string $value): bool
{
    $trimmed = ltrim($value);
    return $trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[');
}

function renderTrainingPlanJson(array $data): string
{
    if (isset($data['sections']) && is_array($data['sections'])) {
        return renderTrainingPlanSections($data['sections']);
    }

    if (array_is_list($data)) {
        $allStrings = true;
        foreach ($data as $item) {
            if (!is_string($item)) {
                $allStrings = false;
                break;
            }
        }
        if ($allStrings) {
            return renderTrainingPlanList($data);
        }

        $html = '';
        foreach ($data as $item) {
            if (is_array($item)) {
                $html .= renderTrainingPlanJson($item);
            } elseif (is_string($item)) {
                $html .= '<p>' . htmlspecialchars($item) . '</p>';
            }
        }
        return $html !== '' ? $html : '<p class="helper">Kein Inhalt hinterlegt.</p>';
    }

    $items = [];
    foreach ($data as $key => $value) {
        $label = htmlspecialchars((string) $key);
        if (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE) ?: '';
        }
        $items[] = '<li><strong>' . $label . ':</strong> ' . htmlspecialchars((string) $value) . '</li>';
    }
    return $items !== [] ? '<ul class="plan-list">' . implode('', $items) . '</ul>' : '<p class="helper">Kein Inhalt hinterlegt.</p>';
}

function renderTrainingPlanSections(array $sections): string
{
    $html = '';
    foreach ($sections as $section) {
        if (!is_array($section)) {
            continue;
        }
        $title = trim((string) ($section['title'] ?? $section['titel'] ?? ''));
        if ($title !== '') {
            $html .= '<p><strong>' . htmlspecialchars($title) . '</strong></p>';
        }
        $items = $section['items'] ?? $section['punkte'] ?? null;
        if (is_array($items)) {
            $html .= renderTrainingPlanList($items);
        } elseif (is_string($items) && trim($items) !== '') {
            $html .= '<p>' . htmlspecialchars($items) . '</p>';
        }
    }
    return $html !== '' ? $html : '<p class="helper">Kein Inhalt hinterlegt.</p>';
}

function renderTrainingPlanList(array $items): string
{
    $listItems = [];
    foreach ($items as $item) {
        if ($item === null || $item === '') {
            continue;
        }
        if (is_array($item)) {
            $item = json_encode($item, JSON_UNESCAPED_UNICODE) ?: '';
        }
        $listItems[] = '<li>' . htmlspecialchars((string) $item) . '</li>';
    }
    return $listItems !== [] ? '<ul class="plan-list">' . implode('', $listItems) . '</ul>' : '';
}

function handleTrainingDetailPost(string $rootPath): array
{
    $action = trim((string) ($_POST['action'] ?? ''));
    if (!in_array($action, ['update_training_detail', 'cancel_training_assignment'], true)) {
        return ['Aktion nicht unterstützt.', 'error'];
    }

    $trainingId = trim((string) ($_POST['training_id'] ?? ''));
    $assignmentId = trim((string) ($_POST['einteilung_id'] ?? ''));
    if ($trainingId === '' || $assignmentId === '') {
        return ['Training oder Einteilung fehlt.', 'error'];
    }

    $training = findTrainingById(loadTrainingRecords($rootPath), $trainingId);
    if (!$training) {
        return ['Training nicht gefunden.', 'error'];
    }

    $monthStatus = resolveTrainingMonthStatus($rootPath, (string) ($training['datum'] ?? ''));
    if (isTrainingMonthLocked($monthStatus)) {
        return ['Monatsstatus ist gesperrt oder freigegeben. Änderungen sind nicht möglich.', 'error'];
    }

    $assignment = findAssignmentById(loadAssignmentRecords($rootPath), $assignmentId);
    if (!$assignment) {
        return ['Einteilung nicht gefunden.', 'error'];
    }
    if ($assignment['training_id'] !== $trainingId) {
        return ['Einteilung gehört nicht zu diesem Training.', 'error'];
    }

    $trainerId = (string) ($_SESSION['user']['trainer_id'] ?? '');
    if (!isAdmin() && ($trainerId === '' || $assignment['trainer_id'] !== $trainerId)) {
        return ['Keine Berechtigung für diese Einteilung.', 'error'];
    }

    $abmeldungKey = buildAbmeldungKey($trainingId, $assignment['trainer_id']);
    $activeAbmeldungen = collectActiveAbmeldungen($rootPath);
    $alreadyAbgemeldet = $assignment['abgemeldet_am'] !== '' || ($abmeldungKey !== '' && isset($activeAbmeldungen[$abmeldungKey]));

    if ($action === 'cancel_training_assignment') {
        $grund = trim((string) ($_POST['abmeldung_grund'] ?? ''));
        if ($grund === '') {
            return ['Bitte einen Grund für die Abmeldung angeben.', 'error'];
        }

        if ($alreadyAbgemeldet) {
            return ['Du bist bereits abgemeldet.', 'error'];
        }

        $timestamp = date('c');
        $abmeldungStore = loadAbmeldungStore($rootPath);
        $abmeldungStore['abmeldungen'][] = [
            'training_id' => $trainingId,
            'trainer_id' => $assignment['trainer_id'],
            'grund' => $grund,
            'abgemeldet_am' => $timestamp,
            'deleted_at' => '',
        ];
        saveAbmeldungStore($rootPath, $abmeldungStore);

        $assignment['abgemeldet_am'] = $timestamp;
        $assignmentStore = loadAssignmentStore($rootPath);
        $assignmentStore['assignments'][$assignmentId] = $assignment;
        saveAssignmentStore($rootPath, $assignmentStore);

        return ['Abmeldung gespeichert.', 'success'];
    }

    if ($alreadyAbgemeldet) {
        return ['Abgemeldete Einteilungen können nicht mehr bearbeitet werden.', 'error'];
    }

    $attendance = isset($_POST['attendance']) ? 'JA' : 'NEIN';
    $assignment['attendance'] = $attendance;
    $assignment['kommentar'] = trim((string) ($_POST['kommentar'] ?? ''));
    $assignment['einsatz_start'] = normalizeTime((string) ($_POST['einsatz_start'] ?? ''));
    $assignment['einsatz_ende'] = normalizeTime((string) ($_POST['einsatz_ende'] ?? ''));

    if (isAdmin()) {
        $role = trim((string) ($_POST['rolle'] ?? ''));
        if ($role !== '') {
            $roleRates = loadRoleRates($rootPath);
            $assignment['rolle'] = $role;
            $assignment['satz_eur'] = $roleRates[$role] ?? $assignment['satz_eur'];
        }
    }

    $assignment['training_datum'] = $training['datum'] ?? $assignment['training_datum'];
    $assignment['training_status'] = $training['status'] ?? $assignment['training_status'];
    $assignment['training_dauer_stunden'] = $training['dauer_stunden'] ?? $assignment['training_dauer_stunden'];

    if (strtolower(trim((string) ($training['status'] ?? ''))) === 'ausgefallen') {
        $assignment['betrag_eur'] = '0';
    }

    $assignmentStore = loadAssignmentStore($rootPath);
    $assignmentStore['assignments'][$assignmentId] = $assignment;
    saveAssignmentStore($rootPath, $assignmentStore);

    return ['Training gespeichert.', 'success'];
}

function resolveTrainingMonthStatus(string $rootPath, string $date): string
{
    $store = loadMonthlyStatusStore($rootPath);
    $monthKey = buildMonthKey($date);
    if ($monthKey === '') {
        return 'offen';
    }

    $status = trim((string) ($store['months'][$monthKey] ?? ''));
    return $status !== '' ? $status : 'offen';
}

function isTrainingMonthLocked(string $status): bool
{
    $normalized = strtolower(trim($status));
    return in_array($normalized, ['gesperrt', 'freigegeben'], true);
}

function buildMonthKey(string $date): string
{
    $timestamp = parseDateToTimestamp($date);
    if ($timestamp === null) {
        return '';
    }
    return date('Y-m', $timestamp);
}

function loadMonthlyStatusStore(string $rootPath): array
{
    $path = $rootPath . '/storage/abrechnung_status.json';
    if (!is_file($path)) {
        return ['months' => []];
    }

    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data)) {
        return ['months' => []];
    }

    if (!isset($data['months']) || !is_array($data['months'])) {
        $data['months'] = [];
    }

    return $data;
}

function formatTrainingDateLabel(string $date): string
{
    $timestamp = parseDateToTimestamp($date);
    if ($timestamp === null) {
        return $date;
    }
    return date('d.m.Y', $timestamp);
}

function formatTrainingTimeLabel(array $training): string
{
    $start = trim((string) ($training['start'] ?? ''));
    $end = trim((string) ($training['ende'] ?? ''));
    if ($start === '' && $end === '') {
        return '';
    }
    if ($start !== '' && $end !== '') {
        return $start . '–' . $end;
    }
    return $start !== '' ? $start : $end;
}

function renderEinsatzStatusBadge(string $status): string
{
    $normalized = strtolower(trim($status));
    $labelMap = [
        'geplant' => 'geplant',
        'ausgefallen' => 'abgesagt',
        'stattgefunden' => 'erfasst',
    ];
    $label = $labelMap[$normalized] ?? ($status !== '' ? $status : 'offen');

    $class = 'badge';
    if ($normalized === 'stattgefunden') {
        $class .= ' success';
    } elseif ($normalized === 'ausgefallen') {
        $class .= ' danger';
    } elseif ($normalized === 'geplant') {
        $class .= ' warning';
    }

    return '<span class="' . $class . '">' . htmlspecialchars($label) . '</span>';
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
    $assignmentRecords = loadAssignmentRecords($rootPath);
    $activeAbmeldungen = collectActiveAbmeldungen($rootPath);
    $trainers = loadTrainerRecords($rootPath);
    $roleRates = loadRoleRates($rootPath);
    $trainingPlans = loadTrainingPlanRecords($rootPath);

    $filterStart = trim((string) ($_GET['filter_start'] ?? ''));
    $filterEnd = trim((string) ($_GET['filter_end'] ?? ''));
    $filterGroup = trim((string) ($_GET['filter_group'] ?? ''));
    $filterStatus = trim((string) ($_GET['filter_status'] ?? ''));
    $editId = trim((string) ($_GET['edit'] ?? ''));
    $assignTrainingId = trim((string) ($_GET['assign_training_id'] ?? ''));
    $trainerSearch = trim((string) ($_GET['trainer_search'] ?? ''));
    $planTrainingId = trim((string) ($_GET['plan_training_id'] ?? ''));

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

    $sortedTrainings = $trainings;
    usort($sortedTrainings, static function (array $a, array $b): int {
        $dateA = parseDateToTimestamp($a['datum']) ?? 0;
        $dateB = parseDateToTimestamp($b['datum']) ?? 0;
        if ($dateA === $dateB) {
            return strcmp($a['start'], $b['start']);
        }
        return $dateA <=> $dateB;
    });

    if ($assignTrainingId === '' && $sortedTrainings !== []) {
        $assignTrainingId = $sortedTrainings[0]['training_id'];
    }
    $assignTraining = $assignTrainingId !== '' ? findTrainingById($trainings, $assignTrainingId) : null;

    if ($planTrainingId === '' && $sortedTrainings !== []) {
        $planTrainingId = $sortedTrainings[0]['training_id'];
    }
    $activePlan = $planTrainingId !== '' ? ($trainingPlans[$planTrainingId] ?? null) : null;
    $planTitleValue = htmlspecialchars((string) ($activePlan['titel'] ?? ''));
    $planContentValue = htmlspecialchars((string) ($activePlan['inhalt'] ?? ''));
    $planLinkValue = htmlspecialchars((string) ($activePlan['link'] ?? ''));
    $planUpdatedAt = trim((string) ($activePlan['updated_at'] ?? ''));
    $planUpdatedBy = trim((string) ($activePlan['updated_by'] ?? ''));
    $planMetaHtml = '';
    if ($planUpdatedAt !== '' || $planUpdatedBy !== '') {
        $metaParts = [];
        if ($planUpdatedAt !== '') {
            $metaParts[] = 'Zuletzt: ' . htmlspecialchars($planUpdatedAt);
        }
        if ($planUpdatedBy !== '') {
            $metaParts[] = 'Trainer:in ' . htmlspecialchars($planUpdatedBy);
        }
        $planMetaHtml = '<p class="helper">' . implode(' · ', $metaParts) . '</p>';
    }

    $roleNames = array_keys($roleRates);
    if ($roleNames === []) {
        $roleNames = array_values(array_unique(array_filter(array_map(static fn (array $trainer): string => $trainer['rolle_standard'] ?? '', $trainers))));
    }
    sort($roleNames, SORT_NATURAL | SORT_FLAG_CASE);

    $trainingSelectOptions = '<option value="">Training auswählen</option>';
    foreach ($sortedTrainings as $training) {
        $trainingId = $training['training_id'];
        $selected = $trainingId === $assignTrainingId ? ' selected' : '';
        $label = buildTrainingLabel($training);
        $trainingSelectOptions .= '<option value="' . htmlspecialchars($trainingId) . '"' . $selected . '>' . $label . '</option>';
    }

    $planTrainingOptions = '<option value="">Training auswählen</option>';
    foreach ($sortedTrainings as $training) {
        $trainingId = $training['training_id'];
        $selected = $trainingId === $planTrainingId ? ' selected' : '';
        $label = buildTrainingLabel($training);
        $planTrainingOptions .= '<option value="' . htmlspecialchars($trainingId) . '"' . $selected . '>' . $label . '</option>';
    }

    $trainerById = [];
    foreach ($trainers as $trainer) {
        $trainerById[$trainer['trainer_id']] = $trainer;
    }

    $assignmentsForTraining = [];
    $assignedTrainerIds = [];
    if ($assignTrainingId !== '') {
        foreach ($assignmentRecords as $assignment) {
            if ($assignment['training_id'] !== $assignTrainingId) {
                continue;
            }
            if ($assignment['ausgetragen_am'] !== '') {
                continue;
            }
            $abmeldungKey = buildAbmeldungKey($assignment['training_id'], $assignment['trainer_id']);
            if ($abmeldungKey !== '' && isset($activeAbmeldungen[$abmeldungKey])) {
                continue;
            }
            $assignmentsForTraining[] = $assignment;
            if ($assignment['trainer_id'] !== '') {
                $assignedTrainerIds[$assignment['trainer_id']] = true;
            }
        }
    }

    usort($assignmentsForTraining, static function (array $a, array $b) use ($trainerById): int {
        $nameA = $trainerById[$a['trainer_id']]['name'] ?? $a['trainer_id'];
        $nameB = $trainerById[$b['trainer_id']]['name'] ?? $b['trainer_id'];
        return strcasecmp($nameA, $nameB);
    });

    $assignmentRowsHtml = '';
    foreach ($assignmentsForTraining as $assignment) {
        $trainerId = $assignment['trainer_id'];
        $trainerName = $trainerById[$trainerId]['name'] ?? $trainerId;
        $roleValue = $assignment['rolle'] !== '' ? $assignment['rolle'] : ($trainerById[$trainerId]['rolle_standard'] ?? '');
        $satzLabel = $assignment['satz_eur'] !== '' ? $assignment['satz_eur'] . ' €' : '—';
        $betragLabel = $assignment['betrag_eur'] !== '' ? $assignment['betrag_eur'] . ' €' : '—';
        $query = buildAdminQuery(['assign_training_id' => $assignTrainingId, 'trainer_search' => $trainerSearch]);

        $assignmentRowsHtml .= '<div class="assignment-row">'
            . '<div class="assignment-main">'
            . '<strong>' . htmlspecialchars($trainerName) . '</strong>'
            . '<span class="badge">' . htmlspecialchars($trainerId) . '</span>'
            . '<span class="badge">Rolle: ' . htmlspecialchars($roleValue) . '</span>'
            . '<span class="badge">Satz: ' . htmlspecialchars($satzLabel) . '</span>'
            . '<span class="badge">Betrag: ' . htmlspecialchars($betragLabel) . '</span>'
            . '</div>'
            . '<div class="assignment-actions">'
            . '<form method="post" action="/admin' . $query . '">'
            . '<input type="hidden" name="action" value="update_assignment">'
            . '<input type="hidden" name="einteilung_id" value="' . htmlspecialchars($assignment['einteilung_id']) . '">'
            . '<input type="hidden" name="training_id" value="' . htmlspecialchars($assignTrainingId) . '">'
            . '<label class="sr-only" for="role_' . htmlspecialchars($assignment['einteilung_id']) . '">Rolle</label>'
            . '<select id="role_' . htmlspecialchars($assignment['einteilung_id']) . '" name="rolle">'
            . renderRoleOptions($roleNames, $roleValue)
            . '</select>'
            . '<button class="secondary" type="submit">Rolle ändern</button>'
            . '</form>'
            . '<form method="post" action="/admin' . $query . '" onsubmit="return confirm(\'Einteilung entfernen?\')">'
            . '<input type="hidden" name="action" value="remove_assignment">'
            . '<input type="hidden" name="einteilung_id" value="' . htmlspecialchars($assignment['einteilung_id']) . '">'
            . '<input type="hidden" name="training_id" value="' . htmlspecialchars($assignTrainingId) . '">'
            . '<button class="danger" type="submit">Entfernen</button>'
            . '</form>'
            . '</div>'
            . '</div>';
    }

    if ($assignmentRowsHtml === '') {
        $assignmentRowsHtml = '<p class="helper">Noch keine Einteilungen für dieses Training.</p>';
    }

    $activeTrainers = array_values(array_filter($trainers, static fn (array $trainer): bool => $trainer['aktiv']));
    usort($activeTrainers, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

    $filteredTrainers = array_values(array_filter($activeTrainers, static function (array $trainer) use ($trainerSearch): bool {
        if ($trainerSearch === '') {
            return true;
        }
        return stripos($trainer['name'], $trainerSearch) !== false
            || stripos($trainer['trainer_id'], $trainerSearch) !== false;
    }));

    $trainerListHtml = '';
    foreach ($filteredTrainers as $trainer) {
        $trainerId = $trainer['trainer_id'];
        $trainerName = $trainer['name'];
        $defaultRole = $trainer['rolle_standard'] ?? '';
        if ($defaultRole === '' && $roleNames !== []) {
            $defaultRole = $roleNames[0];
        }
        $isAssigned = isset($assignedTrainerIds[$trainerId]);
        $query = buildAdminQuery(['assign_training_id' => $assignTrainingId, 'trainer_search' => $trainerSearch]);

        $trainerListHtml .= '<div class="trainer-card">'
            . '<div class="trainer-meta">'
            . '<strong>' . htmlspecialchars($trainerName) . '</strong>'
            . '<span class="badge">' . htmlspecialchars($trainerId) . '</span>'
            . ($defaultRole !== '' ? '<span class="badge">Standard: ' . htmlspecialchars($defaultRole) . '</span>' : '')
            . ($isAssigned ? '<span class="badge success">Eingeteilt</span>' : '')
            . '</div>'
            . '<div class="trainer-actions">'
            . '<form method="post" action="/admin' . $query . '">'
            . '<input type="hidden" name="action" value="assign_trainer">'
            . '<input type="hidden" name="training_id" value="' . htmlspecialchars($assignTrainingId) . '">'
            . '<input type="hidden" name="trainer_id" value="' . htmlspecialchars($trainerId) . '">'
            . '<label class="sr-only" for="assign_role_' . htmlspecialchars($trainerId) . '">Rolle</label>'
            . '<select id="assign_role_' . htmlspecialchars($trainerId) . '" name="rolle">'
            . renderRoleOptions($roleNames, $defaultRole)
            . '</select>'
            . '<button class="primary" type="submit"' . ($isAssigned || $assignTrainingId === '' ? ' disabled' : '') . '>Zuweisen</button>'
            . '</form>'
            . '</div>'
            . '</div>';
    }

    if ($trainerListHtml === '') {
        $trainerListHtml = '<p class="helper">Keine aktiven Trainer:innen gefunden.</p>';
    }

    $assignStatusHtml = '';
    if ($assignTraining) {
        $neededCount = (int) ($assignTraining['benoetigt_trainer'] ?? 0);
        $assignedCount = (int) ($assignTraining['eingeteilt'] ?? 0);
        $openCount = (int) ($assignTraining['offen'] ?? 0);
        $assignStatusHtml = '<div class="admin-meta">'
            . '<span class="badge">Benötigt: ' . htmlspecialchars((string) $neededCount) . '</span>'
            . '<span class="badge success">Eingeteilt: ' . htmlspecialchars((string) $assignedCount) . '</span>'
            . '<span class="badge warning">Offen: ' . htmlspecialchars((string) $openCount) . '</span>'
            . '</div>';
    }

    $trainerSearchValue = htmlspecialchars($trainerSearch);
    $assignSection = '<section class="card">'
        . '<div class="section-title"><h2>Trainer zuweisen</h2><span class="badge">Einteilungen</span></div>'
        . '<p class="helper">Trainings auswählen, aktive Trainer:innen suchen und Einteilungen mit Rolle verwalten.</p>'
        . '<form class="form-grid cols-2" method="get" action="/admin">'
        . '<div><label for="assign_training_id">Training</label><select id="assign_training_id" name="assign_training_id">' . $trainingSelectOptions . '</select></div>'
        . '<div><label for="trainer_search">Trainer:in suchen</label><input id="trainer_search" name="trainer_search" placeholder="Name oder ID" value="' . $trainerSearchValue . '"></div>'
        . '<div class="admin-actions">'
        . '<button class="secondary" type="submit">Ansicht aktualisieren</button>'
        . '<a class="button-link secondary" href="/admin?assign_training_id=' . rawurlencode($assignTrainingId) . '">Suche zurücksetzen</a>'
        . '</div>'
        . '</form>'
        . $assignStatusHtml
        . '</section>'
        . '<section class="card">'
        . '<div class="section-title"><h2>Aktuelle Einteilungen</h2></div>'
        . '<div class="assignment-grid">' . $assignmentRowsHtml . '</div>'
        . '</section>'
        . '<section class="card">'
        . '<div class="section-title"><h2>Aktive Trainer:innen</h2></div>'
        . '<div class="trainer-list">' . $trainerListHtml . '</div>'
        . '</section>';

    $planQuery = buildAdminQuery(['plan_training_id' => $planTrainingId]);
    $planSection = '<section class="card">'
        . '<div class="section-title"><h2>Trainingsplan verwalten</h2><span class="badge">Inhalte</span></div>'
        . '<p class="helper">Pro Training kann ein Trainingsplan mit Titel, Inhalt und optionalem Link gepflegt werden.</p>'
        . '<form class="form-grid cols-2" method="get" action="/admin">'
        . '<div><label for="plan_training_id">Training</label><select id="plan_training_id" name="plan_training_id">' . $planTrainingOptions . '</select></div>'
        . '<div class="admin-actions">'
        . '<button class="secondary" type="submit">Training laden</button>'
        . '<a class="button-link secondary" href="/admin">Zurücksetzen</a>'
        . '</div>'
        . '</form>'
        . $planMetaHtml
        . '<form class="form-grid" method="post" action="/admin' . $planQuery . '">'
        . '<input type="hidden" name="action" value="save_training_plan">'
        . '<input type="hidden" name="training_id" value="' . htmlspecialchars($planTrainingId) . '">'
        . '<div><label for="plan_titel">Titel</label><input id="plan_titel" name="titel" placeholder="z.B. Technikschwerpunkt" value="' . $planTitleValue . '"></div>'
        . '<div><label for="plan_link">Link (optional)</label><input id="plan_link" name="link" type="url" placeholder="https://..." value="' . $planLinkValue . '"></div>'
        . '<div><label for="plan_inhalt">Inhalt</label><textarea id="plan_inhalt" name="inhalt" placeholder="Inhalte, Material oder Abläufe – am besten mit Zeilenumbrüchen.">'
        . $planContentValue
        . '</textarea><div class="form-note">Tipp: Absätze werden automatisch in gut lesbare Blöcke umgewandelt.</div></div>'
        . '<button class="primary" type="submit"' . ($planTrainingId === '' ? ' disabled' : '') . '>Trainingsplan speichern</button>'
        . '</form>'
        . '</section>';

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
        . $planSection
        . $assignSection
        . $editSection
        . $createSection
        . $createSeriesSection;
}

function handleTrainingAdminPost(string $rootPath): array
{
    $action = trim((string) ($_POST['action'] ?? ''));
    $store = loadTrainingStore($rootPath);
    $assignments = loadTrainingAssignments($rootPath);

    if ($action === 'save_training_plan') {
        $trainingId = trim((string) ($_POST['training_id'] ?? ''));
        if ($trainingId === '') {
            return ['Bitte ein Training auswählen.', 'error', []];
        }

        $training = findTrainingById(loadTrainingRecords($rootPath), $trainingId);
        if (!$training) {
            return ['Training nicht gefunden.', 'error', []];
        }

        $titel = trim((string) ($_POST['titel'] ?? ''));
        $inhalt = trim((string) ($_POST['inhalt'] ?? ''));
        $link = trim((string) ($_POST['link'] ?? ''));

        if ($titel === '' && $inhalt === '' && $link === '') {
            return ['Bitte Titel, Inhalt oder Link ausfüllen.', 'error', []];
        }

        $existingPlans = loadTrainingPlanRecords($rootPath);
        $existing = $existingPlans[$trainingId] ?? null;
        $existingIds = collectExistingTrainingPlanIds($rootPath);
        $planId = trim((string) ($existing['plan_id'] ?? ''));
        if ($planId === '') {
            $planId = generateTrainingPlanId($existingIds);
        }

        $now = date('d.m.Y');
        $trainerId = (string) ($_SESSION['user']['trainer_id'] ?? '');
        $createdAt = trim((string) ($existing['created_at'] ?? ''));
        $createdBy = trim((string) ($existing['created_by'] ?? ''));
        if ($createdAt === '') {
            $createdAt = $now;
        }
        if ($createdBy === '') {
            $createdBy = $trainerId;
        }

        $planRecord = [
            'plan_id' => $planId,
            'training_id' => $trainingId,
            'titel' => $titel,
            'inhalt' => $inhalt,
            'link' => $link,
            'created_at' => $createdAt,
            'created_by' => $createdBy,
            'updated_at' => $now,
            'updated_by' => $trainerId,
            'deleted_at' => '',
        ];

        $planStore = loadTrainingPlanStore($rootPath);
        $planStore['plans'][$trainingId] = $planRecord;
        saveTrainingPlanStore($rootPath, $planStore);

        return ['Trainingsplan gespeichert.', 'success', []];
    }

    if ($action === 'assign_trainer') {
        $trainingId = trim((string) ($_POST['training_id'] ?? ''));
        $trainerId = trim((string) ($_POST['trainer_id'] ?? ''));
        if ($trainingId === '' || $trainerId === '') {
            return ['Training und Trainer:in müssen ausgewählt sein.', 'error', []];
        }

        $training = findTrainingById(loadTrainingRecords($rootPath), $trainingId);
        if (!$training) {
            return ['Training nicht gefunden.', 'error', []];
        }

        $trainer = findTrainerById(loadTrainerRecords($rootPath), $trainerId);
        if (!$trainer) {
            return ['Trainer:in nicht gefunden.', 'error', []];
        }

        $assignmentRecords = loadAssignmentRecords($rootPath);
        foreach ($assignmentRecords as $assignment) {
            if ($assignment['training_id'] === $trainingId && $assignment['trainer_id'] === $trainerId && $assignment['ausgetragen_am'] === '') {
                return ['Trainer:in ist bereits eingeteilt.', 'error', []];
            }
        }

        $roleRates = loadRoleRates($rootPath);
        $role = trim((string) ($_POST['rolle'] ?? ''));
        if ($role === '') {
            $role = $trainer['rolle_standard'] ?? '';
        }
        if ($role === '' && $roleRates !== []) {
            $role = array_key_first($roleRates);
        }

        $satz = $role !== '' && isset($roleRates[$role]) ? $roleRates[$role] : '0';
        $assignmentId = generateAssignmentId($rootPath);
        $record = [
            'einteilung_id' => $assignmentId,
            'training_id' => $trainingId,
            'trainer_id' => $trainerId,
            'rolle' => $role,
            'eingetragen_am' => date('c'),
            'ausgetragen_am' => '',
            'attendance' => 'NEIN',
            'checkin_am' => '',
            'kommentar' => '',
            'einsatz_start' => '',
            'einsatz_ende' => '',
            'training_datum' => $training['datum'] ?? '',
            'training_status' => $training['status'] ?? '',
            'training_dauer_stunden' => $training['dauer_stunden'] ?? '',
            'satz_eur' => $satz,
            'betrag_eur' => '0',
        ];

        $assignmentStore = loadAssignmentStore($rootPath);
        $assignmentStore['assignments'][$assignmentId] = $record;
        saveAssignmentStore($rootPath, $assignmentStore);

        return ['Trainer:in wurde eingeteilt.', 'success', []];
    }

    if ($action === 'update_assignment') {
        $assignmentId = trim((string) ($_POST['einteilung_id'] ?? ''));
        if ($assignmentId === '') {
            return ['Einteilung-ID fehlt.', 'error', []];
        }

        $assignment = findAssignmentById(loadAssignmentRecords($rootPath), $assignmentId);
        if (!$assignment) {
            return ['Einteilung nicht gefunden.', 'error', []];
        }

        $roleRates = loadRoleRates($rootPath);
        $role = trim((string) ($_POST['rolle'] ?? ''));
        if ($role !== '') {
            $assignment['rolle'] = $role;
            $assignment['satz_eur'] = $roleRates[$role] ?? $assignment['satz_eur'];
        }

        $training = findTrainingById(loadTrainingRecords($rootPath), $assignment['training_id']);
        if ($training) {
            $assignment['training_datum'] = $training['datum'] ?? $assignment['training_datum'];
            $assignment['training_status'] = $training['status'] ?? $assignment['training_status'];
            $assignment['training_dauer_stunden'] = $training['dauer_stunden'] ?? $assignment['training_dauer_stunden'];
        }

        $assignmentStore = loadAssignmentStore($rootPath);
        $assignmentStore['assignments'][$assignmentId] = $assignment;
        saveAssignmentStore($rootPath, $assignmentStore);

        return ['Einteilung aktualisiert.', 'success', []];
    }

    if ($action === 'remove_assignment') {
        $assignmentId = trim((string) ($_POST['einteilung_id'] ?? ''));
        if ($assignmentId === '') {
            return ['Einteilung-ID fehlt.', 'error', []];
        }

        $assignment = findAssignmentById(loadAssignmentRecords($rootPath), $assignmentId);
        if (!$assignment) {
            return ['Einteilung nicht gefunden.', 'error', []];
        }

        $assignment['ausgetragen_am'] = date('c');
        $assignmentStore = loadAssignmentStore($rootPath);
        $assignmentStore['assignments'][$assignmentId] = $assignment;
        saveAssignmentStore($rootPath, $assignmentStore);

        return ['Einteilung entfernt.', 'success', []];
    }

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

function loadTrainingPlanRecords(string $rootPath): array
{
    $rows = loadHtmlRows($rootPath . '/database/TRAININGSPLAN.html');
    $records = [];

    if ($rows) {
        [$header, $dataRows] = $rows;
        $header = normalizeHeaderCells($header);

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

            $record = [
                'plan_id' => trim((string) ($assoc['plan_id'] ?? '')),
                'training_id' => $trainingId,
                'titel' => trim((string) ($assoc['titel'] ?? '')),
                'inhalt' => trim((string) ($assoc['inhalt'] ?? '')),
                'link' => trim((string) ($assoc['link'] ?? '')),
                'created_at' => trim((string) ($assoc['created_at'] ?? '')),
                'created_by' => trim((string) ($assoc['created_by'] ?? '')),
                'updated_at' => trim((string) ($assoc['updated_at'] ?? '')),
                'updated_by' => trim((string) ($assoc['updated_by'] ?? '')),
                'deleted_at' => trim((string) ($assoc['deleted_at'] ?? '')),
            ];

            if ($record['deleted_at'] !== '') {
                continue;
            }

            if (!isset($records[$trainingId]) || isTrainingPlanNewer($record, $records[$trainingId])) {
                $records[$trainingId] = $record;
            }
        }
    }

    $store = loadTrainingPlanStore($rootPath);
    foreach ($store['plans'] as $trainingId => $record) {
        $trainingId = trim((string) $trainingId);
        if ($trainingId === '') {
            continue;
        }
        $records[$trainingId] = array_merge($records[$trainingId] ?? defaultTrainingPlanRecord($trainingId), $record);
    }

    return $records;
}

function isTrainingPlanNewer(array $candidate, array $current): bool
{
    $candidateStamp = parseDateToTimestamp($candidate['updated_at'] ?? '') ?? 0;
    $currentStamp = parseDateToTimestamp($current['updated_at'] ?? '') ?? 0;
    if ($candidateStamp === $currentStamp) {
        return (string) ($candidate['plan_id'] ?? '') >= (string) ($current['plan_id'] ?? '');
    }
    return $candidateStamp > $currentStamp;
}

function defaultTrainingPlanRecord(string $trainingId): array
{
    return [
        'plan_id' => '',
        'training_id' => $trainingId,
        'titel' => '',
        'inhalt' => '',
        'link' => '',
        'created_at' => '',
        'created_by' => '',
        'updated_at' => '',
        'updated_by' => '',
        'deleted_at' => '',
    ];
}

function loadTrainingPlanStore(string $rootPath): array
{
    $path = $rootPath . '/storage/trainingsplan.json';
    if (!is_file($path)) {
        return ['plans' => []];
    }

    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data)) {
        return ['plans' => []];
    }

    $plans = isset($data['plans']) && is_array($data['plans']) ? $data['plans'] : [];
    return ['plans' => $plans];
}

function saveTrainingPlanStore(string $rootPath, array $store): void
{
    $path = $rootPath . '/storage/trainingsplan.json';
    $payload = json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    file_put_contents($path, $payload === false ? '{}' : $payload);
}

function generateTrainingPlanId(array $existingIds): string
{
    do {
        $candidate = 'P_' . bin2hex(random_bytes(4));
    } while (isset($existingIds[$candidate]));

    return $candidate;
}

function collectExistingTrainingPlanIds(string $rootPath): array
{
    $ids = [];
    $plans = loadTrainingPlanRecords($rootPath);
    foreach ($plans as $plan) {
        $planId = trim((string) ($plan['plan_id'] ?? ''));
        if ($planId !== '') {
            $ids[$planId] = true;
        }
    }
    $store = loadTrainingPlanStore($rootPath);
    foreach ($store['plans'] as $plan) {
        $planId = trim((string) ($plan['plan_id'] ?? ''));
        if ($planId !== '') {
            $ids[$planId] = true;
        }
    }
    return $ids;
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

function buildTrainingLabel(array $training): string
{
    $date = $training['datum'] ?? '';
    $time = trim(($training['start'] ?? '') . '–' . ($training['ende'] ?? ''));
    $group = $training['gruppe'] ?? '';
    $label = trim($date . ' · ' . $time . ' · ' . $group);
    if (($training['training_id'] ?? '') !== '') {
        $label .= ' (' . $training['training_id'] . ')';
    }
    return htmlspecialchars($label);
}

function renderRoleOptions(array $roles, string $selected): string
{
    $options = '';
    if ($roles === [] && $selected !== '') {
        $roles = [$selected];
    }
    foreach ($roles as $role) {
        $selectedAttr = $role === $selected ? ' selected' : '';
        $options .= '<option value="' . htmlspecialchars($role) . '"' . $selectedAttr . '>' . htmlspecialchars($role) . '</option>';
    }
    return $options;
}

function buildAdminQuery(array $params): string
{
    $filtered = array_filter($params, static fn ($value): bool => $value !== null && $value !== '');
    $query = http_build_query($filtered);
    return $query === '' ? '' : '?' . $query;
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

function findTrainerById(array $trainers, string $trainerId): ?array
{
    foreach ($trainers as $trainer) {
        if ($trainer['trainer_id'] === $trainerId) {
            return $trainer;
        }
    }
    return null;
}

function findAssignmentById(array $assignments, string $assignmentId): ?array
{
    foreach ($assignments as $assignment) {
        if ($assignment['einteilung_id'] === $assignmentId) {
            return $assignment;
        }
    }
    return null;
}

function loadTrainingAssignments(string $rootPath): array
{
    $records = loadAssignmentRecords($rootPath);
    if ($records === []) {
        return [];
    }

    $activeAbmeldungen = collectActiveAbmeldungen($rootPath);

    $assignments = [];
    foreach ($records as $assignment) {
        if ($assignment['training_id'] === '' || $assignment['ausgetragen_am'] !== '') {
            continue;
        }
        if ($assignment['trainer_id'] !== '') {
            $abmeldungKey = buildAbmeldungKey($assignment['training_id'], $assignment['trainer_id']);
            if ($abmeldungKey !== '' && isset($activeAbmeldungen[$abmeldungKey])) {
                continue;
            }
        }
        $assignments[$assignment['training_id']] = true;
    }

    return $assignments;
}

function collectActiveAssignmentCounts(string $rootPath): array
{
    $assignments = loadAssignmentRecords($rootPath);
    if ($assignments === []) {
        return [];
    }

    $activeAbmeldungen = collectActiveAbmeldungen($rootPath);

    $counts = [];
    foreach ($assignments as $assignment) {
        if ($assignment['training_id'] === '' || $assignment['trainer_id'] === '') {
            continue;
        }
        if ($assignment['ausgetragen_am'] !== '') {
            continue;
        }
        $abmeldungKey = buildAbmeldungKey($assignment['training_id'], $assignment['trainer_id']);
        if ($abmeldungKey !== '' && isset($activeAbmeldungen[$abmeldungKey])) {
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
    $records = [];
    if ($rows) {
        [$header, $dataRows] = $rows;
        $header = normalizeHeaderCells($header);

        foreach ($dataRows as $row) {
            $row = normalizeRowCells($header, $row);
            $assoc = array_combine($header, $row);
            if (!$assoc) {
                continue;
            }

            $assignmentId = trim((string) ($assoc['einteilung_id'] ?? ''));
            if ($assignmentId === '') {
                continue;
            }

            $records[$assignmentId] = normalizeAssignmentRecord($assoc);
        }
    }

    $store = loadAssignmentStore($rootPath);
    foreach ($store['assignments'] as $assignmentId => $record) {
        $records[$assignmentId] = array_merge($records[$assignmentId] ?? defaultAssignmentRecord($assignmentId), $record);
    }

    return array_values($records);
}

function normalizeAssignmentRecord(array $assoc): array
{
    $assignmentId = trim((string) ($assoc['einteilung_id'] ?? ''));
    return [
        'einteilung_id' => $assignmentId,
        'training_id' => trim((string) ($assoc['training_id'] ?? '')),
        'trainer_id' => trim((string) ($assoc['trainer_id'] ?? '')),
        'rolle' => trim((string) ($assoc['rolle'] ?? '')),
        'eingetragen_am' => trim((string) ($assoc['eingetragen_am'] ?? '')),
        'ausgetragen_am' => trim((string) ($assoc['ausgetragen_am'] ?? '')),
        'attendance' => trim((string) ($assoc['attendance'] ?? '')),
        'checkin_am' => trim((string) ($assoc['checkin_am'] ?? '')),
        'kommentar' => trim((string) ($assoc['kommentar'] ?? '')),
        'einsatz_start' => trim((string) ($assoc['einsatz_start'] ?? '')),
        'einsatz_ende' => trim((string) ($assoc['einsatz_ende'] ?? '')),
        'abgemeldet_am' => trim((string) ($assoc['abgemeldet_am'] ?? '')),
        'training_datum' => trim((string) ($assoc['training_datum'] ?? '')),
        'training_status' => trim((string) ($assoc['training_status'] ?? '')),
        'training_dauer_stunden' => trim((string) ($assoc['training_dauer_stunden'] ?? '')),
        'satz_eur' => trim((string) ($assoc['satz_eur'] ?? '')),
        'betrag_eur' => trim((string) ($assoc['betrag_eur'] ?? '')),
    ];
}

function defaultAssignmentRecord(string $assignmentId): array
{
    return [
        'einteilung_id' => $assignmentId,
        'training_id' => '',
        'trainer_id' => '',
        'rolle' => '',
        'eingetragen_am' => '',
        'ausgetragen_am' => '',
        'attendance' => '',
        'checkin_am' => '',
        'kommentar' => '',
        'einsatz_start' => '',
        'einsatz_ende' => '',
        'abgemeldet_am' => '',
        'training_datum' => '',
        'training_status' => '',
        'training_dauer_stunden' => '',
        'satz_eur' => '',
        'betrag_eur' => '',
    ];
}

function loadAssignmentStore(string $rootPath): array
{
    $path = $rootPath . '/storage/einteilungen.json';
    if (!is_file($path)) {
        return ['assignments' => []];
    }

    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data)) {
        return ['assignments' => []];
    }

    $assignments = isset($data['assignments']) && is_array($data['assignments']) ? $data['assignments'] : [];
    return ['assignments' => $assignments];
}

function saveAssignmentStore(string $rootPath, array $store): void
{
    $path = $rootPath . '/storage/einteilungen.json';
    $payload = json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    file_put_contents($path, $payload === false ? '{}' : $payload);
}

function generateAssignmentId(string $rootPath): string
{
    $year = (int) date('Y');
    $existingIds = collectExistingAssignmentIds($rootPath);
    $yearCounters = [];

    return nextAssignmentId($year, $existingIds, $yearCounters);
}

function collectExistingAssignmentIds(string $rootPath): array
{
    $ids = [];
    $records = loadAssignmentRecords($rootPath);
    foreach ($records as $record) {
        $assignmentId = trim((string) ($record['einteilung_id'] ?? ''));
        if ($assignmentId !== '') {
            $ids[$assignmentId] = true;
        }
    }

    $store = loadAssignmentStore($rootPath);
    foreach ($store['assignments'] as $assignmentId => $_record) {
        if ($assignmentId !== '') {
            $ids[$assignmentId] = true;
        }
    }

    return $ids;
}

function nextAssignmentId(int $year, array &$existingIds, array &$yearCounters): string
{
    if (!isset($yearCounters[$year])) {
        $max = 0;
        foreach ($existingIds as $assignmentId => $_value) {
            if (preg_match('/^EIN-(\\d{4})-(\\d{3})$/', $assignmentId, $matches)) {
                if ((int) $matches[1] === $year) {
                    $max = max($max, (int) $matches[2]);
                }
            }
        }
        $yearCounters[$year] = $max + 1;
    }

    do {
        $candidate = sprintf('EIN-%d-%03d', $year, $yearCounters[$year]);
        $yearCounters[$year]++;
    } while (isset($existingIds[$candidate]));

    $existingIds[$candidate] = true;
    return $candidate;
}

function loadAbmeldungRecords(string $rootPath): array
{
    $rows = loadHtmlRows($rootPath . '/database/ABMELDUNGEN.html');
    $records = [];
    if ($rows) {
        [$header, $dataRows] = $rows;
        $header = normalizeHeaderCells($header);

        foreach ($dataRows as $row) {
            $row = normalizeRowCells($header, $row);
            $assoc = array_combine($header, $row);
            if (!$assoc) {
                continue;
            }

            $records[] = [
                'training_id' => trim((string) ($assoc['training_id'] ?? '')),
                'trainer_id' => trim((string) ($assoc['trainer_id'] ?? '')),
                'grund' => trim((string) ($assoc['grund'] ?? '')),
                'abgemeldet_am' => trim((string) ($assoc['abgemeldet_am'] ?? '')),
                'deleted_at' => trim((string) ($assoc['deleted_at'] ?? '')),
            ];
        }
    }

    $store = loadAbmeldungStore($rootPath);
    foreach ($store['abmeldungen'] as $record) {
        $records[] = normalizeAbmeldungRecord($record);
    }

    return $records;
}

function normalizeAbmeldungRecord(array $record): array
{
    return [
        'training_id' => trim((string) ($record['training_id'] ?? '')),
        'trainer_id' => trim((string) ($record['trainer_id'] ?? '')),
        'grund' => trim((string) ($record['grund'] ?? '')),
        'abgemeldet_am' => trim((string) ($record['abgemeldet_am'] ?? '')),
        'deleted_at' => trim((string) ($record['deleted_at'] ?? '')),
    ];
}

function loadAbmeldungStore(string $rootPath): array
{
    $path = $rootPath . '/storage/abmeldungen.json';
    if (!is_file($path)) {
        return ['abmeldungen' => []];
    }

    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data)) {
        return ['abmeldungen' => []];
    }

    $abmeldungen = isset($data['abmeldungen']) && is_array($data['abmeldungen']) ? $data['abmeldungen'] : [];
    return ['abmeldungen' => $abmeldungen];
}

function saveAbmeldungStore(string $rootPath, array $store): void
{
    $path = $rootPath . '/storage/abmeldungen.json';
    $payload = json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    file_put_contents($path, $payload === false ? '{}' : $payload);
}

function buildAbmeldungKey(string $trainingId, string $trainerId): string
{
    $trainingId = trim($trainingId);
    $trainerId = trim($trainerId);
    if ($trainingId === '' || $trainerId === '') {
        return '';
    }
    return $trainingId . '|' . $trainerId;
}

function collectActiveAbmeldungen(string $rootPath): array
{
    $records = loadAbmeldungRecords($rootPath);
    $active = [];
    foreach ($records as $record) {
        if ($record['deleted_at'] !== '') {
            continue;
        }
        $key = buildAbmeldungKey($record['training_id'], $record['trainer_id']);
        if ($key === '') {
            continue;
        }
        if (!isset($active[$key])) {
            $active[$key] = $record;
            continue;
        }
        $currentTimestamp = $active[$key]['abgemeldet_am'] ?? '';
        $candidateTimestamp = $record['abgemeldet_am'] ?? '';
        if ($candidateTimestamp !== '' && $candidateTimestamp >= $currentTimestamp) {
            $active[$key] = $record;
        }
    }
    return $active;
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

function loadRoleRates(string $rootPath): array
{
    $rows = loadHtmlRows($rootPath . '/database/ROLLEN_SAETZE.html');
    if (!$rows) {
        return [];
    }

    [$header, $dataRows] = $rows;
    $header = normalizeHeaderCells($header);

    $rates = [];
    foreach ($dataRows as $row) {
        $row = normalizeRowCells($header, $row);
        $assoc = array_combine($header, $row);
        if (!$assoc) {
            continue;
        }

        $role = trim((string) ($assoc['rolle'] ?? ''));
        if ($role === '') {
            continue;
        }
        $rate = trim((string) ($assoc['stundensatz_eur'] ?? ''));
        $rates[$role] = $rate;
    }

    return $rates;
}

function loadTrainerRecords(string $rootPath): array
{
    $rows = loadHtmlRows($rootPath . '/database/TRAINER.html');
    if (!$rows) {
        return [];
    }

    [$header, $dataRows] = $rows;
    $normalizedHeader = normalizeHeaderCells($header);

    $records = [];
    foreach ($dataRows as $row) {
        $row = normalizeRowCells($normalizedHeader, $row);
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
            'rolle_standard' => trim((string) ($assoc['rolle_standard'] ?? '')),
            'stundensatz_eur' => trim((string) ($assoc['stundensatz_eur'] ?? '')),
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
