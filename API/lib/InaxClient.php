<?php
declare(strict_types=1);

final class InaxConfigurationException extends RuntimeException {}
final class InaxNetworkException extends RuntimeException {}

final class InaxClient
{
    private string $endpoint;
    private string $username;
    private string $password;
    private int $timeout;

    public function __construct()
    {
        $config = require __DIR__ . '/../includes/inax_config.php';
        $this->endpoint = (string)$config['endpoint'];
        $this->username = (string)$config['username'];
        $this->password = (string)$config['password'];
        $this->timeout = (int)$config['timeout'];
        if ($this->username === '' || $this->password === '') throw new InaxConfigurationException('INAX_CONFIGURATION_MISSING');
        if (!function_exists('curl_init')) throw new InaxConfigurationException('INAX_CURL_EXTENSION_MISSING');
    }

    public function topup(string $operator, int $amount, string $mobile, string $orderId): array
    {
        return $this->request(['method' => 'topup', 'operator' => $operator, 'amount' => $amount, 'mobile' => $mobile, 'charge_type' => 'normal', 'order_id' => $orderId, 'pay_type' => 'credit', 'company' => 'آفاریکس']);
    }

    public function topupWithOptions(string $operator, int $amount, string $mobile, string $orderId, string $chargeType, ?int $mnp = null): array
    {
        $configuredMnp = getenv('INAX_MNP');
        if ($mnp === null) {
            $configuredMnp = $configuredMnp === false || trim($configuredMnp) === '' ? '0' : trim($configuredMnp);
            if (!in_array($configuredMnp, ['0', '1'], true)) throw new InaxConfigurationException('INAX_MNP_INVALID');
            $mnp = (int)$configuredMnp;
        }
        if (!in_array($mnp, [0, 1], true)) throw new InaxConfigurationException('INAX_MNP_INVALID');
        // MNP remains disabled by default until Inax confirms how portable numbers are routed.
        return $this->request(['method' => 'topup', 'operator' => $operator, 'amount' => $amount, 'mobile' => $mobile, 'charge_type' => $chargeType, 'order_id' => $orderId, 'pay_type' => 'credit', 'mnp' => $mnp, 'company' => 'آفاریکس']);
    }

    public function transStatus(string $orderId, ?string $transId = null): array
    {
        $parameters = ['method' => 'trans_status', 'order_id' => $orderId];
        if ($transId !== null && trim($transId) !== '') $parameters['trans_id'] = trim($transId);
        return $this->request($parameters);
    }
    public function credit(): array { return $this->request(['method' => 'credit']); }

    private function request(array $parameters): array
    {
        $payload = array_merge(['username' => $this->username, 'password' => $this->password], $parameters);
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) throw new InaxNetworkException('INAX_REQUEST_ENCODING_FAILED');
        $this->log('request', $this->safeRequestMetadata($payload));
        $curl = curl_init($this->endpoint);
        if ($curl === false) throw new InaxNetworkException('INAX_CURL_INIT_FAILED');
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout), CURLOPT_TIMEOUT => $this->timeout, CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json', 'Content-Length: ' . strlen($body)], CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
        $responseBody = curl_exec($curl);
        $curlError = curl_error($curl);
        $httpStatus = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        $decoded = is_string($responseBody) ? json_decode($responseBody, true) : null;
        $this->log('response', [
            'http_status' => $httpStatus,
            'network_error' => $curlError !== '' || $responseBody === false,
            'provider_code' => is_array($decoded) && is_numeric($decoded['code'] ?? null) ? (int)$decoded['code'] : null,
        ]);
        if ($responseBody === false || $curlError !== '') throw new InaxNetworkException('INAX_NETWORK_ERROR');
        if ($httpStatus < 200 || $httpStatus >= 300) throw new InaxNetworkException('INAX_HTTP_ERROR');
        if (!is_array($decoded)) throw new InaxNetworkException('INAX_INVALID_JSON_RESPONSE');
        return $decoded;
    }

    private function safeRequestMetadata(array $payload): array
    {
        $safeKeys = ['method', 'operator', 'amount', 'charge_type', 'order_id', 'pay_type', 'mnp'];
        return array_intersect_key($payload, array_flip($safeKeys));
    }

    private function log(string $event, array $data): void
    {
        $directory = dirname(__DIR__) . '/logs';
        if (!is_dir($directory)) @mkdir($directory, 0700, true);
        $path = $directory . '/inax_' . date('Y-m-d') . '.log';
        $line = json_encode(['timestamp' => date('c'), 'event' => $event, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($line)) @file_put_contents($path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
