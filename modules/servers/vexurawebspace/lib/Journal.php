<?php

declare(strict_types=1);

namespace Vexura\Whmcs\Webspace;

/** Call only while holding the store lock. Persist exact request BEFORE sending money. */
final class Journal
{
    public function __construct(private readonly Api $api, private readonly StateStore $store) {}

    public function hasRequest(array &$state, string $operation): bool
    {
        $entry = $state['requests'][$operation] ?? null;
        if ($entry !== null && ! isset($entry['result']) && ($entry['retry_at'] ?? 0) <= time()
            && (in_array($entry['last_status'] ?? 0, [401, 402, 403, 404, 422], true)
                || in_array($entry['error_code'] ?? '', ['price_changed', 'expiry_changed'], true))) {
            $state['rejections'][$operation] = ['status' => $entry['last_status'], 'request_id' => $entry['request_id'] ?? ''];
            unset($state['requests'][$operation]);
            $this->store->write($state);
        }

        return isset($state['requests'][$operation]);
    }

    public function send(array &$state, string $operation, string $method, string $path, array $body): array
    {
        $identity = $this->api->identity();
        if (isset($state['api_identity']) && $state['api_identity'] !== $identity) {
            throw new \RuntimeException('API-Server oder Team wurde geändert. Bestehenden Auftrag vor einer Kontoumstellung abgleichen.');
        }
        $state['api_identity'] = $identity;
        $entry = $state['requests'][$operation] ?? null;
        if ($entry === null) {
            $entry = ['key' => 'whmcs-'.bin2hex(random_bytes(24)), 'method' => $method, 'path' => $path, 'body' => $body, 'created' => time()];
            $state['requests'][$operation] = $entry;
            $this->store->write($state);
        }
        if (isset($entry['result'])) {
            return $entry['result'];
        }
        if (($entry['retry_at'] ?? 0) > time()) {
            throw new \RuntimeException('API-Ratelimit: Anfrage ist gespeichert und wird nach der Wartezeit erneut geprüft.');
        }
        try {
            $result = $this->api->request($entry['method'], $entry['path'], $entry['body'], $entry['key']);
        } catch (ApiException $error) {
            $state['requests'][$operation]['last_status'] = $error->status;
            $state['requests'][$operation]['request_id'] = $error->requestId;
            $state['requests'][$operation]['error_code'] = $error->apiCode;
            $state['requests'][$operation]['retry_at'] = time() + max(60, $error->retryAfter);
            $this->store->write($state);
            throw $error;
        }
        $state['requests'][$operation]['result'] = $result;
        $this->store->write($state);

        return $result;
    }
}
