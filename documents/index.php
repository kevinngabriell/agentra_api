<?php

require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../connection/db.php';
require_once __DIR__ . '/../helpers/object_storage.php';
require_once __DIR__ . '/../helpers/policy_log.php';
require_once __DIR__ . '/../helpers/claims.php';

// Uploaded files are stored in Cloudeka S3 (helpers/object_storage.php). Rows are
// never hard-deleted: DELETE soft-deletes, and documents on expired policies can
// only be archived — see migration_documents.sql. Documents of a claim that is
// still in progress are exempt: they stay deletable and are not auto-archived.

const DOC_MAX_BYTES = 10 * 1024 * 1024;

// Allowed types are decided by the file's real content (finfo), not its extension.
const DOC_ALLOWED_MIME = [
    'application/pdf' => 'pdf',
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
];

const DOC_TYPES = [
    'policy'         => 'Polis',
    'endorsement'    => 'Endorsemen',
    'sppa'           => 'SPPA',
    'ktp'            => 'KTP',
    'npwp'           => 'NPWP',
    'nib'            => 'NIB',
    'akta_pendirian' => 'Akta Pendirian',
    'survey_photo'   => 'Foto Survei',
    'claim'          => 'Dokumen Klaim',
    'payment_proof'  => 'Bukti Bayar',
    'other'          => 'Lainnya',
];

// KYC documents required per customer type (APU-PPT). For a company, "ktp" is the
// KTP of its director / PIC. Individuals only need a KTP: since the NIK–NPWP
// integration the NIK doubles as the personal tax ID.
const DOC_KYC_REQUIRED = [
    'individual' => ['ktp'],
    'company'    => ['akta_pendirian', 'nib', 'npwp', 'ktp'],
];
const DOC_KYC_TYPES = ['ktp', 'npwp', 'nib', 'akta_pendirian'];

// ── Shared query pieces ───────────────────────────────────────────────────────

function documentSelectSql(): string {
    return "SELECT d.document_id, d.customer_id, c.display_name AS customer_name, c.customer_type,
                d.policy_id, p.policy_number, p.product_type, p.coverage_end,
                p.insurer_id, i.short_name AS insurer_short_name,
                d.claim_id, d.claim_requirement, d.document_type, d.title, d.notes, d.document_year,
                d.file_name, d.mime_type, d.file_size,
                d.status, d.archived_at, d.archive_reason,
                d.uploaded_by, TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS uploaded_by_name,
                d.uploaded_at,
                (p.policy_id IS NOT NULL AND p.coverage_end < CURDATE() AND (cl.claim_id IS NULL OR cl.status IN ('paid', 'rejected'))) AS policy_expired
            FROM " . APP_SCHEMA . ".documents d
            JOIN " . APP_SCHEMA . ".customers c ON c.customer_id = d.customer_id
            LEFT JOIN " . APP_SCHEMA . ".policies p ON p.policy_id = d.policy_id
            LEFT JOIN " . APP_SCHEMA . ".claims cl ON cl.claim_id = d.claim_id
            LEFT JOIN " . APP_SCHEMA . ".insurers i ON i.insurer_id = p.insurer_id
            LEFT JOIN " . CORE_SCHEMA . ".app_user u ON u.user_id COLLATE utf8mb4_unicode_ci = d.uploaded_by";
}

function normalizeDocument(array $row): array {
    $row['document_year']  = (int)$row['document_year'];
    $row['file_size']      = (int)$row['file_size'];
    $row['policy_expired'] = (bool)$row['policy_expired'];
    $row['uploaded_by_name'] = $row['uploaded_by_name'] !== '' ? $row['uploaded_by_name'] : null;
    return $row;
}

function findDocument($conn, string $document_id, string $company_id): array {
    $document_id = mysqli_real_escape_string($conn, $document_id);
    $result = mysqli_query($conn, documentSelectSql() . "
        WHERE d.document_id = '$document_id' AND d.company_id = '$company_id' AND d.is_deleted = 0
        LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        throw ApiException::from('ERR_DOC_001');
    }
    return normalizeDocument(mysqli_fetch_assoc($result));
}

function documentLabel(array $doc): string {
    return (DOC_TYPES[$doc['document_type']] ?? $doc['document_type']) . ' — ' . ($doc['title'] ?: $doc['file_name']);
}

