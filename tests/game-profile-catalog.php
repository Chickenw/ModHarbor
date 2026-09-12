<?php

namespace Illuminate\Support\Facades {
    class Cache {
        public static function remember($key, $ttl, $callback): array { return $callback(); }
    }
}

namespace {
    // Reuse the standalone page/container fixtures, including existing CRUD guards.
    require __DIR__ . '/game-builder-page.php';

    use GameNest\GameNestModManager\Pages\GameSetup;
    use GameNest\GameNestModManager\Services\GameCatalogSearchService;
    use GameNest\GameNestModManager\Services\GameDefinitionStore;
    use GameNest\GameNestModManager\Services\GameProfileCatalog;
    use GameNest\GameNestModManager\Services\ProviderHttpClient;
    use GameNest\GameNestModManager\Services\ProviderGameIdentityService;
    use GameNest\GameNestModManager\Services\SourceMetadataValidator;

    function now() { return new class { public function addHours($hours) { return $this; } }; }
    $assertions = 0;
    function profileCheck(bool $condition, string $message): void {
        $GLOBALS['assertions']++;
        checkPage($condition, $message);
    }
    $catalog = new GameProfileCatalog;
    $http = new class extends ProviderHttpClient {
        public array $items = [];
        public bool $offline = false;
        public int $calls = 0;
        public function send(string $method, string $url, array $parameters = [], array $headers = []): array {
            $this->calls++;
            if ($this->offline) { throw new RuntimeException('Offline'); }
            return ['status' => 200, 'body' => json_encode(['items' => $this->items])];
        }
    };
    $search = new GameCatalogSearchService($http);
    $GLOBALS['pageServices'][GameCatalogSearchService::class] = $search;
    $identity = new class extends ProviderGameIdentityService {
        public int $calls = 0;
        public function __construct() {}
        public function discover(string $provider, string $gameName, ?int $steamAppId = null): array {
            $this->calls++;
            return ['status' => 'unverified', 'metadata' => []];
        }
    };
    $GLOBALS['pageServices'][ProviderGameIdentityService::class] = $identity;

    profileCheck(count($catalog->all()) === count($settings['game_profiles']), 'Initial profile count');
    foreach (['Minecraft' => 'minecraft-java', 'ECO' => 'eco', 'rust' => 'rust', '7DTD' => '7-days-to-die'] as $query => $key) {
        profileCheck($catalog->search($query)[0]['id'] === $key, 'Case/alias search: ' . $query);
        $d = $catalog->get($key)['defaults'];
        $d['detection'] = ['egg_names' => ['Test server'], 'egg_ids' => []];
        $store->validate($d);
        (new SourceMetadataValidator)->validate($d);
        profileCheck(!$d['enabled'], 'Recommendations must not enable games');
        $d['enabled'] = true;
        $adapter = new \GameNest\GameNestModManager\Adapters\ConfiguredGameAdapter($store->validate($d));
        foreach (array_keys($d['sources']) as $source) {
            profileCheck((new \GameNest\GameNestModManager\Services\ConfiguredDriverResolver)->driverClass($adapter, $source) !== null,
                'Recommended source has no deployment integration: ' . $key . '/' . $source);
        }
    }
    $settings['game_profiles']['fixture-game'] = [
        'aliases' => ['A new game'],
        'defaults' => array_replace($catalog->get('minecraft-java')['defaults'], ['key' => 'fixture-game', 'name' => 'Fixture Game']),
    ];
    profileCheck($catalog->search('A new game')[0]['id'] === 'fixture-game', 'New profile required shared service changes');
    unset($settings['game_profiles']['fixture-game']);
    profileCheck($search->search('x') === [] && $http->calls === 0, 'Short query requested remote data');
    $http->items = [['id' => 252490, 'name' => 'Rust'], ['id' => 123, 'name' => 'Rust-like game']];
    $results = $search->search('rust');
    profileCheck(count($results) === 2 && $results[0]['catalog'] === 'profile', 'Profile/Steam duplicate or ordering');
    profileCheck($search->search('unrelated')[0]['id'] === 'rust', 'Steam identity did not resolve known profile');
    $http->offline = true;
    profileCheck($search->search('minecraft')[0]['steam_app_id'] === null, 'Non-Steam profile lost offline');
    $unavailable = false;
    try { $search->search('unknown game'); } catch (RuntimeException $e) { $unavailable = str_contains($e->getMessage(), 'manually'); }
    profileCheck($unavailable, 'Remote failure did not preserve manual fallback');
    $http->offline = false;
    $http->items = array_map(fn ($id) => ['id' => $id, 'name' => 'Other game ' . $id], range(1000, 1020));
    profileCheck(count($search->search('game')) === 10, 'Search result bound');
    $http->items = [];

