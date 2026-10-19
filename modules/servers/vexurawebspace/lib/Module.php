<?php

declare(strict_types=1);

namespace Vexura\Whmcs\Webspace;

/** WHMCS provisioning callbacks. API keys remain in WHMCS's encrypted server password. */
final class Module
{
    public static function config(string $slug): array
    {
        $profile = Profiles::get($slug);
        $options = [
            'API-Adresse' => ['Type' => 'text', 'Size' => '60', 'Default' => 'https://api.vexura.eu/api/v1', 'Description' => 'Bearer-Key im Passwortfeld des zugeordneten Servers. Scopes: read:products, write:products, read:credentials, read:billing, write:billing.'],
            'Einkaufslimit pro Monat (EUR)' => ['Type' => 'text', 'Size' => '12', 'Description' => 'Pflichtfeld. Maximale Vexura-Kosten pro gebuchtem Monat; keine WHMCS-Verkaufspreise.'],
            'Einrichtungslimit (EUR)' => ['Type' => 'text', 'Size' => '12', 'Default' => '0', 'Description' => 'Zusätzlich einmalig erlaubte Einrichtungskosten.'],
            'Bestellbedingungen akzeptiert' => ['Type' => 'yesno', 'Description' => 'Ich akzeptiere die Vexura-Vertragsbedingungen und den sofortigen Leistungsbeginn für diese Bestellungen.'],
            'Weitere Bestellvorgaben (JSON)' => ['Type' => 'textarea', 'Rows' => '4', 'Cols' => '60', 'Default' => '{}', 'Description' => 'Katalog-IDs aus der Vexura API-Dokumentation. Monatlich, vierteljährlich, halbjährlich oder jährlich; Prorata deaktivieren. WHMCS-Cron alle 5 Minuten. Kündigung beendet Verlängerungen; Löschung erfolgt nach gebuchter Laufzeit.'],
        ];
        foreach ($profile['fields'] as $definition) {
            [$name, $type] = array_pad(explode(':', rtrim($definition, '?')), 2, 'string');
            $options[$name] = ['Type' => $type === 'json' || $name === 'csr' ? 'textarea' : 'text', 'Size' => '40', 'Rows' => '3', 'Description' => (str_ends_with($definition, '?') ? 'Optional' : 'Pflichtfeld').($type === 'json' ? ' · JSON' : '')];
        }

        return $options;
    }

    public static function api(array $params): Api
    {
        return new Api((string) ($params['serverpassword'] ?? ''), (string) (($params['configoption1'] ?? '') ?: 'https://api.vexura.eu/api/v1'));
    }

    public static function connection(array $params): array
    {
        try {
            $account = Profiles::value(self::api($params)->request('GET', '/account'), 'data');
            if (($account['currency'] ?? '') !== 'EUR') {
                throw new \RuntimeException('Das Modul benötigt ein EUR-Prepaid-Konto.');
            }
            $missing = array_diff(['read:products', 'write:products', 'read:credentials', 'read:billing', 'write:billing'], $account['scopes'] ?? []);
            if ($missing !== []) {
                throw new \RuntimeException('API-Key benötigt diese Scopes: '.implode(', ', $missing));
            }

            return ['success' => true];
        } catch (\Throwable $error) {
            return ['success' => false, 'error' => self::error($error)];
        }
    }

