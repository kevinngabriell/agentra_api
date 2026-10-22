<?php
require_once __DIR__ . '/../general.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(405, 'Method not allowed');
}

// Validate the caller's token so we know WHO is logging out, and so an
// attacker can't trivially lull a victim into thinking they're logged out
// by calling this endpoint with a random request.
try {
    $authUser = requireAuth();
} catch (Exception $e) {
    // requireAuth already responded with 401.
    exit;
}

// Log the logout event server-side. A stateless JWT cannot be truly revoked
// without a server-side blacklist, but recording the event gives an audit trail
// and lets future token-revocation work pick up prior logouts.
error_log(sprintf(
    'logout: user=%s company=%s ip=%s',
    $authUser['sub'] ?? '',
    $authUser['company_id'] ?? '',
    getUserIP()
));

jsonResponse(200, 'Logged out successfully');
