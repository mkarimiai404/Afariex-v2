<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }

function probe_url(string $label, string $url): void
{
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_NOBODY => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($handle);
    $errno = curl_errno($handle);
    $error = curl_error($handle);
    $info = curl_getinfo($handle);
    curl_close($handle);
    echo $label . '_http=' . (string)($info['http_code'] ?? 0) . PHP_EOL;
    echo $label . '_ip=' . (string)($info['primary_ip'] ?? 'none') . PHP_EOL;
    echo $label . '_ssl_verify=' . (string)($info['ssl_verifyresult'] ?? 'unavailable') . PHP_EOL;
    echo $label . '_curl_errno=' . $errno . PHP_EOL;
    echo $label . '_curl_error=' . ($errno === 0 ? 'none' : preg_replace('/\s+/', ' ', substr($error, 0, 180))) . PHP_EOL;
    echo $label . '_result=' . ($body === false ? 'failed' : 'completed') . PHP_EOL;
}

$host = 'api.payamak-panel.com';
$ips = gethostbynamel($host) ?: [];
echo 'php=' . PHP_VERSION . PHP_EOL;
echo 'openssl=' . (defined('OPENSSL_VERSION_TEXT') ? OPENSSL_VERSION_TEXT : 'unavailable') . PHP_EOL;
echo 'curl=' . (curl_version()['version'] ?? 'unavailable') . PHP_EOL;
echo 'provider_dns=' . ($ips ? 'resolved' : 'failed') . PHP_EOL;
echo 'provider_ip_count=' . count($ips) . PHP_EOL;
$errno = 0; $error = '';
$socket = @fsockopen('tcp://' . $host, 80, $errno, $error, 8);
echo 'provider_tcp=' . ($socket ? 'connected' : 'failed') . PHP_EOL;
echo 'provider_tcp_errno=' . $errno . PHP_EOL;
echo 'provider_tcp_error=' . ($error === '' ? 'none' : preg_replace('/\s+/', ' ', substr($error, 0, 180))) . PHP_EOL;
if (is_resource($socket)) fclose($socket);
probe_url('provider_wsdl', 'http://' . $host . '/post/Send.asmx?wsdl');
probe_url('generic_https', 'https://example.com/');
