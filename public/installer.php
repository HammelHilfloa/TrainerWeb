<?php

declare(strict_types=1);

$rootPath = dirname(__DIR__);
$storageDir = $rootPath . '/storage';
$configPath = $storageDir . '/config.php';
$lockPath = $storageDir . '/installer.lock';
$schemaPath = $rootPath . '/sql/schema.sql';
$seedPath = $rootPath . '/sql/seed.sql';

$messages = [];
$errors = [];
$warnings = [];

if (is_file($lockPath)) {
    $warnings[] = 'Der Installer wurde bereits ausgeführt und ist gesperrt. Lösche die Sperrdatei oder den Installer, bevor du ihn erneut nutzt.';
}

$existingConfig = [];
if (is_file($configPath)) {
    $loaded = require $configPath;
    if (is_array($loaded)) {
        $existingConfig = $loaded;
    }
}

$formData = [
    'db_host' => $existingConfig['db']['host'] ?? '127.0.0.1',
    'db_name' => $existingConfig['db']['name'] ?? '',
    'db_user' => $existingConfig['db']['user'] ?? '',
    'db_pass' => $existingConfig['db']['pass'] ?? '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete_installer') {
        if (@unlink(__FILE__)) {
            $messages[] = 'Installer wurde gelöscht.';
        } else {
            $errors[] = 'Installer konnte nicht gelöscht werden. Bitte entferne die Datei manuell.';
        }
    } elseif (is_file($lockPath) && $action !== 'import_html') {
        $errors[] = 'Installer ist gesperrt. Bitte entferne die Sperrdatei, wenn du ihn erneut ausführen möchtest.';
    } else {
        $formData['db_host'] = trim((string) ($_POST['db_host'] ?? ''));
        $formData['db_name'] = trim((string) ($_POST['db_name'] ?? ''));
        $formData['db_user'] = trim((string) ($_POST['db_user'] ?? ''));
        $formData['db_pass'] = (string) ($_POST['db_pass'] ?? '');

        if ($action === 'import_html') {
            if (!is_file($configPath)) {
                $errors[] = 'Bitte installiere zuerst das Schema und die Seed-Daten, bevor HTML-Seeds importiert werden.';
            } else {
                $config = require $configPath;
                if (!is_array($config)) {
                    $errors[] = 'Konfiguration konnte nicht geladen werden.';
                } else {
                    putenv('DB_HOST=' . ($config['db']['host'] ?? ''));
                    putenv('DB_NAME=' . ($config['db']['name'] ?? ''));
                    putenv('DB_USER=' . ($config['db']['user'] ?? ''));
                    putenv('DB_PASS=' . ($config['db']['pass'] ?? ''));

                    define('INSTALLER_MODE', true);
                    require $rootPath . '/tools/import_html_seeds.php';
                    exit;
                }
            }
        }

        if (in_array($action, ['test_connection', 'run_install'], true)) {
            if (empty($formData['db_host']) || empty($formData['db_name']) || empty($formData['db_user'])) {
                $errors[] = 'Bitte fülle DB Host, DB Name und DB User aus.';
            }

            if (!$errors) {
                try {
                    $pdo = createPdo($formData['db_host'], $formData['db_name'], $formData['db_user'], $formData['db_pass']);
                } catch (Throwable $exception) {
                    $errors[] = 'Verbindung fehlgeschlagen: ' . $exception->getMessage();
                }
            }
        }

        if (!$errors && $action === 'test_connection') {
            $messages[] = 'Verbindung erfolgreich.';
        }

        if (!$errors && $action === 'run_install') {
            if (!is_dir($storageDir) && !@mkdir($storageDir, 0755, true)) {
                $errors[] = 'Storage-Verzeichnis konnte nicht erstellt werden.';
            }

            if (!$errors && (!is_file($schemaPath) || !is_file($seedPath))) {
                $errors[] = 'SQL-Dateien wurden nicht gefunden.';
            }

            if (!$errors) {
                try {
                    $pdo->beginTransaction();
                    runSqlFile($pdo, $schemaPath);
                    runSqlFile($pdo, $seedPath);
                    $pdo->commit();

                    $config = [
                        'db' => [
                            'host' => $formData['db_host'],
                            'name' => $formData['db_name'],
                            'user' => $formData['db_user'],
                            'pass' => $formData['db_pass'],
                        ],
                    ];

                    $configPhp = "<?php\n\nreturn " . var_export($config, true) . ";\n";

                    if (@file_put_contents($configPath, $configPhp, LOCK_EX) === false) {
                        throw new RuntimeException('Konfiguration konnte nicht gespeichert werden.');
                    }

                    @file_put_contents($lockPath, date('c'));
                    $messages[] = 'Installation abgeschlossen. Konfiguration gespeichert und Installer gesperrt.';
                } catch (Throwable $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $errors[] = 'Installation fehlgeschlagen: ' . $exception->getMessage();
                }
            }
        }
    }
}