    $page = new GameSetup;
    $page->mount();

    profileCheck(
        $page->isOfficialDefinition('minecraft-java')
        && $page->isOfficialDefinition('eco')
        && $page->isOfficialDefinition('rust')
        && $page->isOfficialDefinition('7-days-to-die')
        && $page->isOfficialDefinition('valheim')
        && $page->isOfficialDefinition('icarus')
        && !$page->isOfficialDefinition('minecraft-custom'),
        'First-party identity must come from shipped seed keys'
    );

    $snapshot = $store->snapshot();
    profileCheck(
        ($snapshot['definitions']['minecraft-java']['steamgriddb_game_id'] ?? null) === 5248835,
        'Minecraft seed missing SteamGridDB Java Edition ID'
    );
    $page->eggIds = '42';
    $page->updatedForm('Rust', 'name');
    $page->selectGameSearchResult(0);
    profileCheck($page->form['capability'] === 'rust-carbon-oxide' && $page->form['behavior']['install_while_running'], 'Rust capability/runtime lost');
    $page->updatedForm('Minecraft', 'name');
    $page->selectGameSearchResult(0);
    profileCheck($page->form['steam_app_id'] === null && $page->form['name'] === 'Minecraft Java Edition', 'Canonical non-Steam identity');
    profileCheck($page->form['key'] === 'minecraft-java' && $page->form['capability'] === 'generic', 'Previous capability/key survived switch');
    profileCheck($page->form['package_types'] === ['jar'] && $page->form['deployment']['strategy'] === 'copy', 'JAR deployment must copy intact');
    profileCheck($page->modDirectories === 'mods' && $page->configDirectories === 'config', 'Directories not replaced');
    profileCheck(!isset($page->sourceMetadata['umod']) && $page->allowedSources === ['curseforge', 'modrinth', 'github', 'upload'], 'Previous provider metadata survived');
    profileCheck(json_decode($page->sourceMetadata['curseforge'], true)['game_id'] === 432, 'Provider identity absent');
    profileCheck(
        ($page->form['runtime_metadata']['curseforge']['mod_loader_type']['map']['neoforge'] ?? null) === 6
        && ($page->form['runtime_metadata']['modrinth']['loaders']['map']['neoforge'] ?? null) === 'neoforge'
        && ($page->form['runtime_metadata']['modrinth']['loaders']['list'] ?? null) === true
        && ($page->form['runtime_metadata']['modrinth']['game_versions']['list'] ?? null) === true,
        'Profile runtime metadata was not applied'
    );
    profileCheck($page->eggIds === '42' && !$page->form['enabled'], 'Selection changed detection or enabled state');
    profileCheck($store->snapshot() === $snapshot && $identity->calls === 0, 'Profile selection wrote data or requested identity discovery');

