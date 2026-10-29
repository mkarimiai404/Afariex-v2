<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin_panel/receipt_image_helper.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException('Assertion failed: ' . $message);
};

$uploadDir = __DIR__ . '/../admin_panel/uploads/receipts';
if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
    throw new RuntimeException('Could not create receipt fixture directory.');
}

$fixtures = [
    'helper_test.jpg',
    'helper_test.jpeg',
    'helper_test.png',
];

try {
    foreach ($fixtures as $fixture) {
        file_put_contents($uploadDir . '/' . $fixture, 'receipt-helper-fixture');
        $extension = pathinfo($fixture, PATHINFO_EXTENSION);
        $expected = 'uploads/receipts/helper_test.' . $extension;

        $assert(receipt_image_public_url('uploads/receipts/' . $fixture) === $expected, strtoupper($extension) . ' new stored path resolves');
        $assert(receipt_image_public_url($fixture) === $expected, strtoupper($extension) . ' filename-only legacy value resolves');
        $assert(receipt_image_public_url('admin_panel/uploads/receipts/' . $fixture) === $expected, strtoupper($extension) . ' admin_panel-prefixed value resolves');
        $assert(receipt_image_public_url('uploads/' . $fixture) === $expected, strtoupper($extension) . ' uploads-prefixed legacy value resolves into receipts directory');
    }

    $assert(receipt_image_public_url('uploads/receipts/missing.jpg') === null, 'missing file returns null');
    $assert(receipt_image_public_url('../config.php') === null, 'path traversal is rejected');
    $assert(receipt_image_public_url('https://example.com/receipt.jpg') === null, 'external URL is rejected');
    $assert(receipt_image_public_url('') === null, 'empty path returns null');

    echo "Receipt image helper fixtures passed ({$assertions} assertions)." . PHP_EOL;
} finally {
    foreach ($fixtures as $fixture) {
        $path = $uploadDir . '/' . $fixture;
        if (is_file($path)) @unlink($path);
    }
}
