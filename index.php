<?php
require_once __DIR__ . '/general.php';

$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri    = trim($uri, '/');
$parts  = explode('/', $uri);

// Expect: api / v1 / {module} / {action}
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

    case 'plans':
        if ($action === '') {
            require __DIR__ . '/plans/index.php';
        } else {
            require __DIR__ . '/plans/detail.php';
        }
        break;

    case 'subscription':
        $actionMap = [
            'current'     => 'current.php',
            'change-plan' => 'change-plan.php',
        ];
        $file = isset($actionMap[$action]) ? __DIR__ . '/subscription/' . $actionMap[$action] : null;
        if (!$file) jsonResponse(404, 'Route not found');
        require $file;
        break;

    case 'customers':
        require __DIR__ . '/customers/index.php';
        break;

    case 'insurers':
        require __DIR__ . '/insurers/index.php';
        break;

    case 'policies':
        require __DIR__ . '/policies/index.php';
        break;

    case 'renewals':
        require __DIR__ . '/renewals/index.php';
        break;

    case 'services':
        require __DIR__ . '/services/index.php';
        break;

    case 'comissions':
        require __DIR__ . '/comissions/index.php';
        break;

    case 'users':
        require __DIR__ . '/users/index.php';
        break;

    default:
        jsonResponse(404, 'Route not found');
}
