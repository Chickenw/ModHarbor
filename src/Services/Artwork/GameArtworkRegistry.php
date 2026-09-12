<?php

namespace GameNest\GameNestModManager\Services\Artwork;

use GameNest\GameNestModManager\Contracts\ArtworkProviderInterface;

/**
 * Ordered list of artwork providers. Registration order = try order.
 * Manual overrides sit first; fallback sits last.
 */
class GameArtworkRegistry
{
    /** @var list<ArtworkProviderInterface> */
    private array $providers = [];

    public function register(ArtworkProviderInterface $provider): self
    {
        $this->providers[] = $provider;
        return $this;
    }

    /** @return list<ArtworkProviderInterface> */
    public function all(): array
    {
        return $this->providers;
    }
}
