<?php
declare(strict_types=1);

namespace App\Service;

use Closure;
use RuntimeException;

/** This client never retries writes and never logs request headers or provider response bodies. */
final class WalletProvider
{
    public function __construct(private readonly ?Closure $transport = null, private readonly ?string $apiKey = null) {}

    public function configured(): bool { return $this->key() !== ''; }

    /** @return array{status:int,body:string} */
    public function request(string $method, string $path, ?array $payload = null): array
    {
        if (!preg_match('~^/api/(?:auth/usage|passes(?:/[A-Za-z0-9_-]+)?)(?:\?credentials=platform)?$~D', $path)) {
            throw new RuntimeException('Некорректный путь Wallet API.');
        }
        if ($this->transport !== null) { return ($this->transport)($method, $path, $payload); }
        if (!$this->configured() || !function_exists('curl_init')) { throw new RuntimeException('Wallet API не настроен или отсутствует PHP cURL.'); }
        $curl = curl_init('https://api.walletwallet.dev'.$path);
        $body = ''; $headers = ['Authorization: Bearer '.$this->key(), 'Accept: application/json'];
        $options = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20, CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
            if (strlen($body) + strlen($chunk) > 15000000) { return 0; }
            $body .= $chunk; return strlen($chunk);
        }];
        if ($payload !== null) { $headers[] = 'Content-Type: application/json'; $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($curl, $options);
        $complete = curl_exec($curl); $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl);
        // A broken response is ambiguous even when an HTTP status arrived before the timeout.
        return ['status' => $complete === false ? 0 : $status, 'body' => $complete === false ? '' : $body];
    }

    private function key(): string { return trim($this->apiKey ?? (string) ($_SERVER['WALLETWALLET_API_KEY'] ?? $_ENV['WALLETWALLET_API_KEY'] ?? getenv('WALLETWALLET_API_KEY') ?: '')); }
}
