<?php
$databaseDir = realpath(__DIR__ . '/../database');

if ($databaseDir === false) {
    http_response_code(500);
    echo 'Database-Verzeichnis nicht gefunden.';
    exit;
}

$availableFiles = glob($databaseDir . '/*.html') ?: [];
$availableMap = [];
foreach ($availableFiles as $filePath) {
    $fileName = basename($filePath);
    $availableMap[$fileName] = $filePath;
}
ksort($availableMap, SORT_NATURAL | SORT_FLAG_CASE);

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
?>
<!DOCTYPE html>
<html lang="de">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Database HTML Dateien</title>
    <?php if (file_exists(__DIR__ . '/style.html')) {
        include __DIR__ . '/style.html';
    } ?>
    <style>
      body { padding: 2rem; }
      .file-list { list-style: none; padding: 0; display: grid; gap: 0.5rem; }
      .file-list a { display: inline-block; padding: 0.4rem 0.75rem; border-radius: 6px; background: #f3f4f6; text-decoration: none; }
      .file-list a:hover { background: #e5e7eb; }
    </style>
  </head>
  <body>
    <h1>Database HTML Dateien</h1>
    <p>Wähle eine Datei aus dem <code>database</code>-Ordner aus, um sie anzuzeigen.</p>
    <ul class="file-list">
      <?php foreach ($availableMap as $fileName => $filePath) { ?>
        <li><a href="?file=<?php echo urlencode($fileName); ?>"><?php echo htmlspecialchars($fileName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></a></li>
      <?php } ?>
    </ul>
  </body>
</html>
