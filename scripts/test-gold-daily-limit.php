<?php
declare(strict_types=1);

putenv('GOLD_DAILY_LIMIT_TOMAN');
session_save_path(__DIR__);
session_id('gold-daily-limit-test');

require_once __DIR__ . '/../admin_panel/config.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException('Assertion failed: ' . $message);
};

$definitions = verification_level_definitions();
$goldLimit = $definitions['gold']['daily_limit'] ?? null;

$assert($goldLimit === GOLD_DAILY_LIMIT_TOMAN, 'Gold uses the fixed default constant');
$assert($goldLimit === 15000000, 'Gold daily limit is exactly 15,000,000 Toman');
$assert($goldLimit !== null, 'Gold limit is never null when the environment variable is missing');
$assert($definitions['bronze']['daily_limit'] === INITIAL_WITHDRAWAL_LIMIT_TOMAN, 'Bronze limit is unchanged');
$assert($definitions['silver']['daily_limit'] === SILVER_DAILY_LIMIT_TOMAN, 'Silver limit is unchanged');

$allowed = static function (float $usedToday, float $requested) use ($goldLimit): bool {
    return $requested <= max(0, (float)$goldLimit - $usedToday);
};

$assert($allowed(0, 5000000), 'Gold user with zero usage can spend 5,000,000');
$assert($allowed(10000000, 5000000), 'Gold user can spend exactly the remaining 5,000,000');
$assert(!$allowed(10000000, 5000001), 'Gold user is rejected above the 15,000,000 daily limit');

putenv('GOLD_DAILY_LIMIT_TOMAN=not-a-number');
$assert(verification_level_definitions()['gold']['daily_limit'] === 15000000, 'Invalid environment value does not null Gold');

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
@unlink(__DIR__ . DIRECTORY_SEPARATOR . 'sess_gold-daily-limit-test');

echo "Gold daily limit fixtures passed ({$assertions} assertions)." . PHP_EOL;