    // Edits to every requested field survive Save, including cleared metadata.
    $page->form['name'] = 'My Java Server';
    $page->form['steam_app_id'] = 123;
    $page->allowedSources = ['modrinth', 'upload'];
    $page->updateProviderMetadataField('modrinth', 'project_type', '');
    $page->modDirectories = 'custom-mods';
    $page->configDirectories = 'custom-config';
    $page->form['package_types'] = ['jar', 'dll'];
    $page->form['deployment']['target'] = 'custom-mods';
    $page->form['behavior'] = ['install_while_running' => true, 'restart_required' => false];

    // minecraft-java is now a first-party seeded definition. Saving the
    // editable recommendation as a new stable key verifies that every
    // recommended field remains customizable without replacing the seed.
    $page->form['key'] = 'minecraft-custom';

    $page->saveGame();
    $saved = $store->all()['minecraft-custom']->data();
    profileCheck($saved['name'] === 'My Java Server' && $saved['steam_app_id'] === 123, 'Identity edits lost');
    profileCheck(array_keys($saved['sources']) === ['modrinth', 'upload'] && $saved['sources']['modrinth']['metadata'] === [], 'Provider edits lost');
    profileCheck($saved['mod_directories'] === ['custom-mods'] && $saved['config_directories'] === ['custom-config'], 'Path edits lost');
    profileCheck($saved['package_types'] === ['jar', 'dll'] && $saved['deployment']['target'] === 'custom-mods', 'Deployment edits lost');
    profileCheck($saved['behavior'] === ['install_while_running' => true, 'restart_required' => false] && $identity->calls === 0, 'Runtime edits or cleared metadata overwritten at save');
    profileCheck(
        !isset($saved['runtime_metadata']['curseforge'])
        && isset($saved['runtime_metadata']['modrinth'])
        && ($saved['runtime_metadata']['modrinth']['loaders']['map']['neoforge'] ?? null) === 'neoforge'
        && ($saved['runtime_metadata']['modrinth']['loaders']['list'] ?? null) === true
        && ($saved['runtime_metadata']['modrinth']['game_versions']['list'] ?? null) === true,
        'Runtime metadata did not follow enabled provider edits'
    );

    $page->newGame();
    $page->updatedForm('Minecraft', 'name');
    $page->selectGameSearchResult(0);
    $page->form['key'] = 'minecraft-archive';
    $page->eggIds = '44';
    $page->form['package_types'] = ['zip'];
    $page->form['deployment']['strategy'] = 'archive';
    $page->form['deployment']['archive_prefix'] = 'payload';
    $page->saveGame();
    $archive = $store->all()['minecraft-archive']->data();
    profileCheck($archive['steam_app_id'] === null && $archive['deployment']['strategy'] === 'archive'
        && $archive['deployment']['archive_prefix'] === 'payload', 'Optional Steam identity or strategy/prefix edits lost');

    $page->newGame();
    $page->updatedForm('7DTD', 'name');
    $page->selectGameSearchResult(0);
    profileCheck(in_array('nexus', $page->allowedSources, true) && in_array('7daystodiemods', $page->allowedSources, true), '7DTD sources missing');
    $page->eggIds = '43';
    $beforeCollision = $store->snapshot();
    $page->saveGame();
    profileCheck(str_contains($page->message, 'already exists') && $store->snapshot() === $beforeCollision, 'Profile overwrote seeded definition');
    $page->gameSearchResults = [['catalog' => 'steam', 'name' => 'Unknown Game', 'steam_app_id' => 999]];
    $page->selectGameSearchResult(0);
    $retained = array_intersect($page->allowedSources, ['nexus', '7daystodiemods', 'curseforge', 'modrinth', 'thunderstore', 'umod']);
    profileCheck($retained === [] && !$page->profileSelected, 'Unknown game retained profile sources');
    profileCheck($page->form['key'] === '' && $page->form['steam_app_id'] === 999, 'Unknown game retained canonical profile key');
    $page->newGame();
    profileCheck(!$page->profileSelected && $page->gameSearchResults === [] && $page->form['name'] === '', 'New game did not clear recommendation state');
    $page->editGame('minecraft-java');
    $page->updatedForm('eco', 'name');
    $beforeSelection = $page->form;
    $page->selectGameSearchResult(0);
    profileCheck($page->form === $beforeSelection && str_contains($page->gameSearchMessage, 'stable key'), 'Restricted capability applied to incompatible key');
    $page->editGame('eco');
    $page->updatedForm('eco', 'name');
    $page->selectGameSearchResult(0);
    profileCheck($page->editing === 'eco' && $page->form['capability'] === 'eco-modkit', 'Matching existing capability rejected');
    $page->gameSearchResults = [['catalog' => 'profile', 'id' => 'missing']];
    $invalid = false;
    try { $page->selectGameSearchResult(0); } catch (RuntimeException) { $invalid = true; }
    profileCheck($invalid, 'Missing profile accepted');
    // Seed v5 upgrades only the exact first-party Minecraft
    // Modrinth runtime mapping shipped by v4. Administrator changes
    // must survive unchanged.
    $oldSeedVersion = $settings['game_definition_seed_version'];

