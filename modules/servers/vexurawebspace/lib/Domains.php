<?php

declare(strict_types=1);

namespace Vexura\Whmcs\Webspace;

/** Registrar lifecycle: local domain UUIDs, annual prepaid quotes and durable invoice receipts. */
final class Domains
{
    private readonly Journal $journal;

    public function __construct(private readonly Api $api, private readonly StateStore $store)
    {
        $this->journal = new Journal($api, $store);
    }

    public function create(array $params, bool $transfer): void
    {
        $this->store->locked(function () use ($params, $transfer): void {
            $this->available();
            $state = $this->store->read();
            $domain = self::name($params);
            if (isset($state['domain']) && $state['domain'] !== $domain) {
                throw new \RuntimeException('Diese WHMCS-ID ist bereits einer anderen Domain zugeordnet.');
            }
            $years = self::years($params);
            $state['domain'] = $domain;
            $this->store->write($state);
            if (empty($state['id'])) {
                if (empty($state['handle_id'])) {
                    $this->journal->hasRequest($state, 'contact-create');
                    $contact = $this->journal->send($state, 'contact-create', 'POST', '/domain-handles', self::contact($params));
                    $state['handle_id'] = Service::uuid(Profiles::value($contact, 'handle.id'));
                    $this->store->write($state);
                }
                $body = [];
                if (! $this->journal->hasRequest($state, 'domain-create')) {
                    $body = ['domain' => $domain, 'owner_handle_id' => $state['handle_id'], 'type' => $transfer ? 'transfer' : 'register', 'years' => $years];
                    $nameservers = self::nameservers($params);
                    if ($nameservers !== []) {
                        $body['nameservers'] = $nameservers;
                    }
                    if ($transfer) {
                        $body['auth_code'] = (string) ($params['eppcode'] ?? '');
                        if ($body['auth_code'] === '') {
                            throw new \InvalidArgumentException('Transfercode fehlt.');
                        }
                    }
                    $quote = $this->api->request('POST', '/domain-subscriptions/quote', $body);
                    $amount = Profiles::money(Profiles::value($quote, 'pricing.amount'));
                    self::limit($amount, $params, $years);
                    $body += ['expected_amount' => $amount, 'accept_terms' => true, 'accept_cancellation' => true];
                }
                $result = $this->journal->send($state, 'domain-create', 'POST', '/domain-subscriptions/order', $body);
                if (! isset($result['domain']['id'])) {
                    throw new \RuntimeException('Domainauftrag angenommen, Zuordnung noch unklar. Vexura-Support muss die vorhandene Bestellung abgleichen. Keine Neubestellung.');
                }
                $state['id'] = Service::uuid($result['domain']['id']);
                $state['subscription_id'] = Service::uuid($result['domain']['subscription_id'] ?? '');
                $state['expires_at'] = $result['domain']['expires_at'];
                $this->store->write($state);
            }
            if (! $transfer) {
                $detail = $this->detail($state);
                if (! self::active($detail)) {
                    throw new \RuntimeException('Registrierung angenommen, aber vom Registrar noch nicht als aktiv bestätigt. Erneutes Registrieren prüft dieselbe Domain.');
                }
            }
        });
    }

    public function renew(array $params, string $receipt): void
    {
        $this->store->locked(function () use ($params, $receipt): void {
            $state = $this->store->read();
            $this->available();
            $years = self::years($params);
            if (isset($state['renewed'][$receipt])) {
                return;
            }
            $this->requireMapping($state, $params);
            $operation = 'renew:'.$receipt;
            $body = [];
            if (! $this->journal->hasRequest($state, $operation)) {
                $quote = Profiles::value($this->api->request('GET', '/subscriptions/'.Service::uuid($state['subscription_id']).'/quote', null, null, ['years' => $years]), 'data');
                $amount = Profiles::money(Profiles::value($quote, 'total'));
                self::limit($amount, $params, $years);
                $body = ['years' => $years, 'expected_amount' => $amount, 'expected_expires_at' => Profiles::value($quote, 'expires_at')];
            }
            $result = $this->journal->send($state, $operation, 'POST', '/domain-subscriptions/'.Service::uuid($state['id']).'/renew', $body);
            $state['expires_at'] = Profiles::value($result, 'expires_at');
            $state['renewed'][$receipt] = ['years' => $years, 'expires_at' => $state['expires_at']];
            $this->store->write($state);
        });
    }

