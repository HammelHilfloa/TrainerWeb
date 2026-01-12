<?php
declare(strict_types=1);

require_once __DIR__ . '/api/lib/db.php';

$databaseDir = realpath(__DIR__ . '/../database');
$schemaPath = realpath(__DIR__ . '/../database/schema.sql');

if ($databaseDir === false) {
    http_response_code(500);
    echo 'Database-Verzeichnis nicht gefunden.';
    exit;
}

$schemaTables = [];
if ($schemaPath !== false && file_exists($schemaPath)) {
    $schemaTables = parse_schema_tables($schemaPath);
}

$availableFiles = glob($databaseDir . '/*.html') ?: [];
$availableMap = [];
foreach ($availableFiles as $filePath) {
    $fileName = basename($filePath);
    $availableMap[$fileName] = $filePath;
}
ksort($availableMap, SORT_NATURAL | SORT_FLAG_CASE);

$seedResults = [];
$seedErrors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'seed') {
    $seedErrors = validate_seed_requirements($schemaTables, $schemaPath);
    if (!$seedErrors) {
        $pdo = db();
        foreach ($availableMap as $fileName => $filePath) {
            $result = import_html_seed($pdo, $filePath, $schemaTables);
            if ($result['status'] === 'error') {
                $seedErrors[] = $result['message'];
            } else {
                $seedResults[] = $result['message'];
            }
        }
    }
}

$requested = $_GET['file'] ?? '';
if ($requested !== '') {
    $requested = basename($requested);
    if (!str_ends_with(strtolower($requested), '.html')) {
        http_response_code(400);
        echo 'Nur HTML-Dateien sind erlaubt.';
        exit;
    }

    if (!isset($availableMap[$requested])) {
        http_response_code(404);
        echo 'Datei nicht gefunden.';
        exit;
    }

    $target = realpath($availableMap[$requested]);
    if ($target === false || !str_starts_with($target, $databaseDir)) {
        http_response_code(400);
        echo 'Ungültiger Dateipfad.';
        exit;
    }

    header('Content-Type: text/html; charset=UTF-8');
    readfile($target);
    exit;
}

function parse_schema_tables(string $schemaPath): array
{
    $schema = file_get_contents($schemaPath);
    if ($schema === false) {
        return [];
    }
    $tables = [];
    $pattern = '/CREATE TABLE IF NOT EXISTS\\s+([a-zA-Z0-9_]+)\\s*\\((.*?)\\)\\s*ENGINE/si';
    if (!preg_match_all($pattern, $schema, $matches, PREG_SET_ORDER)) {
        return [];
    }
    foreach ($matches as $match) {
        $table = $match[1];
        $body = $match[2];
        $columns = [];
        foreach (preg_split('/\\R/', $body) as $line) {
            $line = trim(rtrim($line, ','));
            if ($line === '') {
                continue;
            }
            $upper = strtoupper($line);
            if (str_starts_with($upper, 'PRIMARY KEY') || str_starts_with($upper, 'CONSTRAINT')) {
                continue;
            }
            if (preg_match('/^`?([a-zA-Z0-9_]+)`?\\s+/', $line, $colMatch)) {
                $columns[] = $colMatch[1];
            }
        }
        $tables[$table] = $columns;
    }
    return $tables;
}

function normalize_header(string $value): string
{
    $value = trim($value);
    $value = preg_replace('/\\s+/', '_', $value) ?? $value;
    return strtolower($value);
}

function normalize_cell(?string $value): int|string|null
{
    if ($value === null) {
        return null;
    }
    $cleaned = trim($value);
    if ($cleaned === '') {
        return null;
    }
    $upper = strtoupper($cleaned);
    if (in_array($upper, ['TRUE', 'WAHR'], true)) {
        return 1;
    }
    if (in_array($upper, ['FALSE', 'FALSCH'], true)) {
        return 0;
    }
    if ($upper === 'NULL') {
        return null;
    }
    return $cleaned;
}

