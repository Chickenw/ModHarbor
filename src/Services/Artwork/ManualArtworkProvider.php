<?php

namespace GameNest\GameNestModManager\Services\Artwork;

use GameNest\GameNestModManager\Contracts\ArtworkProviderInterface;
use GameNest\GameNestModManager\Services\GameArtworkService;

/**
 * Highest-priority provider: explicit admin override (manual URL or future upload).
 * Bundled @artwork/ references are handled by BundledArtworkProvider so that
 * the two concerns stay separate.
 */
class ManualArtworkProvider implements ArtworkProviderInterface
{
    public function supports(array $game): bool
    {
        $override = $game['artwork_url'] ?? '';
        if (!is_string($override) || $override === '') {
            return false;
        }
        // Leave @artwork/ and @upload/ to dedicated providers.
        if (str_starts_with($override, '@artwork/') || str_starts_with($override, '@upload/')) {
            return false;
        }
        return GameArtworkService::validOverride($override);
    }

    public function resolve(array $game): ?string
    {
        $override = $game['artwork_url'] ?? '';
        return is_string($override) && GameArtworkService::validOverride($override)
            ? $override
            : null;
    }
}
