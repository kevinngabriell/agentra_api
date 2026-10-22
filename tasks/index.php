<?php
// [NEW v1.12.0] Tasks and reminders.
//
//   GET    /api/v1/tasks[?status=open|done|all][&due=overdue|today|week|today_overdue]
//                       [&customer_id=..][&policy_id=..][&claim_id=..][&limit=50]
//   POST   /api/v1/tasks                 {title, due_date, notes?, customer_id?, policy_id?, claim_id?}
//   PUT    /api/v1/tasks/{id}            same fields
//   POST   /api/v1/tasks/{id}/complete   | /reopen
//   DELETE /api/v1/tasks/{id}

require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../connection/db.php';

const TASK_SELECT = "SELECT t.task_id, t.title, t.notes, t.due_date, t.status, t.completed_at,
        t.customer_id, t.policy_id, t.claim_id, t.created_at,
        COALESCE(c.display_name, pc.display_name) AS customer_name,
        p.policy_number, cl.claim_number,
        DATEDIFF(t.due_date, CURDATE()) AS days_until_due
    FROM ";

function taskFrom(): string {
    $a = APP_SCHEMA;
    return "$a.tasks t
        LEFT JOIN $a.customers c ON c.customer_id = t.customer_id
        LEFT JOIN $a.policies p ON p.policy_id = t.policy_id
        LEFT JOIN $a.customers pc ON pc.customer_id = p.customer_id
        LEFT JOIN $a.claims cl ON cl.claim_id = t.claim_id";
}

function shapeTask(array $r): array {
    $r['days_until_due'] = (int)$r['days_until_due'];
    return $r;
}

