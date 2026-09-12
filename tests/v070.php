<?php

use App\Models\Server;
use App\Repositories\Daemon\{DaemonFileRepository, DaemonServerRepository};
use GameNest\GameNestModManager\Adapters\ConfiguredGameAdapter;
use GameNest\GameNestModManager\Services\{AdapterRegistry, ConfiguredDriverResolver, ConfiguredLifecycleDriver, ConfiguredPackagePlan,
    GameDefinition, GameDefinitionStore, SourceRegistry, ProviderContext, UploadPackageStore, ManifestService, ManagedFileScanner,
    SafeRemoteDownloader, ConfigManagementService, ModLifecycleService};
use GameNest\GameNestModManager\Providers\UploadProvider;

function game070(): array { return array_replace(json_decode(file_get_contents(__DIR__ . '/../examples/7-days-to-die.modharbor.json'), true, 16, JSON_THROW_ON_ERROR), ['key' => 'fixture-seven-days']); }
function definition070(array $data): GameDefinition { return GameDefinition::validate($data, ['upload', 'github', 'direct', 'modio', 'umod'], []); }
function test070(string $name, callable $test): void
{
    $GLOBALS['tests']['Game Builder: ' . $name] = function () use ($test) {
        $GLOBALS['configOverrides'] = [];
        try { $test(); } finally { $GLOBALS['configOverrides'] = []; }
    };
}
function zip070(array $entries, bool $link = false): string
{
    $file = tempnam(sys_get_temp_dir(), 'zip070-');
    $zip = new ZipArchive;
    $zip->open($file, ZipArchive::OVERWRITE);
    foreach ($entries as $name => $contents) { $zip->addFromString($name, $contents); }
    if ($link) { $zip->setExternalAttributesIndex(0, 3, 0120777 << 16); }
    $zip->close(); $contents = file_get_contents($file); unlink($file); return $contents;
}
class Upload070 extends UploadPackageStore
{
    public string $owner = 'test-server';
    public string $body = '';
    public string $filename = 'example.zip';
    public function get(string $id): ?array { return ['id' => $id, 'server_uuid' => $this->owner, 'filename' => $this->filename, 'name' => 'Test mod', 'size' => strlen($this->body), 'version_label' => '1.0']; }
    public function contents(string $id): string { return $this->body; }
    public function delete(string $id, ?string $serverUuid = null): bool { return true; }
}
function fixture070(?array $definition = null): array
{
    $f = fixture();
    $f[0]->egg->name = 'ModHarbor Fixture Game';
    $GLOBALS['configOverrides']['gamenest-mod-manager.game_builder.enabled'] = true;

    $definition ??= game070();
    $definition['enabled'] = true;

    // Generic Game Builder lifecycle tests must not collide with
    // the seeded 7-days-to-die definition. Ambiguity is tested
    // explicitly in its own test below.
    $definition['detection']['egg_ids'] = [];
    $definition['detection']['egg_names'] = ['ModHarbor Fixture Game'];
    $definition['detection']['egg_name_contains'] = [];
    $store = new GameDefinitionStore;
    $snapshot = $store->snapshot();

    if (isset($snapshot['definitions'][$definition['key']])) {
        $store->save(
            $definition,
            $snapshot['revision'],
            false
        );
    } else {
        $store->save(
            $definition,
            $snapshot['revision'],
            true
        );
    }

    $GLOBALS['services'][GameDefinitionStore::class] = $store;
    $registry = new AdapterRegistry;
    $GLOBALS['services'][AdapterRegistry::class] = $registry;
    $GLOBALS['services'][SourceRegistry::class] = new SourceRegistry($registry, new ProviderContext($registry));
    $upload = new Upload070;
    $upload->body = zip070(['Example/ModInfo.xml' => '<xml/>', 'Example/main.dll' => 'binary']);
    $GLOBALS['services'][UploadPackageStore::class] = $upload;
    $GLOBALS['services'][UploadProvider::class] = new UploadProvider($upload);
    return array_merge($f, [$store, $upload]);
}

