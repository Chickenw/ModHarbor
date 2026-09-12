<?php

namespace GameNest\GameNestModManager\Contracts;

use GameNest\GameNestModManager\Services\SourceContext;

interface ModProvider
{
    public function key(): string;

    public function name(): string;

    public function supportsSearch(): bool;

    /**
     * Providers are shared services and must never store mutable current-game
     * state. Game/source-specific metadata arrives through SourceContext.
     */
    public function search(
        string $query,
        array $options = [],
        ?SourceContext $source = null
    ): array;

    public function get(
        string|int $id,
        ?SourceContext $source = null
    ): ?array;
}
