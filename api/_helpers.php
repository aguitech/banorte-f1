<?php
declare(strict_types=1);

/**
 * Shared API helpers — CORS, JSON in/out, request parsing.
 * CLI-safe: no headers are emitted when running under PHP_SAPI === 'cli'.
 */

if (PHP_SAPI !== 'cli') {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowed = [
        'http://localhost:8080', 'http://127.0.0.1:8080',
        'http://localhost:8081', 'http://127.0.0.1:8081',
        'http://localhost:8082', 'http://127.0.0.1:8082',
        'http://localhost:8090', 'http://127.0.0.1:8090',
        'http://localhost:3000', 'http://127.0.0.1:3000',
        'https://aguitech.github.io',
    ];

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    if ($origin !== '' && in_array($origin, $allowed, true)) {
        header("Access-Control-Allow-Origin: {$origin}");
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function json_out(array $payload, int $code = 200): never
{
    if (PHP_SAPI !== 'cli') {
        http_response_code($code);
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $msg, int $code = 400): never
{
    json_out(['ok' => false, 'error' => $msg], $code);
}

function json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function q(string $key, $default = null)
{
    return $_GET[$key] ?? $default;
}

/** Resolve the shared SQLite path (server and API must agree). */
function bf1_db_path(): string
{
    $env = getenv('BF1_DB');
    if (is_string($env) && $env !== '') {
        return $env;
    }
    return dirname(__DIR__) . '/storage/banorte.sqlite';
}