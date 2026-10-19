<?php

declare(strict_types=1);

namespace Vexura\Whmcs\Webspace;

use WHMCS\Database\Capsule;
use WHMCS\Domain\Domain;
use WHMCS\Domains\DomainLookup\ResultsList;
use WHMCS\Domains\DomainLookup\SearchResult;

final class Registrar
{
    private static ?int $invoiceId = null;

    public static function paymentContext(?int $invoiceId): void
    {
        self::$invoiceId = $invoiceId;
    }

    public static function config(): array
    {
        return [
            'FriendlyName' => ['Type' => 'System', 'Value' => 'Vexura Domains'],
            'Description' => ['Type' => 'System', 'Value' => 'Prepaid-Registrar: Registrierung, Transfer, Verlängerung, Kontakte, Nameserver, DNS und EPP. WHMCS-Cron und Domain-Synchronisierung aktivieren. API-Scopes: read:products, write:products, read:credentials, read:billing, write:billing.'],
            'BaseUrl' => ['FriendlyName' => 'API-Adresse', 'Type' => 'text', 'Size' => '60', 'Default' => 'https://api.vexura.eu/api/v1'],
            'ApiKey' => ['FriendlyName' => 'Bearer API-Key', 'Type' => 'password', 'Size' => '60'],
            'YearlyLimit' => ['FriendlyName' => 'Maximaler Einkauf je Domainjahr (EUR)', 'Type' => 'text', 'Size' => '12', 'Description' => 'Pflichtfeld. Obergrenze für Registrierung, Transfer und Verlängerung.'],
            'AcceptTerms' => ['FriendlyName' => 'Vexura-Bestellbedingungen', 'Type' => 'yesno', 'Description' => 'Ich akzeptiere die Vexura-Vertragsbedingungen und den sofortigen Leistungsbeginn.'],
        ];
    }

    public static function api(array $params): Api
    {
        return new Api((string) ($params['ApiKey'] ?? ''), (string) (($params['BaseUrl'] ?? '') ?: 'https://api.vexura.eu/api/v1'));
    }

    public static function run(string $action, array $params): array
    {
        try {
            $id = (int) ($params['domainid'] ?? 0);
            $row = Capsule::table('tbldomains')->where('id', $id)->where('registrar', 'vexuradomains')->first();
            if ($row === null || strtolower($row->domain) !== Domains::name($params)) {
                throw new \RuntimeException('Domain ist diesem Vexura-Registrar nicht zugeordnet.');
            }
            $store = new WhmcsStore('vexuradomains', $id);
            $domains = new Domains(self::api($params), $store);
            if (in_array($action, ['RegisterDomain', 'TransferDomain', 'RenewDomain', 'RetryRenewals'], true) && ! in_array($params['AcceptTerms'] ?? '', ['on', '1', 1, true], true)) {
                throw new \RuntimeException('Vexura-Bestellbedingungen müssen in der Registrar-Konfiguration akzeptiert werden.');
            }
            switch ($action) {
                case 'RegisterDomain':
                case 'TransferDomain':
                    $domains->create($params, $action === 'TransferDomain');

                    return ['success' => true];
                case 'RenewDomain':
                    $receipt = self::receipt($id, (string) $row->expirydate, Domains::years($params));
                    $store->locked(function () use ($store, $receipt, $params): void {
                        $state = $store->read();
                        $state['pending_renewals'][$receipt] ??= ['years' => Domains::years($params), 'invoice_id' => self::$invoiceId];
                        $thisYear = $state['pending_renewals'][$receipt]['years'];
                        if ($thisYear !== Domains::years($params)) {
                            throw new \RuntimeException('Diese Zahlung ist bereits einer anderen Domainlaufzeit zugeordnet.');
                        }
                        $store->write($state);
                    });
                    self::renewPending($domains, $store, $params);

                    return ['success' => true];
                case 'RetryRenewals':
                    self::renewPending($domains, $store, $params);

                    return ['success' => true];
                case 'Sync': return $domains->sync($params);
                case 'TransferSync': return $domains->sync($params, true);
                case 'GetNameservers': return $domains->getNameservers($params);
                case 'SaveNameservers': $domains->saveNameservers($params);

                    return ['success' => true];
                case 'GetContactDetails': return $domains->contacts($params);
                case 'SaveContactDetails': $domains->saveContacts($params);

                    return ['success' => true];
                case 'GetEPPCode': return $domains->epp($params);
                case 'GetDNS': return $domains->dns($params);
                case 'SaveDNS': $domains->saveDns($params);

                    return ['success' => true];
                default: throw new \InvalidArgumentException('Registrar-Aktion nicht unterstützt.');
            }
        } catch (\Throwable $error) {
            return ['error' => Module::error($error)];
        }
    }

