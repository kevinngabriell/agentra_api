<?php
class Response {
  public static function json(mixed $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
  }

  public static function success(mixed $data = null, string $message = 'OK', int $status = 200): void {
    self::json(['success' => true, 'message' => $message, 'data' => $data], $status);
  }

  public static function error(string $message, int $status = 400, mixed $data = null): void {
    self::json(['success' => false, 'message' => $message, 'data' => $data], $status);
  }
}
