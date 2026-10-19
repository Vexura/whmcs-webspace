<?php

declare(strict_types=1);

namespace Vexura\Whmcs\Webspace;

/** HTTPS JSON client. Deliberately no implicit retries of prepaid mutations. */
final class Api
{
    private ?array $identity = null;

    public function __construct(
        private readonly string $token,
        private readonly string $baseUrl = 'https://api.vexura.eu/api/v1',
        private readonly ?\Closure $transport = null,
    ) {
        $url = parse_url($baseUrl);
        if (! is_array($url) || ($url['scheme'] ?? '') !== 'https' || empty($url['host']) || isset($url['user'], $url['pass']) || isset($url['user']) || isset($url['query']) || isset($url['fragment']) || rtrim($url['path'] ?? '', '/') !== '/api/v1') {
            throw new \InvalidArgumentException('API-Adresse muss eine HTTPS-Adresse mit /api/v1 ohne Zugangsdaten sein.');
        }
        if ($token === '' || preg_match('/[\x00-\x20\x7f]/', $token)) {
            throw new \InvalidArgumentException('API-Key im Passwortfeld des WHMCS-Servers hinterlegen.');
        }
    }

    public function __debugInfo(): array
    {
        return ['baseUrl' => $this->baseUrl, 'token' => '[redacted]'];
    }

    public function identity(): array
    {
        if ($this->identity === null) {
            $account = $this->request('GET', '/account');
            $team = Service::uuid(Profiles::value($account, 'data.team.id'));
            if (($account['data']['currency'] ?? '') !== 'EUR') {
                throw new \RuntimeException('Vexura-Abrechnungskonto muss EUR verwenden.');
            }
            $this->identity = ['url' => rtrim($this->baseUrl, '/'), 'team_id' => $team];
        }

        return $this->identity;
    }

    /** @param array<string,mixed>|null $body @return array<string,mixed> */
    public function request(string $method, string $path, ?array $body = null, ?string $key = null, array $query = []): array
    {
        if (! in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true) || ! str_starts_with($path, '/') || str_starts_with($path, '//') || preg_match('~[{}?#\\\\\x00-\x20]|(?:^|/)\.\.?(?:/|$)~', $path)) {
            throw new \InvalidArgumentException('Ungültiger API-Pfad.');
        }
        if ($key !== null && ! preg_match('/^[a-zA-Z0-9._:-]{8,128}$/D', $key)) {
            throw new \InvalidArgumentException('Ungültiger Idempotency-Key.');
        }
        $url = rtrim($this->baseUrl, '/').$path;
        if ($query !== []) {
            $url .= '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        $headers = ['Accept: application/json', 'Authorization: Bearer '.$this->token, 'User-Agent: Vexura-WHMCS/1.0'];
        if ($key !== null) {
            $headers[] = 'Idempotency-Key: '.$key;
        }
        $encoded = $body !== null ? json_encode($body === [] ? new \stdClass : $body, JSON_THROW_ON_ERROR) : null;
        if ($encoded !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        [$status, $responseHeaders, $raw] = $this->transport !== null
            ? ($this->transport)($method, $url, $headers, $encoded)
            : $this->curl($method, $url, $headers, $encoded);
        $json = json_decode($raw, true);
        if ($status < 200 || $status >= 300) {
            $requestId = preg_match('/^[a-zA-Z0-9-]{1,64}$/D', $responseHeaders['x-request-id'] ?? '') ? $responseHeaders['x-request-id'] : '';
            $code = is_string($json['code'] ?? null) && preg_match('/^[a-z_]{1,64}$/D', $json['code']) ? $json['code'] : '';
            $fields = array_filter(array_keys(is_array($json['errors'] ?? null) ? $json['errors'] : []), static fn ($field) => is_string($field) && preg_match('/^[a-zA-Z0-9_.*-]{1,80}$/D', $field));
            throw new ApiException($status, $code, $requestId, min(3600, max(0, (int) ($responseHeaders['retry-after'] ?? 0))), $fields);
        }
        if (! is_array($json)) {
            throw new ApiException(502, 'invalid_response');
        }

        return $json;
    }

    private function curl(string $method, string $url, array $headers, ?string $body): array
    {
        $handle = curl_init();
        $responseHeaders = [];
        $raw = '';
        $options = [CURLOPT_URL => $url, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 45,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
                if (str_starts_with($line, 'HTTP/')) {
                    $responseHeaders = [];
                } elseif (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$raw): int {
                if (strlen($raw) + strlen($chunk) > 8 * 1024 * 1024) {
                    return 0;
                }
                $raw .= $chunk;

                return strlen($chunk);
            }];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        if ($handle === false) {
            throw new ApiException(0, 'transport_failed');
        }
        try {
            if (! curl_setopt_array($handle, $options) || curl_exec($handle) === false) {
                throw new ApiException(0, 'transport_failed');
            }

            return [(int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $responseHeaders, $raw];
        } finally {
            curl_close($handle);
        }
    }
}
