<?php

return [
    'game_builder' => ['enabled' => true],
    /*
     * ModHarbor
     *
     * v1 primary games:
     *
     * Eco
     *   - mod.io
     *
     * Rust
     *   - uMod
     *   - GitHub
     *   - Direct downloads
     *   - Runtime: Carbon or Oxide
     *
     * 7 Days to Die
     *   - 7DTD mod sources
     *   - GitHub
     *   - Direct downloads
     */

    'manifest' => '.gamenest-mod-manager.json',

    /*
     * Provider/source definitions.
     *
     * Game adapters decide WHICH sources a game exposes and supply
     * game-specific metadata. This registry decides HOW ModHarbor treats
     * the shared provider itself. Adding a new provider should normally
     * require one provider class plus one entry here, without teaching
     * every game about that provider's implementation details.
     */
    'providers' => [

        'steamgriddb' => [
            'class' => \GameNest\GameNestModManager\Providers\SteamGridDbProvider::class,
            'label' => 'SteamGridDB',
            'browse_priority' => 0,
            'capabilities' => [],
            'metadata_fields' => [],
            'credential_fields' => [
                'api_key' => [
                    'label' => 'API Key',
                    'type' => 'secret',
                    'required' => false,
                    'placeholder' => 'SteamGridDB API key',
                    'help' => 'Optional. Used for automatic game artwork when Steam has no image or the game has no Steam App ID. Generate a key at steamgriddb.com profile preferences.',
                ],
            ],
        ],
        'nexus' => [
            'metadata_fields' => ['domain' => ['type' => 'text', 'required' => true]],
            'class' => \GameNest\GameNestModManager\Providers\NexusModsProvider::class,
            'label' => 'Nexus Mods', 'browse_priority' => 60,
            'capabilities' => ['browse', 'discover', 'search', 'install', 'update'],
            'metadata_example' => ['domain' => '7daystodie'],
            'credential_require_any' => ['api_key'],
            'credential_fields' => [
                'api_key' => [
                    'label' => 'API Key',
                    'type' => 'secret',
                    'required' => false,
                    'placeholder' => 'Enter Nexus Mods API key',
                    'help' => 'Required for Nexus catalog and download operations.',
                ],
            ],
            'discovery' => [
                'description' => 'Browse the full Nexus Mods catalog for this game. Search by mod name or enter a numeric mod ID for exact lookup. Direct installation requires eligible Nexus API access; other accounts can download from Nexus and install with Upload File.',
                'search_placeholder' => 'Filter this feed or enter a mod ID...',
                'default_sort' => 'updated', 'sorts' => ['updated' => 'Recently Updated', 'newest' => 'Recently Added', 'trending' => 'Trending'],
                'page_sizes' => [24, 48],
            ],
        ],
        'thunderstore' => [
            'metadata_fields' => ['community' => ['type' => 'text', 'required' => true]],
            'class' => \GameNest\GameNestModManager\Providers\ThunderstoreProvider::class,
            'label' => 'Thunderstore',
            'browse_priority' => 65,
            'capabilities' => ['browse', 'discover', 'search', 'install', 'update', 'dependencies'],
            'metadata_example' => ['community' => 'valheim'],
            'discovery' => [
                'description' => 'Browse Thunderstore packages for the configured community. Select a package to install the published zip through the generic archive driver.',
                'search_placeholder' => 'Filter Thunderstore packages...',
                'default_sort' => 'updated',
                'sorts' => ['updated' => 'Recently Updated'],
                'page_sizes' => [24, 48],
            ],
        ],
        '7daystodiemods' => [
            'metadata_fields' => ['game_version' => ['type' => 'list'], 'category' => ['type' => 'list'], 'server_side' => ['type' => 'list']],
            'class' => \GameNest\GameNestModManager\Providers\SevenDaysModsProvider::class,
            'label' => '7DaysToDieMods.com', 'browse_priority' => 70,
            'capabilities' => ['browse', 'discover', 'search', 'install', 'update'],
            'metadata_example' => ['game_version' => ['v3'], 'server_side' => ['server-only', 'server-and-client']],
            'discovery' => [
                'description' => 'Search the public catalog. Single clean main packages can install directly; external links and packages requiring manual selection use the provider website and Upload File.',
                'default_sort' => 'newest', 'sorts' => ['newest' => 'Newest', 'updated' => 'Recently Updated', 'downloads' => 'Most Downloaded'], 'page_sizes' => [20, 40],
            ],
        ],
        'steam-workshop' => [
            'metadata_fields' => ['app_id' => ['type' => 'number', 'required' => true, 'min' => 1, 'inherit_from' => 'steam_app_id'], 'tags' => ['type' => 'list'], 'item_subdirectory' => ['type' => 'boolean'], 'content_prefix' => ['type' => 'text', 'format' => 'relative-path']],
            'class' => \GameNest\GameNestModManager\Providers\SteamWorkshopProvider::class,
            'configured_driver_factory' => \GameNest\GameNestModManager\Services\SteamWorkshopDriverFactory::class,
            'label' => 'Steam Workshop', 'browse_priority' => 80,
            'capabilities' => ['browse', 'discover', 'search', 'install', 'update', 'dependencies'],
            'metadata_example' => ['app_id' => 123456, 'tags' => [], 'item_subdirectory' => true, 'content_prefix' => ''],
            'credential_fields' => [
                'api_key' => [
                    'label' => 'Steam Web API Key',
                    'type' => 'secret',
                    'required' => false,
                    'placeholder' => 'Enter Steam Web API key',
                    'help' => 'Used for Steam Workshop catalog metadata.',
                ],
                'steamcmd_root' => [
                    'label' => 'SteamCMD Root',
                    'type' => 'text',
                    'required' => false,
                    'placeholder' => '/opt/steamcmd',
                    'help' => 'Dedicated SteamCMD installation used for Workshop downloads.',
                ],
            ],
            'discovery' => [
                'description' => 'Public Workshop items for this app. Deployment requires a dedicated SteamCMD installation and anonymous download support. Older revisions cannot be retrieved.',
                'default_sort' => 'popular', 'sorts' => ['popular' => 'Popular', 'newest' => 'Newest', 'updated' => 'Recently Updated'], 'page_sizes' => [24, 48],
            ],
        ],
        'curseforge' => [
            'metadata_fields' => ['game_id' => ['type' => 'number', 'required' => true, 'min' => 1], 'class_id' => ['type' => 'number'], 'category_id' => ['type' => 'number'], 'game_version' => ['type' => 'text'], 'mod_loader_type' => ['type' => 'number'], 'allow_prerelease' => ['type' => 'boolean']],
            'class' => \GameNest\GameNestModManager\Providers\CurseForgeProvider::class,
            'label' => 'CurseForge', 'browse_priority' => 90,
            'capabilities' => ['browse', 'discover', 'search', 'install', 'update', 'dependencies'],
            'metadata_example' => ['game_id' => 432, 'class_id' => 6, 'game_version' => '1.21.1', 'mod_loader_type' => 4],
            'credential_require_any' => ['api_key'],
            'credential_fields' => [
                'api_key' => [
                    'label' => 'API Key',
                    'type' => 'secret',
                    'required' => false,
                    'placeholder' => 'Enter CurseForge API key',
                    'help' => 'Required for CurseForge catalog and download operations.',
                ],
            ],
            'discovery' => [
                'description' => 'Browse files compatible with the configured game and version. Requires a global CurseForge API key; author distribution restrictions are respected.',
                'default_sort' => 'popular', 'sorts' => ['popular' => 'Popular', 'updated' => 'Recently Updated', 'name' => 'A–Z', 'downloads' => 'Most Downloaded'], 'page_sizes' => [24, 48],
            ],
        ],
        'modrinth' => [
            'metadata_fields' => ['project_type' => ['type' => 'text'], 'loaders' => ['type' => 'list'], 'game_versions' => ['type' => 'list'], 'categories' => ['type' => 'list'], 'allow_prerelease' => ['type' => 'boolean']],
            'class' => \GameNest\GameNestModManager\Providers\ModrinthProvider::class,
            'label' => 'Modrinth', 'browse_priority' => 100,
            'capabilities' => ['browse', 'discover', 'search', 'install', 'update', 'dependencies'],
            'metadata_example' => ['project_type' => 'mod', 'loaders' => ['fabric'], 'game_versions' => ['1.21.1']],
            'credential_fields' => [
                'token' => [
                    'label' => 'API Token',
                    'type' => 'secret',
                    'required' => false,
                    'placeholder' => 'Optional Modrinth token',
                    'help' => 'Optional. Public Modrinth operations work without a token.',
                ],
            ],
            'discovery' => [
                'description' => 'Browse packages for the configured loaders and game versions. Stable releases are selected unless prereleases are explicitly enabled in source metadata.',
                'default_sort' => 'relevance', 'sorts' => ['relevance' => 'Relevance', 'downloads' => 'Most Downloaded', 'newest' => 'Newest', 'updated' => 'Recently Updated'], 'page_sizes' => [24, 48],
            ],
        ],
        'modio' => [
            'metadata_require_any' => ['game_id', 'name_id', 'game_name'],
            'metadata_fields' => ['game_id' => ['type' => 'number', 'min' => 1], 'name_id' => ['type' => 'text'], 'game_name' => ['type' => 'text']],
            'class' => \GameNest\GameNestModManager\Providers\ModIoProvider::class,
            'label' => 'mod.io',
            'browse_priority' => 10,
            'credential_fields' => [
                'api_path' => [
                    'label' => 'API URL',
                    'type' => 'text',
                    'required' => true,
                    'default' => 'https://api.mod.io/v1',
                    'placeholder' => 'https://api.mod.io/v1',
                    'help' => 'Base URL used for mod.io API requests.',
                ],
                'api_key' => [
                    'label' => 'API Key',
                    'type' => 'secret',
                    'required' => false,
                    'placeholder' => 'Enter mod.io API key',
                    'help' => 'Your global mod.io API key.',
                ],
            ],
            'credential_require_any' => ['api_key'],
            'capabilities' => [
                'browse',
                'discover',
                'search',
                'install',
                'update',
                'dependencies',
                'settings',
            ],
            'discovery' => [
                'default_sort' => 'hot',
                'sorts' => [
                    'hot' => 'Hot Today',
                    'downloads' => 'Most Downloaded',
                    'subscribers' => 'Most Subscribers',
                    'rating' => 'Highest Rated',
                    'newest' => 'Newest',
                    'updated' => 'Recently Updated',
                    'name' => 'A-Z',
                ],
                'page_sizes' => [24, 48],
                'default_page_size' => 24,
                'filters' => [
                    'period' => [
                        'label' => 'Period',
                        'default' => 'all',
                        'options' => [
                            'all' => 'All Time',
                            '7d' => 'Last 7 Days',
                            '30d' => 'Last 30 Days',
                            '3m' => 'Last 3 Months',
                            '6m' => 'Last 6 Months',
                            '1y' => 'Last Year',
                        ],
                    ],
                ],
                'supports_tags' => true,
            ],
        ],

        'umod' => [
            'metadata_fields' => ['categories' => ['type' => 'list']],
            'class' => \GameNest\GameNestModManager\Providers\UModProvider::class,
            'label' => 'uMod',
            'browse_priority' => 20,
            'capabilities' => [
                'browse',
                'discover',
                'search',
                'install',
                'update',
                'dependencies',
            ],
            'discovery' => [
                'default_sort' => 'updated',
                'sorts' => [
                    'updated' => 'Recently Updated',
                    'downloads' => 'Most Downloaded',
                    'watchers' => 'Most Watched',
                    'newest' => 'Newest',
                    'name' => 'A-Z',
                ],
                'page_sizes' => [10],
                'default_page_size' => 10,
                'supports_tags' => false,
            ],
        ],

        'github' => [
            'metadata_fields' => ['repository' => ['type' => 'text']],
            'class' => \GameNest\GameNestModManager\Providers\GitHubProvider::class,
            'label' => 'GitHub',
            'browse_priority' => 30,
            'credential_fields' => [
                'token' => [
                    'label' => 'Personal Access Token',
                    'type' => 'secret',
                    'required' => false,
                    'placeholder' => 'Optional GitHub token',
                    'help' => 'Optional. Increases GitHub API rate limits.',
                ],
            ],
            'capabilities' => [
                'browse',
                'inspect',
                'install',
                'update',
            ],
            'discovery' => [
                'mode' => 'manual',
                'page_sizes' => [1],
            ],
        ],

        'direct' => [
            'class' => \GameNest\GameNestModManager\Providers\DirectDownloadProvider::class,
            'label' => 'Direct Download',
            'browse_priority' => 40,
            'capabilities' => [
                'browse',
                'manual',
                'install',
                'reinstall',
            ],
            'discovery' => [
                'mode' => 'manual',
                'page_sizes' => [1],
            ],
        ],

        'upload' => [
            'class' => \GameNest\GameNestModManager\Providers\UploadProvider::class,
            'label' => 'Upload File',
            'browse_priority' => 50,
            'capabilities' => [
                'browse',
                'upload',
                'install',
                'replace',
                'reinstall',
            ],
            'discovery' => [
                'mode' => 'manual',
                'page_sizes' => [1],
            ],
        ],
    ],

    /*
     * Supported game adapters.
     *
     * Adding a game should normally begin here plus one adapter class.
     * Shared ModHarbor services discover these automatically.
     */
    /*
     * Trusted internal behavior profiles.
     *
     * Portable Game Builder files may select only these inert identifiers.
     * They cannot load arbitrary PHP classes.
     */
    'game_capabilities' => [
        'generic' => [
            'label' => 'Generic',
            'game_keys' => '*',
            'behavior_adapter' => null,
            'drivers' => [],
        ],

        'eco-modkit' => [
            'label' => 'Eco ModKit',
            'game_keys' => ['eco'],
            'behavior_adapter' =>
                \GameNest\GameNestModManager\Adapters\EcoAdapter::class,
            'drivers' => [
                'modio' =>
                    \GameNest\GameNestModManager\Services\EcoLifecycleDriver::class,
                'github' =>
                    \GameNest\GameNestModManager\Services\EcoGitHubLifecycleDriver::class,
                'upload' =>
                    \GameNest\GameNestModManager\Services\EcoUploadLifecycleDriver::class,
            ],
        ],

        'rust-carbon-oxide' => [
            'label' => 'Carbon / Oxide',
            'game_keys' => ['rust'],
            'behavior_adapter' =>
                \GameNest\GameNestModManager\Adapters\RustAdapter::class,
            'drivers' => [
                'umod' =>
                    \GameNest\GameNestModManager\Services\RustUModLifecycleDriver::class,
                'github' =>
                    \GameNest\GameNestModManager\Services\RustGitHubLifecycleDriver::class,
                'direct' =>
                    \GameNest\GameNestModManager\Services\RustDirectLifecycleDriver::class,
                'upload' =>
                    \GameNest\GameNestModManager\Services\RustUploadLifecycleDriver::class,
            ],
        ],
    ],

    /*
     * First-party definitions are inserted exactly once.
     *
     * After seed version 1 has been applied, administrators can disable,
     * edit, export or delete them like any other Game Builder definition.
     */
    'game_definition_seed_version' => 12,

    // Searchable recommendations. Seed references reuse existing deployment
    // capabilities without migrating or overwriting administrators' definitions.
    'game_profiles' => [
        'minecraft-java' => [
            'seed_key' => 'minecraft-java',
            'aliases' => ['Minecraft', 'Minecraft Java'],
            'notes' => 'For Java server mods. Compatible providers can derive the game version and loader from mapped Pelican server variables. JAR files are copied intact; modpacks need a separate setup.',
            'defaults' => [
                'schema_version' => 1,
                'key' => 'minecraft-java',
                'name' => 'Minecraft Java Edition',
                'steam_app_id' => null,
                'steamgriddb_game_id' => 5248835,
                'artwork_url' => '',
                'capability' => 'generic',
                'sources' => [
                    'curseforge' => ['metadata' => ['game_id' => 432, 'class_id' => 6]],
                    'modrinth' => ['metadata' => ['project_type' => 'mod']],
                    'github' => ['metadata' => []],
                    'upload' => ['metadata' => []],
                ],
                'runtime_metadata' => [
                    'curseforge' => [
                        'game_version' => [
                            'variable' => 'MINECRAFT_VERSION',
                            'ignore' => ['latest'],
                        ],
                        'mod_loader_type' => [
                            'variable' => 'SERVER_TYPE',
                            'map' => [
                                'forge' => 1,
                                'fabric' => 4,
                                'quilt' => 5,
                                'neoforge' => 6,
                            ],
                        ],
                    ],
                    'modrinth' => [
                        'game_versions' => [
                            'variable' => 'MINECRAFT_VERSION',
                            'ignore' => ['latest'],
                            'list' => true,
                        ],
                        'loaders' => [
                            'variable' => 'SERVER_TYPE',
                            'map' => [
                                'forge' => 'forge',
                                'fabric' => 'fabric',
                                'quilt' => 'quilt',
                                'neoforge' => 'neoforge',
                            ],
                            'list' => true,
                        ],
                    ],
                ],
                'mod_directories' => ['mods'],
                'config_directories' => ['config'],
                'package_types' => ['jar'],
                'deployment' => ['strategy' => 'copy', 'target' => 'mods', 'archive_prefix' => ''],
                'behavior' => ['install_while_running' => false, 'restart_required' => true],
            ],
            'runtime_metadata_upgrades' => [
                'modrinth' => [
                    'from' => [
                        'game_version' => [
                            'variable' => 'MINECRAFT_VERSION',
                            'ignore' => ['latest'],
                        ],
                        'loader' => [
                            'variable' => 'SERVER_TYPE',
                            'map' => [
                                'forge' => 'forge',
                                'fabric' => 'fabric',
                                'quilt' => 'quilt',
                                'neoforge' => 'neoforge',
                            ],
                        ],
                    ],
                    'to' => [
                        'game_versions' => [
                            'variable' => 'MINECRAFT_VERSION',
                            'ignore' => ['latest'],
                            'list' => true,
                        ],
                        'loaders' => [
                            'variable' => 'SERVER_TYPE',
                            'map' => [
                                'forge' => 'forge',
                                'fabric' => 'fabric',
                                'quilt' => 'quilt',
                                'neoforge' => 'neoforge',
                            ],
                            'list' => true,
                        ],
                    ],
                ],
            ],
        ],
        'eco' => [
            'seed_key' => 'eco',
            'notes' => 'Uses the Eco ModKit deployment capability. Review server detection before saving.',
        ],
        'rust' => [
            'seed_key' => 'rust',
            'aliases' => ['Rust Carbon', 'Rust Oxide'],
            'notes' => 'For Carbon or Oxide plugins. The existing runtime capability selects the active plugin directory.',
        ],
        '7-days-to-die' => [
            'seed_key' => '7-days-to-die',
            'aliases' => ['7DTD', 'Seven Days to Die'],
            'notes' => 'Review the ZIP layout and config directories for your server before installing.',
            'defaults' => [
                'sources' => [
                    'nexus' => ['metadata' => ['domain' => '7daystodie']],
                    '7daystodiemods' => ['metadata' => []],
                    'github' => ['metadata' => []],
                    'direct' => ['metadata' => []],
                    'upload' => ['metadata' => []],
                ],
            ],
        ],
        'valheim' => [
            'seed_key' => 'valheim',
            'aliases' => ['Valheim Dedicated', 'Valheim Server'],
            'notes' => 'BepInEx plugins. Thunderstore community is valheim. Required Thunderstore dependencies install automatically; BepInExPack is skipped.',
        ],
        'icarus' => [
            'seed_key' => 'icarus',
            'aliases' => ['Icarus Dedicated'],
            'notes' => 'Workshop and file drops. Review server Paths after the first save.',
        ],
    ],

    'game_definition_seeds' => [
        [
            'schema_version' => 1,
            'key' => 'minecraft-java',
            'name' => 'Minecraft Java Edition',
            'enabled' => true,
            'steam_app_id' => null,
            'steamgriddb_game_id' => 5248835,
            'artwork_url' => '',
            'capability' => 'generic',

            'detection' => [
                'egg_ids' => [],
                'egg_names' => [
                    'Minecraft',
                    'Minecraft Java',
                    'Minecraft Java Edition',
                ],
            ],

            'sources' => [
                'curseforge' => [
                    'metadata' => [
                        'game_id' => 432,
                        'class_id' => 6,
                    ],
                ],
                'modrinth' => [
                    'metadata' => [
                        'project_type' => 'mod',
                    ],
                ],
                'github' => [
                    'metadata' => [],
                ],
                'upload' => [
                    'metadata' => [],
                ],
            ],

            'runtime_metadata' => [
                'curseforge' => [
                    'game_version' => [
                        'variable' => 'MINECRAFT_VERSION',
                        'ignore' => ['latest'],
                    ],
                    'mod_loader_type' => [
                        'variable' => 'SERVER_TYPE',
                        'map' => [
                            'forge' => 1,
                            'fabric' => 4,
                            'quilt' => 5,
                            'neoforge' => 6,
                        ],
                    ],
                ],
                'modrinth' => [
                    'game_versions' => [
                        'variable' => 'MINECRAFT_VERSION',
                        'ignore' => ['latest'],
                        'list' => true,
                    ],
                    'loaders' => [
                        'variable' => 'SERVER_TYPE',
                        'map' => [
                            'forge' => 'forge',
                            'fabric' => 'fabric',
                            'quilt' => 'quilt',
                            'neoforge' => 'neoforge',
                        ],
                        'list' => true,
                    ],
                ],
            ],

            'mod_directories' => [
                'mods',
            ],

            'config_directories' => [
                'config',
            ],

            'package_types' => [
                'jar',
            ],

            'deployment' => [
                'strategy' => 'copy',
                'target' => 'mods',
                'archive_prefix' => '',
            ],

            'behavior' => [
                'install_while_running' => false,
                'restart_required' => true,
            ],
        ],

        [
            'schema_version' => 1,
            'key' => 'eco',
            'name' => 'Eco',
            'enabled' => true,
            'steam_app_id' => 382310,
            'capability' => 'eco-modkit',
            'detection' => [
                'egg_ids' => [],
                'egg_names' => ['Eco'],
            ],
            'sources' => [
                'modio' => [
                    'metadata' => [
                        'game_name' => 'Eco',
                        'name_id' => 'eco',
                        'discovery' => [
                            'title' => 'Eco Mod Catalog',
                            'description' => 'Browse Eco mods from mod.io with game-aware deployment and dependency handling.',
                            'search_placeholder' => 'Search Eco mods...',
                        ],
                    ],
                ],
                'github' => [
                    'metadata' => [],
                ],
                'upload' => [
                    'metadata' => [],
                ],
            ],
            'mod_directories' => [
                'Mods',
                'Mods/UserCode',
            ],
            'config_directories' => [
                'Configs',
                'Mods',
                'Mods/UserCode',
            ],
            'package_types' => ['zip'],
            'deployment' => [
                'strategy' => 'provider-managed',
                'target' => 'Mods',
                'archive_prefix' => '',
            ],
            'behavior' => [
                'install_while_running' => false,
                'restart_required' => true,
            ],
        ],

        [
            'schema_version' => 1,
            'key' => 'rust',
            'name' => 'Rust',
            'enabled' => true,
            'steam_app_id' => 252490,
            'capability' => 'rust-carbon-oxide',
            'detection' => [
                'egg_ids' => [],
                'egg_names' => ['Rust'],
                'egg_name_contains' => [
                    'Rust - All In One',
                ],
            ],
            'sources' => [
                'umod' => [
                    'metadata' => [
                        'categories' => ['rust'],
                        'discovery' => [
                            'title' => 'Rust Plugin Catalog',
                            'description' => 'Browse uMod plugins and let ModHarbor deploy them to the active Carbon or Oxide runtime.',
                            'search_placeholder' => 'Search Rust plugins...',
                        ],
                    ],
                ],
                'github' => [
                    'metadata' => [],
                ],
                'direct' => [
                    'metadata' => [],
                ],
                'upload' => [
                    'metadata' => [],
                ],
            ],
            'mod_directories' => [
                'oxide/plugins',
                'carbon/plugins',
            ],
            'config_directories' => [
                'oxide/config',
                'carbon/configs',
                'carbon/config',
            ],
            'package_types' => [
                'cs',
                'zip',
            ],
            'deployment' => [
                'strategy' => 'provider-managed',
                'target' => 'carbon/plugins',
                'archive_prefix' => '',
            ],
            'behavior' => [
                'install_while_running' => true,
                'restart_required' => false,
            ],
        ],

        [
            'schema_version' => 1,
            'key' => '7-days-to-die',
            'name' => '7 Days to Die',
            'enabled' => false,
            'steam_app_id' => 251570,
            'capability' => 'generic',
            'detection' => [
                'egg_ids' => [],
                'egg_names' => [
                    '7 Days to Die',
                    '7 Days To Die',
                    '7DTD',
                ],
            ],
            'sources' => [
                'nexus' => [
                    'metadata' => [
                        'domain' => '7daystodie',
                    ],
                ],
                '7daystodiemods' => [
                    'metadata' => [],
                ],
                'steam-workshop' => [
                    'metadata' => [
                        'app_id' => 251570,
                    ],
                ],
                'github' => [
                    'metadata' => [],
                ],
                'direct' => [
                    'metadata' => [],
                ],
                'upload' => [
                    'metadata' => [],
                ],
            ],
            'mod_directories' => ['Mods'],
            'config_directories' => ['Configs'],
            'package_types' => ['zip'],
            'deployment' => [
                'strategy' => 'archive',
                'target' => 'Mods',
                'archive_prefix' => '',
            ],
            'behavior' => [
                'install_while_running' => false,
                'restart_required' => true,
            ],
        ],

        [
            'schema_version' => 1,
            'key' => 'valheim',
            'name' => 'Valheim',
            'enabled' => true,
            'steam_app_id' => 892970,
            'capability' => 'generic',
            'detection' => [
                'egg_ids' => [],
                'egg_names' => [
                    'Valheim',
                    'Valheim Dedicated Server',
                ],
                'egg_name_contains' => [
                    'Valheim',
                ],
            ],
            'sources' => [
                'thunderstore' => [
                    'metadata' => [
                        'community' => 'valheim',
                    ],
                ],
                'nexus' => [
                    'metadata' => [
                        'domain' => 'valheim',
                    ],
                ],
                'steam-workshop' => [
                    'metadata' => [
                        'app_id' => 892970,
                    ],
                ],
                'github' => [
                    'metadata' => [],
                ],
                'direct' => [
                    'metadata' => [],
                ],
                'upload' => [
                    'metadata' => [],
                ],
            ],
            'mod_directories' => [
                'BepInEx',
                'BepInEx/plugins',
            ],
            'config_directories' => [
                'BepInEx/config',
            ],
            'package_types' => [
                'zip',
            ],
            'deployment' => [
                'strategy' => 'archive',
                'target' => 'BepInEx',
                'archive_prefix' => '',
            ],
            'behavior' => [
                'install_while_running' => false,
                'restart_required' => true,
            ],
        ],

        [
            'schema_version' => 1,
            'key' => 'icarus',
            'name' => 'ICARUS',
            'enabled' => true,
            'steam_app_id' => 1149460,
            'capability' => 'generic',
            'detection' => [
                'egg_ids' => [],
                'egg_names' => [
                    'Icarus',
                    'ICARUS',
                ],
                'egg_name_contains' => [
                    'Icarus',
                ],
            ],
            'sources' => [
                'nexus' => [
                    'metadata' => [
                        'domain' => 'icarus',
                    ],
                ],
                'steam-workshop' => [
                    'metadata' => [
                        'app_id' => 1149460,
                    ],
                ],
                'github' => [
                    'metadata' => [],
                ],
                'direct' => [
                    'metadata' => [],
                ],
                'upload' => [
                    'metadata' => [],
                ],
            ],
            'mod_directories' => [
                'Mods',
            ],
            'config_directories' => [
                'Configs',
            ],
            'package_types' => [
                'zip',
            ],
            'deployment' => [
                'strategy' => 'archive',
                'target' => 'Mods',
                'archive_prefix' => '',
            ],
            'behavior' => [
                'install_while_running' => false,
                'restart_required' => true,
            ],
        ],
    ],

    'github' => [
        /*
         * Optional. Public repositories work without a token.
         * A token raises GitHub API limits and can be configured later.
         */
        'token' => env('GITHUB_TOKEN', ''),
    ],
    // Credentials stay in panel environment/config; never in portable Game Builder JSON.
    'nexus' => ['api_key' => env('MODHARBOR_NEXUS_API_KEY', '')],
    'curseforge' => ['api_key' => env('MODHARBOR_CURSEFORGE_API_KEY', '')],
    'modrinth' => ['token' => env('MODHARBOR_MODRINTH_TOKEN', '')],
    'steam-workshop' => [
        'api_key' => env('MODHARBOR_STEAM_API_KEY', ''),
        'steamcmd_root' => env('MODHARBOR_STEAMCMD_ROOT', ''),
    ],
];
