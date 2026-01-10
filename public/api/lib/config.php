<?php

declare(strict_types=1);

function load_env_file(string $path): void
{
    if (!is_readable($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ($key === '') {
            continue;
        }
        if ($value !== '' && $value[0] === '"' && str_ends_with($value, '"')) {
            $value = stripcslashes(substr($value, 1, -1));
        } elseif ($value !== '' && $value[0] === "'" && str_ends_with($value, "'")) {
            $value = substr($value, 1, -1);
        }
        if (getenv($key) !== false) {
            continue;
        }
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

function load_config(): array
{
    static $config = null;
    if (is_array($config)) {
        return $config;
    }

    $root = dirname(__DIR__, 2);
    load_env_file($root . '/.env');
    load_env_file($root . '/.env.local');

    $config = [
        'DB_HOST' => getenv('DB_HOST') ?: '127.0.0.1',
        'DB_PORT' => getenv('DB_PORT') ?: '3306',
        'DB_NAME' => getenv('DB_NAME') ?: 'trainerweb',
        'DB_USER' => getenv('DB_USER') ?: 'root',
        'DB_PASS' => getenv('DB_PASS') ?: '',
        'SESSION_TTL_SECONDS' => (int)(getenv('SESSION_TTL_SECONDS') ?: 60 * 60 * 8),
        'APP_TIMEZONE' => getenv('APP_TIMEZONE') ?: 'Europe/Berlin',
        'SPREADSHEET_ID' => getenv('SPREADSHEET_ID') ?: '',
        'SHEETS' => [
            'TRAINER' => getenv('SHEET_TRAINER') ?: 'TRAINER',
            'TRAININGS' => getenv('SHEET_TRAININGS') ?: 'TRAININGS',
            'EINTEILUNGEN' => getenv('SHEET_EINTEILUNGEN') ?: 'EINTEILUNGEN',
            'ABMELDUNGEN' => getenv('SHEET_ABMELDUNGEN') ?: 'ABMELDUNGEN',
            'TRAININGSPLAN' => getenv('SHEET_TRAININGSPLAN') ?: 'TRAININGSPLAN',
            'ROLLEN_SAETZE' => getenv('SHEET_ROLLEN_SAETZE') ?: 'ROLLEN_SAETZE',
            'TURNIERE' => getenv('SHEET_TURNIERE') ?: 'TURNIERE',
            'TURNIER_EINSAETZE' => getenv('SHEET_TURNIER_EINSAETZE') ?: 'TURNIER_EINSAETZE',
            'FAHRTEN' => getenv('SHEET_FAHRTEN') ?: 'FAHRTEN',
        ],
    ];

    return $config;
}

function cfg(string $key, $default = null)
{
    $config = load_config();
    return $config[$key] ?? $default;
}

function ensure_timezone(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $timezone = cfg('APP_TIMEZONE', 'Europe/Berlin');
    if (is_string($timezone) && $timezone !== '') {
        date_default_timezone_set($timezone);
    }
}

ensure_timezone();
