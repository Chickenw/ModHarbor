<?php

namespace GameNest\GameNestModManager\Adapters;

use App\Models\Server;
use GameNest\GameNestModManager\Contracts\GameAdapter;

abstract class AbstractGameAdapter implements GameAdapter, \GameNest\GameNestModManager\Contracts\ManagedFilesAdapter
{
    /**
     * Text fragments used to identify compatible Pelican eggs.
     *
     * Child adapters only need to declare their identifiers instead
     * of repeating the server-detection implementation.
     */
    abstract protected function eggIdentifiers(): array;

    public function scanRules(Server $server): array { return []; }

    public function configRules(Server $server): array
    {
        return array_map(fn ($root) => ['root' => ltrim($root, '/'),
            'patterns' => ['*.json', '*.yaml', '*.yml', '*.toml', '*.ini', '*.cfg', '*.txt', '*.xml'],
            'exclude' => ['.*', '*.template', '*.bak*'], 'depth' => 4], $this->configDirectories());
    }

    public function validateConfig(string $path, string $contents): void {}

    public function metadata(): array
    {
        return [
            'key' => $this->key(),
            'name' => $this->name(),
            'adapter' => static::class,
        ];
    }

    public function features(): array
    {
        return [
            'managed_mods' => true,
            'configs' => $this->configDirectories() !== [],
            'history' => true,
            'recovery' => true,
        ];
    }

    public function sourceDefaults(): array
    {
        return [];
    }

    public function supports(Server $server): bool
    {
        $server->loadMissing('egg');

        $egg = strtolower(
            trim(
                (string) (
                    $server->egg?->name
                    ?? ''
                )
            )
        );

        if ($egg === '') {
            return false;
        }

        foreach ($this->eggIdentifiers() as $identifier) {
            $identifier = strtolower(
                trim((string) $identifier)
            );

            if (
                $identifier !== ''
                && str_contains($egg, $identifier)
            ) {
                return true;
            }
        }

        return false;
    }

    public function providers(): array
    {
        return array_keys(
            $this->sources()
        );
    }

    public function hasSource(string $source): bool
    {
        $source = strtolower(
            trim($source)
        );

        return $source !== ''
            && array_key_exists(
                $source,
                $this->sources()
            );
    }

    public function sourceConfig(string $source): array
    {
        $source = strtolower(
            trim($source)
        );

        if (!$this->hasSource($source)) {
            return [];
        }

        $config =
            $this->sources()[$source]
            ?? [];

        if (!is_array($config)) {
            $config = [];
        }

        return array_replace_recursive(
            $this->sourceDefaults(),
            $config
        );
    }
}
