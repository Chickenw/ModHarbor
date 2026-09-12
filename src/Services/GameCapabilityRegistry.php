<?php

namespace GameNest\GameNestModManager\Services;

use GameNest\GameNestModManager\Contracts\GameAdapter;
use GameNest\GameNestModManager\Contracts\LifecycleDriver;
use RuntimeException;

final class GameCapabilityRegistry
{
    public function definitions(): array
    {
        $definitions = config(
            'gamenest-mod-manager.game_capabilities',
            []
        );

        if (!is_array($definitions)) {
            throw new RuntimeException(
                'ModHarbor game capability configuration is invalid.'
            );
        }

        return $definitions;
    }

    public function keys(): array
    {
        return array_keys($this->definitions());
    }

    public function definition(string $key): array
    {
        $key = strtolower(trim($key));

        $definition =
            $this->definitions()[$key]
            ?? null;

        if (!is_array($definition)) {
            throw new RuntimeException(
                'Unknown ModHarbor game capability: ' . $key
            );
        }

        return $definition;
    }

    public function supportsGame(
        string $capability,
        string $gameKey
    ): bool {
        $definition =
            $this->definition($capability);

        $games =
            $definition['game_keys']
            ?? [];

        if ($games === '*') {
            return true;
        }

        if (!is_array($games)) {
            throw new RuntimeException(
                'Invalid ModHarbor capability game restriction.'
            );
        }

        return in_array(
            $gameKey,
            $games,
            true
        );
    }

    public function behaviorAdapter(
        string $capability
    ): ?GameAdapter {
        $definition =
            $this->definition($capability);

        $class =
            $definition['behavior_adapter']
            ?? null;

        if ($class === null || $class === '') {
            return null;
        }

        if (
            !is_string($class)
            || !class_exists($class)
        ) {
            throw new RuntimeException(
                'Invalid ModHarbor capability behavior adapter.'
            );
        }

        $adapter = app($class);

        if (!$adapter instanceof GameAdapter) {
            throw new RuntimeException(
                $class .
                ' must implement the ModHarbor GameAdapter contract.'
            );
        }

        return $adapter;
    }

    public function driverClass(
        string $capability,
        string $source
    ): ?string {
        $definition =
            $this->definition($capability);

        $drivers =
            $definition['drivers']
            ?? [];

        if (!is_array($drivers)) {
            throw new RuntimeException(
                'Invalid ModHarbor capability driver map.'
            );
        }

        $class =
            $drivers[$source]
            ?? null;

        if ($class === null || $class === '') {
            return null;
        }

        if (
            !is_string($class)
            || !class_exists($class)
            || !is_subclass_of(
                $class,
                LifecycleDriver::class
            )
        ) {
            throw new RuntimeException(
                'Invalid ModHarbor capability lifecycle driver.'
            );
        }

        return $class;
    }
}
