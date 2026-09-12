<?php

namespace GameNest\GameNestModManager\Contracts;

/**
 * Universal artwork source. Providers are tried in registry order.
 * A provider returns null when it cannot (or should not) supply artwork
 * so the resolver can continue to the next provider.
 *
 * Implementations must remain game-agnostic: decide only from fields on
 * the game definition array (artwork_url, steam_app_id, etc.), never by
 * hard-coded game names.
 */
interface ArtworkProviderInterface
{
    /**
     * Whether this provider can attempt to resolve artwork for the given
     * game definition. Returning false skips the provider without cost.
     */
    public function supports(array $game): bool;

    /**
     * Resolve artwork for the game.
     *
     * @return string|null  data-URI, validated HTTPS URL, panel-relative
     *                      path, or null to let the next provider try.
     */
    public function resolve(array $game): ?string;
}
