<?php
declare(strict_types=1);
if (!defined('WHMCS')) { exit('Direct access is not permitted.'); }
spl_autoload_register(static function (string $class): void {
    $prefix = 'Vexura\\Whmcs\\Webspace\\';
    if (str_starts_with($class, $prefix)) {
        $name = substr($class, strlen($prefix));
        if (preg_match('/^[A-Za-z]+$/D', $name)) {
            $path = __DIR__.'/lib/'.$name.'.php';
            if (is_file($path)) { require_once $path; }
        }
    }
});
