<?php
declare(strict_types=1);

final class InaxConfigurationException extends RuntimeException {}
final class InaxNetworkException extends RuntimeException {}

final class InaxClient
{
    private const TOPUP_OPERATION_TIMEOUT_SECONDS = 20;

    private string $endpoint;
    private string $username;
    private string $password;
    private int $timeout;
    private bool $mockGateway;

    public function __construct()
    {
        $config = require __DIR__ . '/../includes/inax_config.php';
        $this->endpoint = (string)$config['endpoint'];
        $this->username = (string)$config['username'];
        $this->password = (string)$config['password'];
        $this->timeout = (int)$config['timeout'];
        $mockToggle = strtolower(trim((string)(getenv('TOPUP_MOCK_GATEWAY') ?: '')));
        $applicationEnvironment = strtolower(trim((string)(getenv('APP_ENV') ?: 'production')));
        if (!in_array($mockToggle, ['', '0', '1', 'false', 'true'], true)) {
            throw new InaxConfigurationException('TOPUP_MOCK_GATEWAY_INVALID');
        }
        $this->mockGateway = in_array($mockToggle, ['1', 'true'], true);
        if ($this->mockGateway && !in_array($applicationEnvironment, ['local', 'development', 'test', 'staging'], true)) {
            throw new InaxConfigurationException('TOPUP_MOCK_GATEWAY_FORBIDDEN_IN_ENVIRONMENT');
        }
        if ($this->mockGateway) return;
        if ($this->username === '' || $this->password === '') throw new InaxConfigurationException('INAX_CONFIGURATION_MISSING');
        if (!function_exists('curl_init')) throw new InaxConfigurationException('INAX_CURL_EXTENSION_MISSING');
    }

    public function topup(string $operator, int $amount, string $mobile, string $orderId): array
    {
        return $this->request(['method' => 'topup', 'operator' => $operator, 'amount' => $amount, 'mobile' => $mobile, 'charge_type' => 'normal', 'order_id' => $orderId, 'pay_type' => 'credit', 'company' => 'آفاریکس'], self::TOPUP_OPERATION_TIMEOUT_SECONDS);
    }

    public function topupWithOptions(string $operator, int $amount, string $mobile, string $orderId, string $chargeType, ?int $mnp = null): array
    {
        if ($this->mockGateway) {
            return ['code' => 1, 'msg' => 'Deterministic top-up mock success', 'final_status' => 'succeeded',
                'payment_status' => 'paid', 'trans_id' => 'MOCK-' . $orderId, 'ref_code' => 'MOCK-' . $orderId,
                'mock' => true];
        }
        $configuredMnp = getenv('INAX_MNP');
        if ($mnp === null) {
            $configuredMnp = $configuredMnp === false || trim($configuredMnp) === '' ? '0' : trim($configuredMnp);
            if (!in_array($configuredMnp, ['0', '1'], true)) throw new InaxConfigurationException('INAX_MNP_INVALID');
            $mnp = (int)$configuredMnp;
        }
        if (!in_array($mnp, [0, 1], true)) throw new InaxConfigurationException('INAX_MNP_INVALID');
        // MNP remains disabled by default until Inax confirms how portable numbers are routed.
        return $this->request(['method' => 'topup', 'operator' => $operator, 'amount' => $amount, 'mobile' => $mobile, 'charge_type' => $chargeType, 'order_id' => $orderId, 'pay_type' => 'credit', 'mnp' => $mnp, 'company' => 'آفاریکس'], self::TOPUP_OPERATION_TIMEOUT_SECONDS);
    }

    public function transStatus(string $orderId, ?string $transId = null): array
    {
        if ($this->mockGateway) {
            return ['code' => 1, 'msg' => 'Deterministic top-up mock status', 'final_status' => 'succeeded',
                'payment_status' => 'paid', 'trans_id' => $transId ?: 'MOCK-' . $orderId,
                'ref_code' => 'MOCK-' . $orderId, 'mock' => true];
        }
        // Inax documents order_id OR trans_id as sufficient for trans_status.
        // An interrupted purchase may have a local order_id without a provider trans_id.
        $orderId = trim($orderId);
        $transId = is_string($transId) ? trim($transId) : '';
        $validId = static fn(string $id): bool => $id !== ''
            && strlen($id) <= 50
            && preg_match('/[\x00-\x1F\x7F]/', $id) !== 1;
        if (!$validId($orderId) && !$validId($transId)) {
            throw new InvalidArgumentException('INAX_TRANSACTION_REFERENCE_REQUIRED');
        }
        if ($transId !== '' && !$validId($transId)) {
            throw new InvalidArgumentException('INAX_TRANS_ID_INVALID');
        }
        $parameters = ['method' => 'trans_status'];
        if ($validId($orderId)) $parameters['order_id'] = $orderId;
        if ($transId !== '') $parameters['trans_id'] = $transId;
        return $this->request($parameters, self::TOPUP_OPERATION_TIMEOUT_SECONDS);
    }
    /** Creates an INAX-hosted payment URL; does not use the user's Afariex wallet. */
    public function addFund(int $amountToman): array
    {
        if($amountToman<1000 || $amountToman>1000000000) throw new InvalidArgumentException('INVALID_ADDFUND_AMOUNT');
        if($this->mockGateway) throw new InaxConfigurationException('ADDFUND_UNAVAILABLE_IN_MOCK');
        return $this->request(['method'=>'addfund','amount'=>$amountToman]);
    }

    /** Read-only INAX product list; never purchases anything. */
    public function products(): array
    {
        if ($this->mockGateway) return ['code'=>1,'products'=>[],'mock'=>true];
        return $this->request(['method'=>'products']);
    }

    /** Charges vendor balance. Only call from a committed, unique paid reservation. */
    public function internet(string $productId,string $operator,string $mobile,string $internetType,string $simType,string $orderId): array
    {
        if ($this->mockGateway) return ['code'=>1,'msg'=>'INTERNET_TEST_MOCK','ref_code'=>'MOCK-'.$orderId,'mock'=>true];
        return $this->request(['method'=>'internet','product_id'=>$productId,'operator'=>$operator,
            'mobile'=>$mobile,'internet_type'=>$internetType,'sim_type'=>$simType,
            'order_id'=>$orderId,'pay_type'=>'credit'],self::TOPUP_OPERATION_TIMEOUT_SECONDS);
    }

    public function credit(): array { return $this->request(['method' => 'credit']); }

    private function request(array $parameters, ?int $timeoutOverride = null): array
    {
        $timeout = $timeoutOverride === null ? $this->timeout : min($this->timeout, max(1, $timeoutOverride));
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
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => min(10, $timeout), CURLOPT_TIMEOUT => $timeout, CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json', 'Content-Length: ' . strlen($body)], CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
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
            } elseif ($normalizedKey === 'url' && is_string($value)) {
                // Keep a trusted payment URL usable by admin, but never log it.
                $parts = parse_url($value);
                $host = strtolower((string)($parts['host'] ?? ''));
                $sanitized[$key] = is_array($parts)
                    && ($parts['scheme'] ?? '') === 'https'
                    && in_array($host, ['inax.ir', 'www.inax.ir'], true)
                    && !isset($parts['user']) && !isset($parts['pass'])
                    ? $value : '[UNTRUSTED_URL]';
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
