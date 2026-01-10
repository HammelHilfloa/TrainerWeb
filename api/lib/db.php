<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = (string)cfg('DB_HOST', '127.0.0.1');
    $port = (string)cfg('DB_PORT', '3306');
    $name = (string)cfg('DB_NAME', 'trainerweb');
    $user = (string)cfg('DB_USER', 'root');
    $pass = (string)cfg('DB_PASS', '');

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    ensure_sessions_table($pdo);

    return $pdo;
}

function ensure_sessions_table(PDO $pdo): void
{
    $sql = <<<SQL
CREATE TABLE IF NOT EXISTS sessions (
  token VARCHAR(128) NOT NULL PRIMARY KEY,
  trainer_id VARCHAR(32) NOT NULL,
  email VARCHAR(255),
  name VARCHAR(255),
  is_admin BOOLEAN NOT NULL DEFAULT 0,
  rolle_standard VARCHAR(64),
  stundensatz DECIMAL(10,2),
  notizen TEXT,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  expires_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
    $pdo->exec($sql);

    $stmt = $pdo->prepare("SHOW COLUMNS FROM sessions LIKE 'expires_at'");
    $stmt->execute();
    if (!$stmt->fetch()) {
        $pdo->exec('ALTER TABLE sessions ADD COLUMN expires_at DATETIME NULL');
    }
}
