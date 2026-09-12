<?php

namespace GameNest\GameNestModManager\Contracts;

use GameNest\GameNestModManager\Services\DiscoveryQuery;
use GameNest\GameNestModManager\Services\DiscoveryResult;
use GameNest\GameNestModManager\Services\SourceContext;

/**
 * Optional provider contract for true catalog/search discovery.
 *
 * Manual sources such as GitHub URL inspection, direct downloads, and uploads
 * continue implementing ModProvider only. The shared browse layer can therefore
 * distinguish catalog providers from manual sources without game-specific code.
 */
interface DiscoverableProvider extends ModProvider
{
    public function discover(
        DiscoveryQuery $query,
        SourceContext $source
    ): DiscoveryResult;
}
