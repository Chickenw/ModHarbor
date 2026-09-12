<?php

namespace GameNest\GameNestModManager\Services;

use GameNest\GameNestModManager\Contracts\LifecycleDriver;

class PackageRecipeRegistry
{
    /**
     * Package recipes are deliberately separate from providers.
     *
     * GitHub only knows GitHub.
     * mod.io only knows mod.io.
     *
     * Recipes describe relationships between packages when the upstream
     * provider does not expose machine-readable dependency metadata.
     */
    public function dependencies(
        string $game,
        LifecycleDriver $driver,
        string|int $providerId,
        ?SourceContext $source = null
    ): array {
        $provider = $driver->provider()->key();

        if ($provider !== 'github') {
            return [];
        }

        $mod = $driver->provider()->get(
            $providerId,
            $source
        );

        if (!$mod) {
            return [];
        }

        $fullName = strtolower(
            trim(
                (string) (
                    $mod['full_name']
                    ?? ''
                )
            )
        );

        return match ($game . ':' . $fullName) {

            /*
             * Official DiscordLink installation instructions require
             * MightyMooseCore before DiscordLink.
             *
             * We intentionally resolve it by exact provider search instead
             * of hard-coding a mod.io numeric ID.
             */
            'eco:eco-discordlink/ecodiscordplugin' => [
                [
                    'provider' => 'modio',
                    'id' => '3561559',
                    'name' => 'MightyMooseCore',
                ],
            ],

            default => [],
        };
    }
}
