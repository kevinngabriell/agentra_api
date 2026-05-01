<?php
require_once __DIR__ . '/config.php';

class DB {
  private static ?mysqli $conn = null;

  // Available databases — switch freely by name
  public const DATABASES = [
    'core'    => 'movira_core_dev',
    'agentra' => 'agentra_dev',      // switch to 'agentra' for production
  ];

  public static function conn(?string $dbName = null): mysqli {
    if (self::$conn instanceof mysqli) {
      if ($dbName) self::$conn->select_db($dbName);
      return self::$conn;
    }

    // development  → remote host; production (VPS) → localhost
    $host = (APP_ENV === 'production') ? '127.0.0.1' : '100.98.160.119';
    $port = (int)($_ENV['DB_PORT'] ?? 3306);
    $user = $_ENV['DB_USER'] ?? '';
    $pass = $_ENV['DB_PASS'] ?? '';

    $defaultDb = $dbName ?: ($_ENV['DB_NAME'] ?? self::DATABASES['core']);

    $conn = new mysqli($host, $user, $pass, $defaultDb, $port);

    if ($conn->connect_error) {
      die(json_encode(['error' => 'Database connection failed: ' . $conn->connect_error]));
    }

    $conn->set_charset('utf8mb4');
    $conn->query("SET time_zone = '+07:00'");

    self::$conn = $conn;
    return self::$conn;
  }

  // Switch to a named database alias (see DATABASES constant above)
  public static function use(string $alias): mysqli {
    $dbName = self::DATABASES[$alias] ?? $alias;
    return self::conn($dbName);
  }

  // Single row — returns associative array or null
  public static function fetchOne(string $sql, array $params = []): ?array {
    $res = self::run($sql, $params);
    $row = $res->fetch_assoc();
    return $row ?: null;
  }

  // All rows — returns array of associative arrays
  public static function fetchAll(string $sql, array $params = []): array {
    return self::run($sql, $params)->fetch_all(MYSQLI_ASSOC);
  }

  // INSERT / UPDATE / DELETE — returns affected rows
  public static function execute(string $sql, array $params = []): int {
    self::run($sql, $params);
    return self::conn()->affected_rows;
  }

  // Internal: always uses a prepared statement, always returns mysqli_result
  private static function run(string $sql, array $params): mysqli_result {
    $conn = self::conn();

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
      throw new Exception('Prepare failed: ' . $conn->error);
    }

    if (!empty($params)) {
      $types = '';
      foreach ($params as $p) {
        $types .= is_int($p) ? 'i' : (is_float($p) ? 'd' : 's');
      }
      $stmt->bind_param($types, ...$params);
    }

    if (!$stmt->execute()) {
      throw new Exception('Execute failed: ' . $stmt->error);
    }

    return $stmt->get_result() ?: new mysqli_result($conn);
  }
}
