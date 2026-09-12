<?php

namespace GameNest\GameNestModManager\Services;

use RuntimeException;

/**
 * One atomic JSON catalog outside the plugin tree.
 *
 * Readers and writers share a lock. Seed migrations are versioned so
 * ModHarbor can install first-party definitions once without turning
 * them into permanently hardcoded games.
 */
class GameDefinitionStore
{
    private string $directory;

    public function __construct(
        ?string $directory = null
    ) {
        $this->directory =
            $directory
            ?? storage_path(
                'app/gamenest-mod-manager/game-builder'
            );
    }

    public function providerKeys(): array
    {
        $result = [];

        foreach (
            config(
                'gamenest-mod-manager.providers',
                []
            )
            as $key => $value
        ) {
            (new ProviderSdk)
                ->normalizeDefinition(
                    $key,
                    $value
                );

            $result[] = $key;
        }

        return $result;
    }

    public function reservedKeys(): array
    {
        // Game definitions are now the authority for game registration.
        return [];
    }

    public function validate(
        array $data
    ): GameDefinition {
        return GameDefinition::validate(
            $data,
            $this->providerKeys(),
            $this->reservedKeys()
        );
    }

    public function import(
        string $json
    ): GameDefinition {
        return GameDefinition::fromJson(
            $json,
            $this->providerKeys(),
            $this->reservedKeys()
        );
    }

    public function snapshot(): array
    {
        return $this->locked(
            fn () =>
                $this->seed(
                    $this->read()
                )
        );
    }

    public function all(): array
    {
        return array_map(
            fn ($definition) =>
                $this->validate(
                    $definition
                ),
            $this->snapshot()['definitions']
        );
    }

    public function save(
        array $data,
        string $revision,
        bool $create
    ): array {
        $definition =
            $this->validate(
                $data
            )->data();
        if ($definition['enabled']) { (new SourceMetadataValidator)->validate($definition); }

        return $this->mutate(
            $revision,
            function (
                &$definitions
            ) use (
                $definition,
                $create
            ) {
                $key =
                    $definition['key'];

                if (
                    $create ===
                    isset(
                        $definitions[$key]
                    )
                ) {
                    throw new RuntimeException(
                        $create
                            ? 'Game key already exists. Cancel and edit the existing definition, or choose a unique key and review overlapping detection rules. Nothing was overwritten.'
                            : 'Game no longer exists.'
                    );
                }

                $definitions[$key] =
                    $definition;
            }
        );
    }

    public function toggle(
        string $key,
        string $revision
    ): array {
        GameDefinition::key($key);

        return $this->mutate(
            $revision,
            function (
                &$definitions
            ) use ($key) {
                if (
                    !isset(
                        $definitions[$key]
                    )
                ) {
                    throw new RuntimeException(
                        'Game no longer exists.'
                    );
                }

                $definitions[$key]['enabled'] =
                    !$definitions[$key]['enabled'];
                if ($definitions[$key]['enabled']) { (new SourceMetadataValidator)->validate($definitions[$key]); }
            }
        );
    }

    public function delete(
        string $key,
        string $revision
    ): array {
        GameDefinition::key($key);

        return $this->mutate(
            $revision,
            function (
                &$definitions
            ) use ($key) {
                if (
                    !isset(
                        $definitions[$key]
                    )
                    || $definitions[$key]['enabled']
                ) {
                    throw new RuntimeException(
                        'Disable the game before deleting it.'
                    );
                }

                unset(
                    $definitions[$key]
                );
            }
        );
    }

