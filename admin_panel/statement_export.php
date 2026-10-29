<?php
declare(strict_types=1);

/** Escape an XML text or attribute value without treating it as markup. */
function statement_xml(string $value): string
{
    $value = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value) ?? '';
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/** Return a bounded page while leaving the source report and its totals intact. */
function statement_page_rows(array $rows, int $page, int $pageSize = 25): array
{
    $pageSize = max(1, min(200, $pageSize));
    $pageCount = max(1, (int)ceil(count($rows) / $pageSize));
    $page = max(1, min($pageCount, $page));
    return [
        'rows' => array_slice($rows, ($page - 1) * $pageSize, $pageSize),
        'page' => $page,
        'page_size' => $pageSize,
        'page_count' => $pageCount,
        'total_rows' => count($rows),
    ];
}

/** Build the OOXML package parts used by both the download and isolated tests. */
function statement_xlsx_parts(array $report, ?string $from, ?string $to): array
{
    $user = $report['user'];
    $summary = $report['summary'];
    $generatedAt = date('Y-m-d H:i:s');
    $columns = [
        'ردیف', 'تاریخ و ساعت', 'نوع فعالیت', 'شرح فعالیت', 'مبلغ',
        'واحد', 'جهت', 'وضعیت', 'مانده پس از تراکنش', 'کد رهگیری',
    ];
    $rows = [
        ['AFARIEX - صورتحساب مالی'],
        ['کاربر', trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? '')) ?: (string)$user['mobile']],
        ['موبایل', (string)$user['mobile']],
        ['زمان صدور', $generatedAt],
        ['بازه گزارش', ($from ?: 'ابتدا') . ' تا ' . ($to ?: 'امروز')],
        ['موجودی کیف پول (تومان)', (string)$summary['wallet_balance']],
        ['بدهی باز (تومان)', (string)$summary['outstanding_debt']],
        ['موقعیت خالص حساب (تومان)', (string)$summary['final_balance']],
        ['طبقه‌بندی موقعیت', (float)$summary['final_balance'] > 0 ? 'بستانکار' : ((float)$summary['final_balance'] < 0 ? 'بدهکار' : 'تسویه')],
        [],
        $columns,
    ];
    foreach ($report['rows'] as $index => $row) {
        $rows[] = [
            (string)($index + 1),
            to_jalali_datetime((string)$row['date']),
            statement_type_fa((string)$row['type']),
            (string)$row['description'],
            (string)$row['amount'],
            'تومان',
            statement_direction_fa((string)$row['direction']),
            statement_status_fa((string)$row['status']),
            $row['balance_after'] === null ? '' : (string)$row['balance_after'],
            (string)$row['reference'],
        ];
    }

    $xmlRows = '';
    foreach ($rows as $rowIndex => $row) {
        $xmlCells = '';
        foreach (array_values($row) as $columnIndex => $value) {
            $cellRef = statement_xlsx_column($columnIndex + 1) . ($rowIndex + 1);
            $text = (string)$value;
            $isTransactionRow = $rowIndex >= 11;
            $isNumeric = ($isTransactionRow && in_array($columnIndex, [0, 4, 8], true))
                || (in_array($rowIndex, [5, 6, 7], true) && $columnIndex === 1);
            if ($isNumeric && preg_match('/^-?\d+(?:\.\d+)?$/D', $text) === 1) {
                $xmlCells .= '<c r="' . $cellRef . '"><v>' . $text . '</v></c>';
            } else {
                $xmlCells .= '<c r="' . $cellRef . '" t="inlineStr"><is><t xml:space="preserve">'
                    . statement_xml($text) . '</t></is></c>';
            }
        }
        $xmlRows .= '<row r="' . ($rowIndex + 1) . '">' . $xmlCells . '</row>';
    }

    $widths = [9, 22, 22, 48, 18, 14, 18, 20, 24, 24];
    $xmlWidths = '';
    foreach ($widths as $index => $width) {
        $column = $index + 1;
        $xmlWidths .= '<col min="' . $column . '" max="' . $column . '" width="' . $width . '" customWidth="1"/>';
    }
    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetViews><sheetView rightToLeft="1" workbookViewId="0"><pane ySplit="11" topLeftCell="A12" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . '<cols>' . $xmlWidths . '</cols><sheetData>' . $xmlRows . '</sheetData></worksheet>';

    return [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '</Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Statement" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '</Relationships>',
        'xl/worksheets/sheet1.xml' => $sheet,
    ];
}

function statement_render_xlsx(array $report, ?string $from, ?string $to): never
{
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('XLSX_EXPORT_REQUIRES_ZIP');
    }
    $temporaryFile = tempnam(sys_get_temp_dir(), 'afariex_statement_');
    if ($temporaryFile === false) {
        throw new RuntimeException('XLSX_TEMP_FILE_FAILED');
    }
    $archive = new ZipArchive();
    $opened = false;
    try {
        if ($archive->open($temporaryFile, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('XLSX_ARCHIVE_CREATE_FAILED');
        }
        $opened = true;
        foreach (statement_xlsx_parts($report, $from, $to) as $path => $contents) {
            if (!$archive->addFromString($path, $contents)) {
                throw new RuntimeException('XLSX_ARCHIVE_ENTRY_FAILED');
            }
        }
        if (!$archive->close()) {
            $opened = false;
            throw new RuntimeException('XLSX_ARCHIVE_CLOSE_FAILED');
        }
        $opened = false;
        $userId = (int)($report['user']['id'] ?? 0);
        $filename = 'User_Statement_' . $userId . '_' . date('Ymd_His') . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . (string)filesize($temporaryFile));
        readfile($temporaryFile);
    } finally {
        if ($opened) {
            $archive->close();
        }
        if (is_file($temporaryFile)) {
            unlink($temporaryFile);
        }
    }
    exit;
}

function statement_xlsx_column(int $column): string
{
    $name = '';
    while ($column > 0) {
        $remainder = ($column - 1) % 26;
        $name = chr(65 + $remainder) . $name;
        $column = intdiv($column - 1, 26);
    }
    return $name;
}
