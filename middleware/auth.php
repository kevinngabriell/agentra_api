<?php
require_once __DIR__ . '/../helpers/jwt.php';
require_once __DIR__ . '/../helpers/response.php';

function requireAuth(): array {
  try {
    return JWT::fromRequest();
  } catch (Exception $e) {
    Response::error($e->getMessage(), 401);
    exit;
  }
}