    // Exercise the historical v4 -> v5 migration in isolation.
    // Later seed migrations must not participate in this fixture.
    $settings['game_definition_seed_version'] = 5;

    $oldModrinthRuntime = [
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
    ];

    $recommendedModrinthRuntime =
        $catalog->get('minecraft-java')['defaults']
        ['runtime_metadata']['modrinth'];

    $minecraftV4 =
        $catalog->get('minecraft-java')['defaults'];

    $minecraftV4['enabled'] = false;
    $minecraftV4['detection'] = [
        'egg_ids' => [],
        'egg_names' => ['Minecraft'],
        'egg_name_contains' => [],
    ];

    $minecraftV4 =
        $store->validate(
            $minecraftV4
        )->data();

    // Build a real v4 catalog in an isolated directory.
    $migrationDirectory =
        sys_get_temp_dir()
        . '/modharbor-profile-v5-'
        . bin2hex(random_bytes(8));

    mkdir($migrationDirectory, 0700, true);

    $migrationStore = new GameDefinitionStore($migrationDirectory);
    $migrationStore->snapshot();

    $migrationCatalogPath =
        $migrationDirectory
        . '/catalog.json';

    $migrationCatalog =
        json_decode(
            file_get_contents($migrationCatalogPath),
            true,
            32,
            JSON_THROW_ON_ERROR
        );

    $migrationCatalog['seed_version'] = 4;
    $migrationCatalog['definitions']['minecraft-java'] =
        $minecraftV4;

    $migrationCatalog[
        'definitions'
    ][
        'minecraft-java'
    ][
        'runtime_metadata'
    ][
        'modrinth'
    ] = $oldModrinthRuntime;

    file_put_contents(
        $migrationCatalogPath,
        json_encode(
            $migrationCatalog,
            JSON_PRETTY_PRINT
            | JSON_UNESCAPED_SLASHES
            | JSON_THROW_ON_ERROR
        )
    );

    $migrated = $migrationStore->snapshot();

    profileCheck(
        ($migrated['seed_version'] ?? null) === 5
        && (
            $migrated[
                'definitions'
            ][
                'minecraft-java'
            ][
                'runtime_metadata'
            ][
                'modrinth'
            ]
            ?? null
        ) === $recommendedModrinthRuntime,
        'Seed v5 did not upgrade exact first-party Modrinth runtime metadata'
    );

    // Repeat with an administrator-customized v4 mapping.
    $customDirectory =
        sys_get_temp_dir()
        . '/modharbor-profile-v5-custom-'
        . bin2hex(random_bytes(8));

    mkdir($customDirectory, 0700, true);

    $customStore = new GameDefinitionStore($customDirectory);
    $customStore->snapshot();

    $customCatalogPath =
        $customDirectory
        . '/catalog.json';