test070('251570 round trip and provider metadata resolution', function () {
    $d = game070(); $d['sources']['github']['metadata'] = ['repository' => 'owner/project', 'loaders' => ['server'], 'app_id' => 251570];
    $definition = definition070($d);
    check(GameDefinition::fromJson($definition->json(), ['upload', 'github', 'direct'])->data() === $definition->data(), 'Round trip changed data');
    [$server] = fixture070($d);
    $registry = app(SourceRegistry::class);
    check($registry->context($server, 'github')->gameKey() === 'fixture-seven-days', 'Built-in shadowed custom game');
    check($registry->context($server, 'github')->require('steam_app_id') === 251570, 'Steam metadata missing');
    check($registry->context($server, 'github')->require('repository') === 'owner/project', 'Source metadata missing');
    check($registry->context($server, 'github')->require('app_id') === 251570, 'Provider-specific ID missing');
    check($registry->sourceKeys($server) === ['upload', 'github', 'direct'], 'Generic sources not available');
});
test070('store create edit stale write toggle delete and key collision', function () {
    fixture();
    $store = new GameDefinitionStore;
    $snapshot = $store->snapshot();

    $d = game070();
    $d['key'] = 'crud-test-game';
    $d['name'] = 'CRUD Test Game';
    $d['enabled'] = false;
    $d['detection']['egg_names'] = ['CRUD Test Game'];
    $d['detection']['egg_ids'] = [];
    $d['detection']['egg_name_contains'] = [];

    $a = $store->save($d, $snapshot['revision'], true);

    fails(
        fn () => $store->save($d, $a['revision'], true),
        'already exists'
    );

    $d['name'] = 'CRUD Test Game Edited';

    $b = $store->save(
        $d,
        $a['revision'],
        false
    );

    fails(
        fn () => $store->save($d, $a['revision'], false),
        'changed'
    );

    $c = $store->toggle(
        $d['key'],
        $b['revision']
    );

    fails(
        fn () => $store->delete($d['key'], $c['revision']),
        'Disable'
    );

    $e = $store->toggle(
        $d['key'],
        $c['revision']
    );

    $store->delete(
        $d['key'],
        $e['revision']
    );

    check(
        !isset($store->snapshot()['definitions'][$d['key']]),
        'Delete failed'
    );
});
test070('disabled configured game does not resolve', function () {
    [$s,,,,,,$store] = fixture070(); $snapshot = $store->snapshot();
    $store->toggle('fixture-seven-days', $snapshot['revision']);
    check((new AdapterRegistry)->forServer($s) === null, 'Disabled definition fell through');
    check(!(new ModLifecycleService(new AdapterRegistry, new ManifestService, new \GameNest\GameNestModManager\Services\OperationStore))->supported($s, 'upload'), 'Disabled lifecycle exposed');
});
test070('ambiguous custom detection fails closed', function () {
    [$s,,,,,,$store] = fixture070();

    $d = game070();
    $d['key'] = 'other-game';
    $d['name'] = 'Other Game';
    $d['enabled'] = true;
    $d['detection']['egg_ids'] = [];
    $d['detection']['egg_names'] = ['ModHarbor Fixture Game'];
    $d['detection']['egg_name_contains'] = [];

    $store->save(
        $d,
        $store->snapshot()['revision'],
        true
    );

    fails(
        fn () => (new AdapterRegistry)->forServer($s),
        'Multiple'
    );
});
test070('exact egg names and egg ID precedence', function () {
    $d = game070(); $d['enabled'] = true; $adapter = new ConfiguredGameAdapter(definition070($d)); $s = new Server;
    $s->egg->name = '7 Days to Die - unrelated'; check(!$adapter->supports($s), 'Substring matched');
    $d['detection']['egg_ids'] = [42]; $adapter = new ConfiguredGameAdapter(definition070($d));
    $s->egg->id = 42; check($adapter->supports($s), 'Egg ID not detected');
    $s->egg->id = 43;
    $s->egg->name = '7 Days to Die';
    check(
        $adapter->supports($s),
        'Exact egg name should remain a valid detection signal'
    );
});
test070('BepInEx scan skips loader core and patchers', function () {
    $d = game070();
    $d['enabled'] = true;
    $d['mod_directories'] = ['BepInEx', 'BepInEx/plugins'];
    $d['deployment']['target'] = 'BepInEx';
    $adapter = new ConfiguredGameAdapter(definition070($d));
    $rules = $adapter->scanRules(new Server);
    $bepinex = null;
    foreach ($rules as $rule) {
        if (($rule['root'] ?? '') === 'BepInEx') {
            $bepinex = $rule;
        }
    }
    check(
        is_array($bepinex)
        && in_array('core', $bepinex['exclude'] ?? [], true)
        && in_array('patchers', $bepinex['exclude'] ?? [], true)
        && in_array('cache', $bepinex['exclude'] ?? [], true),
        'BepInEx loader directories are still treated as unmanaged mods'
    );
});
test070('seeded games resolve alongside configured games', function () {
    [$s] = fixture070();

    $s->egg->name = 'Eco';
    $eco = (new AdapterRegistry)->forServer($s);

    check(
        $eco instanceof ConfiguredGameAdapter,
        'Eco did not resolve through Game Builder'
    );
    check(
        $eco->key() === 'eco',
        'Eco changed'
    );
    check(
        $eco->capability() === 'eco-modkit',
        'Eco capability changed'
    );

    $s->egg->name = 'Rust';
    $rust = (new AdapterRegistry)->forServer($s);

    check(
        $rust instanceof ConfiguredGameAdapter,
        'Rust did not resolve through Game Builder'
    );
    check(
        $rust->key() === 'rust',
        'Rust changed'
    );
    check(
        $rust->capability() === 'rust-carbon-oxide',
        'Rust capability changed'
    );

    $all = (new AdapterRegistry)->all();
    $keys = array_map(
        static fn ($adapter) => $adapter->key(),
        $all
    );

    check(
        in_array('eco', $keys, true)
            && in_array('rust', $keys, true)
            && in_array('fixture-seven-days', $keys, true),
        'Registry lost an enabled configured game'
    );

    check(
        !in_array('7-days-to-die', $keys, true),
        'Disabled configured game was exposed by the active registry'
    );

    $definitions = app(GameDefinitionStore::class)->snapshot()['definitions'];

    check(
        isset($definitions['7-days-to-die'])
            && $definitions['7-days-to-die']['enabled'] === false,
        'Disabled seeded game was lost from Game Builder'
    );
});
foreach (['../Mods', '/Mods', 'C:/Mods', 'Mods\\bad', '.gamenest', 'Mods/../Config', 'Mods/.hidden', 'Mods/NUL', 'Mods/trailing.', 'Mods/trailing '] as $path) {
    test070('reject unsafe directory ' . $path, function () use ($path) { $d = game070(); $d['config_directories'] = [$path]; fails(fn () => definition070($d)); });
}
test070('reject malformed fields, providers, types and executable hooks', function () {
    foreach ([['schema_version', 2], ['enabled', 'false'], ['steam_app_id', '251570'], ['key', 'BAD KEY!'], ['name', ''], ['sources', ['steam-workshop' => ['metadata' => []]]], ['detection', ['egg_ids' => [], 'egg_names' => []]], ['package_types', ['php']]] as [$field, $value]) {
        $d = game070(); $d[$field] = $value; fails(fn () => definition070($d));
    }
    $d = game070(); $d['php'] = 'system("id")'; fails(fn () => definition070($d));
    $d = game070(); $d['sources']['upload']['metadata']['driver'] = 'SomeClass'; fails(fn () => definition070($d));
    fails(fn () => GameDefinition::fromJson('{bad', []));
    fails(fn () => GameDefinition::fromJson(str_repeat(' ', 65537), []));
});
test070('corrupt persisted catalog blocks resolution', function () {
    [$s] = fixture070(); file_put_contents(storage_path('app/gamenest-mod-manager/game-builder/catalog.json'), '{corrupt');
    fails(fn () => (new AdapterRegistry)->forServer($s));
});
test070('provider-managed is unavailable without trusted factory', function () {
    $d = game070(); $d['deployment']['strategy'] = 'provider-managed';
    [$s] = fixture070($d);
    check(app(SourceRegistry::class)->sourceKeys($s) === [], 'Unimplemented provider-managed install exposed');
    fails(fn () => (new ConfiguredDriverResolver)->create($s, (new AdapterRegistry)->forServer($s), 'upload'));
});
test070('ZIP extraction prefix and copy deployment plans', function () {
    $plan = new ConfiguredPackagePlan; $d = game070(); $d['deployment']['archive_prefix'] = 'Mods';
    check($plan->files(zip070(['Mods/Test/a.dll' => 'bytes']), 'mod.zip', definition070($d)) === ['Mods/Test/a.dll' => 'bytes'], 'Wrong prefix mapping');
    $d['deployment']['strategy'] = 'copy'; $d['package_types'] = ['dll'];
    check($plan->files('binary', 'mod.dll', definition070($d)) === ['Mods/mod.dll' => 'binary'], 'Copy failed');
    fails(fn () => $plan->files('binary', '../mod.dll', definition070($d)));
});
test070('archive traversal symlinks collisions wrong prefix and empty rejected', function () {
    $plan = new ConfiguredPackagePlan; $d = game070();
    foreach ([['../escaped.dll'=>'x'], ['/root.dll'=>'x'], ['A.dll'=>'x', 'a.dll'=>'y'], ['a.dll'=>'x', 'a.dll/b.dll'=>'y'], ['.hidden/a'=>'x']] as $entries) {
        fails(fn () => $plan->files(zip070($entries), 'mod.zip', definition070($d)));
    }
    fails(fn () => $plan->files(zip070(['link'=>'target'], true), 'mod.zip', definition070($d)));
    fails(fn () => $plan->files('not zip', 'mod.zip', definition070($d)));
    $d['deployment']['archive_prefix'] = 'Mods';
    fails(fn () => $plan->files(zip070(['Outside/a.dll'=>'x']), 'mod.zip', definition070($d)));
});
test070('custom upload install disable enable reinstall remove audited through shared engine', function () {
    [$s,$r,,$manifest,$store,$engine] = fixture070(); $key = 'upload:' . str_repeat('a', 40);
    $engine->run($s, 'install', $key);
    check($r->files['Mods/Example/main.dll'] === 'binary', 'No deployed binary');
    check($manifest->read($s)['game'] === 'fixture-seven-days', 'Wrong manifest game');
    $engine->run($s, 'disable', $key); check(!isset($r->files['Mods/Example/main.dll']), 'Disable did not move binary');
    $engine->run($s, 'enable', $key); $engine->run($s, 'reinstall', $key);
    $engine->run($s, 'remove', $key); check(!isset($r->files['Mods/Example/main.dll']), 'Remove failed');
    check(count($store->history($s)) === 5, 'Missing audit events');
});
test070('custom install partial move failure restores pre-operation files', function () {
    [$s,$r,,$manifest,,$engine] = fixture070(); $r->failAt = 2;
    fails(fn () => $engine->run($s, 'install', 'upload:' . str_repeat('b', 40)));
    check(!isset($r->files['Mods/Example/main.dll']) && !isset($r->files['Mods/Example/ModInfo.xml']), 'Partial install left files');
    check($manifest->mods($s) === [], 'Failed install altered manifest');
});
test070('wrong-server uploads and denied permissions cannot deploy', function () {
    [$s,$r,,,,$engine,,$upload] = fixture070(); $upload->owner = 'other-server';
    fails(fn () => $engine->run($s, 'install', 'upload:' . str_repeat('b', 40)));
    check(!isset($r->files['Mods/Example/main.dll']), 'Cross-server upload deployed');
    \Illuminate\Support\Facades\Gate::$allow = false;
    fails(fn () => $engine->run($s, 'install', 'upload:' . str_repeat('b', 40)), 'Denied');
});
test070('running policy defaults closed and opt-in allows only running or offline', function () {
    [$s,,,,,$engine] = fixture070(); app(DaemonServerRepository::class)->state = 'running';
    fails(fn () => $engine->run($s, 'install', 'upload:' . str_repeat('c', 40)), 'Stop');
    $d = game070(); $d['behavior']['install_while_running'] = true;
    [$s,$r,,,,$engine] = fixture070($d); app(DaemonServerRepository::class)->state = 'running';
    $engine->run($s, 'install', 'upload:' . str_repeat('c', 40));
    check(isset($r->files['Mods/Example/main.dll']), 'Explicit running policy ignored');
    app(DaemonServerRepository::class)->state = 'starting';
    fails(fn () => $engine->run($s, 'disable', 'upload:' . str_repeat('c', 40)), 'Stop');
});
test070('configured scanner and config validation use shared services', function () {
    [$s,$r] = fixture070(); seedFiles($r, ['Mods/Example/main.dll'=>'x', 'Configs/Test.json'=>'{"v":1}']);
    check(in_array('Mods/Example/main.dll', array_column((new ManagedFileScanner)->scan($s), 'path'), true), 'Custom scan missing');
    check((new ConfigManagementService)->read($s, 'Configs/Test.json') === '{"v":1}', 'Config read failed');
    fails(fn () => (new ConfigManagementService)->validate($s, 'Configs/Test.json', '{bad'));
});

