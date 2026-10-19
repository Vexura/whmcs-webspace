<?php

declare(strict_types=1);

namespace Vexura\Whmcs\Webspace;

/** Explicit API v1 contracts; unavailable product families have no fabricated callbacks. */
final class Profiles
{
    public static function all(): array
    {
        $profiles = [
            'rootserver' => ['Root Server', 'rootserver', 'root_server', '/root-servers', 'server', 'amount', 'monthly', ['cluster_id', 'os_version_id', 'cpu_cores:int', 'ram_gb:int', 'disk_gb:int', 'hostname', 'bandwidth_tier_id?', 'ipv4_count:int?', 'ipv6_count:int?']],
            'gameserver' => ['Gameserver', 'gameservers', 'gameserver', '/game-servers', 'server', 'pricing.total', 'months', ['package_id', 'location_id', 'egg_id', 'name', 'variables:json?']],
            'gamecloud' => ['Gamecloud', 'gameservers', 'gamecloud', '/game-clouds', 'cloud', 'pricing.amount', 'monthly', ['location_id', 'package_id?', 'name', 'tier', 'cores:int?', 'ram_mb:int?', 'disk_gb:int?', 'dedicated_ips:int?']],
            'webspace' => ['Webspace', 'webspaces', 'webspace', '/webspaces', 'webspace', 'pricing.total', 'months', ['package_id', 'location_id', 'egg_id', 'name', 'variables:json?']],
            'teamspeak' => ['TeamSpeak', 'teamspeak', 'teamspeak', '/teamspeak', 'server', 'pricing.amount', 'monthly', ['slots:int', 'name', 'price_plan_id?']],
            'vpn' => ['VPN', 'vpn', 'vpn', '/vpn', 'resource', 'pricing.amount', 'vpn', ['plan_id', 'dns_option']],
            'ip-tunnel' => ['IP-Tunnel', 'ip-tunnel', 'ip_tunnel', '/ip-tunnels', 'resource', 'resource.total', 'monthly', ['location_id', 'name', 'protocol', 'ip_count:int', 'peer_endpoint?']],
            'asn' => ['ASN', 'asn', 'asn', '/asn', 'asn_number', 'resource.total', 'asn', ['organisation_name', 'contact_name', 'contact_email', 'address', 'use_case']],
            'ipv6' => ['IPv6', 'ipv6', 'ipv6', '/ipv6', 'subnet', 'resource.total', 'monthly', ['ip_pool_id', 'organisation_id', 'country', 'asns:json?']],
            'dedicated' => ['Dedicated Server', 'dedicated', 'dedicated', '/products/dedicated', 'resource', 'provider', 'monthly', ['provider_plan_id', 'hostname', 'os_profile_id:int?']],
            'kubernetes' => ['Kubernetes', 'kubernetes', 'kubernetes', '/products/kubernetes', 'resource', 'provider', 'monthly', ['provider_plan_id', 'name', 'location_id', 'cluster_id', 'version_id', 'worker_pools:json']],
            'loadbalancer' => ['Load Balancer', 'loadbalancer', 'loadbalancer', '/products/loadbalancer', 'resource', 'provider', 'monthly', ['provider_plan_id', 'name', 'location_id', 'cluster_id', 'algorithm?']],
            's3-storage' => ['S3 Storage', 's3-storage', 's3-storage', '/products/s3-storage', 'resource', 'provider', 'monthly', ['provider_plan_id', 'friendly_name', 'size_gb:int', 'location_id?']],
            'storagebox' => ['Storagebox', 'storagebox', 'storagebox', '/products/storagebox', 'resource', 'provider', 'monthly', ['provider_plan_id', 'friendly', 'size_gb:int', 'location_id?', 'speed:int?']],
            'ssl' => ['SSL-Zertifikat', 'ssl', 'ssl', '/ssl', 'data', 'resource.total', 'ssl', ['product_id', 'common_name', 'csr', 'dcv:json', 'sans:json?', 'organization:json?', 'approver:json?']],
        ];
        $result = [];
        foreach ($profiles as $slug => [$name, $product, $type, $path, $wrapper, $price, $term, $fields]) {
            $orderPath = in_array($slug, ['ip-tunnel', 'asn', 'ipv6', 'ssl'], true) ? '/catalog/'.$slug : $path;
            $result[$slug] = compact('slug', 'name', 'product', 'type', 'path', 'wrapper', 'price', 'term', 'fields', 'orderPath') + ['module' => 'vexura'.str_replace('-', '', $slug)];
        }

        return $result;
    }