function parse_html_table_rows(string $filePath): array
{
    $content = file_get_contents($filePath);
    if ($content === false) {
        return [];
    }
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML($content);
    libxml_clear_errors();
    $xpath = new DOMXPath($doc);
    $rowNodes = $xpath->query('//tbody/tr');
    if ($rowNodes === false || $rowNodes->length === 0) {
        $rowNodes = $xpath->query('//table//tr');
    }
    if ($rowNodes === false) {
        return [];
    }
    $rows = [];
    foreach ($rowNodes as $rowNode) {
        $cellNodes = $xpath->query('./th|./td', $rowNode);
        if ($cellNodes === false) {
            continue;
        }
        $row = [];
        foreach ($cellNodes as $cellNode) {
            $row[] = trim($cellNode->textContent);
        }
        if ($row) {
            $rows[] = $row;
        }
    }
    return $rows;
}

function find_header_row(array $rows, array $schemaColumns): ?array
{
    $schemaMap = [];
    foreach ($schemaColumns as $column) {
        $schemaMap[normalize_header($column)] = $column;
    }
    foreach ($rows as $rowIndex => $row) {
        $nonEmpty = [];
        $normalized = [];
        foreach ($row as $idx => $cell) {
            $trimmed = trim($cell);
            $normalized[$idx] = normalize_header($trimmed);
            if ($trimmed !== '') {
                $nonEmpty[] = $idx;
            }
        }
        if (!$nonEmpty) {
            continue;
        }
        if (row_matches_schema($nonEmpty, $normalized, $schemaMap)) {
            return build_header_info($nonEmpty, $normalized, $schemaMap, $rowIndex);
        }
        if (count($nonEmpty) > 1) {
            $nonEmptyWithoutFirst = array_slice($nonEmpty, 1);
            if (row_matches_schema($nonEmptyWithoutFirst, $normalized, $schemaMap)) {
                return build_header_info($nonEmptyWithoutFirst, $normalized, $schemaMap, $rowIndex);
            }
        }
    }
    return null;
}

function row_matches_schema(array $indices, array $normalized, array $schemaMap): bool
{
    foreach ($indices as $idx) {
        $value = $normalized[$idx] ?? '';
        if ($value === '' || !isset($schemaMap[$value])) {
            return false;
        }
    }
    return true;
}

function build_header_info(array $indices, array $normalized, array $schemaMap, int $rowIndex): array
{
    $columnIndices = [];
    $columns = [];
    foreach ($indices as $idx) {
        $normalizedValue = $normalized[$idx] ?? '';
        if (!isset($schemaMap[$normalizedValue])) {
            continue;
        }
        $columnIndices[] = $idx;
        $columns[] = $schemaMap[$normalizedValue];
    }
    return [
        'rowIndex' => $rowIndex,
        'indices' => $columnIndices,
        'columns' => $columns,
    ];
}

function collect_rows(array $rows, array $headerInfo): array
{
    $dataRows = [];
    $startRow = $headerInfo['rowIndex'] + 1;
    for ($i = $startRow; $i < count($rows); $i++) {
        $row = $rows[$i];
        $values = [];
        foreach ($headerInfo['indices'] as $idx) {
            $raw = $row[$idx] ?? '';
            $values[] = normalize_cell($raw);
        }
        $allNull = true;
        foreach ($values as $value) {
            if ($value !== null) {
                $allNull = false;
                break;
            }
        }
        if ($allNull) {
            continue;
        }
        $dataRows[] = $values;
    }
    return $dataRows;
}

