<?php

namespace GameNest\GameNestModManager\Contracts;

use GameNest\GameNestModManager\Services\FileTransaction;
use GameNest\GameNestModManager\Services\SourceContext;

/** Game/provider-specific rules; orchestration and UI remain game independent. */
interface LifecycleDriver
{
    public function provider(): ModProvider;

    public function dependencies(
        string|int $id,
        ?SourceContext $source = null
    ): array;

    public function prepare(
        string|int $id,
        string|int|null $fileId,
        FileTransaction $files,
        ?SourceContext $source = null
    ): array;

    public function validatePath(string $path): void;

    public function preservePath(string $path): bool;
}
