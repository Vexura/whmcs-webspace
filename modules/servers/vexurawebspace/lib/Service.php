<?php

declare(strict_types=1);

namespace Vexura\Whmcs\Webspace;

/** Prepaid lifecycle independent of WHMCS, allowing deterministic tests without spending balance. */
final class Service
{
    private readonly Journal $journal;

    public function __construct(private readonly Api $api, private readonly StateStore $store, private readonly array $profile)
    {
        $this->journal = new Journal($api, $store);
    }

    public function create(array $body, int $months, float $monthlyLimit, float $setupLimit, int $initialInvoice): string
    {
        return $this->store->locked(function () use ($body, $months, $monthlyLimit, $setupLimit, $initialInvoice): string {
            $state = $this->store->read();
            $this->available();
            if (! empty($state['terminated'])) {
                throw new \RuntimeException('Dieser WHMCS-Dienst ist beendet. Für eine neue Bestellung einen neuen Dienst anlegen.');
            }
            if (! isset($state['initial_months'])) {
                $state += ['initial_months' => $months, 'initial_invoice' => $initialInvoice, 'initial_covered' => Profiles::initialMonths($this->profile, $months)];
                $this->store->write($state);
            }
            if ($state['initial_months'] !== $months) {
                throw new \RuntimeException('Die Laufzeit der begonnenen Bestellung darf nicht geändert werden.');
            }
            if (empty($state['order_id'])) {
                if (! $this->journal->hasRequest($state, 'create')) {
                    $amount = Profiles::amount($this->api->request('POST', $this->profile['orderPath'].'/quote', $body), $this->profile);
                    $this->limit($amount, $monthlyLimit * $state['initial_covered'] + $setupLimit);
                    $body['accept_terms'] = true;
                    $body['accept_cancellation'] = true;
                    $body['expected_amount'] = $amount;
                }
                $result = $this->journal->send($state, 'create', 'POST', $this->profile['orderPath'].'/order', $body);
                $state['order_id'] = self::uuid(Profiles::value($result, $this->profile['price'] === 'resource.total' ? 'resource.order_id' : 'order_id'));
                $this->store->write($state);
            }
            if (empty($state['id'])) {
                $order = $this->api->request('GET', '/orders/'.self::uuid($state['order_id']))['data'] ?? [];
                if (($order['status'] ?? '') !== 'completed') {
                    throw new \RuntimeException('Bestellung '.$state['order_id'].' ist '.(in_array($order['status'] ?? '', ['failed', 'refunded'], true) ? 'fehlgeschlagen. Keine automatische Neubestellung.' : 'noch in Bearbeitung. Status erneut prüfen; keine neue Bestellung.'));
                }
                $products = array_values(array_filter($order['products'] ?? [], fn ($product) => ($product['type'] ?? '') === $this->profile['type']));
                if (count($products) !== 1) {
                    throw new \RuntimeException('Bestellung noch ohne eindeutige Produktzuordnung.');
                }
                $state['id'] = self::uuid($products[0]['id']);
                $state['subscription_id'] = self::uuid($products[0]['subscription_id']);
                $this->store->write($state);
            }
            $remote = $this->detail($state);
            if (! in_array($remote['status'] ?? '', ['active', 'running', 'online', 'stopped', 'offline', 'issued', 'valid'], true)) {
                throw new \RuntimeException('Produkt wurde angelegt, ist aber noch nicht betriebsbereit. Erneut prüfen.');
            }
            if ($this->profile['term'] !== 'ssl') {
                $this->disableAutoRenew($state);
            }
            $remaining = $state['initial_months'] - $state['initial_covered'];
            if ($remaining > 0) {
                $this->extend($state, 'initial-term', $remaining, $monthlyLimit);
            }
            $state['ready'] = true;
            $state['expires_at'] = $state['expires_at'] ?? ($remote['expires_at'] ?? null);
            $this->store->write($state);

            return $state['id'];
        });
    }

    public function renew(int $invoiceId, int $months, float $monthlyLimit): void
    {
        $this->store->locked(function () use ($invoiceId, $months, $monthlyLimit): void {
            $state = $this->store->read();
            if ($invoiceId < 1 || ! in_array($months, [1, 3, 6, 12], true)) {
                throw new \InvalidArgumentException('Bezahlte Rechnung und gültige Laufzeit erforderlich.');
            }
            if ($invoiceId === ($state['initial_invoice'] ?? null) || isset($state['paid'][$invoiceId])) {
                return;
            }
            if (empty($state['ready']) || ! empty($state['terminated'])) {
                throw new \RuntimeException('Produkt ist noch nicht bereit oder bereits beendet.');
            }
            if (isset($state['renewal_terms'][$invoiceId]) && $state['renewal_terms'][$invoiceId] !== $months) {
                throw new \RuntimeException('Die gespeicherte Rechnungslaufzeit darf nicht geändert werden.');
            }
            $state['renewal_terms'][$invoiceId] = $months;
            $this->store->write($state);
            $this->available();
            if ($this->profile['term'] === 'ssl') {
                $this->renewCertificate($state, $invoiceId, $months, $monthlyLimit);
            } else {
                $this->extend($state, 'invoice:'.$invoiceId, $months, $monthlyLimit);
            }
            $state['paid'][$invoiceId] = $months;
            $this->store->write($state);
        });
    }