    $customCatalog =
        json_decode(
            file_get_contents($customCatalogPath),
            true,
            32,
            JSON_THROW_ON_ERROR
        );

    $customRuntime = $oldModrinthRuntime;
    $customRuntime['loader']['map']['neoforge'] = 'my-neoforge';

    $customCatalog['seed_version'] = 4;
    $customCatalog['definitions']['minecraft-java'] =
        $minecraftV4;

    $customCatalog[
        'definitions'
    ][
        'minecraft-java'
    ][
        'runtime_metadata'
    ][
        'modrinth'
    ] = $customRuntime;

    file_put_contents(
        $customCatalogPath,
        json_encode(
            $customCatalog,
            JSON_PRETTY_PRINT
            | JSON_UNESCAPED_SLASHES
            | JSON_THROW_ON_ERROR
        )
    );

    $customMigrated = $customStore->snapshot();

    profileCheck(
        ($customMigrated['seed_version'] ?? null) === 5
        && (
            $customMigrated[
                'definitions'
            ][
                'minecraft-java'
            ][
                'runtime_metadata'
            ][
                'modrinth'
            ]
            ?? null
        ) === $customRuntime,
        'Seed v5 overwrote administrator-customized runtime metadata'
    );

    $settings['game_definition_seed_version'] = $oldSeedVersion;

    // Seed v8 clears the former bundled Minecraft artwork reference while
    // preserving administrator custom artwork. v7 inherit behaviour is
    // obsolete because first-party seeds no longer ship bundled art.
    $minecraftV6 =
        $catalog->get('minecraft-java')['defaults'];

    $minecraftV6['enabled'] = false;
    $minecraftV6['detection'] = [
        'egg_ids' => [],
        'egg_names' => ['Minecraft'],
        'egg_name_contains' => [],
    ];

    $minecraftV6 =
        $store->validate(
            $minecraftV6
        )->data();

    // Blank artwork stays blank through migration to current seed.
    $blankArtworkDirectory =
        sys_get_temp_dir()
        . '/modharbor-profile-v8-blank-'
        . bin2hex(random_bytes(8));

    mkdir(
        $blankArtworkDirectory,
        0700,
        true
    );

    $blankArtworkStore =
        new GameDefinitionStore(
            $blankArtworkDirectory
        );

    $blankArtworkStore->snapshot();

    $blankArtworkCatalogPath =
        $blankArtworkDirectory
        . '/catalog.json';

    $blankArtworkCatalog =
        json_decode(
            file_get_contents(
                $blankArtworkCatalogPath
            ),
            true,
            32,
            JSON_THROW_ON_ERROR
        );

    $blankArtworkCatalog['seed_version'] = 6;
    $blankArtworkCatalog['definitions']['minecraft-java'] =
        $minecraftV6;

    $blankArtworkCatalog[
        'definitions'
    ][
        'minecraft-java'
    ][
        'artwork_url'
    ] = '';

    file_put_contents(
        $blankArtworkCatalogPath,
        json_encode(
            $blankArtworkCatalog,
            JSON_PRETTY_PRINT
            | JSON_UNESCAPED_SLASHES
            | JSON_THROW_ON_ERROR
        )
    );

    $blankArtworkMigrated =
        $blankArtworkStore->snapshot();

    profileCheck(
        ($blankArtworkMigrated['seed_version'] ?? null) === $oldSeedVersion
        && (
            $blankArtworkMigrated[
                'definitions'
            ][
                'minecraft-java'
            ][
                'artwork_url'
            ]
            ?? null
        ) === '',
        'Seed v8 left blank first-party artwork non-blank'
    );

    // Legacy bundled reference is cleared on upgrade to seed 8.
    $bundledArtworkDirectory =
        sys_get_temp_dir()
        . '/modharbor-profile-v8-bundled-'
        . bin2hex(random_bytes(8));

    mkdir(
        $bundledArtworkDirectory,
        0700,
        true
    );