    public function sync(array $params, bool $transfer = false): array
    {
        $state = $this->store->read();
        $this->requireMapping($state, $params);
        $detail = $this->detail($state);
        $expires = $detail['domain']['expires_at'] ?? null;
        if (! is_string($expires) || strtotime($expires) === false) {
            throw new \RuntimeException('Domain hat noch kein bestätigtes Ablaufdatum.');
        }
        $active = self::active($detail);
        $date = substr($expires, 0, 10);
        if ($transfer) {
            $status = $detail['api']['status'] ?? $detail['domain']['status'] ?? '';

            return ['completed' => $active, 'failed' => in_array($status, ['failed', 'transfer_failed'], true), 'expirydate' => $date];
        }

        return ['active' => $active, 'expired' => strtotime($expires) <= time(), 'expirydate' => $date];
    }

    public function getNameservers(array $params): array
    {
        $response = $this->read($params, '/nameservers');
        $nameservers = Profiles::value($response, 'nameservers');
        $result = [];
        foreach (array_values($nameservers) as $index => $name) {
            if (! is_string($name)) {
                throw new \RuntimeException('API hat kein gültiges Nameserver-Format geliefert.');
            }
            $result['ns'.($index + 1)] = $name;
        }

        return $result;
    }

    public function saveNameservers(array $params): void
    {
        $nameservers = self::nameservers($params);
        if (count($nameservers) < 2) {
            throw new \InvalidArgumentException('Mindestens zwei Nameserver erforderlich.');
        }
        $this->mutate($params, 'nameservers', 'PUT', '/nameservers', ['nameservers' => $nameservers]);
    }

    public function epp(array $params): array
    {
        return ['eppcode' => Profiles::value($this->read($params, '/auth-code'), 'auth_code')];
    }

    public function contacts(array $params): array
    {
        $state = $this->store->read();
        $this->requireMapping($state, $params);
        $contact = Profiles::value($this->api->request('GET', '/domains/'.Service::uuid($state['handle_id'] ?? '')), 'domain');
        $map = ['First Name' => 'first_name', 'Last Name' => 'last_name', 'Organization Name' => 'organization', 'Address 1' => 'street', 'City' => 'city', 'Postcode' => 'postal_code', 'Country' => 'country_code', 'Phone Number' => 'phone', 'Email' => 'email'];
        $registrant = [];
        foreach ($map as $label => $key) {
            $registrant[$label] = $contact[$key] ?? '';
        }

        return ['Registrant' => $registrant];
    }

    public function saveContacts(array $params): void
    {
        $this->store->locked(function () use ($params): void {
            $state = $this->store->read();
            $this->requireMapping($state, $params);
            $contact = $params['contactdetails']['Registrant'] ?? [];
            $body = ['label' => 'WHMCS '.self::name($params), 'type' => empty($contact['Organization Name']) ? 'person' : 'organization',
                'first_name' => $contact['First Name'] ?? '', 'last_name' => $contact['Last Name'] ?? '', 'organization' => $contact['Organization Name'] ?? '',
                'street' => $contact['Address 1'] ?? '', 'city' => $contact['City'] ?? '', 'postal_code' => $contact['Postcode'] ?? '',
                'country_code' => $contact['Country'] ?? '', 'phone' => $contact['Phone Number'] ?? '', 'email' => $contact['Email'] ?? ''];
            $key = 'contact-update:'.hash('sha256', json_encode($body, JSON_THROW_ON_ERROR));
            $this->journal->hasRequest($state, $key);
            $this->journal->send($state, $key, 'PUT', '/domain-handles/'.Service::uuid($state['handle_id'] ?? ''), $body);
            unset($state['requests'][$key]);
            $this->store->write($state);
        });
    }

    public function dns(array $params): array
    {
        $records = Profiles::value($this->read($params, '/dns'), 'records');
        $result = [];
        foreach ($records as $record) {
            if (in_array($record['type'] ?? '', ['A', 'AAAA', 'CNAME', 'MX', 'TXT'], true)) {
                $result[] = ['hostname' => $record['name'], 'type' => $record['type'], 'address' => $record['content'], 'priority' => $record['priority'] ?? 0];
            }
        }

        return $result;
    }

