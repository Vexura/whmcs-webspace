<?php

declare(strict_types=1);

namespace Vexura\Whmcs\Webspace;

/** Durable, exclusively locked journal for one WHMCS service or domain. */
interface StateStore
{
    public function read(): array;

    public function write(array $state): void;

    public function locked(\Closure $operation): mixed;
}