    $bundledArtworkStore =
        new GameDefinitionStore(
            $bundledArtworkDirectory
        );

    $bundledArtworkStore->snapshot();

    $bundledArtworkCatalogPath =
        $bundledArtworkDirectory
        . '/catalog.json';

    $bundledArtworkCatalog =
        json_decode(
            file_get_contents(
                $bundledArtworkCatalogPath
            ),
            true,
            32,
            JSON_THROW_ON_ERROR
        );

    $bundledArtworkCatalog['seed_version'] = 7;
    $bundledArtworkCatalog['definitions']['minecraft-java'] =
        $minecraftV6;

    $bundledArtworkCatalog[
        'definitions'
    ][
        'minecraft-java'
    ][
        'artwork_url'
    ] = '@artwork/minecraft-java.webp';

    file_put_contents(
        $bundledArtworkCatalogPath,
        json_encode(
            $bundledArtworkCatalog,
            JSON_PRETTY_PRINT
            | JSON_UNESCAPED_SLASHES
            | JSON_THROW_ON_ERROR
        )
    );

    $bundledArtworkMigrated =
        $bundledArtworkStore->snapshot();

    profileCheck(
        ($bundledArtworkMigrated['seed_version'] ?? null) === $oldSeedVersion
        && (
            $bundledArtworkMigrated[
                'definitions'
            ][
                'minecraft-java'
            ][
                'artwork_url'
            ]
            ?? null
        ) === '',
        'Seed v8 did not clear legacy bundled Minecraft artwork'
    );

    // Existing administrator artwork must remain authoritative.
    $customArtworkDirectory =
        sys_get_temp_dir()
        . '/modharbor-profile-v8-custom-art-'
        . bin2hex(random_bytes(8));

    mkdir(
        $customArtworkDirectory,
        0700,
        true
    );

    $customArtworkStore =
        new GameDefinitionStore(
            $customArtworkDirectory
        );

    $customArtworkStore->snapshot();

    $customArtworkCatalogPath =
        $customArtworkDirectory
        . '/catalog.json';

    $customArtworkCatalog =
        json_decode(
            file_get_contents(
                $customArtworkCatalogPath
            ),
            true,
            32,
            JSON_THROW_ON_ERROR
        );

    $customArtwork =
        'https://example.com/admin-minecraft.webp';

    $customArtworkCatalog['seed_version'] = 6;
    $customArtworkCatalog['definitions']['minecraft-java'] =
        $minecraftV6;

    $customArtworkCatalog[
        'definitions'
    ][
        'minecraft-java'
    ][
        'artwork_url'
    ] = $customArtwork;

    file_put_contents(
        $customArtworkCatalogPath,
        json_encode(
            $customArtworkCatalog,
            JSON_PRETTY_PRINT
            | JSON_UNESCAPED_SLASHES
            | JSON_THROW_ON_ERROR
        )
    );

    $customArtworkMigrated =
        $customArtworkStore->snapshot();

    profileCheck(
        ($customArtworkMigrated['seed_version'] ?? null) === $oldSeedVersion
        && (
            $customArtworkMigrated[
                'definitions'
            ][
                'minecraft-java'
            ][
                'artwork_url'
            ]
            ?? null
        ) === $customArtwork,
        'Seed v8 overwrote administrator-customized artwork'
    );

    // Runtime metadata schema accepts generic list wrapping and
    // rejects non-boolean list configuration.
    $runtimeDefinition = $catalog->get('minecraft-java')['defaults'];
    $runtimeDefinition['detection'] = [
        'egg_names' => ['Runtime Test'],
        'egg_ids' => [],
    ];

    $validatedRuntime = $store->validate($runtimeDefinition)->data();

    profileCheck(
        ($validatedRuntime['runtime_metadata']['modrinth']['game_versions']['list'] ?? null) === true
        && ($validatedRuntime['runtime_metadata']['modrinth']['loaders']['list'] ?? null) === true,
        'Runtime metadata list wrapping was not preserved'
    );

