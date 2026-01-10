<?php

declare(strict_types=1);

function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function param(array $body, string $key, $default = null)
{
    if (array_key_exists($key, $_GET)) {
        return $_GET[$key];
    }
    if (array_key_exists($key, $body)) {
        return $body[$key];
    }
    return $default;
}

function truthy($value): bool
{
    if ($value === true) {
        return true;
    }
    if ($value === false) {
        return false;
    }
    $s = strtoupper(trim((string)$value));
    return in_array($s, ['TRUE', 'WAHR', '1', 'JA', 'YES', 'X'], true);
}

function normalize_name(string $name): string
{
    $lower = strtolower(trim($name));
    return preg_replace('/\s+/', ' ', $lower);
}

function create_token(): string
{
    return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
}

function generate_pin(int $length = 4): string
{
    $length = max(4, min(8, $length));
    $digits = '';
    for ($i = 0; $i < $length; $i += 1) {
        $digits .= (string)random_int(0, 9);
    }
    return $digits;
}

function hash_pin(string $pin): string
{
    $pin = trim($pin);
    if ($pin === '') {
        return '';
    }
    $digest = hash('sha256', $pin, true);
    return 'sha256:' . base64_encode($digest);
}

function is_hashed_pin(string $value): bool
{
    $normalized = trim($value);
    if ($normalized === '') {
        return false;
    }
    if (str_starts_with($normalized, 'sha256:')) {
        return true;
    }
    if (str_starts_with($normalized, 'sha256hex:')) {
        return true;
    }
    return (bool)preg_match('/^[0-9a-f]{64}$/i', $normalized);
}

function verify_pin(string $input, string $stored): bool
{
    $candidate = trim($input);
    $expected = trim($stored);
    if ($candidate === '' || $expected === '') {
        return false;
    }
    if (str_starts_with($expected, 'sha256:')) {
        return hash_pin($candidate) === $expected;
    }

    $digestHex = hash('sha256', $candidate);

    if (str_starts_with($expected, 'sha256hex:')) {
        $needle = strtolower(trim(substr($expected, strlen('sha256hex:'))));
        return strtolower($digestHex) === $needle;
    }

    if (preg_match('/^[0-9a-f]{64}$/i', $expected)) {
        return strtolower($digestHex) === strtolower($expected);
    }

    return $candidate === $expected;
}

function parse_date($value): ?DateTimeImmutable
{
    if ($value instanceof DateTimeInterface) {
        return DateTimeImmutable::createFromInterface($value);
    }
    $raw = trim((string)$value);
    if ($raw === '') {
        return null;
    }
    $dt = date_create_immutable($raw);
    return $dt ?: null;
}

function format_date(?DateTimeInterface $date): string
{
    if (!$date) {
        return '';
    }
    return $date->format('d.m.Y');
}

function format_datetime(?DateTimeInterface $date): string
{
    if (!$date) {
        return '';
    }
    return $date->format('d.m.Y H:i');
}

function format_time($value): string
{
    if ($value === null) {
        return '';
    }
    if ($value instanceof DateTimeInterface) {
        return $value->format('H:i');
    }
    $raw = trim((string)$value);
    if ($raw === '') {
        return '';
    }
    if (preg_match('/^(\d{1,2}):(\d{2})/', $raw, $m)) {
        return str_pad((string)(int)$m[1], 2, '0', STR_PAD_LEFT) . ':' . $m[2];
    }
    $dt = date_create_immutable($raw);
    if ($dt) {
        return $dt->format('H:i');
    }
    return $raw;
}

function start_of_day_ts(?DateTimeInterface $date): int
{
    if (!$date) {
        return 0;
    }
    $d = DateTimeImmutable::createFromInterface($date)->setTime(0, 0, 0);
    return (int)($d->getTimestamp() * 1000);
}