    public static function get(string $slug): array
    {
        return self::all()[$slug] ?? throw new \InvalidArgumentException('Unbekanntes Modul.');
    }

    public static function value(array $data, string $path): mixed
    {
        foreach (explode('.', $path) as $key) {
            if (! is_array($data) || ! array_key_exists($key, $data)) {
                throw new \RuntimeException('API-Antwort enthält das erforderliche Feld '.$path.' nicht.');
            }
            $data = $data[$key];
        }

        return $data;
    }

    public static function amount(array $quote, array $profile): float
    {
        $amount = $profile['price'] === 'provider'
            ? self::money(self::value($quote, 'price_monthly')) + self::money(self::value($quote, 'price_setup'))
            : self::money(self::value($quote, $profile['price']));

        return round($amount, 2);
    }

    public static function money(mixed $value): float
    {
        if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value < 0) {
            throw new \RuntimeException('API hat keinen gültigen Preis geliefert.');
        }

        return round((float) $value, 2);
    }

    public static function months(string $cycle): int
    {
        return match ($cycle) {
            'Monthly' => 1, 'Quarterly' => 3, 'Semi-Annually' => 6, 'Annually' => 12,
            default => throw new \RuntimeException('Unterstützt: monatlich, vierteljährlich, halbjährlich und jährlich. Keine kostenlose, einmalige oder anteilige Abrechnung.'),
        };
    }

    /** Only explicitly listed customer fields can override administrator-defined plans/resources. */
    public static function input(array $profile, array $params, int $months): array
    {
        $body = json_decode((string) (($params['configoption5'] ?? '') ?: '{}'), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($body) || ($body !== [] && array_is_list($body))) {
            throw new \InvalidArgumentException('Bestellvorgaben müssen ein JSON-Objekt sein.');
        }
        foreach ($profile['fields'] as $index => $definition) {
            $optional = str_ends_with($definition, '?');
            [$name, $type] = array_pad(explode(':', rtrim($definition, '?')), 2, 'string');
            $value = $params['configoption'.($index + 6)] ?? '';
            if ($value !== '') {
                $body[$name] = $type === 'json' ? json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR) : ($type === 'int' ? filter_var($value, FILTER_VALIDATE_INT) : (string) $value);
            }
            if (in_array($name, ['name', 'hostname', 'peer_endpoint', 'common_name', 'csr', 'contact_name', 'contact_email', 'organisation_name', 'address', 'use_case'], true)) {
                $customer = $params['customfields'][$name] ?? null;
                if (is_string($customer) && $customer !== '') {
                    $body[$name] = $customer;
                }
            }
            if (empty($body[$name]) && in_array($name, ['name', 'hostname', 'common_name', 'friendly', 'friendly_name'], true) && ! empty($params['domain'])) {
                $body[$name] = $params['domain'];
            }
            if (! $optional && (! isset($body[$name]) || $body[$name] === '' || $body[$name] === false)) {
                throw new \InvalidArgumentException('Modulkonfiguration unvollständig: '.$name);
            }
        }
        unset($body['request_id'], $body['expected_amount'], $body['months'], $body['billing_cycle'], $body['period_months'], $body['game_cloud_id']);
        if ($profile['term'] === 'months') {
            $body['months'] = $months;
        } elseif ($profile['term'] === 'vpn') {
            $body['billing_cycle'] = match ($months) {
                12 => 'yearly', 3 => 'quarterly', default => 'monthly'
            };
        } elseif ($profile['term'] === 'asn') {
            $body['billing_cycle'] = $months === 12 ? 'yearly' : 'monthly';
        } elseif ($profile['term'] === 'ssl') {
            $body['period_months'] = $months;
        }
        if ($profile['slug'] === 'gamecloud' && ($body['tier'] ?? '') !== 'premium') {
            throw new \InvalidArgumentException('WHMCS benötigt eine bezahlte Premium-Gamecloud.');
        }
        if ($profile['slug'] === 'dedicated') {
            $body['auto_renew'] = false;
        }

        return $body;
    }

    public static function initialMonths(array $profile, int $months): int
    {
        return match ($profile['term']) {
            'months', 'ssl' => $months,
            'vpn' => in_array($months, [3, 12], true) ? $months : 1,
            'asn' => $months === 12 ? 12 : 1,
            default => 1,
        };
    }
}