    private function mutate(
        string $revision,
        callable $change
    ): array {
        return $this->locked(
            function () use (
                $revision,
                $change
            ) {
                $catalog =
                    $this->seed(
                        $this->read()
                    );

                if (
                    !hash_equals(
                        $catalog['revision'],
                        $revision
                    )
                ) {
                    throw new RuntimeException(
                        'Game catalog changed. Reload before saving.'
                    );
                }

                $change(
                    $catalog['definitions']
                );

                if (
                    count(
                        $catalog['definitions']
                    ) > 100
                ) {
                    throw new RuntimeException(
                        'Game Builder supports at most 100 definitions.'
                    );
                }

                foreach (
                    $catalog['definitions']
                    as $key => $definition
                ) {
                    $normalized =
                        $this->validate(
                            $definition
                        )->data();

                    if (
                        $normalized['key']
                        !== $key
                    ) {
                        throw new RuntimeException(
                            'Game catalog key mismatch.'
                        );
                    }

                    $catalog['definitions'][$key] =
                        $normalized;
                }

                $catalog['revision'] =
                    bin2hex(
                        random_bytes(16)
                    );

                $this->writeCatalog(
                    $catalog
                );

                return $catalog;
            }
        );
    }

    private function seed(
        array $catalog
    ): array {
        $targetVersion =
            (int) config(
                'gamenest-mod-manager.game_definition_seed_version',
                0
            );

        $currentVersion =
            (int) (
                $catalog['seed_version']
                ?? 0
            );

        if (
            $currentVersion
            >= $targetVersion
        ) {
            return $this->healSeededArtworkIds($catalog);
        }

        /*
         * Seed migration v2:
         *
         * Older first-party Rust definitions only matched an egg named
         * exactly "Rust". Pelican AIO eggs include a version suffix such as
         * "Rust - All In One 3.0.3".
         *
         * Upgrade only the original trusted Rust detection shape. If an
         * administrator changed the capability or exact egg names, their
         * definition wins and is left untouched.
         */
        if (
            $currentVersion < 2
            && isset(
                $catalog['definitions']['rust']
            )
        ) {
            $rust =
                $catalog['definitions']['rust'];

            if (
                is_array($rust)
                && (
                    $rust['capability']
                    ?? null
                ) === 'rust-carbon-oxide'
                && (
                    $rust['detection']['egg_names']
                    ?? null
                ) === ['Rust']
                && !array_key_exists(
                    'egg_name_contains',
                    $rust['detection']
                    ?? []
                )
            ) {
                $catalog[
                    'definitions'
                ][
                    'rust'
                ][
                    'detection'
                ][
                    'egg_name_contains'
                ] = [
                    'Rust - All In One',
                ];
            }
        }

        /*
         * Seed migration v3:
         *
         * Eco and Rust originally shipped without Steam App IDs, which
         * prevented the shared artwork resolver from using cached Steam
         * artwork. Upgrade only unchanged first-party definitions that
         * still have no Steam App ID. Administrator overrides win.
         */
        if ($currentVersion < 3) {
            if (
                isset($catalog['definitions']['eco'])
                && is_array($catalog['definitions']['eco'])
                && (
                    $catalog['definitions']['eco']['capability']
                    ?? null
                ) === 'eco-modkit'
                && (
                    $catalog['definitions']['eco']['steam_app_id']
                    ?? null
                ) === null
            ) {
                $catalog['definitions']['eco']['steam_app_id'] =
                    382310;
            }

            if (
                isset($catalog['definitions']['rust'])
                && is_array($catalog['definitions']['rust'])
                && (
                    $catalog['definitions']['rust']['capability']
                    ?? null
                ) === 'rust-carbon-oxide'
                && (
                    $catalog['definitions']['rust']['steam_app_id']
                    ?? null
                ) === null
            ) {
                $catalog['definitions']['rust']['steam_app_id'] =
                    252490;
            }
        }

        /*
         * Seed migration v4:
         *
         * Game profiles can now declare portable runtime metadata mappings
         * that derive provider compatibility from Pelican server variables.
         *
         * Older saved definitions predate this field and normalize to an
         * empty runtime_metadata object. For matching profile definitions,
         * inherit the recommendation only when the saved mapping is still
         * empty. Existing non-empty administrator mappings always win.
         *
         * Only mappings for sources that the administrator still has enabled
         * are inherited.
         */
        if ($currentVersion < 4) {
            $profiles = (new GameProfileCatalog)->all();

            foreach ($profiles as $profile) {
                $defaults = (array) ($profile['defaults'] ?? []);
                $key = (string) ($defaults['key'] ?? '');

                if (
                    $key === ''
                    || !isset($catalog['definitions'][$key])
                    || !is_array($catalog['definitions'][$key])
                ) {
                    continue;
                }

                $existing =
                    $catalog['definitions'][$key];

                if (
                    ($existing['runtime_metadata'] ?? []) !== []
                ) {
                    continue;
                }

                $recommended =
                    (array) ($defaults['runtime_metadata'] ?? []);

                if ($recommended === []) {
                    continue;
                }

                $sources =
                    (array) ($existing['sources'] ?? []);

                $recommended =
                    array_intersect_key(
                        $recommended,
                        $sources
                    );

                if ($recommended !== []) {
                    $catalog['definitions'][$key]['runtime_metadata'] =
                        $recommended;
                }
            }
        }

        /*
         * Seed migration v5:
         *
         * Profiles may declare replacements for exact first-party runtime
         * metadata mappings shipped by an earlier seed version.
         *
         * The store applies those replacements generically. A replacement is
         * made only when the saved mapping exactly matches the declared old
         * mapping, so administrator-customized metadata always wins.
         */
        if ($currentVersion < 5) {
            $profiles = (new GameProfileCatalog)->all();

            foreach ($profiles as $profile) {
                $defaults =
                    (array) ($profile['defaults'] ?? []);

                $key =
                    (string) ($defaults['key'] ?? '');

                $upgrades =
                    (array) (
                        $profile['runtime_metadata_upgrades']
                        ?? []
                    );

                if (
                    $key === ''
                    || $upgrades === []
                    || !isset($catalog['definitions'][$key])
                    || !is_array($catalog['definitions'][$key])
                ) {
                    continue;
                }

                foreach (
                    $upgrades
                    as $source => $upgrade
                ) {
                    if (
                        !is_string($source)
                        || !is_array($upgrade)
                        || !array_key_exists('from', $upgrade)
                        || !array_key_exists('to', $upgrade)
                    ) {
                        continue;
                    }

                    $existing =
                        $catalog[
                            'definitions'
                        ][
                            $key
                        ][
                            'runtime_metadata'
                        ][
                            $source
                        ]
                        ?? null;

                    if (
                        $existing !== $upgrade['from']
                        || !isset(
                            $catalog[
                                'definitions'
                            ][
                                $key
                            ][
                                'sources'
                            ][
                                $source
                            ]
                        )
                    ) {
                        continue;
                    }

                    $replacement =
                        (array) $upgrade['to'];

                    if ($replacement === []) {
                        continue;
                    }

                    $catalog[
                        'definitions'
                    ][
                        $key
                    ][
                        'runtime_metadata'
                    ][
                        $source
                    ] = $replacement;
                }
            }
        }

        /*
         * Seed migration v7:
         *
         * First-party definitions may gain bundled artwork after they have
         * already been seeded. Inherit that artwork only when the saved
         * definition still has a blank artwork field. Administrator artwork
         * overrides always win.
         *
         * This is deliberately generic across all first-party game seeds.
         */
        if (
            $currentVersion < 7
            && $targetVersion >= 7
        ) {
            $artworkSeeds =
                config(
                    'gamenest-mod-manager.game_definition_seeds',
                    []
                );

            if (!is_array($artworkSeeds)) {
                throw new RuntimeException(
                    'ModHarbor game seed configuration is invalid.'
                );
            }

            foreach ($artworkSeeds as $artworkSeed) {
                if (!is_array($artworkSeed)) {
                    continue;
                }

                $key =
                    (string) (
                        $artworkSeed['key']
                        ?? ''
                    );

                $seedArtwork =
                    (string) (
                        $artworkSeed['artwork_url']
                        ?? ''
                    );

                if (
                    $key === ''
                    || $seedArtwork === ''
                    || !isset(
                        $catalog['definitions'][$key]
                    )
                    || !is_array(
                        $catalog['definitions'][$key]
                    )
                ) {
                    continue;
                }

                $existingArtwork =
                    (string) (
                        $catalog[
                            'definitions'
                        ][
                            $key
                        ][
                            'artwork_url'
                        ]
                        ?? ''
                    );

                if ($existingArtwork === '') {
                    $catalog[
                        'definitions'
                    ][
                        $key
                    ][
                        'artwork_url'
                    ] = $seedArtwork;
                }
            }
        }


        /*
         * Seed migration v8:
         *
         * Clear first-party bundled artwork references that still use the
         * @artwork/<key>.webp convention so automatic Steam / SteamGridDB
         * resolution can take over. Never clear custom admin artwork,
         * HTTPS URLs, panel paths, or @upload references.
         */
        if (
            $currentVersion < 8
            && $targetVersion >= 8
        ) {
            foreach (
                (array) config(
                    'gamenest-mod-manager.game_definition_seeds',
                    []
                ) as $seed
            ) {
                if (!is_array($seed)) {
                    continue;
                }
                $key = (string) ($seed['key'] ?? '');
                if (
                    $key === ''
                    || !isset($catalog['definitions'][$key])
                    || !is_array($catalog['definitions'][$key])
                ) {
                    continue;
                }
                $existing = (string) (
                    $catalog['definitions'][$key]['artwork_url'] ?? ''
                );
                // Only strip the conventional first-party bundled reference
                // for this seed key. Any other artwork_url is left alone.
                $legacyBundled = '@artwork/' . $key . '.webp';
                if ($existing === $legacyBundled) {
                    $catalog['definitions'][$key]['artwork_url'] = '';
                }
            }
        }

        /*
         * Seed migration v11:
         * Strip leftover autofill sources from first-party ICARUS only.
         * Keep Steam Workshop, Nexus, GitHub, Direct and Upload.
         */
        if (
            $currentVersion < 11
            && $targetVersion >= 11
            && isset($catalog['definitions']['icarus'])
            && is_array($catalog['definitions']['icarus'])
        ) {
            $sources = $catalog['definitions']['icarus']['sources'] ?? [];
            if (is_array($sources)) {
                $keep = ['steam-workshop', 'nexus', 'github', 'direct', 'upload'];
                $catalog['definitions']['icarus']['sources'] = array_intersect_key(
                    $sources,
                    array_flip($keep)
                );
            }
        }

        /*
         * Seed migration v12:
         * Copy first-party SteamGridDB IDs from seed config when a
         * saved definition still has none. Do not overwrite an
         * administrator-chosen ID.
         */
        if (
            $currentVersion < 12
            && $targetVersion >= 12
        ) {
            $catalog = $this->healSeededArtworkIds($catalog);
        }
        $seeds =
            config(
                'gamenest-mod-manager.game_definition_seeds',
                []
            );

        if (!is_array($seeds)) {
            throw new RuntimeException(
                'ModHarbor game seed configuration is invalid.'
            );
        }

        foreach (
            $catalog['definitions']
            as $key => $definition
        ) {
            $catalog['definitions'][$key] =
                $this->validate(
                    $definition
                )->data();
        }

        foreach (
            $seeds
            as $definition
        ) {
            if (!is_array($definition)) {
                throw new RuntimeException(
                    'Invalid ModHarbor game seed.'
                );
            }

            $definition =
                $this->validate(
                    $definition
                )->data();

            $key =
                $definition['key'];

            // Existing administrator definitions always win.
            if (
                !isset(
                    $catalog['definitions'][$key]
                )
            ) {
                $catalog['definitions'][$key] =
                    $definition;
            }
        }

        $catalog['seed_version'] =
            $targetVersion;

        $catalog['revision'] =
            bin2hex(
                random_bytes(16)
            );

        $this->writeCatalog(
            $catalog
        );

        return $catalog;
    }