class Download070 extends SafeRemoteDownloader
{
    public string $body = '';
    public function __construct() {}
    public function download(string $url, int $maxBytes): string { return $this->body; }
}
test070('Direct ZIP and GitHub pinned asset use shared generic deployment', function () {
    [$s,$r,,,,$engine] = fixture070();
    $download = new Download070;
    $download->body = zip070(['DirectMod/a.dll' => 'direct bytes']);
    $GLOBALS['services'][SafeRemoteDownloader::class] = $download;
    $direct = new \GameNest\GameNestModManager\Providers\DirectDownloadProvider;
    $id = $direct->makeId('https://example.org/test.zip');
    $engine->run($s, 'install', 'direct:' . $id);
    check($r->files['Mods/DirectMod/a.dll'] === 'direct bytes', 'Direct path failed');
    $download->body = zip070(['GitMod/a.dll' => 'pinned bytes']);
    $engine->run($s, 'install', 'github:9001', 420);
    check($r->files['Mods/GitMod/a.dll'] === 'pinned bytes', 'GitHub path failed');
    unset($GLOBALS['services'][\GameNest\GameNestModManager\Providers\GitHubProvider::class]->assets[9001][420]);
    fails(fn () => $engine->run($s, 'reinstall', 'github:9001'));
});
test070('metadata changes invalidate source cache identity', function () {
    $a = new \GameNest\GameNestModManager\Services\SourceContext('sample', 'Sample', 'modio', ['game_id' => 1]);
    $b = new \GameNest\GameNestModManager\Services\SourceContext('sample', 'Sample', 'modio', ['game_id' => 2]);
    check($a->cacheKey('search') !== $b->cacheKey('search'), 'Stale source metadata cache collision');
});