function listTasks($conn, string $cid, array $q): void {
    $where = "t.company_id = '$cid'";
    $status = $q['status'] ?? 'open';
    if (in_array($status, ['open', 'done'], true)) $where .= " AND t.status = '$status'";
    $due = match ($q['due'] ?? '') {
        'overdue'       => " AND t.due_date < CURDATE()",
        'today'         => " AND t.due_date = CURDATE()",
        'week'          => " AND t.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)",
        'today_overdue' => " AND t.due_date <= CURDATE()",
        default         => '',
    };
    $where .= $due;
    foreach (['customer_id', 'policy_id', 'claim_id'] as $f) {
        if (!empty($q[$f])) $where .= " AND t.$f = '" . mysqli_real_escape_string($conn, $q[$f]) . "'";
    }
    $limit = min(100, max(1, (int)($q['limit'] ?? 50)));
    $order = $status === 'done' ? 't.completed_at DESC' : 't.due_date ASC, t.created_at ASC';

    $res = mysqli_query($conn, TASK_SELECT . taskFrom() . " WHERE $where ORDER BY $order LIMIT $limit");
    if (!$res) throw new RuntimeException('listTasks: ' . mysqli_error($conn));

    $a = APP_SCHEMA;
    $cnt = mysqli_query($conn, "SELECT
            SUM(due_date < CURDATE()) AS overdue, SUM(due_date = CURDATE()) AS today
        FROM $a.tasks WHERE company_id = '$cid' AND status = 'open'");
    $c = $cnt ? mysqli_fetch_assoc($cnt) : [];

    jsonResponse(200, 'Tasks retrieved', [
        'items'         => array_map('shapeTask', mysqli_fetch_all($res, MYSQLI_ASSOC)),
        'overdue_count' => (int)($c['overdue'] ?? 0),
        'today_count'   => (int)($c['today'] ?? 0),
    ]);
}

/** Validates the writable fields; every link must belong to the company. */
function taskFields($conn, string $cid, array $in, bool $partial): array {
    $errors = [];
    $title = trim((string)($in['title'] ?? ''));
    if ($title === '' || mb_strlen($title) > 200) $errors['title'] = 'Judul wajib diisi (maks. 200 karakter)';
    $due = (string)($in['due_date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) || !strtotime($due)) $errors['due_date'] = 'Tanggal jatuh tempo tidak valid (YYYY-MM-DD)';

    $links = [];
    foreach (['customer_id' => 'customers', 'policy_id' => 'policies', 'claim_id' => 'claims'] as $f => $table) {
        $v = trim((string)($in[$f] ?? ''));
        if ($v === '') { $links[$f] = null; continue; }
        $e = mysqli_real_escape_string($conn, $v);
        $res = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".$table WHERE {$f} = '$e' AND company_id = '$cid' LIMIT 1");
        if (!$res || mysqli_num_rows($res) === 0) $errors[$f] = 'Data tidak ditemukan';
        $links[$f] = $e;
    }
    if ($errors) Response::validationError($errors);

    return ['title' => mysqli_real_escape_string($conn, $title), 'due' => $due,
            'notes' => trim((string)($in['notes'] ?? '')) !== '' ? mysqli_real_escape_string($conn, trim($in['notes'])) : null] + $links;
}

function sqlOrNull(?string $v): string { return $v === null ? 'NULL' : "'$v'"; }

function saveTask($conn, string $cid, ?string $id, array $in, string $user): void {
    $f = taskFields($conn, $cid, $in, $id !== null);
    $a = APP_SCHEMA;
    if ($id === null) {
        $id = generateUUID();
        $u = mysqli_real_escape_string($conn, $user);
        $ok = mysqli_query($conn, "INSERT INTO $a.tasks (task_id, company_id, title, notes, due_date, customer_id, policy_id, claim_id, created_by)
            VALUES ('$id', '$cid', '{$f['title']}', " . sqlOrNull($f['notes']) . ", '{$f['due']}', "
            . sqlOrNull($f['customer_id']) . ', ' . sqlOrNull($f['policy_id']) . ', ' . sqlOrNull($f['claim_id']) . ", '$u')");
        $code = 201;
    } else {
        $e = mysqli_real_escape_string($conn, $id);
        $ok = mysqli_query($conn, "UPDATE $a.tasks SET title = '{$f['title']}', notes = " . sqlOrNull($f['notes']) . ",
            due_date = '{$f['due']}', customer_id = " . sqlOrNull($f['customer_id']) . ', policy_id = ' . sqlOrNull($f['policy_id'])
            . ', claim_id = ' . sqlOrNull($f['claim_id']) . " WHERE task_id = '$e' AND company_id = '$cid'");
        if ($ok && mysqli_affected_rows($conn) === 0) requireTask($conn, $cid, $id);
        $code = 200;
    }
    if (!$ok) throw new RuntimeException('saveTask: ' . mysqli_error($conn));
    jsonResponse($code, 'Task saved', ['task_id' => $id]);
}

function requireTask($conn, string $cid, string $id): void {
    $e = mysqli_real_escape_string($conn, $id);
    $res = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".tasks WHERE task_id = '$e' AND company_id = '$cid' LIMIT 1");
    if (!$res || mysqli_num_rows($res) === 0) throw ApiException::from('ERR_COMMON_002');
}

function setTaskDone($conn, string $cid, string $id, bool $done): void {
    requireTask($conn, $cid, $id);
    $e = mysqli_real_escape_string($conn, $id);
    $set = $done ? "status = 'done', completed_at = NOW()" : "status = 'open', completed_at = NULL";
    if (!mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".tasks SET $set WHERE task_id = '$e' AND company_id = '$cid'")) {
        throw new RuntimeException('setTaskDone: ' . mysqli_error($conn));
    }
    jsonResponse(200, $done ? 'Task completed' : 'Task reopened', ['task_id' => $id]);
}

function deleteTask($conn, string $cid, string $id): void {
    requireTask($conn, $cid, $id);
    $e = mysqli_real_escape_string($conn, $id);
    if (!mysqli_query($conn, "DELETE FROM " . APP_SCHEMA . ".tasks WHERE task_id = '$e' AND company_id = '$cid'")) {
        throw new RuntimeException('deleteTask: ' . mysqli_error($conn));
    }
    jsonResponse(200, 'Task deleted', ['task_id' => $id]);
}

$authUser   = requireAuth();
$method     = $_SERVER['REQUEST_METHOD'];
$company_id = $authUser['company_id'] ?? null;
$username   = $authUser['user_id'] ?? $authUser['sub'] ?? '';
if (!$company_id) { jsonResponse(400, 'company_id is required'); exit; }
$sub_action = $parts[4] ?? '';

try {
    $conn = getConn();
    $cid  = mysqli_real_escape_string($conn, $company_id);

    if ($action === '') {
        if ($method === 'GET')       listTasks($conn, $cid, $_GET);
        elseif ($method === 'POST')  saveTask($conn, $cid, null, input(), $username);
        else                         Response::methodNotAllowed();
    } elseif ($sub_action === '') {
        if ($method === 'PUT')         saveTask($conn, $cid, $action, input(), $username);
        elseif ($method === 'DELETE')  deleteTask($conn, $cid, $action);
        else                           Response::methodNotAllowed();
    } elseif ($sub_action === 'complete' || $sub_action === 'reopen') {
        if ($method !== 'POST') Response::methodNotAllowed();
        setTaskDone($conn, $cid, $action, $sub_action === 'complete');
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