    public function saveDns(array $params): void
    {
        $this->store->locked(function () use ($params): void {
            $state = $this->store->read();
            $this->requireMapping($state, $params);
            $desired = [];
            foreach ($params['dnsrecords'] ?? [] as $record) {
                if (empty($record['hostname']) && empty($record['address'])) {
                    continue;
                }
                if (! in_array($record['type'] ?? '', ['A', 'AAAA', 'CNAME', 'MX', 'TXT'], true) || ! is_string($record['address'] ?? null)) {
                    throw new \InvalidArgumentException('DNS-Typ nicht unterstützt. Erlaubt: A, AAAA, CNAME, MX, TXT.');
                }
                $desired[] = ['type' => $record['type'], 'name' => (string) ($record['hostname'] ?: '@'), 'content' => $record['address'], 'priority' => (int) ($record['priority'] ?? 0)];
            }
            if (count($desired) > 100) {
                throw new \InvalidArgumentException('Höchstens 100 DNS-Einträge pro Änderung.');
            }
            $hash = hash('sha256', json_encode($desired, JSON_THROW_ON_ERROR));
            if (isset($state['dns_plan']) && $state['dns_plan']['hash'] !== $hash) {
                throw new \RuntimeException('Eine DNS-Änderung ist noch unbestätigt. Zuerst denselben Vorgang erneut speichern.');
            }
            $base = '/domain-subscriptions/'.Service::uuid($state['id']).'/dns';
            if (! isset($state['dns_plan'])) {
                $existing = Profiles::value($this->api->request('GET', $base), 'records');
                $state['dns_plan'] = ['hash' => $hash, 'desired' => $desired, 'step' => 0, 'expected' => $existing];
                $this->store->write($state);
            }
            // Bound work per callback; the exact next mutation survives timeouts and rate limits.
            for ($i = 0; $i < 20; $i++) {
                $key = 'dns:'.$state['dns_plan']['step'];
                if (isset($state['requests'][$key])) {
                    $entry = $state['requests'][$key];
                    $this->journal->send($state, $key, $entry['method'], $entry['path'], $entry['body']);
                    $state['dns_plan']['expected'] = Profiles::value($this->api->request('GET', $base), 'records');
                    $state['dns_plan']['step']++;
                    unset($state['requests'][$key]);
                    $this->store->write($state);

                    continue;
                }
                $existing = Profiles::value($this->api->request('GET', $base), 'records');
                if ($existing !== $state['dns_plan']['expected']) {
                    throw new \RuntimeException('DNS-Zone wurde parallel geändert. Änderung administrativ abgleichen, bevor weitergeschrieben wird.');
                }
                $remaining = $desired;
                $obsolete = [];
                foreach ($existing as $index => $record) {
                    if (! in_array($record['type'] ?? '', ['A', 'AAAA', 'CNAME', 'MX', 'TXT'], true)) {
                        continue;
                    }
                    $normalized = ['type' => $record['type'], 'name' => $record['name'], 'content' => $record['content'], 'priority' => (int) ($record['priority'] ?? 0)];
                    $match = array_search($normalized, $remaining, true);
                    if ($match !== false) {
                        unset($remaining[$match]);
                    } else {
                        $obsolete[] = (string) ($record['id'] ?? $index);
                    }
                }
                if ($remaining !== []) {
                    $this->journal->send($state, $key, 'POST', $base, array_values($remaining)[0] + ['ttl' => 3600]);
                } elseif ($obsolete !== []) {
                    $this->journal->send($state, $key, 'DELETE', $base.'/'.rawurlencode($obsolete[0]), []);
                } else {
                    unset($state['dns_plan']);
                    $this->store->write($state);

                    return;
                }
                $state['dns_plan']['expected'] = Profiles::value($this->api->request('GET', $base), 'records');
                unset($state['requests'][$key]);
                $state['dns_plan']['step']++;
                $this->store->write($state);
            }
            throw new \RuntimeException('DNS-Änderung wird in Etappen verarbeitet. Denselben Stand erneut speichern.');
        });
    }

    private function read(array $params, string $suffix): array
    {
        $state = $this->store->read();
        $this->requireMapping($state, $params);

        return $this->api->request('GET', '/domain-subscriptions/'.Service::uuid($state['id']).$suffix);
    }

