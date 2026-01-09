<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utils.php';

function save_session(string $token, array $data): void
{
    $pdo = db();
    $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
    $stmt = $pdo->prepare(
        'INSERT INTO sessions (token, trainer_id, email, name, is_admin, rolle_standard, stundensatz, notizen, created_at, updated_at)
         VALUES (:token, :trainer_id, :email, :name, :is_admin, :rolle_standard, :stundensatz, :notizen, :created_at, :updated_at)
         ON DUPLICATE KEY UPDATE email = VALUES(email), name = VALUES(name), is_admin = VALUES(is_admin),
         rolle_standard = VALUES(rolle_standard), stundensatz = VALUES(stundensatz), notizen = VALUES(notizen), updated_at = VALUES(updated_at)'
    );
    $stmt->execute([
        ':token' => $token,
        ':trainer_id' => $data['trainer_id'],
        ':email' => $data['email'] ?? '',
        ':name' => $data['name'] ?? '',
        ':is_admin' => $data['is_admin'] ? 1 : 0,
        ':rolle_standard' => $data['rolle_standard'] ?? null,
        ':stundensatz' => $data['stundensatz'] ?? 0,
        ':notizen' => $data['notizen'] ?? '',
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
}

function get_session(string $token): ?array
{
    if (trim($token) === '') {
        return null;
    }
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM sessions WHERE token = :token');
    $stmt->execute([':token' => $token]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    return [
        'trainer_id' => (string)$row['trainer_id'],
        'email' => (string)($row['email'] ?? ''),
        'name' => (string)($row['name'] ?? ''),
        'is_admin' => (bool)$row['is_admin'],
        'rolle_standard' => (string)($row['rolle_standard'] ?? 'Trainer'),
        'stundensatz' => (float)($row['stundensatz'] ?? 0),
        'notizen' => (string)($row['notizen'] ?? ''),
    ];
}

function clear_session(string $token): void
{
    if (trim($token) === '') {
        return;
    }
    $pdo = db();
    $stmt = $pdo->prepare('DELETE FROM sessions WHERE token = :token');
    $stmt->execute([':token' => $token]);
}

function require_session(?string $token): array
{
    $token = trim((string)$token);
    if ($token === '') {
        json_response(['ok' => false, 'error' => 'Session abgelaufen.']);
    }
    $session = get_session($token);
    if (!$session) {
        json_response(['ok' => false, 'error' => 'Session abgelaufen.']);
    }
    return $session;
}

function require_admin(?string $token): array
{
    $session = require_session($token);
    if (empty($session['is_admin'])) {
        json_response(['ok' => false, 'error' => 'Nicht berechtigt.']);
    }
    return $session;
}
