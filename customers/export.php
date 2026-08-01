<?php
// [NEW v1.1] Export customers to XLSX.
// GET /api/v1/customers/export
// Accepts the same filters as GET /api/v1/customers: params (search), customer_type, status.

require_once __DIR__ . '/../helpers/xlsx_writer.php';

function exportCustomers($conn, $company_id, array $params): void {
    $search        = isset($params['params'])        ? mysqli_real_escape_string($conn, $params['params'])        : '';
    $customer_type = isset($params['customer_type']) ? mysqli_real_escape_string($conn, $params['customer_type']) : '';
    $status        = isset($params['status'])        ? mysqli_real_escape_string($conn, $params['status'])        : '';

    $where = "company_id = '$company_id'";

    if ($search) {
        $where .= " AND (display_name LIKE '%$search%' OR company_legal_name LIKE '%$search%' OR nik LIKE '%$search%' OR npwp_company LIKE '%$search%')";
    }
    if ($customer_type && in_array($customer_type, ['individual', 'company'], true)) {
        $where .= " AND customer_type = '$customer_type'";
    }
    if ($status && in_array($status, ['active', 'inactive', 'lapsed'], true)) {
        $where .= " AND status = '$status'";
    }

    $sql = "SELECT
            customer_id, customer_type, status, source, display_name,
            nik, personal_phone, personal_whatsapp, personal_email,
            company_legal_name, npwp_company, business_type,
            pic_name, pic_phone, pic_whatsapp, pic_email,
            created_at
        FROM " . APP_SCHEMA . ".customers
        WHERE $where
        ORDER BY created_at DESC";

    $result = mysqli_query($conn, $sql);
    if (!$result) {
        jsonResponse(500, 'Export query failed', ['error' => mysqli_error($conn)]);
        return;
    }

    $customers = mysqli_fetch_all($result, MYSQLI_ASSOC);
    if (empty($customers)) {
        jsonResponse(404, 'No customers found for export');
        return;
    }

    $xlsx = new XlsxWriter();
    $xlsx->setColWidths([
        0  => 5,   // A  NO
        1  => 30,  // B  NAMA / LEGAL NAME
        2  => 12,  // C  TIPE
        3  => 20,  // D  NIK / NPWP
        4  => 16,  // E  HP
        5  => 16,  // F  WHATSAPP
        6  => 26,  // G  EMAIL
        7  => 22,  // H  PIC (company only)
        8  => 12,  // I  STATUS
        9  => 12,  // J  SUMBER
        10 => 14,  // K  TANGGAL DIBUAT
    ]);

    $xlsx->addRow([null, 'DAFTAR CUSTOMER'], XlsxWriter::S_BOLD);

    $xlsx->addRow([
        'NO', 'NAMA', 'TIPE', 'NIK / NPWP', 'HP', 'WHATSAPP', 'EMAIL',
        'PIC', 'STATUS', 'SUMBER', 'TANGGAL DIBUAT',
    ], XlsxWriter::S_BOLD);

    $no = 1;
    foreach ($customers as $c) {
        $isCompany = $c['customer_type'] === 'company';

        $name    = $isCompany ? $c['company_legal_name'] : $c['display_name'];
        $idNum   = $isCompany ? $c['npwp_company']        : $c['nik'];
        $phone   = $isCompany ? $c['pic_phone']            : $c['personal_phone'];
        $wa      = $isCompany ? $c['pic_whatsapp']         : $c['personal_whatsapp'];
        $email   = $isCompany ? $c['pic_email']            : $c['personal_email'];
        $pic     = $isCompany ? $c['pic_name']              : null;

        $xlsx->addRow([
            $no++,
            $name ?: $c['display_name'],
            $isCompany ? 'Company' : 'Individual',
            $idNum ?: null,
            $phone ?: null,
            $wa ?: null,
            $email ?: null,
            $pic,
            ucfirst($c['status']),
            ucfirst($c['source']),
            $c['created_at'] ? date('d/m/Y', strtotime($c['created_at'])) : null,
        ]);
    }

    $xlsx->output('DAFTAR CUSTOMER.xlsx');
}
