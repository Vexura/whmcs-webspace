<?php

declare(strict_types=1);

namespace Vexura\Whmcs\Webspace;

use WHMCS\Database\Capsule;

/** Private encrypted journal; no state/custom fields are exposed on customer invoices. */
final class WhmcsStore implements StateStore
{
    public function __construct(private readonly string $module, private readonly int $id)
    {
        if ($id < 1 || ! preg_match('/^vexura[a-z0-9]+$/D', $module)) {
            throw new \InvalidArgumentException('WHMCS-Dienstzuordnung fehlt.');
        }
        Capsule::connection()->statement('CREATE TABLE IF NOT EXISTS mod_vexura_whmcs (module VARCHAR(64) NOT NULL, item_id INT UNSIGNED NOT NULL, state LONGTEXT NOT NULL, PRIMARY KEY (module, item_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    public function read(): array
    {
        $raw = Capsule::table('mod_vexura_whmcs')->where('module', $this->module)->where('item_id', $this->id)->value('state');

        return $raw === null ? [] : json_decode(decrypt($raw), true, 512, JSON_THROW_ON_ERROR);
    }

    public function write(array $state): void
    {
        Capsule::table('mod_vexura_whmcs')->updateOrInsert(['module' => $this->module, 'item_id' => $this->id], ['state' => encrypt(json_encode($state, JSON_THROW_ON_ERROR))]);
    }

    public function locked(\Closure $operation): mixed
    {
        $name = 'vexura:'.substr(hash('sha256', $this->module.':'.$this->id), 0, 48);
        $connection = Capsule::connection();
        $lock = $connection->select('SELECT GET_LOCK(?, 0) AS acquired', [$name]);
        if ((int) ($lock[0]->acquired ?? 0) !== 1) {
            throw new \RuntimeException('Für diesen Dienst läuft bereits eine Aktion.');
        }
        try {
            return $operation();
        } finally {
            $connection->select('SELECT RELEASE_LOCK(?)', [$name]);
        }
    }
}