    private function healSeededArtworkIds(array $catalog): array
    {
        $seeds = config('gamenest-mod-manager.game_definition_seeds', []);
        if (!is_array($seeds)) {
            return $catalog;
        }

        foreach ($seeds as $seed) {
            if (!is_array($seed)) {
                continue;
            }

            $key = $seed['key'] ?? null;
            $seededId = $seed['steamgriddb_game_id'] ?? null;
            if (
                !is_string($key)
                || $seededId === null
                || !isset($catalog['definitions'][$key])
                || !is_array($catalog['definitions'][$key])
            ) {
                continue;
            }

            if (($catalog['definitions'][$key]['steamgriddb_game_id'] ?? null) !== null) {
                continue;
            }

            $catalog['definitions'][$key]['steamgriddb_game_id'] = $seededId;
        }

        return $catalog;
    }

    private function read(): array
    {
        $path =
            $this->directory
            . '/catalog.json';

        if (is_link($path)) {
            throw new RuntimeException(
                'Unsafe game catalog link.'
            );
        }

        if (!file_exists($path)) {
            return [
                'revision' => 'empty',
                'seed_version' => 0,
                'definitions' => [],
            ];
        }

        if (
            !is_file($path)
            || filesize($path)
                > 8 * 1024 * 1024
        ) {
            throw new RuntimeException(
                'Game catalog is invalid.'
            );
        }

        $catalog =
            json_decode(
                file_get_contents($path),
                true,
                24,
                JSON_THROW_ON_ERROR
            );

        if (
            !is_array($catalog)
            || !is_string(
                $catalog['revision']
                ?? null
            )
            || !is_array(
                $catalog['definitions']
                ?? null
            )
            || count(
                $catalog['definitions']
            ) > 100
        ) {
            throw new RuntimeException(
                'Game catalog is invalid.'
            );
        }

        $catalog['seed_version'] =
            (int) (
                $catalog['seed_version']
                ?? 0
            );

        foreach (
            $catalog['definitions']
            as $key => $definition
        ) {
            if (
                !is_array($definition)
                || (
                    $definition['key']
                    ?? ''
                ) !== $key
            ) {
                throw new RuntimeException(
                    'Game catalog key mismatch.'
                );
            }

            $this->validate(
                $definition
            );
        }

        return $catalog;
    }

