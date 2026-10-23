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

// Sub-agent logins are limited to their own read-only portal (helpers/access.php).
if (!in_array($module, ['auth', 'plans'], true)) {
    require_once __DIR__ . '/helpers/access.php';
    enforceSubAgentAccess($conn, $module, $action, $parts[4] ?? '', $_SERVER['REQUEST_METHOD']);
}

switch ($module) {

    case 'auth':
        $actionMap = [
            'login'           => 'login.php',
            'sso'             => 'sso.php',
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
            'checkout'    => 'checkout.php',
            'payments'    => 'payments.php',
            'webhook'     => 'webhook.php',
        ];
        $file = isset($actionMap[$action]) ? __DIR__ . '/subscription/' . $actionMap[$action] : null;
        if (!$file) jsonResponse(404, 'Route not found');
        require $file;
        break;

    case 'dashboard':
        require __DIR__ . '/dashboard/index.php';
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

    case 'master-products':
        require __DIR__ . '/master-products/index.php';
        break;

    case 'master-wilayah':
        require __DIR__ . '/master-wilayah/index.php';
        break;

    case 'revenue':
        require __DIR__ . '/revenue/index.php';
        break;

    case 'commissions':
        require __DIR__ . '/commissions/index.php';
        break;

    case 'documents':
        require __DIR__ . '/documents/index.php';
        break;

    case 'sub-agents':
        require __DIR__ . '/sub-agents/index.php';
        break;

    case 'communications':
        require __DIR__ . '/communications/index.php';
        break;

    case 'customer-groups':
        require __DIR__ . '/customer-groups/index.php';
        break;

    case 'prospects':
        require __DIR__ . '/prospects/index.php';
        break;

    case 'quotes':
        require __DIR__ . '/quotes/index.php';
        break;

    case 'reports':
        require __DIR__ . '/reports/index.php';
        break;

    case 'notifications':
        require __DIR__ . '/notifications/index.php';
        break;

    case 'tasks':
        require __DIR__ . '/tasks/index.php';
        break;

    case 'claims':
        require __DIR__ . '/claims/index.php';
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
