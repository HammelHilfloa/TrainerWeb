<?php

declare(strict_types=1);

const IMPORT_DATABASE_DIR = __DIR__ . '/../database';
const IMPORT_DEFAULT_FILES = [
    'TRAINER.html',
    'ROLLEN_SAETZE.html',
    'TRAININGS.html',
    'EINTEILUNGEN.html',
    'ABMELDUNGEN.html',
    'TRAININGSPLAN.html',
    'TURNIERE.html',
    'TURNIER_EINSAETZE.html',
    'FAHRTEN.html',
    'MITFAHRER.html',
];

$importConfig = [
    'TRAINER.html' => [
        'table' => 'trainer',
        'primary_keys' => ['trainer_id'],
        'required' => ['trainer_id', 'name'],
        'foreign_keys' => [],
    ],
    'ROLLEN_SAETZE.html' => [
        'table' => 'rollen_saetze',
        'primary_keys' => ['rolle'],
        'required' => ['rolle'],
        'foreign_keys' => [],
    ],
    'TRAININGS.html' => [
        'table' => 'trainings',
        'primary_keys' => ['training_id'],
        'required' => ['training_id'],
        'foreign_keys' => [],
    ],
    'EINTEILUNGEN.html' => [
        'table' => 'einteilungen',
        'primary_keys' => ['einteilung_id'],
        'required' => ['einteilung_id', 'training_id', 'trainer_id'],
        'foreign_keys' => [
            'training_id' => 'trainings',
            'trainer_id' => 'trainer',
        ],
    ],
    'ABMELDUNGEN.html' => [
        'table' => 'abmeldungen',
        'primary_keys' => ['abmeldung_id'],
        'required' => ['abmeldung_id', 'training_id', 'trainer_id'],
        'foreign_keys' => [
            'training_id' => 'trainings',
            'trainer_id' => 'trainer',
        ],
    ],
    'TRAININGSPLAN.html' => [
        'table' => 'trainingsplan',
        'primary_keys' => ['plan_id'],
        'required' => ['plan_id', 'training_id'],
        'foreign_keys' => [
            'training_id' => 'trainings',
            'created_by' => 'trainer',
            'updated_by' => 'trainer',
        ],
    ],
    'TURNIERE.html' => [
        'table' => 'turniere',
        'primary_keys' => ['turnier_id'],
        'required' => ['turnier_id'],
        'foreign_keys' => [],
    ],
    'TURNIER_EINSAETZE.html' => [
        'table' => 'turnier_einsaetze',
        'primary_keys' => ['turnier_einsatz_id'],
        'required' => ['turnier_einsatz_id'],
        'foreign_keys' => [
            'turnier_id' => 'turniere',
            'trainer_id' => 'trainer',
        ],
    ],
    'FAHRTEN.html' => [
        'table' => 'fahrten',
        'primary_keys' => ['fahrt_id'],
        'required' => ['fahrt_id'],
        'foreign_keys' => [
            'turnier_id' => 'turniere',
            'fahrer_trainer_id' => 'trainer',
        ],
    ],
    'MITFAHRER.html' => [
        'table' => 'mitfahrer',
        'primary_keys' => ['mitfahrer_id'],
        'required' => ['mitfahrer_id', 'fahrt_id', 'trainer_id'],
        'foreign_keys' => [
            'fahrt_id' => 'fahrten',
            'trainer_id' => 'trainer',
        ],
    ],
];

$booleanColumns = [
    'aktiv',
    'is_admin',
    'abrechenbar',
    'freigegeben',
];

$dateColumns = [
    'datum',
    'datum_von',
    'datum_bis',
    'last_login',
];

$datetimeColumns = [
    'created_at',
    'updated_at',
    'deleted_at',
    'eingetragen_am',
    'ausgetragen_am',
    'checkin_am',
];

$timeColumns = [
    'start',
    'ende',
    'dauer_stunden',
];

