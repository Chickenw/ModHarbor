<?php

namespace GameNest\GameNestModManager\Contracts;

use App\Models\Server;

/** Optional extension: existing third-party GameAdapter implementations remain compatible. */
interface ManagedFilesAdapter
{
    /** Each rule contains root, patterns, depth and optional exclude patterns. */
    public function scanRules(Server $server): array;
    public function configRules(Server $server): array;
    /** Throw on invalid content; additional parsers/schema validators belong here. */
    public function validateConfig(string $path, string $contents): void;
}