function import_html_seed(PDO $pdo, string $filePath, array $schemaTables): array
{
    $tableName = strtolower(pathinfo($filePath, PATHINFO_FILENAME));
    if (!isset($schemaTables[$tableName])) {
        return [
            'status' => 'error',
            'message' => sprintf('Keine passende Tabelle für %s gefunden.', basename($filePath)),
        ];
    }
    $rows = parse_html_table_rows($filePath);
    if (!$rows) {
        return [
            'status' => 'ok',
            'message' => sprintf('%s: Keine Daten gefunden.', basename($filePath)),
        ];
    }
    $headerInfo = find_header_row($rows, $schemaTables[$tableName]);
    if ($headerInfo === null || !$headerInfo['columns']) {
        return [
            'status' => 'error',
            'message' => sprintf('Keine gültige Kopfzeile in %s gefunden.', basename($filePath)),
        ];
    }
    $dataRows = collect_rows($rows, $headerInfo);
    if (!$dataRows) {
        return [
            'status' => 'ok',
            'message' => sprintf('%s: Keine zu importierenden Zeilen.', basename($filePath)),
        ];
    }
    $placeholders = implode(', ', array_fill(0, count($headerInfo['columns']), '?'));
    $columnSql = implode(', ', array_map(fn ($col) => sprintf('`%s`', $col), $headerInfo['columns']));
    $sql = sprintf('INSERT INTO `%s` (%s) VALUES (%s)', $tableName, $columnSql, $placeholders);
    $stmt = $pdo->prepare($sql);
    $pdo->beginTransaction();
    $inserted = 0;
    foreach ($dataRows as $dataRow) {
        $stmt->execute($dataRow);
        $inserted += $stmt->rowCount();
    }
    $pdo->commit();
    return [
        'status' => 'ok',
        'message' => sprintf('%s: %d Zeilen importiert.', basename($filePath), $inserted),
    ];
}

function validate_seed_requirements(array $schemaTables, ?string $schemaPath): array
{
    $errors = [];
    if (!$schemaPath || !file_exists($schemaPath)) {
        $errors[] = 'Schema-Datei fehlt.';
    }
    if (!$schemaTables) {
        $errors[] = 'Keine Tabellen im Schema gefunden.';
    }
    return $errors;
}
?>
<!DOCTYPE html>
<html lang="de">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Database Seed Import</title>
    <?php if (file_exists(__DIR__ . '/style.html')) {
        include __DIR__ . '/style.html';
    } ?>
    <style>
      body { padding: 2rem; }
      .file-list { list-style: none; padding: 0; display: grid; gap: 0.5rem; }
      .file-list a { display: inline-block; padding: 0.4rem 0.75rem; border-radius: 6px; background: #f3f4f6; text-decoration: none; }
      .file-list a:hover { background: #e5e7eb; }
      form { margin: 1.5rem 0; }
      button { padding: 0.5rem 1rem; border-radius: 6px; border: none; background: #2563eb; color: #fff; cursor: pointer; }
      button:hover { background: #1d4ed8; }
      .alert { margin: 1rem 0; padding: 1rem; border-radius: 6px; }
      .alert ul { margin: 0.5rem 0 0; padding-left: 1.2rem; }
      .alert-error { background: #fee2e2; color: #991b1b; }
      .alert-success { background: #dcfce7; color: #166534; }
    </style>
  </head>
  <body>
    <h1>Database Seed Import</h1>
    <p>Importiere die HTML-Exports aus dem <code>database</code>-Ordner in die Datenbank.</p>
    <?php if ($seedErrors) { ?>
      <div class="alert alert-error">
        <strong>Fehler beim Import:</strong>
        <ul>
          <?php foreach ($seedErrors as $error) { ?>
            <li><?php echo htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></li>
          <?php } ?>
        </ul>
      </div>
    <?php } ?>
    <?php if ($seedResults) { ?>
      <div class="alert alert-success">
        <strong>Import abgeschlossen:</strong>
        <ul>
          <?php foreach ($seedResults as $result) { ?>
            <li><?php echo htmlspecialchars($result, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></li>
          <?php } ?>
        </ul>
      </div>
    <?php } ?>
    <form method="post">
      <input type="hidden" name="action" value="seed" />
      <button type="submit">Seed-Import starten</button>
    </form>
    <ul class="file-list">
      <?php foreach ($availableMap as $fileName => $filePath) { ?>
        <li><a href="?file=<?php echo urlencode($fileName); ?>"><?php echo htmlspecialchars($fileName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></a></li>
      <?php } ?>
    </ul>
  </body>
</html>
