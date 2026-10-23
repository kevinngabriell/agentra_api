<?php
// [NEW v1.12.0] In-app notification bell (brief §4.4.3).
//
//   GET  /api/v1/notifications[?unread=1][&limit=20]   feed + unread_count (syncs new events first)
//   POST /api/v1/notifications/read-all                mark everything read
//   POST /api/v1/notifications/{id}/read               mark one read

require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../connection/db.php';
require_once __DIR__ . '/../helpers/notifications.php';

function listNotifications($conn, string $cid, array $q): void {
    syncNotifications($conn, $cid);

    $limit  = min(50, max(1, (int)($q['limit'] ?? 20)));
    $unread = !empty($q['unread']) ? " AND read_at IS NULL" : '';
    $res = mysqli_query($conn, "SELECT notification_id, type, title, body, link, read_at, created_at
        FROM " . APP_SCHEMA . ".notifications WHERE company_id = '$cid'$unread
        ORDER BY created_at DESC, notification_id LIMIT $limit");
    if (!$res) throw new RuntimeException('listNotifications: ' . mysqli_error($conn));

    $cnt = mysqli_query($conn, "SELECT COUNT(*) AS n FROM " . APP_SCHEMA . ".notifications WHERE company_id = '$cid' AND read_at IS NULL");
    jsonResponse(200, 'Notifications retrieved', [
        'items'        => mysqli_fetch_all($res, MYSQLI_ASSOC),
        'unread_count' => $cnt ? (int)mysqli_fetch_assoc($cnt)['n'] : 0,
    ]);
}

function markNotificationsRead($conn, string $cid, ?string $id): void {
    $one = $id !== null ? " AND notification_id = '" . mysqli_real_escape_string($conn, $id) . "'" : '';
    if (!mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".notifications SET read_at = NOW()
        WHERE company_id = '$cid' AND read_at IS NULL$one")) {
        throw new RuntimeException('markNotificationsRead: ' . mysqli_error($conn));
    }
    jsonResponse(200, 'Notifications marked as read', ['updated' => mysqli_affected_rows($conn)]);
}

$authUser   = requireAuth();
$method     = $_SERVER['REQUEST_METHOD'];
$company_id = $authUser['company_id'] ?? null;
if (!$company_id) { jsonResponse(400, 'company_id is required'); exit; }
$sub_action = $parts[4] ?? '';

try {
    $conn = getConn();
    $cid  = mysqli_real_escape_string($conn, $company_id);

    if ($action === '') {
        if ($method !== 'GET') Response::methodNotAllowed();
        listNotifications($conn, $cid, $_GET);
    } elseif ($action === 'read-all') {
        if ($method !== 'POST') Response::methodNotAllowed();
        markNotificationsRead($conn, $cid, null);
    } elseif ($sub_action === 'read') {
        if ($method !== 'POST') Response::methodNotAllowed();
        markNotificationsRead($conn, $cid, $action);
    } else {
        jsonResponse(404, 'Route not found');
    }
} catch (ApiException $e) {
    throw $e;
} catch (\Throwable $e) {
    RequestLogger::setException($e);
    error_log($e->getMessage());
    Response::internalError();
}