$cli = PHP_SAPI === 'cli';
$options = $cli ? getopt('', ['dry-run', 'file::', 'all', 'installer', 'trainer-id::', 'pin::']) : [];
$dryRun = $cli ? isset($options['dry-run']) : isset($_POST['dry_run']);
$installerMode = $cli ? isset($options['installer']) : (defined('INSTALLER_MODE') && INSTALLER_MODE === true);
$requestedFiles = [];

if ($cli) {
    if (!empty($options['file'])) {
        $requestedFiles = is_array($options['file']) ? $options['file'] : [$options['file']];
    }
    if (isset($options['all'])) {
        $requestedFiles = [];
    }
} else {
    if (!empty($_POST['files'])) {
        $requestedFiles = (array) $_POST['files'];
    }
}

$filesToImport = $requestedFiles ?: IMPORT_DEFAULT_FILES;
$filesToImport = array_values(array_intersect($filesToImport, array_keys($importConfig)));

$dsn = sprintf(
    'mysql:host=%s;dbname=%s;charset=utf8mb4',
    getenv('DB_HOST') ?: '127.0.0.1',
    getenv('DB_NAME') ?: 'trainer_web'
);

$pdo = new PDO(
    $dsn,
    getenv('DB_USER') ?: 'root',
    getenv('DB_PASS') ?: '',
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

$adminCredentials = getAdminCredentials($cli, $options);

if (!$installerMode) {
    if (!$adminCredentials) {
        if ($cli) {
            fwrite(STDERR, "Admin-Zugang erforderlich. Verwende --trainer-id und --pin.\n");
            exit(1);
        }
        renderLoginForm(IMPORT_DEFAULT_FILES);
        exit;
    }

    if (!verifyAdmin($pdo, $adminCredentials['trainer_id'], $adminCredentials['pin'])) {
        if ($cli) {
            fwrite(STDERR, "Admin-Zugang abgelehnt.\n");
            exit(1);
        }
        renderLoginForm(IMPORT_DEFAULT_FILES, 'Ungültige Admin-Zugangsdaten.');
        exit;
    }
}

$results = [];
$errors = [];
$cacheExists = [];

foreach ($filesToImport as $fileName) {
    $config = $importConfig[$fileName];
    $path = IMPORT_DATABASE_DIR . '/' . $fileName;

    if (!is_file($path)) {
        $results[] = [
            'file' => $fileName,
            'table' => $config['table'],
            'imported' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => ["Datei nicht gefunden: {$path}"],
        ];
        continue;
    }

    $rows = loadHtmlRows($path);
    if (!$rows) {
        $results[] = [
            'file' => $fileName,
            'table' => $config['table'],
            'imported' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => ["Keine importierbaren Daten in {$fileName} gefunden."],
        ];
        continue;
    }

    [$header, $dataRows] = $rows;
    $normalizedHeader = array_map('normalizeColumnName', $header);
    $imported = 0;
    $updated = 0;
    $skipped = 0;
    $fileErrors = [];

    foreach ($dataRows as $rowIndex => $rowValues) {
        $rowData = buildRowData($normalizedHeader, $rowValues);
        if (isRowEmpty($rowData)) {
            $skipped++;
            continue;
        }

        $rowData = normalizeRowValues(
            $rowData,
            $booleanColumns,
            $dateColumns,
            $datetimeColumns,
            $timeColumns
        );

        $validationErrors = validateRow(
            $pdo,
            $rowData,
            $config,
            $cacheExists
        );

        if ($validationErrors) {
            $skipped++;
            $fileErrors[] = sprintf(
                'Zeile %d: %s',
                $rowIndex + 2,
                implode('; ', $validationErrors)
            );
            continue;
        }

        $exists = rowExists($pdo, $config['table'], $config['primary_keys'], $rowData, $cacheExists);

        if ($dryRun) {
            $exists ? $updated++ : $imported++;
            continue;
        }

        $statement = buildUpsertStatement($config['table'], $config['primary_keys'], array_keys($rowData));
        $stmt = $pdo->prepare($statement);
        $stmt->execute($rowData);

        $exists ? $updated++ : $imported++;
        if ($config['primary_keys']) {
            cachePrimaryKey($config['table'], $config['primary_keys'], $rowData, $cacheExists);
        }
    }

    $results[] = [
        'file' => $fileName,
        'table' => $config['table'],
        'imported' => $imported,
        'updated' => $updated,
        'skipped' => $skipped,
        'errors' => $fileErrors,
    ];

    if ($fileErrors) {
        $errors = array_merge($errors, $fileErrors);
    }
}

renderReport($results, $dryRun, $cli);

function getAdminCredentials(bool $cli, array $options): ?array
{
    if ($cli) {
        $trainerId = $options['trainer-id'] ?? getenv('IMPORT_ADMIN_ID');
        $pin = $options['pin'] ?? getenv('IMPORT_ADMIN_PIN');
    } else {
        $trainerId = $_POST['trainer_id'] ?? null;
        $pin = $_POST['pin'] ?? null;
    }

    if (!$trainerId || !$pin) {
        return null;
    }

    return [
        'trainer_id' => trim((string) $trainerId),
        'pin' => trim((string) $pin),
    ];
}

function verifyAdmin(PDO $pdo, string $trainerId, string $pin): bool
{
    $stmt = $pdo->prepare('SELECT trainer_id, pin, is_admin FROM trainer WHERE trainer_id = :trainer_id LIMIT 1');
    $stmt->execute(['trainer_id' => $trainerId]);
    $row = $stmt->fetch();

    if (!$row || (int) $row['is_admin'] !== 1) {
        return false;
    }

    return verifyPin($pin, (string) $row['pin']);
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

function normalizeColumnName(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/\s+/', '_', $value) ?? $value;
    $value = preg_replace('/[^a-z0-9_]/', '', $value) ?? $value;

    return $value;
}

function buildRowData(array $header, array $rowValues): array
{
    $rowData = [];
    foreach ($header as $index => $column) {
        if ($column === '') {
            continue;
        }
        $rowData[$column] = $rowValues[$index] ?? null;
    }

    return $rowData;
}

function isRowEmpty(array $rowData): bool
{
    foreach ($rowData as $value) {
        if ($value !== null && $value !== '') {
            return false;
        }
    }

    return true;
}

function normalizeRowValues(
    array $rowData,
    array $booleanColumns,
    array $dateColumns,
    array $datetimeColumns,
    array $timeColumns
): array {
    foreach ($rowData as $column => $value) {
        $value = is_string($value) ? trim($value) : $value;

        if ($value === '') {
            $rowData[$column] = null;
            continue;
        }

        if (in_array($column, $booleanColumns, true)) {
            $rowData[$column] = normalizeBoolean($value);
            continue;
        }

        if (in_array($column, $datetimeColumns, true)) {
            $rowData[$column] = normalizeDateTime($value);
            continue;
        }

        if (in_array($column, $dateColumns, true)) {
            $rowData[$column] = normalizeDate($value);
            continue;
        }

        if (in_array($column, $timeColumns, true)) {
            $rowData[$column] = normalizeTime($value);
            continue;
        }

        if (is_numeric(str_replace(',', '.', (string) $value))) {
            $rowData[$column] = str_replace(',', '.', (string) $value);
        } else {
            $rowData[$column] = $value;
        }
    }

    return $rowData;
}

function normalizeBoolean(string $value): ?int
{
    $value = strtoupper(trim($value));

    if (in_array($value, ['TRUE', 'WAHR', '1', 'JA', 'YES'], true)) {
        return 1;
    }

    if (in_array($value, ['FALSE', 'FALSCH', '0', 'NEIN', 'NO'], true)) {
        return 0;
    }

    return null;
}

function normalizeDate(string $value): ?string
{
    $value = trim($value);
    $formats = ['d.m.Y', 'Y-m-d'];

    foreach ($formats as $format) {
        $date = DateTimeImmutable::createFromFormat($format, $value);
        if ($date !== false) {
            return $date->format('Y-m-d');
        }
    }

    return $value;
}

function normalizeDateTime(string $value): ?string
{
    $value = trim($value);
    $formats = ['d.m.Y H:i', 'd.m.Y H:i:s', 'Y-m-d H:i', 'Y-m-d H:i:s', 'd.m.Y', 'Y-m-d'];

    foreach ($formats as $format) {
        $date = DateTimeImmutable::createFromFormat($format, $value);
        if ($date !== false) {
            return $date->format('Y-m-d H:i:s');
        }
    }

    return $value;
}

function normalizeTime(string $value): ?string
{
    $value = trim($value);
    $formats = ['H:i', 'H:i:s'];

    foreach ($formats as $format) {
        $date = DateTimeImmutable::createFromFormat($format, $value);
        if ($date !== false) {
            return $date->format('H:i:s');
        }
    }

    return $value;
}

function validateRow(PDO $pdo, array $rowData, array $config, array &$cacheExists): array
{
    $errors = [];

    foreach ($config['required'] as $column) {
        if (!isset($rowData[$column]) || $rowData[$column] === null || $rowData[$column] === '') {
            $errors[] = "Pflichtfeld fehlt: {$column}";
        }
    }

    foreach ($config['foreign_keys'] as $column => $table) {
        if (empty($rowData[$column])) {
            $errors[] = "{$column} ist leer";
            continue;
        }

        $targetColumn = resolveForeignKeyTargetColumn($table, $column);
        if (!rowExistsByColumn($pdo, $table, $targetColumn, $rowData[$column], $cacheExists)) {
            $errors[] = "{$column} verweist auf unbekannte ID ({$rowData[$column]})";
        }
    }

    return $errors;
}

function resolveForeignKeyTargetColumn(string $table, string $column): string
{
    if ($table === 'trainer') {
        return 'trainer_id';
    }

    if ($table === 'trainings') {
        return 'training_id';
    }

    if ($table === 'turniere') {
        return 'turnier_id';
    }

    if ($table === 'fahrten') {
        return 'fahrt_id';
    }

    return $column;
}

function rowExists(PDO $pdo, string $table, array $primaryKeys, array $rowData, array &$cacheExists): bool
{
    if (!$primaryKeys) {
        return false;
    }

    $cacheKey = buildCacheKey($table, $primaryKeys, $rowData);
    if (isset($cacheExists[$cacheKey])) {
        return true;
    }

    $conditions = [];
    $params = [];
    foreach ($primaryKeys as $key) {
        $conditions[] = "`{$key}` = :{$key}";
        $params[$key] = $rowData[$key];
    }

    $stmt = $pdo->prepare(sprintf(
        'SELECT 1 FROM `%s` WHERE %s LIMIT 1',
        $table,
        implode(' AND ', $conditions)
    ));
    $stmt->execute($params);
    $exists = (bool) $stmt->fetchColumn();

    if ($exists) {
        $cacheExists[$cacheKey] = true;
    }

    return $exists;
}

function rowExistsByColumn(PDO $pdo, string $table, string $column, string $value, array &$cacheExists): bool
{
    $cacheKey = sprintf('%s:%s:%s', $table, $column, $value);
    if (isset($cacheExists[$cacheKey])) {
        return true;
    }

    $stmt = $pdo->prepare(sprintf('SELECT 1 FROM `%s` WHERE `%s` = :value LIMIT 1', $table, $column));
    $stmt->execute(['value' => $value]);
    $exists = (bool) $stmt->fetchColumn();

    if ($exists) {
        $cacheExists[$cacheKey] = true;
    }

    return $exists;
}

function buildUpsertStatement(string $table, array $primaryKeys, array $columns): string
{
    $quotedColumns = array_map(fn(string $column) => "`{$column}`", $columns);
    $placeholders = array_map(fn(string $column) => ":{$column}", $columns);
    $updateColumns = array_diff($columns, $primaryKeys);
    $updates = array_map(fn(string $column) => "`{$column}` = VALUES(`{$column}`)", $updateColumns);

    $sql = sprintf(
        'INSERT INTO `%s` (%s) VALUES (%s)',
        $table,
        implode(', ', $quotedColumns),
        implode(', ', $placeholders)
    );

    if ($updates) {
        $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);
    }

    return $sql;
}

function cachePrimaryKey(string $table, array $primaryKeys, array $rowData, array &$cacheExists): void
{
    $cacheKey = buildCacheKey($table, $primaryKeys, $rowData);
    $cacheExists[$cacheKey] = true;
}

function buildCacheKey(string $table, array $primaryKeys, array $rowData): string
{
    $values = array_map(
        fn(string $key) => (string) ($rowData[$key] ?? ''),
        $primaryKeys
    );

    return sprintf('%s:%s', $table, implode('|', $values));
}

function renderLoginForm(array $files, string $message = ''): void
{
    $selectedFiles = isset($_POST['files']) ? (array) $_POST['files'] : $files;
    $isDryRun = isset($_POST['dry_run']);

    echo '<!doctype html><html lang="de"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>HTML-Import</title></head><body>';
    echo '<h1>HTML-Import (Admin)</h1>';
    if ($message !== '') {
        echo '<p style="color: #b00;">' . htmlspecialchars($message) . '</p>';
    }
    echo '<form method="post">';
    echo '<label>Trainer-ID <input name="trainer_id" required></label><br>';
    echo '<label>PIN <input name="pin" type="password" required></label><br>';
    echo '<fieldset><legend>Dateien</legend>';
    foreach ($files as $file) {
        $checked = in_array($file, $selectedFiles, true) ? ' checked' : '';
        echo '<label><input type="checkbox" name="files[]" value="' . htmlspecialchars($file) . '"' . $checked . '> ' . htmlspecialchars($file) . '</label><br>';
    }
    echo '</fieldset>';
    echo '<label><input type="checkbox" name="dry_run"' . ($isDryRun ? ' checked' : '') . '> Dry-Run</label><br>';
    echo '<button type="submit">Einloggen</button>';
    echo '</form>';
    echo '</body></html>';
}

function renderReport(array $results, bool $dryRun, bool $cli): void
{
    if ($cli) {
        foreach ($results as $result) {
            echo sprintf(
                "%s -> %s: %d neu, %d aktualisiert, %d übersprungen\n",
                $result['file'],
                $result['table'],
                $result['imported'],
                $result['updated'],
                $result['skipped']
            );
            foreach ($result['errors'] as $error) {
                echo "  - {$error}\n";
            }
        }
        if ($dryRun) {
            echo "\nDry-Run: Es wurden keine Änderungen gespeichert.\n";
        }
        return;
    }

    echo '<!doctype html><html lang="de"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>HTML-Import Bericht</title></head><body>';
    echo '<h1>HTML-Import</h1>';
    if ($dryRun) {
        echo '<p><strong>Dry-Run:</strong> Es wurden keine Änderungen gespeichert.</p>';
    }

    echo '<table border="1" cellpadding="6" cellspacing="0">';
    echo '<thead><tr><th>Datei</th><th>Tabelle</th><th>Neu</th><th>Aktualisiert</th><th>Übersprungen</th><th>Fehler</th></tr></thead><tbody>';
    foreach ($results as $result) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($result['file']) . '</td>';
        echo '<td>' . htmlspecialchars($result['table']) . '</td>';
        echo '<td>' . (int) $result['imported'] . '</td>';
        echo '<td>' . (int) $result['updated'] . '</td>';
        echo '<td>' . (int) $result['skipped'] . '</td>';
        echo '<td>' . htmlspecialchars(implode("\n", $result['errors'])) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '</body></html>';
}
