<?php

namespace GameNest\GameNestModManager\Providers;

use GameNest\GameNestModManager\Contracts\ModProvider;
use GameNest\GameNestModManager\Services\SourceContext;

/**
 * Settings-only provider. Exists so Provider Settings can store and test a
 * SteamGridDB API key. It is not an install source and has no mod catalog.
 */
class SteamGridDbProvider implements ModProvider
{
    public function key(): string
    {
        return 'steamgriddb';
    }

    public function name(): string
    {
        return 'SteamGridDB';
    }

    public function supportsSearch(): bool
    {
        return false;
    }

    public function search(
        string $query,
        array $options = [],
        ?SourceContext $source = null
    ): array {
        return [];
    }

    public function get(
        string|int $id,
        ?SourceContext $source = null
    ): ?array {
        return null;
    }
}
