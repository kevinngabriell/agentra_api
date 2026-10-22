<?php
// [NEW v1.8.0] Shared plumbing for bulk imports (customers, policies): read an uploaded CSV / XLSX into
// rows, normalise dates, and serve the downloadable templates.

require_once __DIR__ . '/xlsx_writer.php';

const IMPORT_MAX_BYTES = 5_000_000;
const IMPORT_MAX_ROWS  = 5000;
/** Rows echoed back in a dry-run preview; counts and errors always cover the whole file. */
const IMPORT_PREVIEW_ROWS = 200;

/**
 * Reads $_FILES['file'] (CSV or XLSX, first sheet) into ['headers' => string[], 'rows' => [['row' => int, 'data' => array]]].
 * `row` is the spreadsheet row number (header = 1). Blank rows are skipped. Replies 4xx and exits on a bad upload.
 */
function importReadUpload(): array {
    if (empty($_FILES['file'])) { jsonResponse(400, 'File is required (field name: file)'); }
    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) { jsonResponse(400, 'File upload error code: ' . $file['error']); }
    if ($file['size'] > IMPORT_MAX_BYTES)  { jsonResponse(413, 'File too large. Maximum 5MB.'); }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['csv', 'xlsx'], true)) { jsonResponse(400, 'Only CSV or XLSX files are supported'); }

    $table = $ext === 'csv' ? importReadCsv($file['tmp_name']) : importReadXlsx($file['tmp_name']);
    if ($table === null || !$table['headers']) { jsonResponse(400, 'Empty or invalid file'); }
    if (count($table['rows']) > IMPORT_MAX_ROWS) { jsonResponse(400, 'Too many rows. Maximum ' . IMPORT_MAX_ROWS . ' per import.'); }
    return $table;
}

function importNormalizeHeader($h): string {
    return strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string)$h)));
}

function importReadCsv(string $path): ?array {
    $mime = function_exists('finfo_file') ? finfo_file(finfo_open(FILEINFO_MIME_TYPE), $path) : '';
    if (!in_array($mime, ['text/plain', 'text/csv', 'text/x-csv', 'application/csv', 'application/octet-stream'], true)) {
        jsonResponse(400, 'File does not look like a CSV (detected: ' . $mime . ')');
    }
    $h = fopen($path, 'r');
    if (!$h) return null;

    // Spreadsheet apps in some locales export with ';' — sniff it from the header line.
    $first = fgets($h);
    rewind($h);
    $delim = $first !== false && substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';

    $headers = fgetcsv($h, 0, $delim, '"', '');
    if (!$headers) { fclose($h); return null; }
    $headers = array_map('importNormalizeHeader', $headers);

    $rows = [];
    $n = 1;
    while (($r = fgetcsv($h, 0, $delim, '"', '')) !== false) {
        $n++;
        if ($r === [null] || !array_filter($r, fn($v) => trim((string)$v) !== '')) continue;
        $rows[] = ['row' => $n, 'data' => importCombine($headers, $r)];
    }
    fclose($h);
    return ['headers' => $headers, 'rows' => $rows];
}

/** Pads / truncates a row to the header width so a ragged row never throws. */
function importCombine(array $headers, array $cells): array {
    $cells = array_slice(array_pad($cells, count($headers), ''), 0, count($headers));
    return array_combine($headers, array_map(fn($v) => is_string($v) ? trim($v) : $v, $cells));
}

function importReadXlsx(string $path): ?array {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) { jsonResponse(400, 'Could not open the XLSX file'); }

    $shared = [];
    $ss = $zip->getFromName('xl/sharedStrings.xml');
    if ($ss !== false) {
        $x = @simplexml_load_string($ss);
        if ($x) foreach ($x->si as $si) {
            $t = '';
            if (isset($si->t)) $t = (string)$si->t;
            else foreach ($si->r as $run) $t .= (string)$run->t;
            $shared[] = $t;
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if ($sheetXml === false) { jsonResponse(400, 'The XLSX file has no worksheet'); }
    $sheet = @simplexml_load_string($sheetXml);
    if (!$sheet) { jsonResponse(400, 'The XLSX worksheet is unreadable'); }

    $grid = [];
    foreach ($sheet->sheetData->row ?? [] as $row) {
        $rn = (int)$row['r'];
        $cells = [];
        foreach ($row->c as $c) {
            preg_match('/^([A-Z]+)/', (string)$c['r'], $m);
            $col = 0;
            foreach (str_split($m[1] ?? 'A') as $ch) $col = $col * 26 + (ord($ch) - 64);
            $type = (string)$c['t'];
            if ($type === 's')              $val = $shared[(int)$c->v] ?? '';
            elseif ($type === 'inlineStr')  $val = (string)$c->is->t;
            else {
                $val = (string)$c->v;
                // Large whole numbers (NIK, NPWP) arrive as 3.2E+15 style floats; render them back as integers.
                if ($type === '' && $val !== '' && is_numeric($val) && (float)$val == floor((float)$val) && abs((float)$val) < 1e15 * 100) {
                    $val = number_format((float)$val, 0, '', '');
                }
            }
            $cells[$col - 1] = $val;
        }
        $grid[$rn] = $cells;
    }
    if (!$grid) return null;

    ksort($grid);
    $headerRow = reset($grid);
    $maxCol = $headerRow ? max(array_keys($headerRow)) : -1;
    $headers = [];
    for ($i = 0; $i <= $maxCol; $i++) $headers[] = importNormalizeHeader($headerRow[$i] ?? '');
    $firstRn = array_key_first($grid);

    $rows = [];
    foreach ($grid as $rn => $cells) {
        if ($rn === $firstRn) continue;
        $line = [];
        for ($i = 0; $i <= $maxCol; $i++) $line[] = $cells[$i] ?? '';
        if (!array_filter($line, fn($v) => trim((string)$v) !== '')) continue;
        $rows[] = ['row' => $rn, 'data' => importCombine($headers, $line)];
    }
    return ['headers' => $headers, 'rows' => $rows];
}

/** Returns YYYY-MM-DD for ISO, d/m/Y, d-m-Y and Excel serial dates; null when unparseable. */
function importNormalizeDate($v): ?string {
    $v = trim((string)$v);
    if ($v === '') return null;
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) { $d = $v; }
    elseif (preg_match('#^(\d{1,2})[/-](\d{1,2})[/-](\d{4})$#', $v, $m)) { $d = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]); }
    elseif (preg_match('/^\d{5}$/', $v) && (int)$v > 20000 && (int)$v < 80000) { $d = gmdate('Y-m-d', ((int)$v - 25569) * 86400); }
    else return null;
    [$y, $mo, $da] = array_map('intval', explode('-', $d));
    return checkdate($mo, $da, $y) ? $d : null;
}

/** Sends the template as CSV or XLSX. $columns: [name => example value]; $notes: shown on a second line in XLSX only. */
function importSendTemplate(string $basename, array $columns, string $format): void {
    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $basename . '.csv"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_keys($columns), ',', '"', '');
        fputcsv($out, array_values($columns), ',', '"', '');
        fclose($out);
        exit;
    }
    $xlsx = new XlsxWriter();
    $xlsx->setColWidths(array_map(fn($k) => max(14, strlen($k) + 4), range(0, count($columns) - 1)));
    $xlsx->addRow(array_keys($columns), XlsxWriter::S_BOLD);
    $xlsx->addRow(array_map('strval', array_values($columns)));
    $xlsx->output($basename . '.xlsx');
}
