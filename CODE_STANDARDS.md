# PHP Backend Code Standards

This document captures the exact patterns, conventions, and architectural decisions used in this project. Copy this guide to replicate the same code quality in any new PHP API project.

---

## Table of Contents

1. [Project Structure](#1-project-structure)
2. [Environment & Configuration](#2-environment--configuration)
3. [Entry Point & Routing](#3-entry-point--routing)
4. [Database Connection](#4-database-connection)
5. [JWT Authentication](#5-jwt-authentication)
6. [Core Utilities (general.php)](#6-core-utilities-generalphp)
7. [Module File Pattern](#7-module-file-pattern)
8. [Input Validation Rules](#8-input-validation-rules)
9. [Response Format](#9-response-format)
10. [ID Generation](#10-id-generation)
11. [Pagination](#11-pagination)
12. [Helper Files](#12-helper-files)
13. [CORS & Headers](#13-cors--headers)
14. [Naming Conventions](#14-naming-conventions)
15. [Error Handling](#15-error-handling)
16. [SQL Patterns](#16-sql-patterns)
17. [Complete File Templates](#17-complete-file-templates)

---

## 1. Project Structure

```
project_root/
├── .env                        # secrets — never commit
├── .env.example                # template — always commit
├── .htaccess                   # route everything to index.php
├── .gitignore                  # must include .env
├── index.php                   # central router
├── general.php                 # shared utilities loaded by every module
├── config.php                  # env loader + constants
├── connection/
│   └── db.php                  # singleton DB connection
├── helpers/
│   ├── jwt.php                 # JWT encode/decode class
│   └── {concern}.php           # one file per cross-cutting concern
├── auth/
│   ├── login.php
│   ├── register.php
│   ├── logout.php
│   ├── refresh.php
│   ├── forgot-password.php
│   └── reset-password.php
└── {module}/
    └── index.php               # all CRUD for that module
```

**Rules:**
- One directory per resource/module (`customers/`, `policies/`, `insurers/`)
- Sub-resources live in separate files inside the parent module directory (`policies/coverages.php`)
- Helpers are files that contain pure functions used by multiple modules
- No `src/`, no `app/`, no framework directories — flat and direct

---

## 2. Environment & Configuration

### `.env` file

```ini
APP_ENV=development

# development = 100.x.x.x | production = 127.0.0.1
DB_HOST=your_dev_host
DB_PORT=3306
DB_USER=your_db_user
DB_PASS=your_db_pass

CORE_SCHEMA=yourapp_core_dev
APP_SCHEMA=yourapp_dev

JWT_SECRET="YOUR_APP_qwertyuiop"

# External APIs (optional)
WAHA_BASE_URL=
WAHA_SESSION=
WAHA_API_KEY=
```

### `.env.example` (always commit this)

Copy `.env` and blank all secret values. Keep comments to explain each key.

### `.gitignore`

```
.env
.DS_Store
```

### `config.php`

```php
<?php
function loadEnv(string $path): void {
    if (!file_exists($path)) return;

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        $value = trim($value, '"\'');
        if (!array_key_exists($key, $_ENV)) {
            $_ENV[$key] = $value;
            putenv("$key=$value");
        }
    }
}

loadEnv(__DIR__ . '/.env');

define('APP_ENV',     $_ENV['APP_ENV']     ?? 'development');
define('JWT_SECRET',  $_ENV['JWT_SECRET']  ?? '');
define('CORE_SCHEMA', $_ENV['CORE_SCHEMA'] ?? 'yourapp_core_dev');
define('APP_SCHEMA',  $_ENV['APP_SCHEMA']  ?? 'yourapp_dev');
```

**Rules:**
- `loadEnv()` is idempotent — skips keys already in `$_ENV` (server env takes priority)
- All values become PHP `define()` constants so they're available globally without `$_ENV`
- Always provide a safe default in the `??` fallback (never `null` for schema names)
- Group related defines together with alignment padding for readability

---

## 3. Entry Point & Routing

### `.htaccess`

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [QSA,L]
```

### `index.php`

```php
<?php
require_once __DIR__ . '/general.php';

$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri    = trim($uri, '/');
$parts  = explode('/', $uri);

// Expect: api / v1 / {module} / {action} [/ {sub_action} [/ {sub_id}]]
$prefix  = $parts[0] ?? '';
$version = $parts[1] ?? '';
$module  = $parts[2] ?? '';
$action  = $parts[3] ?? '';

if ($prefix !== 'api' || $version !== 'v1') {
    jsonResponse(404, 'Route not found');
}

switch ($module) {

    case 'auth':
        $actionMap = [
            'login'           => 'login.php',
            'register'        => 'register.php',
            'refresh'         => 'refresh.php',
            'logout'          => 'logout.php',
            'forgot-password' => 'forgot-password.php',
            'reset-password'  => 'reset-password.php',
        ];
        $file = isset($actionMap[$action]) ? __DIR__ . '/auth/' . $actionMap[$action] : null;
        if (!$file) jsonResponse(404, 'Route not found');
        require $file;
        break;

    case 'customers':
        require __DIR__ . '/customers/index.php';
        break;

    // ... more modules

    default:
        jsonResponse(404, 'Route not found');
}
```

**Rules:**
- URL format is always `api/v1/{module}/{action}/{sub_action}/{sub_id}`
- `$parts` array is parsed once in `index.php` and is available in every required file (PHP scope sharing through `require`)
- Auth module uses an `$actionMap` array — simpler than nested switches
- Simple modules just `require` the module's `index.php` directly
- Always have a `default` that returns 404

---

## 4. Database Connection

### `connection/db.php`

```php
<?php
require_once __DIR__ . '/../config.php';

function getConn(): mysqli {
    static $instance = null;
    if ($instance !== null) return $instance;

    $host = (APP_ENV === 'production') ? '127.0.0.1' : $_ENV['DB_HOST'] ?? '127.0.0.1';
    $port = (int)($_ENV['DB_PORT'] ?? 3306);
    $user = $_ENV['DB_USER'] ?? '';
    $pass = $_ENV['DB_PASS'] ?? '';

    $instance = new mysqli($host, $user, $pass, '', $port);

    if ($instance->connect_error) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Database connection failed']);
        exit;
    }

    $instance->set_charset('utf8mb4');
    $instance->query("SET time_zone = '+07:00'");

    return $instance;
}
```

**Rules:**
- Singleton pattern with `static $instance` — one connection per request
- Production always uses `127.0.0.1` (localhost) — never expose DB to external IPs
- Always `set_charset('utf8mb4')` — supports full Unicode including emoji
- Always set `time_zone` to your application's timezone
- Fail fast on connect error — output JSON and `exit` immediately
- Do NOT select a default database in `new mysqli()` — use schema-qualified table names everywhere (`APP_SCHEMA . '.table_name'`)

---

## 5. JWT Authentication

### `helpers/jwt.php`

```php
<?php
require_once __DIR__ . '/../config.php';

class JWT {
    public const ACCESS_TTL  = 36000;  // 10 hours
    public const REFRESH_TTL = 604800; // 7 days

    public static function encode(array $payload, int $expireSeconds = self::ACCESS_TTL): string {
        $header = self::base64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));

        $payload['iat'] = time();
        $payload['exp'] = time() + $expireSeconds;

        $body = self::base64url(json_encode($payload));
        $sig  = self::base64url(hash_hmac('sha256', "$header.$body", JWT_SECRET, true));

        return "$header.$body.$sig";
    }

    public static function encodeRefresh(array $payload): string {
        return self::encode(array_merge($payload, ['type' => 'refresh']), self::REFRESH_TTL);
    }

    public static function decode(string $token, bool $allowRefresh = false): array {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new Exception('Invalid token format', 401);
        }

        [$header, $body, $sig] = $parts;

        $expected = self::base64url(hash_hmac('sha256', "$header.$body", JWT_SECRET, true));
        if (!hash_equals($expected, $sig)) {
            throw new Exception('Invalid token signature', 401);
        }

        $payload = json_decode(self::base64urlDecode($body), true);
        if (!$payload || !isset($payload['exp'])) {
            throw new Exception('Malformed token payload', 401);
        }

        if (time() > $payload['exp']) {
            throw new Exception('Token expired', 401);
        }

        $isRefresh = ($payload['type'] ?? '') === 'refresh';
        if ($allowRefresh && !$isRefresh) {
            throw new Exception('Expected a refresh token', 401);
        }
        if (!$allowRefresh && $isRefresh) {
            throw new Exception('Cannot use refresh token as access token', 401);
        }

        return $payload;
    }

    public static function fromRequest(): array {
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!str_starts_with($auth, 'Bearer ')) {
            throw new Exception('Authorization header missing or malformed', 401);
        }
        return self::decode(substr($auth, 7));
    }

    private static function base64url(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64urlDecode(string $data): string {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
```

### Token Claims (standard payload)

```php
$claims = [
    'sub'        => $user['user_id'],    // subject — the user's UUID
    'username'   => $user['username'],   // email or login name
    'role'       => $user['app_role_id'],
    'company_id' => $user['company_id'],
];

$access_token  = JWT::encode($claims);
$refresh_token = JWT::encodeRefresh($claims);
```

### Login response shape

```php
jsonResponse(200, 'Login successful', [
    'access_token'  => JWT::encode($claims),
    'refresh_token' => JWT::encodeRefresh($claims),
    'token_type'    => 'Bearer',
    'expires_in'    => JWT::ACCESS_TTL,
]);
```

### Token refresh — re-issue both tokens

```php
$payload = JWT::decode($refreshToken, allowRefresh: true);
// re-fetch user from DB to validate still active
// re-issue both tokens with fresh claims
```

### Logout — stateless

```php
// Stateless JWT — client is responsible for discarding the token.
// To enforce server-side logout, store a token blacklist in the DB.
jsonResponse(200, 'Logged out successfully');
```

**Rules:**
- Access token TTL = 10 hours (`36000`). Refresh token TTL = 7 days (`604800`)
- Refresh tokens carry `'type' => 'refresh'` in payload — decoded with `allowRefresh: true`
- Use `hash_equals()` for signature comparison — prevents timing attacks
- `JWT::fromRequest()` is the only way to read a token from headers — called inside `requireAuth()`
- Never store tokens server-side unless building a blacklist for forced logout
- Always re-fetch the user from DB during refresh to catch deactivated accounts

---

## 6. Core Utilities (`general.php`)

```php
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

$conn = getConn();

function jsonResponse($code, $message, $data = []): void {
    http_response_code($code);
    echo json_encode([
        'status_code'    => $code,
        'status_message' => $message,
        'data'           => $data
    ]);
    exit;
}

function cleanInput(string $value): string {
    global $conn;
    return mysqli_real_escape_string($conn, trim($value));
}

function input(): array {
    return json_decode(file_get_contents('php://input'), true) ?? [];
}

function requireAuth(): array {
    try {
        return JWT::fromRequest();
    } catch (Exception $e) {
        jsonResponse(401, $e->getMessage());
        exit;
    }
}

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
```

**Rules:**
- `general.php` is the only file that must be `require_once`'d at the top of every module
- `jsonResponse()` always calls `exit` — the response terminates immediately
- `cleanInput()` = `trim` + `mysqli_real_escape_string` — use for string inputs
- `input()` reads the JSON request body — use for POST/PUT/PATCH bodies
- `requireAuth()` returns the decoded JWT payload array or kills the request with 401

---

## 7. Module File Pattern

Every module file follows this exact structure:

```php
<?php

require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../connection/db.php';
// require_once helpers as needed

// ── Functions (one per operation) ─────────────────────────────────────────────

function getAllThings($conn, $company_id, $params) {
    // ...
}

function createThing($conn, $input, $username, $company_id) {
    // ...
}

function getDetailThing($conn, $thing_id, $company_id) {
    // ...
}

function updateThing($conn, $thing_id, $input, $username, $company_id) {
    // ...
}

function deleteThing($conn, $thing_id, $company_id) {
    // ...
}

// ── Dispatch ──────────────────────────────────────────────────────────────────

$authUser   = requireAuth();
$method     = $_SERVER['REQUEST_METHOD'];
$company_id = $authUser['company_id'] ?? null;
$username   = $authUser['sub'] ?? $authUser['user_id'] ?? null;

if (!$company_id) {
    jsonResponse(400, 'company_id is required');
    exit;
}

$thing_id   = !empty($action) ? $action : null;
$sub_action = $parts[4] ?? '';

try {
    $conn = getConn();

    if ($thing_id && $sub_action !== '') {
        $input = in_array($method, ['POST', 'PUT', 'PATCH'])
            ? (json_decode(file_get_contents('php://input'), true) ?? [])
            : [];

        switch ($sub_action) {
            case 'status':
                if ($method !== 'PATCH') { jsonResponse(405, 'Method Not Allowed'); }
                updateThingStatus($conn, $thing_id, $company_id, $input);
                break;
            default:
                jsonResponse(404, 'Route not found');
        }

    } elseif ($thing_id) {
        switch ($method) {
            case 'GET':
                getDetailThing($conn, $thing_id, $company_id);
                break;
            case 'PUT':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                updateThing($conn, $thing_id, $input, $username, $company_id);
                break;
            case 'DELETE':
                deleteThing($conn, $thing_id, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }

    } else {
        switch ($method) {
            case 'GET':
                getAllThings($conn, $company_id, $_GET);
                break;
            case 'POST':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                createThing($conn, $input, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    }

} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
```

**Rules:**
- Functions are defined first, dispatch block is at the bottom
- `requireAuth()` is called once at the top of dispatch — never inside individual functions
- `$company_id` is always sourced from the JWT, never from the request body
- `$username` is sourced from `$authUser['sub']` (standard) with fallback to `$authUser['user_id']`
- Dispatch order: `{id}/{sub_action}` → `{id}` → collection (root)
- Always wrap the dispatch in `try/catch (Exception $e)` for a 500 safety net

---

## 8. Input Validation Rules

### Required field check pattern

```php
$required = ['field_one', 'field_two', 'field_three'];
foreach ($required as $field) {
    if (!isset($input[$field]) || is_string($input[$field]) && trim($input[$field]) === '') {
        jsonResponse(400, "$field is required");
        return;
    }
}
```

### Enum validation — always use `in_array` with strict mode

```php
if (!in_array($status, ['active', 'inactive', 'lapsed'], true)) {
    jsonResponse(400, 'status must be active, inactive, or lapsed');
    return;
}
```

### Date format validation

```php
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    jsonResponse(400, 'date must be YYYY-MM-DD format');
    return;
}
```

### Numeric bounds

```php
$page  = max(1, (int)($params['page']  ?? 1));
$limit = min(100, max(1, (int)($params['limit'] ?? 10)));
```

### Optional nullable string — SQL NULL handling pattern

```php
$notes_sql = isset($input['notes']) && trim($input['notes']) !== ''
    ? "'" . mysqli_real_escape_string($conn, trim($input['notes'])) . "'"
    : 'NULL';
```

### Sanitize all string inputs before using in SQL

```php
$name = trim(mysqli_real_escape_string($conn, $input['name']));
```

### Existence check before update/delete

```php
$check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".things WHERE thing_id = '$thing_id' AND company_id = '$company_id' LIMIT 1");
if (mysqli_num_rows($check) === 0) {
    jsonResponse(404, 'Thing not found');
    return;
}
```

### Duplicate check before insert

```php
$dup = mysqli_query($conn, "SELECT 1 FROM ... WHERE unique_field = '$value' LIMIT 1");
if (mysqli_num_rows($dup) > 0) {
    jsonResponse(409, 'Already exists');
    return;
}
```

---

## 9. Response Format

Every response uses this exact shape — no exceptions:

```json
{
  "status_code": 200,
  "status_message": "Human readable message",
  "data": {}
}
```

### HTTP status codes used

| Code | Meaning |
|------|---------|
| 200  | Success (GET, PUT, PATCH, DELETE) |
| 201  | Created (POST that creates a resource) |
| 400  | Bad request / validation error |
| 401  | Unauthorized (missing or invalid token) |
| 404  | Resource not found |
| 405  | Method not allowed |
| 409  | Conflict (duplicate) |
| 422  | Unprocessable entity (missing required fields) |
| 500  | Internal server error |

### Paginated list response

```php
jsonResponse(200, 'Things found', [
    'data'       => $rows,
    'pagination' => [
        'total'       => $total,
        'page'        => $page,
        'limit'       => $limit,
        'total_pages' => (int)ceil($total / $limit),
    ],
]);
```

### Empty list

```php
if (mysqli_num_rows($result) === 0) {
    jsonResponse(404, 'No things found');
}
```

### Created resource

```php
jsonResponse(201, 'Thing created successfully', ['thing_id' => $thing_id]);
```

### Error with detail

```php
jsonResponse(500, 'Failed to create thing', ['error' => mysqli_error($conn)]);
```

---

## 10. ID Generation

### Prefixed IDs using `uniqid()`

Use a meaningful prefix + `uniqid()` for all entity IDs:

```php
$policy_id    = 'pol_'  . uniqid();
$customer_id  = 'cust_' . uniqid();
$commission_id = 'com_' . uniqid();
$coverage_id  = 'cov_'  . uniqid();
$follow_up_id = 'fu_'   . uniqid();
$log_id       = 'plog_' . uniqid();
```

### UUID using `generateUUID()` (for multi-tenant user/company IDs)

```php
$company_id = generateUUID();  // e.g. "6fa459ea-ee8a-3ca4-894e-db77e160355e"
```

Use UUID when the ID must be completely opaque and globally unique (user IDs, company IDs stored in the core schema shared across apps). Use prefixed `uniqid()` for resource IDs within the app schema.

---

## 11. Pagination

Standard query pattern:

```php
$page   = max(1, (int)($params['page']  ?? 1));
$limit  = min(100, max(1, (int)($params['limit'] ?? 10)));
$offset = ($page - 1) * $limit;

$query      = "SELECT ... FROM ... WHERE $where ORDER BY created_at DESC LIMIT $limit OFFSET $offset";
$countQuery = "SELECT COUNT(*) AS total FROM ... WHERE $where";

$result      = mysqli_query($conn, $query);
$countResult = mysqli_query($conn, $countQuery);
$total       = $countResult ? (int)mysqli_fetch_assoc($countResult)['total'] : 0;

jsonResponse(200, 'Things found', [
    'data'       => mysqli_fetch_all($result, MYSQLI_ASSOC),
    'pagination' => [
        'total'       => $total,
        'page'        => $page,
        'limit'       => $limit,
        'total_pages' => (int)ceil($total / $limit),
    ],
]);
```

**Rules:**
- Maximum `limit` is always capped at 100
- Minimum `page` is always 1
- `$where` is built as a string starting with the mandatory tenant filter (`company_id = '...'`)
- The `WHERE $where` clause is shared between the data query and the count query
- Always `ORDER BY created_at DESC` as the default sort

---

## 12. Helper Files

Helper files contain pure functions that are called by multiple modules. They do not run any code at the file level — only function definitions.

### Pattern

```php
<?php
// helpers/my_concern.php

function insertSomething($conn, $entity_id, $company_id, ...$args) {
    // ...
    mysqli_query($conn, "INSERT INTO ...");
}

function syncSomething($conn, $entity_id, ...$args) {
    // ...
    mysqli_query($conn, "UPDATE ...");
}
```

### Audit log helper convention

The policy log helper is a good template for any audit trail:

```php
function insertAuditLog(
    $conn,
    $entity_id,
    $company_id,
    $event_type,       // e.g. 'record_created', 'record_updated', 'status_changed'
    $description,      // human-readable, e.g. "Status changed: pending → active"
    $username,
    $old_value      = null,
    $new_value      = null,
    $reference_type = null,
    $reference_id   = null,
    $metadata       = null  // array — will be JSON-encoded
) {
    // Silent on failure — never let audit writes break the main operation
    $log_id  = 'log_' . uniqid();
    $now     = date('Y-m-d H:i:s');
    $meta_sql = $metadata !== null
        ? "'" . mysqli_real_escape_string($conn, json_encode($metadata, JSON_UNESCAPED_UNICODE)) . "'"
        : 'NULL';

    mysqli_query($conn, "INSERT INTO " . APP_SCHEMA . ".audit_logs (...) VALUES (...)");
}
```

**Rules:**
- Audit log inserts are always silent — never check `mysqli_query()` return value on logs
- Metadata is stored as JSON string in the DB
- Helper functions always take `$conn` as the first parameter

---

## 13. CORS & Headers

```php
// In general.php — applied to every request

if (APP_ENV === 'development') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
    header('Access-Control-Max-Age: 86400');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

header('Content-Type: application/json');
```

**Rules:**
- CORS headers are only set in `development` mode — in production, configure at the nginx/Apache level
- Preflight (`OPTIONS`) requests return `204 No Content` and exit immediately
- `Content-Type: application/json` is always set globally in `general.php`

---

## 14. Naming Conventions

### Variables

```php
$company_id    // snake_case always
$customer_id
$policy_number
$commission_rate
$coverage_start
```

### Functions

```php
getAllPolicies()       // verb + plural noun
createPolicy()        // verb + singular noun
getDetailPolicy()     // getDetail prefix for single-record fetch
updatePolicy()
deletePolicy()
updateRenewalStatus() // specific sub-operation
addFollowUp()
getPaymentSummary()
```

### Classes

```php
class JWT {}   // PascalCase — only used for the JWT utility class
```

### Database columns and PHP variables should match exactly

If the DB column is `coverage_start`, the PHP variable is `$coverage_start`. Never alias.

### SQL table references

Always qualify with schema constant:

```php
APP_SCHEMA . ".policies"
CORE_SCHEMA . ".app_user"
```

### Boolean flags in DB

Store as `TINYINT(1)`, cast to PHP `bool` when returning:

```php
$row['count_in_tsi'] = (bool)(int)$row['count_in_tsi'];
```

### Timestamps

Always `date('Y-m-d H:i:s')` for `created_at` / `updated_at` fields.

```php
$now = date('Y-m-d H:i:s');
```

---

## 15. Error Handling

### Every dispatch block is wrapped in try/catch

```php
try {
    $conn = getConn();
    // ... all logic
} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
```

### Auth module entry pattern (no requireAuth)

```php
try {
    $method = $_SERVER['REQUEST_METHOD'];
    $conn   = getConn();

    switch ($method) {
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            myAuthFunction($conn, $input);
            break;
        default:
            jsonResponse(405, 'Method not allowed');
    }

} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
```

### Early return after validation failure

```php
function createThing($conn, $input, $username, $company_id) {
    if (!isset($input['name'])) {
        jsonResponse(400, 'name is required');
        return;   // <-- always return after jsonResponse inside a function
    }
    // ... continue
}
```

> `jsonResponse()` calls `exit` — so `return` after it is technically redundant inside functions,
> but it signals intent and prevents code analysis tools from flagging dead code.

---

## 16. SQL Patterns

### Schema-qualified tables (never assume a default DB)

```php
mysqli_query($conn, "SELECT * FROM " . APP_SCHEMA . ".policies WHERE ...");
```

### Always LIMIT 1 on single-record lookups

```php
"SELECT user_id FROM ... WHERE email = '$email' LIMIT 1"
```

### Dynamic UPDATE with `$updates` array

```php
$updates = [];

if (isset($input['name'])) {
    $val = trim(mysqli_real_escape_string($conn, $input['name']));
    $updates[] = "name = '$val'";
}
if (isset($input['status'])) {
    $updates[] = "status = '$status'";
}

if (empty($updates)) {
    jsonResponse(400, 'No fields provided for update');
    return;
}

$now = date('Y-m-d H:i:s');
$updates[] = "updated_by = '$username'";
$updates[] = "updated_at = '$now'";

mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".things SET " . implode(', ', $updates) . " WHERE thing_id = '$thing_id' AND company_id = '$company_id'");
```

### Dynamic WHERE with filter building

```php
$where = "company_id = '$company_id'";  // mandatory tenant filter first

if ($search) {
    $where .= " AND (name LIKE '%$search%' OR code LIKE '%$search%')";
}
if ($status && in_array($status, ['active', 'inactive'], true)) {
    $where .= " AND status = '$status'";
}
```

### DELETE cascade order

Delete child records before the parent:

```php
mysqli_query($conn, "DELETE FROM " . APP_SCHEMA . ".child_table    WHERE parent_id = '$id'");
mysqli_query($conn, "DELETE FROM " . APP_SCHEMA . ".another_child  WHERE parent_id = '$id'");
mysqli_query($conn, "DELETE FROM " . APP_SCHEMA . ".parent_table   WHERE parent_id = '$id' AND company_id = '$company_id'");
```

### Fetch all rows

```php
$data = mysqli_fetch_all($result, MYSQLI_ASSOC);
```

### Fetch single row

```php
$row = mysqli_fetch_assoc($result);
```

---

## 17. Complete File Templates

### New module file

```php
<?php

require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../connection/db.php';

// --- GET ALL ---
function getAll{Module}s($conn, $company_id, $params) {
    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(100, max(1, (int)($params['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;
    $search = isset($params['search']) ? mysqli_real_escape_string($conn, $params['search']) : '';

    $where = "company_id = '$company_id'";
    if ($search) {
        $where .= " AND name LIKE '%$search%'";
    }

    $result      = mysqli_query($conn, "SELECT * FROM " . APP_SCHEMA . ".{module}s WHERE $where ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
    $countResult = mysqli_query($conn, "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".{module}s WHERE $where");
    $total       = $countResult ? (int)mysqli_fetch_assoc($countResult)['total'] : 0;

    if ($result && mysqli_num_rows($result) > 0) {
        jsonResponse(200, '{Module}s found', [
            'data'       => mysqli_fetch_all($result, MYSQLI_ASSOC),
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'limit'       => $limit,
                'total_pages' => (int)ceil($total / $limit),
            ],
        ]);
    } else {
        jsonResponse(404, 'No {module}s found');
    }
}

// --- CREATE ---
function create{Module}($conn, $input, $username, $company_id) {
    $required = ['name'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || is_string($input[$field]) && trim($input[$field]) === '') {
            jsonResponse(400, "$field is required");
            return;
        }
    }

    $name = trim(mysqli_real_escape_string($conn, $input['name']));

    $dup = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".{module}s WHERE company_id = '$company_id' AND name = '$name' LIMIT 1");
    if (mysqli_num_rows($dup) > 0) {
        jsonResponse(409, '{Module} already exists');
        return;
    }

    ${module}_id = '{prefix}_' . uniqid();
    $now         = date('Y-m-d H:i:s');

    $sql = "INSERT INTO " . APP_SCHEMA . ".{module}s ({module}_id, company_id, name, created_by, created_at)
            VALUES ('${module}_id', '$company_id', '$name', '$username', '$now')";

    if (mysqli_query($conn, $sql)) {
        jsonResponse(201, '{Module} created successfully', ['{module}_id' => ${module}_id]);
    } else {
        jsonResponse(500, 'Failed to create {module}', ['error' => mysqli_error($conn)]);
    }
}

// --- GET DETAIL ---
function getDetail{Module}($conn, ${module}_id, $company_id) {
    if (!${module}_id) {
        jsonResponse(400, '{module}_id is required');
        return;
    }

    ${module}_id = mysqli_real_escape_string($conn, ${module}_id);

    $result = mysqli_query($conn, "SELECT * FROM " . APP_SCHEMA . ".{module}s WHERE {module}_id = '${module}_id' AND company_id = '$company_id' LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, '{Module} not found');
        return;
    }

    jsonResponse(200, '{Module} found', mysqli_fetch_assoc($result));
}

// --- UPDATE ---
function update{Module}($conn, ${module}_id, $input, $username, $company_id) {
    if (!${module}_id) {
        jsonResponse(400, '{module}_id is required');
        return;
    }

    ${module}_id = mysqli_real_escape_string($conn, ${module}_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".{module}s WHERE {module}_id = '${module}_id' AND company_id = '$company_id' LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, '{Module} not found');
        return;
    }

    $updates = [];

    if (isset($input['name'])) {
        $val = trim(mysqli_real_escape_string($conn, $input['name']));
        if ($val === '') { jsonResponse(400, 'name cannot be empty'); return; }
        $updates[] = "name = '$val'";
    }

    if (empty($updates)) {
        jsonResponse(400, 'No fields provided for update');
        return;
    }

    $now = date('Y-m-d H:i:s');
    $updates[] = "updated_by = '$username'";
    $updates[] = "updated_at = '$now'";

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".{module}s SET " . implode(', ', $updates) . " WHERE {module}_id = '${module}_id' AND company_id = '$company_id'")) {
        jsonResponse(200, '{Module} updated successfully');
    } else {
        jsonResponse(500, 'Failed to update {module}', ['error' => mysqli_error($conn)]);
    }
}

// --- DELETE ---
function delete{Module}($conn, ${module}_id, $company_id) {
    if (!${module}_id) {
        jsonResponse(400, '{module}_id is required');
        return;
    }

    ${module}_id = mysqli_real_escape_string($conn, ${module}_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".{module}s WHERE {module}_id = '${module}_id' AND company_id = '$company_id' LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, '{Module} not found');
        return;
    }

    if (mysqli_query($conn, "DELETE FROM " . APP_SCHEMA . ".{module}s WHERE {module}_id = '${module}_id' AND company_id = '$company_id'")) {
        jsonResponse(200, '{Module} deleted successfully');
    } else {
        jsonResponse(500, 'Failed to delete {module}', ['error' => mysqli_error($conn)]);
    }
}

// ── Dispatch ──────────────────────────────────────────────────────────────────

$authUser   = requireAuth();
$method     = $_SERVER['REQUEST_METHOD'];
$company_id = $authUser['company_id'] ?? null;
$username   = $authUser['sub'] ?? $authUser['user_id'] ?? null;

if (!$company_id) {
    jsonResponse(400, 'company_id is required');
    exit;
}

${module}_id = !empty($action) ? $action : null;

try {
    $conn = getConn();

    if (${module}_id) {
        switch ($method) {
            case 'GET':
                getDetail{Module}($conn, ${module}_id, $company_id);
                break;
            case 'PUT':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                update{Module}($conn, ${module}_id, $input, $username, $company_id);
                break;
            case 'DELETE':
                delete{Module}($conn, ${module}_id, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    } else {
        switch ($method) {
            case 'GET':
                getAll{Module}s($conn, $company_id, $_GET);
                break;
            case 'POST':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                create{Module}($conn, $input, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    }

} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
```

> Replace `{Module}` with PascalCase (e.g., `Customer`), `{module}` with snake_case (e.g., `customer`), and `{prefix}` with the ID prefix (e.g., `cust`).

---

## Quick Checklist for New Projects

- [ ] Copy `config.php` — update schema constant names
- [ ] Copy `connection/db.php` — update prod host if needed
- [ ] Copy `helpers/jwt.php` — set correct `ACCESS_TTL` and `REFRESH_TTL`
- [ ] Copy `general.php` — adjust timezone in `db.php` if needed
- [ ] Copy `auth/` directory — update DB table and column names for your user table
- [ ] Copy `.htaccess`
- [ ] Create `.env` from `.env.example` — fill in real values
- [ ] Add `.env` to `.gitignore`
- [ ] Set `APP_ENV=production` in server environment for prod deployments
- [ ] Configure CORS at nginx/Apache level for production
