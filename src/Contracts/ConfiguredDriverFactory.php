<?php

namespace GameNest\GameNestModManager\Contracts;

use App\Models\Server;
use GameNest\GameNestModManager\Adapters\ConfiguredGameAdapter;

/** Trusted provider registration only; game JSON cannot name or instantiate factories. */
interface ConfiguredDriverFactory
{
    public function create(Server $server, ConfiguredGameAdapter $adapter, string $source): LifecycleDriver;
}
