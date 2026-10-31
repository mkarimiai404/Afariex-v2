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
        $startedAt = microtime(true);
        $startedAtMonotonic = hrtime(true);
        $requestMetadata = [
            'method' => (string)($parameters['method'] ?? ''),
            'order_id' => (string)($parameters['order_id'] ?? ''),
            'request_started_at' => gmdate('c', (int)$startedAt),
        ];
        $this->log('request', $requestMetadata);
        $curl = curl_init($this->endpoint);
        if ($curl === false) {
            $this->logResponse($requestMetadata, $startedAtMonotonic, 0, 0, 'cURL initialization failed', null, null);
            throw new InaxNetworkException('INAX_CURL_INIT_FAILED');
        }
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout), CURLOPT_TIMEOUT => $this->timeout, CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json', 'Content-Length: ' . strlen($body)], CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
        $responseBody = curl_exec($curl);
        $curlError = curl_error($curl);
        $curlErrorNumber = curl_errno($curl);
        $httpStatus = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        $decoded = is_string($responseBody) ? json_decode($responseBody, true) : null;
        $parsed = is_array($decoded) && !array_is_list($decoded);
        $safeResponse = $parsed ? $this->sanitizeProviderData($decoded) : null;
        $this->logResponse($requestMetadata, $startedAtMonotonic, $httpStatus, $curlErrorNumber, $curlError, $responseBody, $safeResponse);
        if ($responseBody === false || $curlError !== '') throw new InaxNetworkException('INAX_NETWORK_ERROR');
        if ($httpStatus < 200 || $httpStatus >= 300) throw new InaxNetworkException('INAX_HTTP_ERROR');
        if (!$parsed || !is_array($safeResponse)) throw new InaxNetworkException('INAX_INVALID_JSON_RESPONSE');
        return $safeResponse;
    }

    private function logResponse(array $metadata, int $startedAtMonotonic, int $httpStatus, int $curlErrorNumber, string $curlError, mixed $responseBody, ?array $response): void
    {
        $this->log('response', [
            ...$metadata,
            'elapsed_ms' => (int)round((hrtime(true) - $startedAtMonotonic) / 1_000_000),
            'http_status' => $httpStatus,
            'curl_error_number' => $curlErrorNumber,
            'curl_error' => $this->sanitizeText($curlError),
            'response_body_empty' => !is_string($responseBody) || trim($responseBody) === '',
            'response_parse_success' => $response !== null,
            'provider_code' => is_array($response) && is_numeric($response['code'] ?? null) ? (int)$response['code'] : null,
            'provider_msg' => is_array($response) ? $this->sanitizeText((string)($response['msg'] ?? '')) : null,
            'final_status' => is_array($response) ? $this->sanitizeText((string)($response['final_status'] ?? '')) : null,
            'payment_status' => is_array($response) ? $this->sanitizeText((string)($response['payment_status'] ?? '')) : null,
            'has_trans_id' => is_array($response) && trim((string)($response['trans_id'] ?? '')) !== '',
            'has_ref_code' => is_array($response) && trim((string)($response['ref_code'] ?? '')) !== '',
        ]);
    }

    private function sanitizeProviderData(array $data): array
    {
        $sanitized = [];
        foreach ($data as $key => $value) {
            $normalizedKey = strtolower((string)$key);
            if (preg_match('/password|token|secret|authorization|username|api[_-]?key/', $normalizedKey)) {
                $sanitized[$key] = '[REDACTED]';
            } elseif (in_array($normalizedKey, ['mobile', 'phone', 'msisdn'], true)) {
                $sanitized[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->sanitizeProviderData($value);
            } elseif (is_string($value)) {
                $sanitized[$key] = $this->sanitizeText($value);
            } else {
                $sanitized[$key] = $value;
            }
        }
        return $sanitized;
    }

    private function sanitizeText(string $value): string
    {
        foreach ([$this->username, $this->password] as $secret) {
            if ($secret !== '') $value = str_replace($secret, '[REDACTED]', $value);
        }
        $value = preg_replace('~https?://[^\s"\'<>]+~i', '[REDACTED_URL]', $value) ?? '';
        $value = preg_replace('/\bBearer\s+[^\s,;]+/i', 'Bearer [REDACTED]', $value) ?? '';
        $value = preg_replace('/\b(password|token|secret|authorization|username|api[_-]?key)(\s*[:=]\s*|\s+)[^\s,;]+/i', '$1=[REDACTED]', $value) ?? '';
        $value = preg_replace('/(?<!\d)(?:\+?98|0098|0)?9\d{9}(?!\d)/', '[REDACTED_PHONE]', $value) ?? '';
        return substr($value, 0, 500);
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
