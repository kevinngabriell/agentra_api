<?php
require_once __DIR__ . '/../general.php';

// $action = $parts[3] — e.g. 'renewal-reminder'
switch ($action) {
    case 'renewal-reminder':
        require __DIR__ . '/renewal-reminder.php';
        break;

    default:
        $authUser = requireAuth();
        jsonResponse(501, 'Not implemented yet');
}
