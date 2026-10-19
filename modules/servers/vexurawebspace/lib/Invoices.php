<?php

declare(strict_types=1);

namespace Vexura\Whmcs\Webspace;

use WHMCS\Database\Capsule;

/** Only complete, paid Hosting invoice items can purchase another prepaid term. */
final class Invoices
{
    public static function context(array $params, array $profile): array
    {
        $row = Capsule::table('tblhosting as h')->join('tblproducts as p', 'p.id', '=', 'h.packageid')
            ->join('tblorders as o', 'o.id', '=', 'h.orderid')->where('h.id', (int) ($params['serviceid'] ?? 0))
            ->where('p.servertype', $profile['module'])->select(['h.billingcycle', 'h.domainstatus', 'p.proratabilling', 'o.invoiceid'])->first();
        if ($row === null || (int) ($params['addonid'] ?? 0) > 0) {
            throw new \RuntimeException('Das Modul benötigt einen eigenständigen, korrekt zugeordneten WHMCS-Dienst.');
        }
        if ($row->proratabilling) {
            throw new \RuntimeException('Anteiliges Billing deaktivieren: Vexura bucht vollständige Prepaid-Laufzeiten.');
        }

        return ['months' => Profiles::months((string) $row->billingcycle), 'initial_invoice' => (int) $row->invoiceid, 'status' => (string) $row->domainstatus];
    }

    public static function isPaid(int $invoiceId): bool
    {
        return $invoiceId > 0 && Capsule::table('tblinvoices')->where('id', $invoiceId)->where('status', 'Paid')->exists();
    }

    public static function paidRenewals(int $serviceId, int $initialInvoice): array
    {
        return Capsule::table('tblinvoiceitems as item')->join('tblinvoices as invoice', 'invoice.id', '=', 'item.invoiceid')
            ->where('item.type', 'Hosting')->where('item.relid', $serviceId)->where('invoice.status', 'Paid')
            ->where('invoice.id', '>', $initialInvoice)->orderBy('invoice.id')->distinct()->pluck('invoice.id')->all();
    }
}
