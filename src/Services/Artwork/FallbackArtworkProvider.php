<?php

namespace GameNest\GameNestModManager\Services\Artwork;

use GameNest\GameNestModManager\Contracts\ArtworkProviderInterface;
use GameNest\GameNestModManager\Services\GameArtworkService;

/**
 * Always-last provider. Returns the ModHarbor lighthouse SVG.
 */
class FallbackArtworkProvider implements ArtworkProviderInterface
{
    public function supports(array $game): bool
    {
        return true;
    }

    public function resolve(array $game): ?string
    {
        return GameArtworkService::fallback();
    }
}
