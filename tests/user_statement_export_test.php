<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin_panel/statement_export.php';

function to_jalali_datetime(string $value): string
{
    return $value;
}

function statement_type_fa(string $type): string
{
    return $type;
}

function statement_direction_fa(string $direction): string
{
    return $direction;
}

function statement_status_fa(string $status): string
{
    return $status;
}

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$page = statement_page_rows(range(1, 51), 2, 25);
expect($page['rows'] === range(26, 50), 'pagination returns the requested slice');
expect($page['total_rows'] === 51 && $page['page_count'] === 3, 'pagination retains report totals');
expect(statement_page_rows([], 9)['rows'] === [], 'empty history remains empty');

$report = [
    'user' => ['id' => 7, 'first_name' => '=HYPERLINK("x")', 'last_name' => '', 'mobile' => '09120000001'],
    'summary' => ['wallet_balance' => '1200.00', 'outstanding_debt' => '200.00', 'final_balance' => '1000.00'],
    'rows' => [[
        'date' => '2026-10-12 10:00:00', 'type' => 'deposit', 'description' => '=1+1 <tag>',
        'amount' => '1000.00', 'direction' => 'credit', 'status' => 'approved',
        'balance_after' => '1200.00', 'reference' => '',
    ]],
];
$parts = statement_xlsx_parts($report, null, null);
$sheet = $parts['xl/worksheets/sheet1.xml'];
expect(isset($parts['[Content_Types].xml'], $parts['xl/workbook.xml']), 'OOXML package parts exist');
expect(str_contains($sheet, '<c r="B3" t="inlineStr">'), 'mobile number is stored as text');
expect(str_contains($sheet, '<c r="E12"><v>1000.00</v></c>'), 'amount is an exact numeric cell');
expect(str_contains($sheet, '<c r="B2" t="inlineStr">'), 'formula-like user value is stored as text');
expect(!str_contains($sheet, '<f>'), 'user text cannot become an Excel formula');
expect(str_contains($sheet, '=1+1 &lt;tag&gt;'), 'XML text is escaped');
foreach (['[Content_Types].xml', 'xl/workbook.xml', 'xl/worksheets/sheet1.xml'] as $xmlPath) {
    expect(simplexml_load_string($parts[$xmlPath]) !== false, $xmlPath . ' is valid XML');
}

$temporaryFile = tempnam(sys_get_temp_dir(), 'statement_test_');
expect($temporaryFile !== false, 'temporary workbook path is available');
try {
    $archive = new ZipArchive();
    expect($archive->open($temporaryFile, ZipArchive::OVERWRITE) === true, 'XLSX ZIP archive opens');
    foreach ($parts as $path => $contents) {
        expect($archive->addFromString($path, $contents), 'XLSX part is added: ' . $path);
    }
    expect($archive->close(), 'XLSX ZIP archive closes');
    $check = new ZipArchive();
    expect($check->open($temporaryFile) === true, 'generated XLSX archive can be reopened');
    expect($check->locateName('xl/worksheets/sheet1.xml') !== false, 'generated XLSX contains its worksheet');
    $check->close();
} finally {
    if (is_string($temporaryFile) && is_file($temporaryFile)) {
        unlink($temporaryFile);
    }
}

echo "user statement export tests passed\n";
