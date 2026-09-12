<?php

namespace GameNest\GameNestModManager\Services\Artwork;

use GameNest\GameNestModManager\Services\GameArtworkService;

/**
 * Walks registered providers in order and returns the first non-null result.
 * Guarantees a non-empty string by falling back to the lighthouse SVG.
 */
class GameArtworkResolver
{
    public function __construct(private GameArtworkRegistry $registry) {}

    public function resolve(array $game): string
    {
        foreach ($this->registry->all() as $provider) {
            if (!$provider->supports($game)) {
                continue;
            }
            $result = $provider->resolve($game);
            if (is_string($result) && $result !== '') {
                return $result;
            }
        }
        return GameArtworkService::fallback();
    }
}
