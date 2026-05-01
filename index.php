<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/helpers/response.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}

$uri   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri   = trim($uri, '/');
$parts = explode('/', $uri);

// Expect: api / v1 / {group} / {action}
$prefix  = $parts[0] ?? '';   // "api"
$version = $parts[1] ?? '';   // "v1"
$group   = $parts[2] ?? '';   // "auth"
$action  = $parts[3] ?? '';   // "login", "refresh", etc.

if ($prefix !== 'api' || $version !== 'v1') {
  Response::error('Route not found', 404);
}

switch ($group) {
  case 'auth':
    require __DIR__ . '/routes/auth.php';
    break;

  // Add new route groups here:
  // case 'agents':
  //   require __DIR__ . '/routes/agents.php';
  //   break;

  default:
    Response::error('Route not found', 404);
}
