<?php
// ── CORS & Content-Type ───────────────────────────────────────────────────────
// Must be set before any output or require that might fail.
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Max-Age: 86400');

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/connection/db.php';
require_once __DIR__ . '/helpers/jwt.php';

// ── Database connection (global) ──────────────────────────────────────────────
$conn = getConn();

// ── Response ──────────────────────────────────────────────────────────────────

function jsonResponse($code, $message, $data = []): void {
    http_response_code($code);
    echo json_encode([
        'status_code' => $code,
        'status_message' => $message,
        'data' => $data
    ]);
    exit;
}

// ── Input ─────────────────────────────────────────────────────────────────────

function cleanInput(string $value): string {
    global $conn;
    return mysqli_real_escape_string($conn, trim($value));
}

function input(): array {
    return json_decode(file_get_contents('php://input'), true) ?? [];
}

// ── Auth ──────────────────────────────────────────────────────────────────────

function requireAuth(): array {
    try {
        return JWT::fromRequest();
    } catch (Exception $e) {
        jsonResponse(401, $e->getMessage());
        exit;
    }
}

// ── Utilities ─────────────────────────────────────────────────────────────────

function generateUUID(): string {
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

function getUserIP(): string {
    return $_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['HTTP_X_REAL_IP']
        ?? $_SERVER['REMOTE_ADDR']
        ?? '';
}
