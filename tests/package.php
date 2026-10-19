<?php

declare(strict_types=1);
use WHMCS\Database\Capsule;

error_reporting(E_ALL & ~E_DEPRECATED);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (! (error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
require __DIR__.'/FakeWhmcs.php';
define('WHMCS', true);
$directory = realpath($argv[1]);
$manifest = json_decode(file_get_contents($directory.'/module.json'), true, 512, JSON_THROW_ON_ERROR);
$module = $manifest['module'];
verify((bool) preg_match('/^vexura[a-z0-9]+$/D', $module), 'Unsafe module name');
$slug = $argv[2];
$namespace = implode('', array_map('ucfirst', explode('-', $slug)));
eval('namespace Vexura\\Whmcs\\'.$namespace.'; function curl_init() { return \\curl_init(); } function curl_setopt_array($handle, $options) { \\CurlBoundary::$options = $options; return true; } function curl_exec($handle) { return \\CurlBoundary::execute($handle); } function curl_getinfo($handle, $option) { return \\CurlBoundary::$status; }');
require $directory.'/'.$module.'.php';
require $directory.'/hooks.php';
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        PhpToken::tokenize(file_get_contents($file->getPathname()), TOKEN_PARSE);
    }
}
$GLOBALS['moduleName'] = $module;
$profilesClass = 'Vexura\\Whmcs\\'.$namespace.'\\Profiles';
$storeClass = 'Vexura\\Whmcs\\'.$namespace.'\\WhmcsStore';
$profile = $slug === 'domains' ? null : $profilesClass::get($slug);
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
Capsule::$pdo = $pdo;
$pdo->exec('CREATE TABLE tblhosting (id INTEGER, packageid INTEGER, orderid INTEGER, billingcycle TEXT, domainstatus TEXT); CREATE TABLE tblproducts (id INTEGER, servertype TEXT, proratabilling INTEGER); CREATE TABLE tblorders (id INTEGER, invoiceid INTEGER); CREATE TABLE tblinvoices (id INTEGER, status TEXT); CREATE TABLE tblinvoiceitems (id INTEGER, invoiceid INTEGER, type TEXT, relid INTEGER); CREATE TABLE tbldomains (id INTEGER, domain TEXT, registrar TEXT, expirydate TEXT, status TEXT);');
$pdo->exec("INSERT INTO tblhosting VALUES (1,1,1,'Annually','Pending'); INSERT INTO tblorders VALUES (1,10); INSERT INTO tblinvoices VALUES (10,'Unpaid'),(11,'Unpaid'); INSERT INTO tblinvoiceitems VALUES (1,10,'Hosting',1),(2,11,'Hosting',1); INSERT INTO tbldomains VALUES (1,'example.de','vexuradomains','2027-01-15','Active');");
$statement = $pdo->prepare('INSERT INTO tblproducts VALUES (1,?,0)');
$statement->execute([$module]);
$order = '11111111-1111-4111-8111-111111111111';
$product = '22222222-2222-4222-8222-222222222222';
$subscription = '33333333-3333-4333-8333-333333333333';
$expiry = '2027-01-15T12:00:00+00:00';
$creates = 0;
$renewed = 0;
$pending = true;
$receipts = [];
CurlBoundary::$handler = static function ($method, $url, $headers, $raw) use (&$expiry, &$creates, &$renewed, &$pending, &$receipts, $profile, $slug, $order, $product, $subscription): array {
    $path = substr(parse_url($url, PHP_URL_PATH), 7);
    $body = $raw === null ? [] : json_decode($raw, true);
    parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $query);
    $key = null;
    foreach ($headers as $header) {
        if (str_starts_with($header, 'Idempotency-Key: ')) {
            $key = substr($header, 17);
        }
    }
    if ($key !== null && isset($receipts[$key])) {
        return $receipts[$key];
    }
    $data = match (true) {
        $path === '/account' => ['data' => ['team' => ['id' => $order], 'currency' => 'EUR', 'scopes' => ['read:products', 'write:products', 'read:credentials', 'read:billing', 'write:billing']]],
        $path === '/products' => ['data' => [['slug' => $profile['product'] ?? 'domains', 'orderable' => true]]],
        $path === '/domain-handles' => ['handle' => ['id' => $order]],
        $path === '/domain-subscriptions/quote' => ['pricing' => ['amount' => 10]],
        $path === '/domain-subscriptions/order' => ['domain' => ['id' => $product, 'subscription_id' => $subscription, 'expires_at' => $expiry]],
        $path === '/domain-subscriptions/'.$product => ['domain' => ['id' => $product, 'domain' => 'example.de', 'status' => 'active', 'expires_at' => $expiry], 'api' => ['status' => 'active']],
        $path === '/orders/'.$order => ['data' => ['status' => $pending ? 'processing' : 'completed', 'products' => [['id' => $product, 'subscription_id' => $subscription, 'type' => $profile['type']]]]],
        $path === '/subscriptions/'.$subscription.'/auto-renew' => ['data' => ['auto_renew' => false, 'expires_at' => $expiry]],
        str_starts_with($path, '/subscriptions/') && str_ends_with($path, '/quote') => ['data' => ['total' => 10 * (int) ($query['months'] ?? $query['years']), 'expires_at' => $expiry]],
        default => null,
    };
    if ($profile !== null && $path === $profile['orderPath'].'/quote') {
        $data = match ($profile['price']) {
            'amount' => ['amount' => 10], 'pricing.total' => ['pricing' => ['total' => 10]], 'pricing.amount' => ['pricing' => ['amount' => 10]], 'resource.total' => ['resource' => ['total' => 10]], 'provider' => ['price_monthly' => 8, 'price_setup' => 2]
        };
    }
    if ($profile !== null && $path === $profile['orderPath'].'/order') {
        $creates++;
        $data = $profile['price'] === 'resource.total' ? ['resource' => ['order_id' => $order]] : ['order_id' => $order];
    }
    if ($profile !== null && $path === $profile['path'].'/'.$product) {
        $data = [$profile['wrapper'] => ['id' => $product, 'name' => 'Customer <script>', 'status' => 'active', 'expires_at' => $expiry, 'renewal_pending' => false, 'renewal_options' => [['period_months' => 12, 'price' => 100]]]];
    }
    if (str_ends_with($path, '/renew')) {
        $months = (int) ($body['months'] ?? $body['period_months'] ?? (12 * ($body['years'] ?? 0)));
        verify($body['expected_expires_at'] === $expiry, 'Stale expiry in packaged request');
        $renewed += $months;
        $expiry = (new DateTimeImmutable($expiry))->modify('+'.$months.' months')->format(DATE_ATOM);
        $data = $slug === 'domains' ? ['expires_at' => $expiry, 'charged' => $body['expected_amount']] : ($slug === 'ssl' ? ['renewal' => ['status' => 'completed', 'id' => $order]] : ['data' => ['expires_at' => $expiry]]);
    }
    verify($data !== null, 'Unexpected packaged HTTP request '.$method.' '.$path);
    $response = [200, [], json_encode($data, JSON_THROW_ON_ERROR)];
    if ($method !== 'GET' && ! str_ends_with($path, '/quote')) {
        verify($key !== null, 'Missing packaged idempotency');
        $receipts[$key] = $response;
    }

    return $response;
};