    public static function run(string $slug, string $action, array $params): string
    {
        try {
            $profile = Profiles::get($slug);
            $context = Invoices::context($params, $profile);
            $store = new WhmcsStore($profile['module'], (int) $params['serviceid']);
            $service = new Service(self::api($params), $store, $profile);
            $state = $store->read();
            if ($action === 'terminate') {
                $service->terminate();

                return 'success';
            }
            if ($action === 'suspend' || $action === 'unsuspend') {
                throw new \RuntimeException('Vexura hat keinen generischen Sperr-/Entsperr-Endpunkt. Der Dienst läuft bis zum bezahlten Ablaufdatum; WHMCS Auto-Suspend für diese Produkte deaktivieren.');
            }
            if (! in_array($context['status'], ['Pending', 'Active', 'Suspended'], true)) {
                throw new \RuntimeException('Dieser WHMCS-Dienst darf nicht bestellt oder verlängert werden.');
            }
            if (! in_array($params['configoption4'] ?? '', ['on', '1', 1, true], true)) {
                throw new \RuntimeException('Vexura-Bestellbedingungen müssen in der Modulkonfiguration akzeptiert werden.');
            }
            $monthlyLimit = Profiles::money(str_replace(',', '.', (string) ($params['configoption2'] ?? '')));
            $setupLimit = Profiles::money(str_replace(',', '.', (string) (($params['configoption3'] ?? '') ?: '0')));
            if ($action === 'create' && empty($state['ready'])) {
                if (! Invoices::isPaid($context['initial_invoice'])) {
                    throw new \RuntimeException('Die Erstbestellung wird erst nach Zahlung der WHMCS-Erstrechnung ausgeführt.');
                }
                if (isset($state['initial_months']) && $state['initial_months'] !== $context['months']) {
                    throw new \RuntimeException('Abrechnungszyklus wurde während der Bereitstellung geändert. Auftrag administrativ abgleichen.');
                }
                $body = Profiles::input($profile, $params, $context['months']);
                $service->create($body, $context['months'], $monthlyLimit, $setupLimit, $context['initial_invoice']);
            } elseif (empty($state['ready'])) {
                throw new \RuntimeException('Zuerst die bereits begonnene Bereitstellung prüfen.');
            }
            $state = $store->read();
            foreach (Invoices::paidRenewals((int) $params['serviceid'], $context['initial_invoice']) as $invoiceId) {
                if (isset($state['paid'][$invoiceId])) {
                    continue;
                }
                $months = $state['invoice_terms'][$invoiceId] ?? null;
                if (! is_int($months)) {
                    throw new \RuntimeException('Rechnung #'.$invoiceId.' hat keine gespeicherte Laufzeit. Vexura-Rechnungs-Hook prüfen; nicht automatisch nach aktuellem Tarif abbuchen.');
                }
                $service->renew((int) $invoiceId, $months, $monthlyLimit);
            }

            return 'success';
        } catch (\Throwable $error) {
            return self::error($error);
        }
    }

