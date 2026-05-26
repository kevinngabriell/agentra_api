<?php
require_once __DIR__ . '/../general.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(405, 'Method not allowed');
}

// Stateless JWT — client is responsible for discarding the token.
// To enforce server-side logout, store a token blacklist in the DB.
jsonResponse(200, 'Logged out successfully');
