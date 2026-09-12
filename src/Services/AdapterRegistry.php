<?php

namespace GameNest\GameNestModManager\Services;

use App\Models\Server;
use GameNest\GameNestModManager\Adapters\ConfiguredGameAdapter;
use GameNest\GameNestModManager\Contracts\GameAdapter;
use RuntimeException;

class AdapterRegistry
{
    /**
     * @return array<int, GameAdapter>
     */
    public function all(): array
    {
        $adapters = [];
        $keys = [];

        foreach (
            $this->configured()
            as $adapter
        ) {
            $key =
                strtolower(
                    trim(
                        $adapter->key()
                    )
                );

            if ($key === '') {
                throw new RuntimeException(
                    'Configured ModHarbor game returned an empty key.'
                );
            }

            if (isset($keys[$key])) {
                throw new RuntimeException(
                    'Duplicate ModHarbor game key: '
                    . $key
                );
            }

            $keys[$key] = true;

            if ($adapter->enabled()) {
                $adapters[] =
                    $adapter;
            }
        }

        return $adapters;
    }

    /**
     * @return array<int, ConfiguredGameAdapter>
     */
    protected function configured(): array
    {
        if (
            !config(
                'gamenest-mod-manager.game_builder.enabled',
                false
            )
        ) {
            return [];
        }

        return array_map(
            static fn ($definition) =>
                new ConfiguredGameAdapter(
                    $definition
                ),
            array_values(
                app(
                    GameDefinitionStore::class
                )->all()
            )
        );
    }

    public function forServer(
        Server $server
    ): ?GameAdapter {
        $matches =
            array_values(
                array_filter(
                    $this->configured(),
                    static fn ($adapter) =>
                        $adapter->matches(
                            $server
                        )
                )
            );

        if (count($matches) > 1) {
            throw new RuntimeException(
                'Multiple ModHarbor games match this egg. Resolve the Game Setup detection rules first.'
            );
        }

        if ($matches === []) {
            return null;
        }

        return $matches[0]->enabled()
            ? $matches[0]
            : null;
    }

    public function describe(
        GameAdapter $adapter
    ): array {
        $key =
            strtolower(
                trim(
                    $adapter->key()
                )
            );

        $name =
            trim(
                $adapter->name()
            );

        if (
            $key === ''
            || preg_match(
                '/^[a-z0-9][a-z0-9._-]*$/',
                $key
            ) !== 1
        ) {
            throw new RuntimeException(
                'Invalid ModHarbor game adapter key: '
                . $key
                . '.'
            );
        }

        if ($name === '') {
            throw new RuntimeException(
                $adapter::class .
                ' returned an empty game adapter name.'
            );
        }

        $sources =
            $adapter->sources();

        if (!is_array($sources)) {
            throw new RuntimeException(
                $adapter::class .
                ' returned invalid source definitions.'
            );
        }

        foreach (
            $sources
            as $source => $config
        ) {
            if (
                !is_string($source)
                || trim($source) === ''
                || !is_array($config)
            ) {
                throw new RuntimeException(
                    $adapter::class .
                    ' contains an invalid source definition.'
                );
            }
        }

        return [
            'key' => $key,
            'name' => $name,
            'class' => $adapter::class,
            'metadata' =>
                $adapter->metadata(),
            'features' =>
                $adapter->features(),
            'sources' =>
                array_keys($sources),
            'source_definitions' =>
                $sources,
            'mod_directories' =>
                array_values(
                    $adapter->modDirectories()
                ),
            'config_directories' =>
                array_values(
                    $adapter->configDirectories()
                ),
        ];
    }

    public function developerManifest(): array
    {
        $manifest = [];

        foreach (
            $this->all()
            as $adapter
        ) {
            $descriptor =
                $this->describe(
                    $adapter
                );

            $manifest[
                $descriptor['key']
            ] = $descriptor;
        }

        return $manifest;
    }

    public function supports(
        Server $server
    ): bool {
        return
            $this->forServer($server)
            !== null;
    }

    public function byKey(
        string $key
    ): ?GameAdapter {
        $key =
            strtolower(
                trim($key)
            );

        foreach (
            $this->all()
            as $adapter
        ) {
            if (
                strtolower(
                    $adapter->key()
                ) === $key
            ) {
                return $adapter;
            }
        }

        return null;
    }
}
