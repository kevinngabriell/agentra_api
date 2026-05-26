<?php
require_once __DIR__ . '/../general.php';

$authUser = requireAuth();
$method   = $_SERVER['REQUEST_METHOD'];

// TODO: implement CRUD for insurers
jsonResponse(501, 'Not implemented yet');
