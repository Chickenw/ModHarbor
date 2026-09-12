<?php

namespace GameNest\GameNestModManager\Services;

use App\Models\Server;
use GameNest\GameNestModManager\Adapters\ConfiguredGameAdapter;
use GameNest\GameNestModManager\Contracts\{ConfiguredDriverFactory, LifecycleDriver};

class SteamWorkshopDriverFactory implements ConfiguredDriverFactory
{
    public function create(Server $server, ConfiguredGameAdapter $adapter, string $source): LifecycleDriver
    {
        return new SteamWorkshopLifecycleDriver($server, $adapter, app(SourceRegistry::class)->provider($source));
    }
}
