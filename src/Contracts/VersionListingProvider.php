<?php

namespace GameNest\GameNestModManager\Contracts;

use GameNest\GameNestModManager\Services\SourceContext;

interface VersionListingProvider
{
    /** Bounded compatible release choices. Download authorization must still be checked at deployment. */
    public function versions(string|int $id, SourceContext $source): array;
}