// --- LIST ---
function listDocuments($conn, string $company_id, array $q): void {
    $page   = max(1, (int)($q['page'] ?? 1));
    $limit  = min(50, max(1, (int)($q['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $where = "d.company_id = '$company_id' AND d.is_deleted = 0";

    $status = $q['status'] ?? 'active';
    if (in_array($status, ['active', 'archived'], true)) {
        $where .= " AND d.status = '$status'";
    }

    foreach (['customer_id' => 'd.customer_id', 'policy_id' => 'd.policy_id', 'claim_id' => 'd.claim_id', 'insurer_id' => 'p.insurer_id'] as $param => $column) {
        if (!empty($q[$param])) {
            $value = mysqli_real_escape_string($conn, (string)$q[$param]);
            $where .= " AND $column = '$value'";
        }
    }

    if (!empty($q['document_type'])) {
        if (!isset(DOC_TYPES[$q['document_type']])) throw ApiException::from('ERR_DOC_005');
        $where .= " AND d.document_type = '{$q['document_type']}'";
    }

    if (!empty($q['year'])) {
        $year = (int)$q['year'];
        $where .= " AND d.document_year = $year";
    }

    if (!empty($q['params'])) {
        $s = mysqli_real_escape_string($conn, trim((string)$q['params']));
        $where .= " AND (d.title LIKE '%$s%' OR d.file_name LIKE '%$s%' OR c.display_name LIKE '%$s%' OR p.policy_number LIKE '%$s%')";
    }

    $result = mysqli_query($conn, documentSelectSql() . " WHERE $where ORDER BY d.uploaded_at DESC LIMIT $limit OFFSET $offset");
    $count  = mysqli_query($conn, "SELECT COUNT(*) AS total
        FROM " . APP_SCHEMA . ".documents d
        JOIN " . APP_SCHEMA . ".customers c ON c.customer_id = d.customer_id
        LEFT JOIN " . APP_SCHEMA . ".policies p ON p.policy_id = d.policy_id
        WHERE $where");
    if (!$result || !$count) {
        throw new RuntimeException('listDocuments: ' . mysqli_error($conn));
    }

    $total = (int)mysqli_fetch_assoc($count)['total'];
    jsonResponse(200, 'Documents retrieved', [
        'data'       => array_map('normalizeDocument', mysqli_fetch_all($result, MYSQLI_ASSOC)),
        'pagination' => [
            'total'       => $total,
            'page'        => $page,
            'limit'       => $limit,
            'total_pages' => (int)ceil($total / $limit),
        ],
    ]);
}

// --- SUMMARY (stat cards + filter options for the documents page) ---
function getDocumentSummary($conn, string $company_id): void {
    $base = APP_SCHEMA . ".documents d WHERE d.company_id = '$company_id' AND d.is_deleted = 0";

    $totals = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT COUNT(*) AS total, COALESCE(SUM(d.status = 'active'), 0) AS active, COALESCE(SUM(d.status = 'archived'), 0) AS archived FROM $base"));

    $by_type = [];
    $res = mysqli_query($conn, "SELECT d.document_type, COUNT(*) AS total FROM $base AND d.status = 'active' GROUP BY d.document_type");
    while ($row = mysqli_fetch_assoc($res)) $by_type[$row['document_type']] = (int)$row['total'];

    $years = [];
    $res = mysqli_query($conn, "SELECT DISTINCT d.document_year FROM $base ORDER BY d.document_year DESC");
    while ($row = mysqli_fetch_assoc($res)) $years[] = (int)$row['document_year'];

    $expired = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".documents d
         JOIN " . APP_SCHEMA . ".policies p ON p.policy_id = d.policy_id
         LEFT JOIN " . APP_SCHEMA . ".claims cl ON cl.claim_id = d.claim_id
         WHERE d.company_id = '$company_id' AND d.is_deleted = 0 AND d.status = 'active' AND p.coverage_end < CURDATE()
           AND (cl.claim_id IS NULL OR cl.status IN ('paid', 'rejected'))"));

    $kyc = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT COUNT(*) AS total FROM (" . kycAggregateSql($company_id, "c.status <> 'inactive'") . " HAVING kyc_missing > 0) t"));

    jsonResponse(200, 'Document summary retrieved', [
        'total'                 => (int)$totals['total'],
        'active'                => (int)$totals['active'],
        'archived'              => (int)$totals['archived'],
        'by_type'               => (object)$by_type,
        'years'                 => $years,
        'expired_policy_active' => (int)$expired['total'],
        'kyc_incomplete'        => (int)$kyc['total'],
    ]);
}

// --- KYC (APU-PPT) ---

// One row per customer with has_{type} flags and kyc_missing (number of required
// KYC document types not on file). Archived documents still count — archiving
// only hides the file, the customer has still been identified.
function kycAggregateSql(string $company_id, string $extraWhere = '1=1'): string {
    $flags = [];
    foreach (DOC_KYC_TYPES as $t) {
        $flags[] = "COALESCE(MAX(d.document_type = '$t'), 0) AS has_$t";
    }
    $missing = [];
    foreach (DOC_KYC_REQUIRED as $customer_type => $required) {
        $present = implode(' + ', array_map(fn($t) => "COALESCE(MAX(d.document_type = '$t'), 0)", $required));
        $missing[] = "WHEN '$customer_type' THEN " . count($required) . " - ($present)";
    }

    return "SELECT c.customer_id, c.display_name, c.customer_type, c.status, " . implode(', ', $flags) . ",
                CASE c.customer_type " . implode(' ', $missing) . " ELSE 0 END AS kyc_missing
            FROM " . APP_SCHEMA . ".customers c
            LEFT JOIN " . APP_SCHEMA . ".documents d
                ON d.customer_id = c.customer_id AND d.is_deleted = 0
               AND d.document_type IN ('" . implode("','", DOC_KYC_TYPES) . "')
            WHERE c.company_id = '$company_id' AND $extraWhere
            GROUP BY c.customer_id";
}

function kycRow(array $row): array {
    $required = DOC_KYC_REQUIRED[$row['customer_type']] ?? [];
    $present  = array_values(array_filter($required, fn($t) => (int)$row["has_$t"] === 1));
    $missing  = array_values(array_diff($required, $present));
    return [
        'customer_id'   => $row['customer_id'],
        'display_name'  => $row['display_name'],
        'customer_type' => $row['customer_type'],
        'status'        => $row['status'],
        'required'      => $required,
        'present'       => $present,
        'missing'       => $missing,
        'complete'      => count($missing) === 0,
    ];
}

function listKyc($conn, string $company_id, array $q): void {
    $page   = max(1, (int)($q['page'] ?? 1));
    $limit  = min(50, max(1, (int)($q['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    // Inactive customers are excluded by default — there is no business relationship to verify.
    $where = ($q['include_inactive'] ?? '') === 'true' ? '1=1' : "c.status <> 'inactive'";
    if (!empty($q['params'])) {
        $s = mysqli_real_escape_string($conn, trim((string)$q['params']));
        $where .= " AND (c.display_name LIKE '%$s%' OR c.company_legal_name LIKE '%$s%')";
    }

    $having = match ($q['kyc'] ?? 'incomplete') {
        'complete' => 'HAVING kyc_missing = 0',
        'all'      => '',
        default    => 'HAVING kyc_missing > 0',
    };

    $sql    = kycAggregateSql($company_id, $where) . " $having";
    $result = mysqli_query($conn, "$sql ORDER BY c.display_name LIMIT $limit OFFSET $offset");
    $count  = mysqli_query($conn, "SELECT COUNT(*) AS total FROM ($sql) t");
    if (!$result || !$count) {
        throw new RuntimeException('listKyc: ' . mysqli_error($conn));
    }

    $total = (int)mysqli_fetch_assoc($count)['total'];
    jsonResponse(200, 'KYC status retrieved', [
        'data'       => array_map('kycRow', mysqli_fetch_all($result, MYSQLI_ASSOC)),
        'pagination' => [
            'total'       => $total,
            'page'        => $page,
            'limit'       => $limit,
            'total_pages' => (int)ceil($total / $limit),
        ],
    ]);
}

function getCustomerKyc($conn, string $company_id, string $customer_id): void {
    $customer_id = mysqli_real_escape_string($conn, $customer_id);
    $result = mysqli_query($conn, kycAggregateSql($company_id, "c.customer_id = '$customer_id'"));
    if (!$result || mysqli_num_rows($result) === 0) {
        throw ApiException::from('ERR_DOC_007');
    }
    jsonResponse(200, 'KYC status retrieved', kycRow(mysqli_fetch_assoc($result)));
}

// --- UPLOAD (multipart/form-data) ---
function uploadDocument($conn, string $company_id, string $username): void {
    // When the body exceeds post_max_size PHP drops $_POST and $_FILES entirely.
    if (empty($_FILES) && empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        throw ApiException::from('ERR_DOC_003');
    }
    $file = $_FILES['file'] ?? null;
    if (!$file || is_array($file['error'])) {
        throw ApiException::from('ERR_DOC_002');
    }
    if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $file['size'] > DOC_MAX_BYTES) {
        throw ApiException::from('ERR_DOC_003');
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        throw ApiException::from('ERR_DOC_002');
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset(DOC_ALLOWED_MIME[$mime])) {
        throw ApiException::from('ERR_DOC_004');
    }

    $document_type = (string)($_POST['document_type'] ?? '');
    if (!isset(DOC_TYPES[$document_type])) {
        throw ApiException::from('ERR_DOC_005');
    }

    $policy_id   = trim((string)($_POST['policy_id'] ?? ''));
    $customer_id = trim((string)($_POST['customer_id'] ?? ''));
    $claim_id    = trim((string)($_POST['claim_id'] ?? ''));
    $claim_requirement = trim((string)($_POST['claim_requirement'] ?? ''));

    // A claim document belongs to the claim's policy and customer; the checklist item
    // (if any) must be one of the claim's product checklist keys.
    $claim_number = null;
    if ($claim_id !== '') {
        $clid = mysqli_real_escape_string($conn, $claim_id);
        $res  = mysqli_query($conn, "SELECT cl.policy_id, cl.claim_number, p.product_type
            FROM " . APP_SCHEMA . ".claims cl JOIN " . APP_SCHEMA . ".policies p ON p.policy_id = cl.policy_id
            WHERE cl.claim_id = '$clid' AND cl.company_id = '$company_id' AND cl.is_deleted = 0 LIMIT 1");
        if (!$res || mysqli_num_rows($res) === 0) throw ApiException::from('ERR_DOC_020');
        $claim = mysqli_fetch_assoc($res);
        if ($policy_id !== '' && $policy_id !== $claim['policy_id']) throw ApiException::from('ERR_DOC_009');
        $policy_id    = $claim['policy_id'];
        $claim_number = $claim['claim_number'];
        if ($claim_requirement !== '' && !in_array($claim_requirement, claimRequirementKeys($claim['product_type']), true)) {
            throw ApiException::from('ERR_DOC_021');
        }
    } else {
        $claim_requirement = '';
    }

    if ($policy_id === '' && $customer_id === '') {
        throw ApiException::from('ERR_DOC_006');
    }

    $document_year = (int)date('Y');
    if ($policy_id !== '') {
        $pid = mysqli_real_escape_string($conn, $policy_id);
        $res = mysqli_query($conn, "SELECT customer_id, YEAR(coverage_start) AS start_year FROM " . APP_SCHEMA . ".policies
            WHERE policy_id = '$pid' AND company_id = '$company_id' LIMIT 1");
        if (!$res || mysqli_num_rows($res) === 0) throw ApiException::from('ERR_DOC_008');
        $policy = mysqli_fetch_assoc($res);
        if ($customer_id !== '' && $customer_id !== $policy['customer_id']) throw ApiException::from('ERR_DOC_009');
        $customer_id   = $policy['customer_id'];
        $document_year = (int)$policy['start_year'];
    } else {
        $cid = mysqli_real_escape_string($conn, $customer_id);
        $res = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".customers WHERE customer_id = '$cid' AND company_id = '$company_id' LIMIT 1");
        if (!$res || mysqli_num_rows($res) === 0) throw ApiException::from('ERR_DOC_007');
    }

    if (isset($_POST['document_year']) && $_POST['document_year'] !== '') {
        $document_year = validDocumentYear($_POST['document_year']);
    }

    $title = trim((string)($_POST['title'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));
    // Keep the original name for display, minus any path and control characters.
    $file_name = mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', basename((string)$file['name'])) ?: 'dokumen', 0, 300);

    $document_id = 'doc_' . bin2hex(random_bytes(10));
    $file_key    = s3Key("$company_id/documents/$customer_id/" . generateUUID() . '.' . DOC_ALLOWED_MIME[$mime]);

    try {
        s3PutObject($file_key, file_get_contents($file['tmp_name']), $mime);
    } catch (RuntimeException $e) {
        error_log('uploadDocument: ' . $e->getMessage());
        throw ApiException::from('ERR_DOC_015');
    }

    $cid_s   = mysqli_real_escape_string($conn, $customer_id);
    $pid_s   = $policy_id !== '' ? "'" . mysqli_real_escape_string($conn, $policy_id) . "'" : 'NULL';
    $clm_s   = $claim_id !== '' ? "'" . mysqli_real_escape_string($conn, $claim_id) . "'" : 'NULL';
    $req_s   = $claim_requirement !== '' ? "'" . mysqli_real_escape_string($conn, $claim_requirement) . "'" : 'NULL';
    $title_s = $title !== '' ? "'" . mysqli_real_escape_string($conn, mb_substr($title, 0, 200)) . "'" : 'NULL';
    $notes_s = $notes !== '' ? "'" . mysqli_real_escape_string($conn, $notes) . "'" : 'NULL';
    $fname_s = mysqli_real_escape_string($conn, $file_name);
    $key_s   = mysqli_real_escape_string($conn, $file_key);
    $user_s  = mysqli_real_escape_string($conn, $username);
    $size    = (int)$file['size'];
    $now     = date('Y-m-d H:i:s');

    $ok = mysqli_query($conn, "INSERT INTO " . APP_SCHEMA . ".documents
        (document_id, company_id, customer_id, policy_id, claim_id, claim_requirement, document_type, title, notes, document_year,
         file_key, file_name, mime_type, file_size, uploaded_by, uploaded_at)
        VALUES
        ('$document_id', '$company_id', '$cid_s', $pid_s, $clm_s, $req_s, '$document_type', $title_s, $notes_s, $document_year,
         '$key_s', '$fname_s', '$mime', $size, '$user_s', '$now')");

    if (!$ok) {
        RequestLogger::setException(new RuntimeException(mysqli_error($conn)));
        // The row is the only reference to the object, so don't leave it orphaned.
        try { s3DeleteObject($file_key); } catch (RuntimeException $e) { error_log('uploadDocument cleanup: ' . $e->getMessage()); }
        throw ApiException::from('ERR_DOC_016');
    }

    $doc = findDocument($conn, $document_id, $company_id);
    if ($claim_id !== '') {
        insertClaimEvent($conn, $claim_id, $company_id, 'document_uploaded', 'Dokumen diunggah: ' . documentLabel($doc), $username);
    }
    if ($policy_id !== '') {
        insertPolicyLog($conn, $policy_id, $company_id, 'document_uploaded', 'Dokumen diunggah: ' . documentLabel($doc),
            $username, null, null, 'documents', $document_id, ['document_type' => $document_type, 'file_name' => $file_name]);
    }

    jsonResponse(201, 'Document uploaded successfully', $doc);
}

function validDocumentYear(mixed $value): int {
    $year = filter_var($value, FILTER_VALIDATE_INT);
    if ($year === false || $year < 1990 || $year > (int)date('Y') + 5) {
        throw ApiException::from('ERR_DOC_010');
    }
    return $year;
}

// --- UPDATE METADATA ---
function updateDocument($conn, string $company_id, string $document_id, array $input, string $username): void {
    $doc = findDocument($conn, $document_id, $company_id);
    if ($doc['status'] === 'archived') throw ApiException::from('ERR_DOC_019');

    $updates = [];
    if (array_key_exists('document_type', $input)) {
        if (!isset(DOC_TYPES[$input['document_type']])) throw ApiException::from('ERR_DOC_005');
        $updates[] = "document_type = '{$input['document_type']}'";
    }
    if (array_key_exists('title', $input)) {
        $title = trim((string)$input['title']);
        $updates[] = 'title = ' . ($title !== '' ? "'" . mysqli_real_escape_string($conn, mb_substr($title, 0, 200)) . "'" : 'NULL');
    }
    if (array_key_exists('notes', $input)) {
        $notes = trim((string)$input['notes']);
        $updates[] = 'notes = ' . ($notes !== '' ? "'" . mysqli_real_escape_string($conn, $notes) . "'" : 'NULL');
    }
    if (array_key_exists('document_year', $input)) {
        $updates[] = 'document_year = ' . validDocumentYear($input['document_year']);
    }
    if (!$updates) throw ApiException::from('ERR_DOC_017');

    $updates[] = "updated_by = '" . mysqli_real_escape_string($conn, $username) . "'";
    $id = mysqli_real_escape_string($conn, $document_id);
    if (!mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".documents SET " . implode(', ', $updates) . " WHERE document_id = '$id' AND company_id = '$company_id'")) {
        RequestLogger::setException(new RuntimeException(mysqli_error($conn)));
        throw ApiException::from('ERR_DOC_018');
    }

    jsonResponse(200, 'Document updated successfully', findDocument($conn, $document_id, $company_id));
}

// --- PRESIGNED URL (preview / download) ---
function getDocumentUrl($conn, string $company_id, string $document_id, string $disposition): void {
    $doc = findDocument($conn, $document_id, $company_id);
    $id  = mysqli_real_escape_string($conn, $document_id);
    $key = mysqli_fetch_assoc(mysqli_query($conn, "SELECT file_key FROM " . APP_SCHEMA . ".documents WHERE document_id = '$id'"))['file_key'];

    $disposition = $disposition === 'attachment' ? 'attachment' : 'inline';
    $ascii       = preg_replace('/[^\x20-\x7E]|["\\\\]/', '_', $doc['file_name']);
    $expires     = 300;

    try {
        $url = s3PresignGetUrl($key, $expires, [
            'response-content-disposition' => "$disposition; filename=\"$ascii\"; filename*=UTF-8''" . rawurlencode($doc['file_name']),
            'response-content-type'        => $doc['mime_type'],
        ]);
    } catch (RuntimeException $e) {
        error_log('getDocumentUrl: ' . $e->getMessage());
        throw ApiException::from('ERR_DOC_015');
    }

    jsonResponse(200, 'Document URL generated', [
        'url'        => $url,
        'expires_in' => $expires,
        'mime_type'  => $doc['mime_type'],
        'file_name'  => $doc['file_name'],
    ]);
}

// --- ARCHIVE / UNARCHIVE ---
function setDocumentArchived($conn, string $company_id, string $document_id, bool $archive, string $reason, string $username): void {
    $doc = findDocument($conn, $document_id, $company_id);
    if ($archive && $doc['status'] === 'archived') throw ApiException::from('ERR_DOC_013');
    if (!$archive && $doc['status'] !== 'archived') throw ApiException::from('ERR_DOC_014');

    $id   = mysqli_real_escape_string($conn, $document_id);
    $user = mysqli_real_escape_string($conn, $username);
    $set  = $archive
        ? "status = 'archived', archived_at = '" . date('Y-m-d H:i:s') . "', archived_by = '$user', archive_reason = "
            . ($reason !== '' ? "'" . mysqli_real_escape_string($conn, mb_substr($reason, 0, 300)) . "'" : 'NULL')
        : "status = 'active', archived_at = NULL, archived_by = NULL, archive_reason = NULL";

    if (!mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".documents SET $set, updated_by = '$user' WHERE document_id = '$id' AND company_id = '$company_id'")) {
        RequestLogger::setException(new RuntimeException(mysqli_error($conn)));
        throw ApiException::from('ERR_DOC_018');
    }

    jsonResponse(200, $archive ? 'Document archived' : 'Document restored', findDocument($conn, $document_id, $company_id));
}

// Archives every active document whose policy has ended. Safe to call repeatedly.
function archiveExpiredDocuments($conn, string $company_id, string $username): void {
    $user = mysqli_real_escape_string($conn, $username);
    $now  = date('Y-m-d H:i:s');
    $ok = mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".documents d
        JOIN " . APP_SCHEMA . ".policies p ON p.policy_id = d.policy_id
        LEFT JOIN " . APP_SCHEMA . ".claims cl ON cl.claim_id = d.claim_id
        SET d.status = 'archived', d.archived_at = '$now', d.archived_by = '$user',
            d.archive_reason = 'Polis sudah berakhir', d.updated_by = '$user'
        WHERE d.company_id = '$company_id' AND d.is_deleted = 0 AND d.status = 'active'
          AND p.coverage_end < CURDATE()
          AND (cl.claim_id IS NULL OR cl.status IN ('paid', 'rejected'))");
    if (!$ok) {
        RequestLogger::setException(new RuntimeException(mysqli_error($conn)));
        throw ApiException::from('ERR_DOC_018');
    }

    jsonResponse(200, 'Expired policy documents archived', ['archived' => mysqli_affected_rows($conn)]);
}

// --- DELETE (soft) ---
function deleteDocument($conn, string $company_id, string $document_id, string $username): void {
    $doc = findDocument($conn, $document_id, $company_id);
    if ($doc['status'] === 'archived') throw ApiException::from('ERR_DOC_012');
    if ($doc['policy_expired'])         throw ApiException::from('ERR_DOC_011');

    $id   = mysqli_real_escape_string($conn, $document_id);
    $user = mysqli_real_escape_string($conn, $username);
    $now  = date('Y-m-d H:i:s');
    if (!mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".documents
            SET is_deleted = 1, deleted_at = '$now', deleted_by = '$user', updated_by = '$user'
            WHERE document_id = '$id' AND company_id = '$company_id'")) {
        RequestLogger::setException(new RuntimeException(mysqli_error($conn)));
        throw ApiException::from('ERR_DOC_018');
    }

    if ($doc['claim_id']) {
        insertClaimEvent($conn, $doc['claim_id'], $company_id, 'document_deleted', 'Dokumen dihapus: ' . documentLabel($doc), $username);
    }
    if ($doc['policy_id']) {
        insertPolicyLog($conn, $doc['policy_id'], $company_id, 'document_deleted', 'Dokumen dihapus: ' . documentLabel($doc),
            $username, null, null, 'documents', $document_id);
    }

    jsonResponse(200, 'Document deleted successfully');
}

// ── Dispatch ──────────────────────────────────────────────────────────────────
// $action and $parts are available from the parent router (index.php via require)
//
//   GET    /documents                      list (filters: customer_id, policy_id, claim_id, insurer_id, document_type, year, status, params)
//   POST   /documents                      upload (multipart: file, document_type, customer_id|policy_id|claim_id, claim_requirement, title, notes, document_year)
//   GET    /documents/summary              counts, years, KYC-incomplete count
//   GET    /documents/kyc                  customers' KYC status (kyc=incomplete|complete|all)
//   GET    /documents/kyc/{customer_id}    one customer's KYC status
//   POST   /documents/archive-expired      archive all active documents on expired policies
//   GET    /documents/{id}                 detail
//   PUT    /documents/{id}                 update document_type, title, notes, document_year
//   GET    /documents/{id}/url             presigned link (?disposition=inline|attachment)
//   PUT    /documents/{id}/archive         archive (body: reason)
//   PUT    /documents/{id}/unarchive       restore from archive
//   DELETE /documents/{id}                 soft delete (not allowed for archived / expired-policy documents)

$authUser   = requireAuth();
$method     = $_SERVER['REQUEST_METHOD'];
$company_id = $authUser['company_id'] ?? null;
$username   = $authUser['sub'] ?? $authUser['user_id'] ?? '';

if (!$company_id) {
    jsonResponse(400, 'company_id is required');
}
$sub_action = $parts[4] ?? '';

try {
    $conn       = getConn();
    $company_id = mysqli_real_escape_string($conn, $company_id);

    if ($action === 'summary') {
        if ($method !== 'GET') Response::methodNotAllowed();
        getDocumentSummary($conn, $company_id);

    } elseif ($action === 'kyc') {
        if ($method !== 'GET') Response::methodNotAllowed();
        $sub_action !== '' ? getCustomerKyc($conn, $company_id, $sub_action) : listKyc($conn, $company_id, $_GET);

    } elseif ($action === 'archive-expired') {
        if ($method !== 'POST') Response::methodNotAllowed();
        archiveExpiredDocuments($conn, $company_id, $username);

    } elseif ($action !== '' && $sub_action === 'url') {
        if ($method !== 'GET') Response::methodNotAllowed();
        getDocumentUrl($conn, $company_id, $action, (string)($_GET['disposition'] ?? 'inline'));

    } elseif ($action !== '' && in_array($sub_action, ['archive', 'unarchive'], true)) {
        if ($method !== 'PUT') Response::methodNotAllowed();
        setDocumentArchived($conn, $company_id, $action, $sub_action === 'archive', trim((string)(input()['reason'] ?? '')), $username);

    } elseif ($action !== '' && $sub_action === '') {
        switch ($method) {
            case 'GET':
                jsonResponse(200, 'Document found', findDocument($conn, $action, $company_id));
                break;
            case 'PUT':
                updateDocument($conn, $company_id, $action, input(), $username);
                break;
            case 'DELETE':
                deleteDocument($conn, $company_id, $action, $username);
                break;
            default:
                Response::methodNotAllowed();
        }

    } elseif ($action === '') {
        switch ($method) {
            case 'GET':
                listDocuments($conn, $company_id, $_GET);
                break;
            case 'POST':
                uploadDocument($conn, $company_id, $username);
                break;
            default:
                Response::methodNotAllowed();
        }

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