    private function extend(array &$state, string $operation, int $months, float $monthlyLimit): void
    {
        $completed = (int) ($state['extended'][$operation] ?? 0);
        while ($completed < $months) {
            $chunk = max(array_filter([1, 3, 6, 12], static fn ($value) => $value <= $months - $completed));
            $key = $operation.':'.$completed;
            $path = '/subscriptions/'.self::uuid($state['subscription_id']);
            $body = [];
            if (! $this->journal->hasRequest($state, $key)) {
                $quote = Profiles::value($this->api->request('GET', $path.'/quote', null, null, ['months' => $chunk]), 'data');
                $amount = Profiles::money(Profiles::value($quote, 'total'));
                $this->limit($amount, $monthlyLimit * $chunk);
                $body = ['months' => $chunk, 'expected_amount' => $amount, 'expected_expires_at' => Profiles::value($quote, 'expires_at')];
            }
            $response = $this->journal->send($state, $key, 'POST', $path.'/renew', $body);
            $state['expires_at'] = Profiles::value($response, 'data.expires_at');
            $completed += $chunk;
            $state['extended'][$operation] = $completed;
            $this->store->write($state);
        }
    }

    private function renewCertificate(array &$state, int $invoiceId, int $months, float $monthlyLimit): void
    {
        $operation = 'ssl-invoice:'.$invoiceId;
        $body = [];
        if (! $this->journal->hasRequest($state, $operation)) {
            $certificate = $this->detail($state);
            if (! empty($certificate['renewal_pending'])) {
                throw new \RuntimeException('Eine SSL-Verlängerung wird bereits bearbeitet.');
            }
            $options = array_values(array_filter($certificate['renewal_options'] ?? [], static fn ($option) => (int) ($option['period_months'] ?? 0) === $months));
            if (count($options) !== 1) {
                throw new \RuntimeException('Die bezahlte Laufzeit wird für dieses Zertifikat nicht angeboten.');
            }
            $amount = Profiles::money($options[0]['price']);
            $this->limit($amount, $monthlyLimit * $months);
            $body = ['period_months' => $months, 'expected_price' => $amount, 'expected_expires_at' => Profiles::value($certificate, 'expires_at'), 'request_key' => self::newUuid()];
        }
        $this->journal->send($state, $operation, 'POST', $this->profile['path'].'/'.self::uuid($state['id']).'/renew', $body);
        $certificate = $this->detail($state);
        $before = $state['requests'][$operation]['body']['expected_expires_at'];
        if (! empty($certificate['renewal_pending']) || empty($certificate['expires_at']) || strtotime($certificate['expires_at']) <= strtotime($before)) {
            throw new \RuntimeException('SSL-Verlängerung ist angenommen; Bestätigung und Validierung stehen noch aus.');
        }
        $state['expires_at'] = $certificate['expires_at'];
    }

    public function terminate(): void
    {
        $this->store->locked(function (): void {
            $state = $this->store->read();
            if (! empty($state['terminated'])) {
                return;
            }
            if (empty($state['id'])) {
                throw new \RuntimeException('Offene Bestellung zuerst abgleichen. Kündigung kann noch nicht bestätigt werden.');
            }
            $this->available();
            if ($this->profile['term'] !== 'ssl') {
                $this->disableAutoRenew($state, 'terminate');
            }
            $state['terminated'] = true;
            $this->store->write($state);
        });
    }

    private function disableAutoRenew(array &$state, string $reason = 'initial'): void
    {
        $response = $this->journal->send($state, 'auto-renew-off:'.$reason, 'PUT', '/subscriptions/'.self::uuid($state['subscription_id']).'/auto-renew', ['enabled' => false]);
        if (($response['data']['auto_renew'] ?? null) !== false) {
            throw new \RuntimeException('Deaktivierung der automatischen Verlängerung wurde nicht bestätigt.');
        }
        $expiry = Profiles::value($response, 'data.expires_at');
        if (empty($state['expires_at']) || strtotime($expiry) > strtotime($state['expires_at'])) {
            $state['expires_at'] = $expiry;
        }
        $this->store->write($state);
    }

    public function available(): void
    {
        $products = $this->api->request('GET', '/products')['data'] ?? [];
        foreach ($products as $product) {
            if (($product['slug'] ?? '') === $this->profile['product'] && ($product['orderable'] ?? false)) {
                return;
            }
        }
        throw new \RuntimeException('Dieses Vexura-Produkt ist deaktiviert oder nicht für die API verfügbar.');
    }

    public function detail(array $state): array
    {
        return Profiles::value($this->api->request('GET', $this->profile['path'].'/'.self::uuid($state['id'] ?? '')), $this->profile['wrapper']);
    }

    public static function uuid(mixed $id): string
    {
        if (! is_string($id) || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $id)) {
            throw new \RuntimeException('API hat keine gültige Produkt-, Bestell- oder Vertrags-ID geliefert.');
        }

        return $id;
    }

    public static function newUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 15) | 64);
        $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
    }

    private function limit(float $amount, float $maximum): void
    {
        if ($maximum <= 0 || $amount > $maximum + 0.001) {
            throw new \RuntimeException('API-Preis überschreitet das konfigurierte Einkaufslimit. Modulpreis prüfen.');
        }
    }
}
