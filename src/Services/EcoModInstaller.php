<?php

namespace GameNest\GameNestModManager\Services;

use App\Models\Server;

/** Compatibility entry point; all mutations use the shared lifecycle engine. */
class EcoModInstaller
{
    public function install(Server $server, int $modId): array
    {
        $result = app(ModLifecycleService::class)->run($server, 'install', 'modio:' . $modId);
        return ['mod' => ['name' => $result['name']], 'dependencies' => []];
    }
}