    $invalidRuntime = $runtimeDefinition;
    $invalidRuntime['runtime_metadata']['modrinth']['loaders']['list'] = 'yes';

    $invalidListRejected = false;

    try {
        $store->validate($invalidRuntime);
    } catch (RuntimeException $e) {
        $invalidListRejected = str_contains(
            $e->getMessage(),
            'list must be boolean'
        );
    }

    profileCheck(
        $invalidListRejected,
        'Non-boolean runtime metadata list configuration was accepted'
    );

    $icarusDirectory =
        sys_get_temp_dir()
        . '/modharbor-profile-v11-'
        . bin2hex(random_bytes(8));
    mkdir($icarusDirectory, 0700, true);
    $icarusStore = new GameDefinitionStore($icarusDirectory);
    $icarusStore->snapshot();
    $icarusCatalogPath = $icarusDirectory . '/catalog.json';
    $icarusCatalog = json_decode(file_get_contents($icarusCatalogPath), true, 32, JSON_THROW_ON_ERROR);
    profileCheck(isset($icarusCatalog['definitions']['icarus']), 'ICARUS seed missing');
    $icarusCatalog['seed_version'] = 10;
    $icarusCatalog['definitions']['icarus']['sources']['7daystodiemods'] = ['metadata' => []];
    $icarusCatalog['definitions']['icarus']['sources']['umod'] = ['metadata' => ['categories' => ['rust']]];
    $icarusCatalog['definitions']['icarus']['sources']['modrinth'] = ['metadata' => ['project_type' => 'mod']];
    file_put_contents(
        $icarusCatalogPath,
        json_encode($icarusCatalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
    );
    $icarusMigrated = $icarusStore->snapshot();
    $icarusSources = $icarusMigrated['definitions']['icarus']['sources'] ?? [];
    profileCheck(
        ($icarusMigrated['seed_version'] ?? null) === $oldSeedVersion
        && !isset($icarusSources['7daystodiemods'])
        && !isset($icarusSources['umod'])
        && !isset($icarusSources['modrinth'])
        && isset($icarusSources['steam-workshop'])
        && isset($icarusSources['nexus']),
        'Seed v11 did not strip leftover ICARUS sources'
    );

    $minecraftSgdbDirectory =
        sys_get_temp_dir()
        . '/modharbor-profile-v12-'
        . bin2hex(random_bytes(8));
    mkdir($minecraftSgdbDirectory, 0700, true);
    $minecraftSgdbStore = new GameDefinitionStore($minecraftSgdbDirectory);
    $minecraftSgdbStore->snapshot();
    $minecraftSgdbPath = $minecraftSgdbDirectory . '/catalog.json';
    $minecraftSgdbCatalog = json_decode(file_get_contents($minecraftSgdbPath), true, 32, JSON_THROW_ON_ERROR);
    $minecraftSgdbCatalog['seed_version'] = 11;
    $minecraftSgdbCatalog['definitions']['minecraft-java']['steamgriddb_game_id'] = null;
    file_put_contents(
        $minecraftSgdbPath,
        json_encode($minecraftSgdbCatalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
    );
    $minecraftSgdbMigrated = $minecraftSgdbStore->snapshot();
    profileCheck(
        ($minecraftSgdbMigrated['seed_version'] ?? null) === $oldSeedVersion
        && ($minecraftSgdbMigrated['definitions']['minecraft-java']['steamgriddb_game_id'] ?? null) === 5248835,
        'Seed v12 did not pin Minecraft SteamGridDB ID'
    );

    $admin = false;
    $denied = false;
    try { $page->selectGameSearchResult(0); } catch (RuntimeException $e) { $denied = $e->getMessage() === '403'; }
    profileCheck($denied, 'Profile action lacked authorization');
    $admin = true;
    echo "$assertions game profile assertions passed.\n";
}
