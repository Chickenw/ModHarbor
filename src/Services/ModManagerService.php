<?php

namespace GameNest\GameNestModManager\Services;

use App\Models\Server;
use GameNest\GameNestModManager\Contracts\GameAdapter;
use RuntimeException;

class ModManagerService
{
    public function __construct(
        protected AdapterRegistry $adapters,
    ) {
    }

    public function adapter(Server $server): GameAdapter
    {
        $adapter = $this->adapters->forServer($server);

        if (!$adapter) {
            throw new RuntimeException(
                'GameNest Mod Manager does not support this server yet.'
            );
        }

        return $adapter;
    }

    public function gameName(Server $server): string
    {
        return $this->adapter($server)->name();
    }

    public function providers(Server $server): array
    {
        return $this->adapter($server)->providers();
    }
}
