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
        '/admin' => renderAdmin($settings),
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

function renderAdmin(array $settings): array
{
    if (!isAdmin()) {
        $content = renderForbidden();
        return ['Admin', $content, null, false, 403];
    }

    return ['Admin', renderAdminPage($settings), null, true, 200];
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
        button.primary {
            background: var(--brand-color);
            color: #fff;
            border: none;
            border-radius: 10px;
            padding: 0.7rem;
            font-weight: 600;
            cursor: pointer;
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

function renderAdminPage(array $settings): string
{
    $brandName = htmlspecialchars((string) $settings['brand_name']);

    return '<section class="card">'
        . '<h1>Admin</h1>'
        . '<p class="helper">Nur Administrator:innen können diese Seite sehen.</p>'
        . '<div class="card"><strong>Branding</strong><p class="helper">Aktueller Mandant: ' . $brandName . '</p></div>'
        . '<div class="card"><strong>Systemstatus</strong><p class="helper">Alle Dienste sind verfügbar.</p></div>'
        . '</section>';
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