function createPdo(string $host, string $name, string $user, string $pass): PDO
{
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, $name);

    return new PDO(
        $dsn,
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
}

function runSqlFile(PDO $pdo, string $path): void
{
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('SQL-Datei konnte nicht gelesen werden: ' . $path);
    }

    $statements = [];
    $buffer = '';
    foreach (preg_split('/\R/', $sql) as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '*/')) {
            continue;
        }

        $buffer .= $line . "\n";
        if (str_ends_with(rtrim($line), ';')) {
            $statements[] = trim($buffer);
            $buffer = '';
        }
    }

    if (trim($buffer) !== '') {
        $statements[] = trim($buffer);
    }

    foreach ($statements as $statement) {
        $pdo->exec($statement);
    }
}

function renderAlert(string $message, string $type): string
{
    $color = match ($type) {
        'error' => '#b00020',
        'warning' => '#ad5f00',
        default => '#0a662e',
    };

    return '<p style="color: ' . $color . ';">' . htmlspecialchars($message) . '</p>';
}

?><!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Installer</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 720px; margin: 2rem auto; padding: 0 1rem; }
        label { display: block; margin: 0.75rem 0 0.25rem; font-weight: 600; }
        input { width: 100%; padding: 0.5rem; }
        .actions { margin-top: 1rem; display: flex; gap: 0.5rem; flex-wrap: wrap; }
        button { padding: 0.6rem 1rem; }
        .panel { border: 1px solid #ddd; padding: 1rem; border-radius: 6px; margin-bottom: 1.5rem; }
    </style>
</head>
<body>
    <h1>Installer</h1>

    <div class="panel">
        <h2>Sicherheitsstatus</h2>
        <?php foreach ($warnings as $message) { echo renderAlert($message, 'warning'); } ?>
        <?php if (!is_file($lockPath)) { ?>
            <p>Der Installer ist aktiv. Bitte lösche ihn nach erfolgreicher Installation.</p>
        <?php } ?>
    </div>

    <?php foreach ($errors as $message) { echo renderAlert($message, 'error'); } ?>
    <?php foreach ($messages as $message) { echo renderAlert($message, 'success'); } ?>

    <div class="panel">
        <h2>Datenbank konfigurieren</h2>
        <form method="post">
            <label for="db_host">DB Host</label>
            <input id="db_host" name="db_host" required value="<?php echo htmlspecialchars($formData['db_host']); ?>">

            <label for="db_name">DB Name</label>
            <input id="db_name" name="db_name" required value="<?php echo htmlspecialchars($formData['db_name']); ?>">

            <label for="db_user">DB User</label>
            <input id="db_user" name="db_user" required value="<?php echo htmlspecialchars($formData['db_user']); ?>">

            <label for="db_pass">DB Passwort</label>
            <input id="db_pass" name="db_pass" type="password" value="<?php echo htmlspecialchars($formData['db_pass']); ?>">

            <div class="actions">
                <button type="submit" name="action" value="test_connection">Test Connection</button>
                <button type="submit" name="action" value="run_install">Schema + Seeds ausführen</button>
                <button type="submit" name="action" value="import_html">HTML Seeds importieren</button>
            </div>
        </form>
        <p>Konfiguration wird nach erfolgreicher Installation in <code>/storage/config.php</code> gespeichert.</p>
    </div>

    <div class="panel">
        <h2>Installer löschen</h2>
        <p>Nach der Installation solltest du den Installer entfernen, damit niemand unbefugt auf ihn zugreifen kann.</p>
        <form method="post">
            <button type="submit" name="action" value="delete_installer">Installer löschen</button>
        </form>
    </div>
</body>
</html>