    public static function clientArea(string $slug, array $params): array
    {
        $profile = Profiles::get($slug);
        $vars = ['vxName' => $profile['name'], 'vxRows' => [], 'vxError' => '', 'vxSecrets' => [], 'vxControls' => [], 'vxNotice' => '', 'vxServiceId' => (int) ($params['serviceid'] ?? 0)];
        try {
            $context = Invoices::context($params, $profile);
            $store = new WhmcsStore($profile['module'], (int) $params['serviceid']);
            $api = self::api($params);
            $service = new Service($api, $store, $profile);
            $service->available();
            $state = $store->read();
            if (empty($state['id'])) {
                throw new \RuntimeException('Dein Dienst wird bereitgestellt. Die Anzeige aktualisiert sich nach dem nächsten Statusabgleich.');
            }
            $remote = $service->detail($state);
            $remote['expires_at'] = $remote['expires_at'] ?? $remote['expired_at'] ?? $state['expires_at'] ?? null;
            foreach (['name' => 'Name', 'domain' => 'Domain', 'hostname' => 'Hostname', 'status' => 'Status', 'ip_address' => 'IP-Adresse', 'connect_address' => 'Verbindung', 'port' => 'Port', 'expires_at' => 'Bezahlt bis'] as $key => $label) {
                if (isset($remote[$key]) && is_scalar($remote[$key])) {
                    $vars['vxRows'][] = ['label' => $label, 'value' => (string) $remote[$key]];
                }
            }
            $vars['vxNotice'] = ! empty($state['terminated']) ? 'Die Verlängerung ist beendet. Der Dienst läuft bis zum gebuchten Ablaufdatum.' : 'Prepaid: Verlängerung nach Zahlung deiner Rechnung. Ohne Verlängerung endet der Dienst zum gebuchten Ablaufdatum.';
            $controls = self::controls($slug);
            if ($context['status'] === 'Active' && empty($state['terminated'])) {
                $vars['vxControls'] = $controls;
            }
            if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['vx_action'])) {
                check_token('WHMCS.default');
                $action = $_POST['vx_action'];
                if (! is_string($action) || ! isset($vars['vxControls'][$action]) || ($_POST['vx_confirm'] ?? '') !== '1') {
                    throw new \RuntimeException('Aktion nicht erlaubt oder nicht bestätigt.');
                }
                $control = $controls[$action];
                $path = $profile['path'].'/'.Service::uuid($state['id']).$control['path'];
                if ($control['method'] === 'GET') {
                    header('Cache-Control: no-store, private');
                    $vars['vxSecrets'] = self::flatten($api->request('GET', $path));
                } else {
                    $store->locked(function () use ($store, $api, $action, $path, $control): void {
                        $state = $store->read();
                        $operation = 'control:'.$action;
                        $journal = new Journal($api, $store);
                        $journal->hasRequest($state, $operation);
                        $journal->send($state, $operation, $control['method'], $path, $control['body']);
                        unset($state['requests'][$operation]);
                        $store->write($state);
                    });
                    $vars['vxNotice'] = 'Aktion angenommen. Der Produktstatus wird nach der Ausführung aktualisiert.';
                }
            }
            if ($slug === 'ssl' && ! empty($remote['dcv'])) {
                $vars['vxSecrets'] = self::flatten(['Domainvalidierung' => $remote['dcv']]);
            }
        } catch (\Throwable $error) {
            $vars['vxError'] = self::error($error);
            $vars['vxControls'] = [];
        }

        return ['templatefile' => 'clientarea', 'tabOverviewReplacementTemplate' => 'clientarea.tpl', 'vars' => $vars];
    }

    private static function controls(string $slug): array
    {
        $controls = [];
        if (in_array($slug, ['rootserver', 'gameserver', 'vpn', 'dedicated', 'kubernetes', 'loadbalancer', 's3-storage', 'storagebox'], true)) {
            $controls['credentials'] = ['label' => 'Zugangsdaten anzeigen', 'method' => 'GET', 'path' => '/credentials'];
        }
        if ($slug === 'ip-tunnel') {
            $controls['credentials'] = ['label' => 'Konfiguration anzeigen', 'method' => 'GET', 'path' => '/configuration'];
        }
        if ($slug === 'rootserver') {
            foreach (['start' => 'Starten', 'stop' => 'Stoppen', 'restart' => 'Neu starten'] as $action => $label) {
                $controls[$action] = ['label' => $label, 'method' => 'POST', 'path' => '/power', 'body' => ['action' => $action]];
            }
        }
        if ($slug === 'gameserver') {
            foreach (['start' => 'Starten', 'stop' => 'Stoppen', 'restart' => 'Neu starten'] as $action => $label) {
                $controls[$action] = ['label' => $label, 'method' => 'POST', 'path' => '/power', 'body' => ['action' => $action]];
            }
        }
        if ($slug === 'teamspeak') {
            foreach (['start' => 'Starten', 'stop' => 'Stoppen'] as $action => $label) {
                $controls[$action] = ['label' => $label, 'method' => 'POST', 'path' => '/'.$action, 'body' => []];
            }
        }

        return $controls;
    }

    private static function flatten(array $data, string $prefix = ''): array
    {
        $rows = [];
        foreach ($data as $key => $value) {
            $label = ltrim($prefix.'.'.$key, '.');
            if (is_array($value)) {
                $rows = array_merge($rows, self::flatten($value, $label));
            } elseif ($value !== null && is_scalar($value)) {
                $rows[] = ['label' => $label, 'value' => is_bool($value) ? ($value ? 'Ja' : 'Nein') : (string) $value];
            }
        }

        return $rows;
    }

    public static function error(\Throwable $error): string
    {
        if ($error instanceof ApiException || ($error instanceof \RuntimeException && get_class($error) === \RuntimeException::class) || $error instanceof \InvalidArgumentException) {
            return $error->getMessage();
        }

        return 'Modul konnte den Vorgang nicht bestätigen ('.get_class($error).'). Konfiguration und WHMCS-Verbindung prüfen; gespeicherten Auftrag beibehalten.';
    }
}
