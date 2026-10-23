<?php

/**
 * Minimal XLSX writer using PHP's built-in ZipArchive.
 * No external dependencies required.
 *
 * Usage:
 *   $xlsx = new XlsxWriter();
 *   $xlsx->setColWidths([0 => 20, 1 => 30]);
 *   $xlsx->addRow(['Header A', 'Header B'], XlsxWriter::S_BOLD);
 *   $xlsx->addRow(['value', 123]);
 *   $xlsx->output('filename.xlsx');
 */
class XlsxWriter {
    private array $rows          = [];
    private array $sharedStrings = [];
    private array $sharedIndex   = [];
    private int   $stringCount   = 0;
    private array $colWidths     = [];

    const S_NORMAL = 0;
    const S_BOLD   = 1;

    /**
     * Add a row. Each cell is string|int|float|null.
     * Null and empty-string cells are skipped (left blank in Excel).
     */
    public function addRow(array $cells, int $style = self::S_NORMAL): void {
        $this->rows[] = ['cells' => $cells, 'style' => $style];
    }

    /** Set column widths. Keys are 0-based column indexes, values are width in characters. */
    public function setColWidths(array $widths): void {
        foreach ($widths as $idx => $w) {
            if (!is_numeric($w) || $w < 0 || $w > 255) {
                throw new InvalidArgumentException("Invalid column width for index $idx");
            }
        }
        $this->colWidths = array_map('floatval', $widths);
    }

    /** Output XLSX directly to the HTTP response as a file download. */
    public function output(string $filename): void {
        $sheetXml = $this->buildSheet();
        $tmpFile  = tempnam(sys_get_temp_dir(), 'xlsx_');

        $zip = new ZipArchive();
        if ($zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($tmpFile);
            http_response_code(500);
            echo json_encode(['status_code' => 500, 'status_message' => 'Failed to create XLSX archive']);
            exit;
        }
        $zip->addFromString('[Content_Types].xml',        $this->buildContentTypes());
        $zip->addFromString('_rels/.rels',                $this->buildRels());
        $zip->addFromString('xl/workbook.xml',            $this->buildWorkbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->buildWorkbookRels());
        $zip->addFromString('xl/styles.xml',              $this->buildStyles());
        $zip->addFromString('xl/sharedStrings.xml',       $this->buildSharedStrings());
        $zip->addFromString('xl/worksheets/sheet1.xml',   $sheetXml);
        $zip->close();

        // Whitelist the filename so control chars / quotes / etc. can't break
        // the Content-Disposition header parse. Also provide the RFC 5987
        // `filename*` form so non-ASCII filenames survive round-tripping.
        $safe = preg_replace('/[^\p{L}\p{N} _.\-]/u', '_', $filename);
        if ($safe === '') $safe = 'export.xlsx';

        if (ob_get_level()) ob_end_clean();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $safe . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
        header('Content-Length: ' . filesize($tmpFile));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        // readfile streams the file directly to output instead of loading it
        // into a PHP string — peak memory is roughly the file size rather than 3x.
        readfile($tmpFile);
        @unlink($tmpFile);
        exit;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function sIdx(string $s): int {
        if (!isset($this->sharedIndex[$s])) {
            $this->sharedIndex[$s] = count($this->sharedStrings);
            $this->sharedStrings[] = $s;
        }
        $this->stringCount++;
        return $this->sharedIndex[$s];
    }

    private function col(int $i): string {
        if ($i < 26) return chr(65 + $i);
        return chr(64 + intdiv($i, 26)) . chr(65 + $i % 26);
    }

    // ── XML builders ─────────────────────────────────────────────────────────

    private function buildContentTypes(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            . '</Types>';
    }

    private function buildRels(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private function buildWorkbook(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private function buildWorkbookRels(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
            . '</Relationships>';
    }

    private function buildStyles(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2">'
            .   '<font><sz val="11"/><name val="Calibri"/></font>'
            .   '<font><b/><sz val="11"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="2">'
            .   '<fill><patternFill patternType="none"/></fill>'
            .   '<fill><patternFill patternType="gray125"/></fill>'
            . '</fills>'
            . '<borders count="1">'
            .   '<border><left/><right/><top/><bottom/><diagonal/></border>'
            . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2">'
            .   '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .   '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '</cellXfs>'
            . '</styleSheet>';
    }

    private function buildSharedStrings(): string {
        $count  = $this->stringCount;
        $unique = count($this->sharedStrings);
        $xml    = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
                . ' count="' . $count . '" uniqueCount="' . $unique . '">';
        foreach ($this->sharedStrings as $s) {
            $xml .= '<si><t xml:space="preserve">' . htmlspecialchars($s, ENT_XML1, 'UTF-8') . '</t></si>';
        }
        return $xml . '</sst>';
    }

    private function buildSheet(): string {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

        if (!empty($this->colWidths)) {
            $xml .= '<cols>';
            foreach ($this->colWidths as $idx => $w) {
                $col = $idx + 1;
                $xml .= '<col min="' . $col . '" max="' . $col . '" width="' . $w . '" customWidth="1"/>';
            }
            $xml .= '</cols>';
        }

        $xml .= '<sheetData>';
        foreach ($this->rows as $ri => $row) {
            $rowNum = $ri + 1;
            $s      = $row['style'];
            $xml   .= '<row r="' . $rowNum . '">';
            foreach ($row['cells'] as $ci => $cell) {
                if ($cell === null || $cell === '') continue;
                $ref = $this->col($ci) . $rowNum;
                if (is_int($cell) || is_float($cell)) {
                    $xml .= '<c r="' . $ref . '" s="' . $s . '"><v>' . $cell . '</v></c>';
                } else {
                    $xml .= '<c r="' . $ref . '" t="s" s="' . $s . '"><v>' . $this->sIdx((string)$cell) . '</v></c>';
                }
            }
            $xml .= '</row>';
        }
        return $xml . '</sheetData></worksheet>';
    }
}