    private function mutate(array $params, string $action, string $method, string $suffix, array $body): void
    {
        $this->store->locked(function () use ($params, $action, $method, $suffix, $body): void {
            $state = $this->store->read();
            $this->requireMapping($state, $params);
            if (isset($state['requests'][$action]) && $state['requests'][$action]['body'] !== $body) {
                throw new \RuntimeException('Eine abweichende Änderung ist noch unbestätigt. Zuerst den ursprünglichen Vorgang abgleichen.');
            }
            $this->journal->hasRequest($state, $action);
            $this->journal->send($state, $action, $method, '/domain-subscriptions/'.Service::uuid($state['id']).$suffix, $body);
            unset($state['requests'][$action]);
            $this->store->write($state);
        });
    }

    private function requireMapping(array $state, array $params): void
    {
        if (empty($state['id']) || ($state['domain'] ?? '') !== self::name($params)) {
            throw new \RuntimeException('Domain ist noch keiner bestätigten Vexura-Bestellung zugeordnet.');
        }
    }

    private function detail(array $state): array
    {
        return $this->api->request('GET', '/domain-subscriptions/'.Service::uuid($state['id']));
    }

    private static function active(array $detail): bool
    {
        if (is_array($detail['api'] ?? null) && isset($detail['api']['status'])) {
            return in_array($detail['api']['status'], ['active', 'ok'], true);
        }

        return ($detail['domain']['status'] ?? '') === 'active';
    }

    private function available(): void
    {
        foreach ($this->api->request('GET', '/products')['data'] ?? [] as $product) {
            if (($product['slug'] ?? '') === 'domains' && ($product['orderable'] ?? false)) {
                return;
            }
        }
        throw new \RuntimeException('Domains sind für die Vexura-API derzeit nicht verfügbar.');
    }

    private static function contact(array $params): array
    {
        return ['label' => 'WHMCS '.self::name($params), 'type' => empty($params['companyname']) ? 'person' : 'organization',
            'first_name' => $params['firstname'] ?? '', 'last_name' => $params['lastname'] ?? '', 'organization' => $params['companyname'] ?? '',
            'street' => trim(($params['address1'] ?? '').' '.($params['address2'] ?? '')), 'city' => $params['city'] ?? '',
            'postal_code' => $params['postcode'] ?? '', 'country_code' => $params['countrycode'] ?? $params['country'] ?? '',
            'phone' => $params['fullphonenumber'] ?? $params['phonenumber'] ?? '', 'email' => $params['email'] ?? ''];
    }

    private static function limit(float $amount, array $params, int $years): void
    {
        $maximum = Profiles::money(str_replace(',', '.', (string) ($params['YearlyLimit'] ?? '')));
        if ($maximum <= 0 || $amount > $maximum * $years + 0.001) {
            throw new \RuntimeException('Domainpreis überschreitet das konfigurierte Einkaufslimit pro Jahr.');
        }
        if (! empty($params['premiumCost']) && abs(Profiles::money($params['premiumCost']) - $amount) > 0.001) {
            throw new \RuntimeException('Premiumpreis stimmt nicht mit dem bestätigten Angebot überein.');
        }
    }

    public static function name(array $params): string
    {
        $domain = strtolower((string) (($params['domainname'] ?? '') ?: (($params['sld'] ?? '').'.'.($params['tld'] ?? ''))));
        if (! preg_match('/^(?=.{3,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$/D', $domain)) {
            throw new \InvalidArgumentException('Gültigen ASCII-/Punycode-Domainnamen übergeben.');
        }

        return $domain;
    }

    public static function years(array $params): int
    {
        $years = filter_var($params['regperiod'] ?? null, FILTER_VALIDATE_INT);
        if ($years === false || $years < 1 || $years > 10) {
            throw new \InvalidArgumentException('Domainlaufzeit muss zwischen einem und zehn Jahren liegen.');
        }

        return $years;
    }

    private static function nameservers(array $params): array
    {
        $result = [];
        for ($i = 1; $i <= 5; $i++) {
            if (! empty($params['ns'.$i])) {
                $result[] = strtolower(trim((string) $params['ns'.$i]));
            }
        }

        return array_values(array_unique($result));
    }
}
