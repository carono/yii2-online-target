<?php
declare(strict_types=1);
namespace carono\onlineTarget;

use yii\base\Component;
use yii\base\InvalidConfigException;

class HttpTransport extends Component implements TransportInterface
{
    public $token = '';
    public $headers = [];
    public $connectTimeout = 2;
    public $timeout = 10;

    public function send(string $endpoint, array $payload): void
    {
        if (!filter_var($endpoint, FILTER_VALIDATE_URL) || !in_array(parse_url($endpoint, PHP_URL_SCHEME), ['http', 'https'], true)
            || parse_url($endpoint, PHP_URL_USER) !== null || parse_url($endpoint, PHP_URL_PASS) !== null) {
            throw new InvalidConfigException('Invalid HTTP endpoint.');
        }
        if ($this->connectTimeout <= 0 || $this->timeout <= 0) { throw new InvalidConfigException('Timeouts must be positive.'); }
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if ($this->token !== '') {
            if (preg_match('/[\r\n]/', $this->token)) { throw new InvalidConfigException('Invalid token.'); }
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }
        foreach ($this->headers as $name => $value) {
            if (!preg_match('/^[A-Za-z0-9-]+$/D', (string) $name) || !is_string($value) || preg_match('/[\r\n]/', $value)) { throw new InvalidConfigException('Invalid HTTP header.'); }
            $headers[] = $name . ': ' . $value;
        }
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        $handle = curl_init($endpoint);
        try {
            curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers,
                CURLOPT_CONNECTTIMEOUT_MS => (int) ($this->connectTimeout * 1000), CURLOPT_TIMEOUT_MS => (int) ($this->timeout * 1000),
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_WRITEFUNCTION => static fn($handle, string $data): int => strlen($data)]);
            $result = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if ($result === false || $status < 200 || $status >= 300) {
                throw new \RuntimeException('Log delivery failed (HTTP ' . $status . ', cURL ' . curl_errno($handle) . ').');
            }
        } finally {
            curl_close($handle);
        }
    }
}
