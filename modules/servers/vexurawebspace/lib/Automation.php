<?php

declare(strict_types=1);

namespace Vexura\Whmcs\Webspace;

use WHMCS\Database\Capsule;

/** Hooks are packaged inside every module and scoped to its own WHMCS server type. */
final class Automation
{
    public static function snapshot(string $slug, int $invoiceId): void
    {
        $profile = Profiles::get($slug);
        foreach (self::services($profile['module'], $invoiceId) as $row) {
            try {
                $months = Profiles::months($row->billingcycle);
                $store = new WhmcsStore($profile['module'], (int) $row->id);
                $store->locked(function () use ($store, $invoiceId, $months): void {
                    $state = $store->read();
                    $state['invoice_terms'][$invoiceId] ??= $months;
                    $store->write($state);
                });
            } catch (\Throwable $error) {
                logActivity('Vexura: Rechnung #'.$invoiceId.' / Dienst #'.$row->id.' – '.Module::error($error));
            }
        }
    }

    public static function paid(string $slug, int $invoiceId): void
    {
        $profile = Profiles::get($slug);
        if (! Invoices::isPaid($invoiceId)) {
            return;
        }
        foreach (self::services($profile['module'], $invoiceId) as $row) {
            self::reconcile($profile, (int) $row->id);
        }
    }

    public static function cron(string $slug): void
    {
        $profile = Profiles::get($slug);
        // Distributed per-module cron lock prevents overlapping API floods across WHMCS nodes.
        $name = 'vexura:cron:'.$profile['module'];
        $lock = Capsule::connection()->select('SELECT GET_LOCK(?, 0) AS acquired', [$name]);
        if ((int) ($lock[0]->acquired ?? 0) !== 1) {
            return;
        }
        try {
            Capsule::table('tblhosting as h')->join('tblproducts as p', 'p.id', '=', 'h.packageid')
                ->where('p.servertype', $profile['module'])->whereIn('h.domainstatus', ['Pending', 'Active', 'Suspended'])
                ->select('h.id')->orderBy('h.id')->chunk(50, static function ($rows) use ($profile): void {
                    foreach ($rows as $row) {
                        self::reconcile($profile, (int) $row->id);
                    }
                });
        } finally {
            Capsule::connection()->select('SELECT RELEASE_LOCK(?)', [$name]);
        }
    }

    private static function services(string $module, int $invoiceId): iterable
    {
        return Capsule::table('tblinvoiceitems as item')->join('tblhosting as h', 'h.id', '=', 'item.relid')
            ->join('tblproducts as p', 'p.id', '=', 'h.packageid')->where('item.invoiceid', $invoiceId)
            ->where('item.type', 'Hosting')->where('p.servertype', $module)->select(['h.id', 'h.billingcycle'])->distinct()->get();
    }

    private static function reconcile(array $profile, int $serviceId): void
    {
        try {
            $state = (new WhmcsStore($profile['module'], $serviceId))->read();
            if (! isset($state['initial_months']) || ! empty($state['terminated'])) {
                return;
            }
            $result = empty($state['ready'])
                ? localAPI('ModuleCreate', ['serviceid' => $serviceId])
                : localAPI('ModuleCustom', ['serviceid' => $serviceId, 'func_name' => 'SyncPaidInvoices']);
            if (($result['result'] ?? '') !== 'success') {
                logActivity('Vexura: Dienst #'.$serviceId.' – '.(string) ($result['message'] ?? 'Abgleich fehlgeschlagen. Modulprotokoll prüfen.'));
            }
        } catch (\Throwable $error) {
            logActivity('Vexura: Dienst #'.$serviceId.' – '.Module::error($error));
        }
    }
}
