<?php

namespace GameNest\GameNestModManager\Contracts;

use GameNest\GameNestModManager\Services\SourceContext;

/** Exact package lookup. Download authorization is obtained only at install time. */
interface VersionedDownloadProvider extends ConfiguredDownloadProvider
{
    public function package(string|int $id, string|int|null $fileId, SourceContext $source): array;
}