    private static function renewPending(Domains $domains, WhmcsStore $store, array $params): void
    {
        foreach ($store->read()['pending_renewals'] ?? [] as $receipt => $entry) {
            if ($entry['invoice_id'] !== null && ! Invoices::isPaid((int) $entry['invoice_id'])) {
                throw new \RuntimeException('Gespeicherte Verlängerungsrechnung ist nicht mehr bezahlt. Buchung administrativ prüfen.');
            }
            $domains->renew([...$params, 'regperiod' => $entry['years']], $receipt);
            $store->locked(function () use ($store, $receipt): void {
                $state = $store->read();
                unset($state['pending_renewals'][$receipt]);
                $store->write($state);
            });
        }
    }

    private static function receipt(int $domainId, string $expiry, int $years): string
    {
        if (self::$invoiceId !== null) {
            $paid = Capsule::table('tblinvoiceitems as item')->join('tblinvoices as invoice', 'invoice.id', '=', 'item.invoiceid')
                ->where('item.invoiceid', self::$invoiceId)->where('item.relid', $domainId)->where('item.type', 'Domain')
                ->where('invoice.status', 'Paid')->exists();
            if (! $paid) {
                throw new \RuntimeException('Keine bezahlte Domain-Verlängerung in diesem Rechnungsvorgang.');
            }

            return 'invoice:'.self::$invoiceId;
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $expiry) || $expiry === '0000-00-00') {
            throw new \RuntimeException('Vor einer manuellen Verlängerung Domainablaufdatum synchronisieren.');
        }

        return 'manual:'.$expiry.':'.$years;
    }

    public static function cron(): void
    {
        Domain::where('registrar', 'vexuradomains')->whereIn('status', ['Active', 'Expired', 'Pending Registration', 'Pending Transfer'])
            ->chunkById(50, static function ($domains): void {
                foreach ($domains as $domain) {
                    try {
                        $state = (new WhmcsStore('vexuradomains', (int) $domain->id))->read();
                        if (empty($state['pending_renewals'])) {
                            continue;
                        }
                        $result = $domain->getRegistrarInterface()->call('RetryRenewals', ['domainid' => (int) $domain->id, 'domainname' => (string) $domain->domain]);
                        if (! is_array($result) || isset($result['error'])) {
                            throw new \RuntimeException(is_array($result) ? (string) $result['error'] : 'Registrar-Abgleich fehlgeschlagen.');
                        }
                    } catch (\Throwable $error) {
                        logActivity('Vexura: Domain #'.$domain->id.' – '.Module::error($error));
                    }
                }
            });
    }

    /** Premium domains and unsupported/multi-label TLDs are never silently sold as standard domains. */
    public static function availability(array $params): ResultsList|array
    {
        try {
            $api = self::api($params);
            $catalog = $api->request('GET', '/domain-subscriptions/order-data');
            $supported = array_column($catalog['tlds'] ?? [], 'tld');
            $sld = (string) (($params['punyCodeSearchTerm'] ?? '') ?: ($params['searchTerm'] ?? ''));
            $results = new ResultsList;
            foreach ($params['tldsToInclude'] ?? [] as $tld) {
                $tld = ltrim((string) $tld, '.');
                $result = new SearchResult($sld, $tld);
                if (str_contains($tld, '.') || ! in_array($tld, $supported, true)) {
                    $result->setStatus(SearchResult::STATUS_TLD_NOT_SUPPORTED);
                } else {
                    $name = Domains::name(['sld' => $sld, 'tld' => $tld]);
                    $data = Profiles::value($api->request('POST', '/domain-subscriptions/check-availability', ['domain' => $name]), 'data');
                    if (! is_bool($data['available'] ?? null)) {
                        throw new \RuntimeException('Domain-Verfügbarkeit wurde nicht bestätigt.');
                    }
                    $result->setStatus(! empty($data['premium']) ? SearchResult::STATUS_RESERVED : ($data['available'] ? SearchResult::STATUS_NOT_REGISTERED : SearchResult::STATUS_REGISTERED));
                }
                $results->append($result);
            }

            return $results;
        } catch (\Throwable $error) {
            return ['error' => Module::error($error)];
        }
    }
}
