<?php
declare(strict_types=1);
ini_set('log_errors', '1');
ini_set('display_errors', '1');
error_reporting(E_ALL);

echo "PHP OK<br>";
require __DIR__ . '/config.php';
echo "Config loaded<br>";
$pdo = db();
echo "DB OK";
