<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

ini_set('display_errors', '0');
error_reporting(E_ALL);

function send_json(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $configPath = __DIR__ . '/../admin_panel/config.php';
    if (file_exists($configPath)) {
        require_once $configPath;
    }

    $dbPath = __DIR__ . '/../admin_panel/db.php';
    if (file_exists($dbPath)) {
        require_once $dbPath;
    }

    if (!function_exists('db')) {
        // بازگرداندن نرخ پیش‌فرض به همراه success: true تا اپلیکیشن قفل نشود
        send_json([
            'success' => true,
            'data' => [
                [
                    'id' => 1,
                    'source_currency' => 'IRT',
                    'destination_currency' => 'AFN',
                    'exchange_rate' => 1 / 4255,
                    'rate' => 4255.0,
                    'toman_per_afn' => 4255.0,
                    'afn_to_toman' => 4255.0,
                    'is_active' => 1
                ]
            ],
            'rows' => []
        ], 200);
    }

    $pdo = db();
    $rates = [];

    $tableExists = false;
    try {
        $chk = $pdo->query("SHOW TABLES LIKE 'exchange_rates'");
        $tableExists = ($chk && $chk->rowCount() > 0);
    } catch (Throwable $e) {}

    if ($tableExists) {
        $cols = $pdo->query("SHOW COLUMNS FROM exchange_rates")->fetchAll(PDO::FETCH_COLUMN);
        $hasIsActive = in_array('is_active', $cols, true);

        $sql = "SELECT * FROM exchange_rates";
        if ($hasIsActive) {
            $sql .= " WHERE is_active = 1";
        }
        $sql .= " ORDER BY id DESC LIMIT 20";

        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 1);
            $src = strtoupper(trim((string)($row['source_currency'] ?? 'IRT')));
            $dst = strtoupper(trim((string)($row['destination_currency'] ?? 'AFN')));

            $tomanPerAfn = 4255.0;
            if (!empty($row['afn_to_toman']) && (float)$row['afn_to_toman'] > 0) {
                $tomanPerAfn = (float)$row['afn_to_toman'];
            } elseif (!empty($row['rate']) && (float)$row['rate'] > 0) {
                $tomanPerAfn = (float)$row['rate'];
            } elseif (!empty($row['exchange_rate']) && (float)$row['exchange_rate'] > 0) {
                $val = (float)$row['exchange_rate'];
                $tomanPerAfn = $val > 1 ? $val : (1 / $val);
            }

            $rates[] = [
                'id' => $id,
                'source_currency' => $src,
                'destination_currency' => $dst,
                'exchange_rate' => 1 / $tomanPerAfn,
                'rate' => $tomanPerAfn,
                'toman_per_afn' => $tomanPerAfn,
                'afn_to_toman' => $tomanPerAfn,
                'is_active' => (int)($row['is_active'] ?? 1),
            ];
        }
    }

    if (empty($rates)) {
        $rates[] = [
            'id' => 1,
            'source_currency' => 'IRT',
            'destination_currency' => 'AFN',
            'exchange_rate' => 1 / 4255,
            'rate' => 4255.0,
            'toman_per_afn' => 4255.0,
            'afn_to_toman' => 4255.0,
            'is_active' => 1,
        ];
    }

    send_json([
        'success' => true,
        'data' => $rates,
        'rows' => $rates
    ], 200);

} catch (Throwable $e) {
    send_json([
        'success' => true,
        'data' => [
            [
                'id' => 1,
                'source_currency' => 'IRT',
                'destination_currency' => 'AFN',
                'exchange_rate' => 1 / 4255,
                'rate' => 4255.0,
                'toman_per_afn' => 4255.0,
                'afn_to_toman' => 4255.0,
                'is_active' => 1
            ]
        ],
        'rows' => []
    ], 200);
}
