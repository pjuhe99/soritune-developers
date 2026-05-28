<?php
declare(strict_types=1);

const SITE_ROOT = __DIR__ . '/..';

function loadDbCredentials(): array {
    $path = SITE_ROOT . '/.db_credentials';
    if (!is_readable($path)) {
        throw new RuntimeException(".db_credentials not readable at $path");
    }
    $out = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line[0] === '#' || trim($line) === '') continue;
        [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
        $out[trim($k)] = trim($v);
    }
    foreach (['DB_HOST','DB_NAME','DB_USER','DB_PASS'] as $req) {
        if (!isset($out[$req])) {
            throw new RuntimeException("Missing $req in .db_credentials");
        }
    }
    return $out;
}

function getDB(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    $c = loadDbCredentials();
    $dsn = sprintf("mysql:host=%s;dbname=%s;charset=utf8mb4", $c['DB_HOST'], $c['DB_NAME']);
    $pdo = new PDO($dsn, $c['DB_USER'], $c['DB_PASS'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function loadEnv(): array {
    static $env = null;
    if ($env !== null) return $env;
    $path = SITE_ROOT . '/.env';
    $env = [];
    if (is_readable($path)) {
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if ($line[0] === '#' || trim($line) === '') continue;
            [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
            $env[trim($k)] = trim($v);
        }
    }
    return $env;
}

function envOrDie(string $key): string {
    $env = loadEnv();
    if (!isset($env[$key]) || $env[$key] === '') {
        throw new RuntimeException("Required env var $key missing");
    }
    return $env[$key];
}

function jsonResponse(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    if (($_ENV['APP_ENV'] ?? getenv('APP_ENV')) !== 'test') {
        exit;
    }
}

// NOTE: $payload must not contain keys 'ok' or 'message' — they would clobber the envelope.
function jsonSuccess(array $payload = [], string $message = ''): void {
    jsonResponse(array_merge(['ok' => true, 'message' => $message], $payload));
}

// NOTE: $extra must not contain keys 'ok' or 'message' — they would clobber the envelope.
function jsonError(string $message, int $status = 400, array $extra = []): void {
    jsonResponse(array_merge(['ok' => false, 'message' => $message], $extra), $status);
}

function e(string $val): string {
    return htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