if ($slug !== 'domains') {
    $body = [];
    foreach ($profile['fields'] as $definition) {
        [$name, $type] = array_pad(explode(':', rtrim($definition, '?')), 2, 'string');
        $body[$name] = $type === 'int' ? 2 : ($type === 'json' ? [] : 'fixture');
    }
    $body['tier'] = 'premium';
    $params = ['serviceid' => 1, 'pid' => 1, 'domain' => 'example.test', 'serverpassword' => 'fixture-token', 'configoption1' => 'https://api.vexura.eu/api/v1', 'configoption2' => '20', 'configoption3' => '5', 'configoption4' => 'on', 'configoption5' => json_encode($body)];
    $GLOBALS['moduleParams'] = $params;
    verify(count(($module.'_ConfigOptions')()) <= 24, 'WHMCS config option limit');
    verify(($module.'_TestConnection')($params)['success'] === true, 'Connection callback');
    verify(str_contains(($module.'_CreateAccount')($params), 'Erstrechnung'), 'Unpaid initial invoice provisioned');
    verify($creates === 0, 'Unpaid purchase');
    event('InvoiceCreated', ['invoiceid' => 10]);
    event('InvoiceCreated', ['invoiceid' => 11]);
    $pdo->exec("UPDATE tblinvoices SET status='Paid' WHERE id=10");
    verify(str_contains(($module.'_CreateAccount')($params), 'Bearbeitung'), 'Pending order marked ready');
    $pending = false;
    event('AfterCronJob');
    verify($pdo->query('SELECT domainstatus FROM tblhosting WHERE id=1')->fetchColumn() === 'Active', 'Cron did not finish pending module provisioning');
    verify($creates === 1, 'Cron repurchased service');
    $initialRenewed = $renewed;
    event('InvoicePaid', ['invoiceid' => 11]);
    verify($renewed === $initialRenewed, 'Unpaid invoice renewed');
    $pdo->exec("UPDATE tblinvoices SET status='Paid' WHERE id=11");
    event('InvoicePaid', ['invoiceid' => 11]);
    event('InvoicePaid', ['invoiceid' => 11]);
    event('AfterCronJob');
    verify($renewed === $initialRenewed + 12, 'InvoicePaid/Cron duplicate or missing renewal');
    $area = ($module.'_ClientArea')($params);
    verify($area['vars']['vxError'] === '', 'ClientArea failed: '.$area['vars']['vxError']);
    verify(str_contains(file_get_contents($directory.'/clientarea.tpl'), "escape:'html'"), 'Missing HTML escaping');
    verify(str_contains(($module.'_SuspendAccount')($params), 'keinen generischen'), 'Fake suspension success');
    verify(($module.'_TerminateAccount')($params) === 'success', 'Termination did not disable renewal');
    $before = $renewed;
    event('AfterCronJob');
    verify($renewed === $before, 'Terminated service renewed');
} else {
    $params = ['domainid' => 1, 'domainname' => 'example.de', 'regperiod' => 1, 'YearlyLimit' => '20', 'AcceptTerms' => 'on', 'ApiKey' => 'fixture-token'];
    $GLOBALS['moduleParams'] = $params;
    verify(vexuradomains_RegisterDomain($params)['success'] === true, 'Domain registration callback');
    $pdo->exec("UPDATE tblinvoices SET status='Paid' WHERE id=11; INSERT INTO tblinvoiceitems VALUES (3,11,'Domain',1)");
    event('InvoicePaidPreEmail', ['invoiceid' => 11]);
    verify(vexuradomains_RenewDomain($params)['success'] === true, 'Registrar renewal callback');
    verify(vexuradomains_RenewDomain($params)['success'] === true, 'Registrar retry callback');
    verify($renewed === 12, 'Registrar invoice charged twice');
    event('InvoicePaid', ['invoiceid' => 11]);
    event('AfterCronJob');
    verify(vexuradomains_Sync($params)['active'] === true, 'Registrar synchronization');
    verify(! function_exists('vexuradomains_SaveRegistrarLock'), 'Invented registrar lock');
}
$rawStates = $pdo->query('SELECT state FROM mod_vexura_whmcs')->fetchAll(PDO::FETCH_COLUMN);
foreach ($rawStates as $state) {
    verify(str_starts_with($state, 'test-encrypted:'), 'Journal not encrypted');
    verify(! str_contains($state, 'fixture-token'), 'API key persisted');
}
foreach (get_included_files() as $file) {
    verify(! str_contains($file, '/whmcs/src/'), 'Packaged module loaded shared development source');
}
verify(Capsule::$locks === [], 'Advisory lock leaked');
echo $module.": isolated install, callbacks, invoice hooks, cron and encrypted journal passed\n";
