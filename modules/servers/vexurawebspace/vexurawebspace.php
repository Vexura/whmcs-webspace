<?php
declare(strict_types=1);
if (!defined('WHMCS')) { exit('Direct access is not permitted.'); }
require_once __DIR__.'/bootstrap.php';
use Vexura\Whmcs\Webspace\Module;
use Vexura\Whmcs\Webspace\Registrar;
use Vexura\Whmcs\Webspace\Automation;

/** Webspace: WHMCS MetaData callback. */
function vexurawebspace_MetaData(): array
{
    return ['DisplayName' => 'Vexura · Webspace', 'APIVersion' => '1.1', 'RequiresServer' => true, 'DefaultSSLPort' => 443];
}

/** Webspace: WHMCS ConfigOptions callback. */
function vexurawebspace_ConfigOptions(): array
{
    return Module::config('webspace');
}

/** Webspace: WHMCS TestConnection callback. */
function vexurawebspace_TestConnection(array $params): array
{
    return Module::connection($params);
}

/** Webspace: WHMCS ClientArea callback. */
function vexurawebspace_ClientArea(array $params): array
{
    return Module::clientArea('webspace', $params);
}

/** Webspace: WHMCS AdminCustomButtonArray callback. */
function vexurawebspace_AdminCustomButtonArray(): array
{
    return ['Bereitstellung prüfen' => 'RefreshStatus', 'Bezahlte Rechnungen abgleichen' => 'SyncPaidInvoices'];
}

/** Webspace: WHMCS CreateAccount callback. */
function vexurawebspace_CreateAccount(array $params): string
{
    return Module::run('webspace', 'create', $params);
}

/** Webspace: WHMCS RefreshStatus callback. */
function vexurawebspace_RefreshStatus(array $params): string
{
    return Module::run('webspace', 'create', $params);
}

/** Webspace: WHMCS SyncPaidInvoices callback. */
function vexurawebspace_SyncPaidInvoices(array $params): string
{
    return Module::run('webspace', 'sync', $params);
}

/** Webspace: WHMCS TerminateAccount callback. */
function vexurawebspace_TerminateAccount(array $params): string
{
    return Module::run('webspace', 'terminate', $params);
}

/** Webspace: WHMCS SuspendAccount callback. */
function vexurawebspace_SuspendAccount(array $params): string
{
    return Module::run('webspace', 'suspend', $params);
}

/** Webspace: WHMCS UnsuspendAccount callback. */
function vexurawebspace_UnsuspendAccount(array $params): string
{
    return Module::run('webspace', 'unsuspend', $params);
}