    private function writeCatalog(
        array $catalog
    ): void {
        $json =
            json_encode(
                $catalog,
                JSON_PRETTY_PRINT
                | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR
            );

        $temporary =
            tempnam(
                $this->directory,
                'catalog-'
            );

        if ($temporary === false) {
            throw new RuntimeException(
                'Cannot stage game catalog.'
            );
        }

        try {
            if (
                file_put_contents(
                    $temporary,
                    $json
                ) !== strlen($json)
                || !chmod(
                    $temporary,
                    0600
                )
                || !rename(
                    $temporary,
                    $this->directory
                    . '/catalog.json'
                )
            ) {
                throw new RuntimeException(
                    'Cannot save game catalog.'
                );
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function locked(
        callable $action
    ): mixed {
        if (is_link($this->directory)) {
            throw new RuntimeException(
                'Unsafe game catalog directory.'
            );
        }

        if (
            !is_dir($this->directory)
            && !mkdir(
                $this->directory,
                0700,
                true
            )
            && !is_dir($this->directory)
        ) {
            throw new RuntimeException(
                'Cannot create game catalog directory.'
            );
        }

        $path =
            $this->directory
            . '/catalog.lock';

        if (is_link($path)) {
            throw new RuntimeException(
                'Unsafe game catalog lock.'
            );
        }

        $lock =
            fopen(
                $path,
                'c'
            );

        if ($lock === false) {
            throw new RuntimeException(
                'Cannot open game catalog lock.'
            );
        }

        try {
            if (
                !flock(
                    $lock,
                    LOCK_EX
                )
            ) {
                throw new RuntimeException(
                    'Cannot lock game catalog.'
                );
            }

            return $action();
        } finally {
            flock(
                $lock,
                LOCK_UN
            );

            fclose($lock);
        }
    }
}
