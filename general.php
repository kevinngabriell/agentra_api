<?php
require_once __DIR__ . '/config.php';

// ── CORS (dev only) ───────────────────────────────────────────────────────────
// Production CORS is handled at the web server (nginx/Apache) level.
if (APP_ENV === 'development') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
    header('Access-Control-Max-Age: 86400');

    if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

header('Content-Type: application/json');

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

// ── Roles (Agentra-only: owner | admin | subagent) ────────────────────────────
// Tokens issued before this feature existed carry no `agentra_role` claim —
// default to 'owner' so already-logged-in single-user accounts keep full access.

function requireRole(array $authUser, array $allowedRoles): void {
    $role = $authUser['agentra_role'] ?? 'owner';
    if (!in_array($role, $allowedRoles, true)) {
        jsonResponse(403, 'You do not have permission to perform this action');
        exit;
    }
}

// Subagents may only write records assigned to them; owner/admin may write any
// record in the company. Pass the resource's owning user_id (e.g.
// policies.issuing_agent_id, customers.referred_by_agent_id) — null means no
// owner is set on the record, which is allowed through for everyone.
function requireOwnRecordOrAdmin(array $authUser, ?string $resourceOwnerId): void {
    $role = $authUser['agentra_role'] ?? 'owner';
    if (in_array($role, ['owner', 'admin'], true)) {
        return;
    }

    $selfId = $authUser['sub'] ?? $authUser['user_id'] ?? null;
    if ($resourceOwnerId !== null && $resourceOwnerId === $selfId) {
        return;
    }

    jsonResponse(403, 'You can only modify records assigned to you');
    exit;
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
