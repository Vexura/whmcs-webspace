<?php
declare(strict_types=1);
if (!defined('WHMCS')) { exit('Direct access is not permitted.'); }
require_once __DIR__.'/bootstrap.php';
use Vexura\Whmcs\Webspace\Module;
use Vexura\Whmcs\Webspace\Registrar;
use Vexura\Whmcs\Webspace\Automation;

add_hook('InvoiceCreated', 1, static function (array $vars): void { Automation::snapshot('webspace', (int) $vars['invoiceid']); });
add_hook('InvoicePaid', 1, static function (array $vars): void { Automation::paid('webspace', (int) $vars['invoiceid']); });
add_hook('AfterCronJob', 1, static function (array $vars): void { Automation::cron('webspace'); });
