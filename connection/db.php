<?php
require_once __DIR__ . '/../config.php';

function getConn(): mysqli {
    static $instance = null;
    if ($instance !== null) return $instance;

    $host = (APP_ENV === 'production') ? '127.0.0.1' : '100.98.160.119';
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
