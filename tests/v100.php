<?php

use GameNest\GameNestModManager\Services\{DiskSpaceGuard, RestartTracker, ModBatchService, ConfigManagementService};

$tests['1.0 restart state persists batches and only acknowledges while running'] = function () {
    [$server, $repo, $provider, $manifest, $store, $engine] = fixture();
    $engine->run($server, 'install', 'modio:88');
    $engine->run($server, 'disable', 'modio:88');
    $tracker = new RestartTracker;
    check(count($tracker->pending($server)) === 2, 'Changes did not accumulate');
    fails(fn () => $tracker->confirm($server), 'Start the server');
    app(\App\Repositories\Daemon\DaemonServerRepository::class)->state = 'running';
    $tracker->confirm($server);
    check((new RestartTracker)->pending($server) === [], 'Acknowledgement did not persist');
    app(\App\Repositories\Daemon\DaemonServerRepository::class)->state = 'offline';
    $engine->run($server, 'enable', 'modio:88');
    check(count($tracker->pending($server)) === 1, 'New changes were incorrectly acknowledged');
};
$tests['1.0 failed changes do not request restart and restart state is server isolated'] = function () {
    [$server, $repo, $provider, $manifest, $store, $engine] = fixture();
    $repo->failAt = 1;
    fails(fn () => $engine->run($server, 'install', 'modio:88'));
    check((new RestartTracker)->pending($server) === [], 'Failed operation needs restart');
    $repo->failAt = null;
    $engine->run($server, 'install', 'modio:88');
    $other = new \App\Models\Server; $other->uuid = 'other-tenant';
    check((new RestartTracker)->pending($other) === [], 'Cross-server restart data leaked');
    \Illuminate\Support\Facades\Gate::$allow = false;
    try { fails(fn () => (new RestartTracker)->pending($server), 'Denied'); }
    finally { \Illuminate\Support\Facades\Gate::$allow = true; }
};
$tests['1.0 quota guard rejects low or unknown resources and allows exact headroom'] = function () {
    fixture();
    $server = new class extends \App\Models\Server { public int $disk = 10; };
    $daemon = new class extends \App\Repositories\Daemon\DaemonServerRepository {
        public ?int $usage = 9 * 1024 * 1024;
        public function getDetails(): array { return ['state' => 'offline', 'resources' => ['disk_bytes' => $this->usage]]; }
    };
    $GLOBALS['services'][\App\Repositories\Daemon\DaemonServerRepository::class] = $daemon;
    fails(fn () => DiskSpaceGuard::server($server, 1), 'Insufficient');
    $daemon->usage = null;
    fails(fn () => DiskSpaceGuard::server($server, 1), 'Cannot verify');
    $daemon->usage = 7 * 1024 * 1024;
    DiskSpaceGuard::server($server, 1024 * 1024);
    fails(fn () => DiskSpaceGuard::server($server, 1024 * 1024 + 1), 'Insufficient');
};
$tests['1.0 quota rejection precedes any lifecycle writes'] = function () {
    [$base, $repo, $provider, $manifest, $store, $engine] = fixture();
    $server = new class extends \App\Models\Server { public int $disk = 1; };
    $GLOBALS['services'][\App\Repositories\Daemon\DaemonServerRepository::class] = new class extends \App\Repositories\Daemon\DaemonServerRepository {
        public function getDetails(): array { return ['state' => 'offline', 'resources' => ['disk_bytes' => 0]]; }
    };
    fails(fn () => $engine->run($server, 'install', 'modio:88'), 'Insufficient');
    check($repo->files === [] && $repo->moves === 0, 'Quota rejection changed server files');
};
$tests['1.0 bulk removal orders parents before selected dependencies'] = function () {
    [$server, $repo, $provider, $manifest, $store, $engine] = fixture();
    $GLOBALS['services'][\GameNest\GameNestModManager\Services\ModLifecycleService::class] = $engine;
    $engine->run($server, 'install', 'modio:77');
    $result = (new ModBatchService)->run($server, 'remove', ['modio:3561559', 'modio:77']);
    check($result['failed'] === null && $manifest->mods($server) === [], 'Batch dependency order failed');
    check($result['completed'][0] === 'modio:77', 'Parent not removed first');
};
$tests['1.0 batch validates every selection before writes and stops after failure'] = function () {
    [$server, $repo, $provider, $manifest, $store, $engine] = fixture();
    $GLOBALS['services'][\GameNest\GameNestModManager\Services\ModLifecycleService::class] = $engine;
    $engine->run($server, 'install', 'modio:77'); $engine->run($server, 'install', 'modio:88');
    $before = $repo->files;
    fails(fn () => (new ModBatchService)->run($server, 'remove', ['modio:88', 'modio:missing']));
    check($repo->files === $before, 'Stale batch partially ran');
    $repo->failAt = $repo->moves + 1;
    $result = (new ModBatchService)->run($server, 'disable', ['modio:88', 'modio:77']);
    check($result['failed'] === 'modio:88' && $result['remaining'] === ['modio:77'], 'Batch continued after failure');
    check($repo->files === $before, 'Failed batch package did not roll back');
};
$tests['1.0 XML configs reject malformed markup and external entities'] = function () {
    [$server] = fixture();
    $service = new ConfigManagementService;
    $service->validate($server, 'Configs/settings.xml', '<settings><value>safe</value></settings>');
    fails(fn () => $service->validate($server, 'Configs/settings.xml', '<settings>'), 'Invalid XML');
    fails(fn () => $service->validate($server, 'Configs/settings.xml', '<!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><x>&e;</x>'), 'entities');
};
test070('declarative config rules round trip reject unsafe roots and preserve selected XML', function () {
    [$server, $repo, $provider, $manifest, $store, $engine, $definition] = fixture070();
    // fixture070 returns its definition through the configured adapter.
    $data = (new \GameNest\GameNestModManager\Services\AdapterRegistry)->forServer($server)->definition();
    $data['config_directories'] = ['Mods'];
    $data['config_rules'] = [['root' => 'Mods', 'patterns' => ['settings.xml'], 'exclude' => ['*.bak*'], 'depth' => 4]];
    $validated = \GameNest\GameNestModManager\Services\GameDefinition::validate($data, array_keys($data['sources']));
    $adapter = new \GameNest\GameNestModManager\Adapters\ConfiguredGameAdapter($validated);
    $driver = new \GameNest\GameNestModManager\Services\ConfiguredLifecycleDriver($server, $adapter, new \GameNest\GameNestModManager\Providers\DirectDownloadProvider);
    check($driver->preservePath('Mods/Example/settings.xml'), 'Declared XML not preserved');
    check(!$driver->preservePath('Mods/Example/ModInfo.xml'), 'Game runtime XML incorrectly preserved');
    $data['config_rules'][0]['root'] = '../outside';
    fails(fn () => \GameNest\GameNestModManager\Services\GameDefinition::validate($data, array_keys($data['sources'])));
});

foreach (['fiveModrinth', 'fiveCurse', 'fiveNexus', 'fiveSeven'] as $factory) {
    fiveTest('1.0 ' . $factory . ' exposes version choices without download authorization', function () use ($factory) {
        [$provider, $http, $source] = $factory();
        [$server] = fiveFixture($provider, $source);
        $id = $provider->key() === 'modrinth' ? 'project1' : ($provider->key() === '7daystodiemods' ? 'example-mod' : '10');
        // Use the fixture's actual item identity for the public website provider.
        if ($provider->key() === '7daystodiemods') { $id = 'example'; }
        $rows = (new \GameNest\GameNestModManager\Services\PackageVersionService)->listing($server, $provider->key() . ':' . $id);
        check(count($rows) >= 1, 'No release choices');
        check(array_keys($rows[0]) === ['id', 'version', 'filename', 'filesize'], 'Version projection exposed private fields');
        foreach ($http->requests as $request) {
            check($request['method'] === 'GET' && !str_contains($request['url'], 'download_link'), 'Listing authorized a download');
        }
    });
}
fiveTest('1.0 selected historical Modrinth update pins the requested compatible release', function () {
    [$provider, , $source] = fiveModrinth();
    [$server, $repo, , $manifest, $ops, $engine] = fiveFixture($provider, $source);
    $engine->run($server, 'install', 'modrinth:project1');
    $journal = $engine->run($server, 'update', 'modrinth:project1', 'older-release');
    check($manifest->mods($server)['modrinth:project1']['file_id'] === 'older-release', 'Selected release was ignored');
    check($journal['phase'] === 'Journal' && in_array('Verify', array_column($journal['phases'], 'name'), true), 'Operation phases absent');
});
fiveTest('1.0 lifecycle diagnostics record safe operation and phase timings', function () {
    [$provider, , $source] = fiveModrinth();
    [$server, , , , $ops, $engine] = fiveFixture($provider, $source);

    $journal = $engine->run(
        $server,
        'install',
        'modrinth:project1'
    );

    check(
        isset($journal['duration_ms'])
        && is_numeric($journal['duration_ms'])
        && $journal['duration_ms'] >= 0,
        'Operation duration missing'
    );

    check(
        !empty($journal['phases']),
        'Diagnostic phases missing'
    );

    foreach ($journal['phases'] as $phase) {
        check(
            isset($phase['started_us'])
            && is_numeric($phase['started_us']),
            'Internal phase start missing'
        );

        check(
            array_key_exists('duration_ms', $phase)
            && is_numeric($phase['duration_ms'])
            && $phase['duration_ms'] >= 0,
            'Phase duration missing'
        );
    }

    $history = $ops->history($server);
    $row = $history[0] ?? [];

    check(
        isset($row['duration_ms'])
        && $row['duration_ms'] >= 0,
        'Safe history duration missing'
    );

    check(
        !array_key_exists('started_us', $row),
        'Internal operation timer leaked into history'
    );

    foreach (($row['phases'] ?? []) as $phase) {
        check(
            array_keys($phase) === ['name', 'duration_ms'],
            'Internal phase timing fields leaked into history'
        );

        check(
            $phase['duration_ms'] === null
            || (
                is_numeric($phase['duration_ms'])
                && $phase['duration_ms'] >= 0
            ),
            'Invalid safe phase duration'
        );
    }
});

test070('manual source update status never claims provider freshness', function () {
    [$server, $repo, , $manifest, , $engine] = fixture070();
    $id = str_repeat('a', 32);
    $engine->run($server, 'install', 'upload:' . $id);
    $updates = $engine->updates($server);
    check($updates['upload:' . $id]['status'] === 'Manual source - no update feed', 'Manual version falsely current');
    check(!$updates['upload:' . $id]['available'], 'Manual source offered an automatic update');
});
test070('declarative config survives reinstall and config save queues only real changes', function () {
    $data = game070(); $data['config_directories'] = ['Mods'];
    $data['config_rules'] = [['root' => 'Mods', 'patterns' => ['settings.xml'], 'exclude' => ['*.bak*'], 'depth' => 4]];
    [$server, $repo, , $manifest, , $engine, , $upload] = fixture070($data);
    $upload->body = zip070(['Example/main.dll' => 'binary', 'Example/settings.xml' => '<settings>default</settings>']);
    $id = str_repeat('b', 32); $engine->run($server, 'install', 'upload:' . $id);
    $repo->files['Mods/Example/settings.xml'] = '<settings>user</settings>';
    $engine->run($server, 'reinstall', 'upload:' . $id);
    check($repo->files['Mods/Example/settings.xml'] === '<settings>user</settings>', 'Reinstall overwrote configured XML');
    $configs = new ConfigManagementService;
    $journal = $configs->save($server, 'Mods/Example/settings.xml', '<settings>user</settings>', hash('sha256', '<settings>user</settings>'));
    check(!$journal['restart_required'], 'No-op save queued a restart');
    $journal = $configs->save($server, 'Mods/Example/settings.xml', '<settings>edited</settings>', hash('sha256', '<settings>user</settings>'));
    check($journal['restart_required'], 'Config change did not queue restart');
    check($repo->files['Mods/Example/settings.xml'] === '<settings>edited</settings>', 'Config edit not committed');
});
$tests['1.0 bulk permission denial leaves files unchanged'] = function () {
    [$server, $repo, , , , $engine] = fixture();
    $engine->run($server, 'install', 'modio:88'); $before = $repo->files;
    \Illuminate\Support\Facades\Gate::$allow = false;
    try { fails(fn () => (new ModBatchService)->run($server, 'remove', ['modio:88']), 'Denied'); }
    finally { \Illuminate\Support\Facades\Gate::$allow = true; }
    check($repo->files === $before, 'Unauthorized batch changed files');
};
test070('version listing rejects another server private upload', function () {
    [$server, , , , , , , $upload] = fixture070();
    $upload->owner = 'another-server';
    fails(fn () => (new \GameNest\GameNestModManager\Services\PackageVersionService)->listing($server, 'upload:' . str_repeat('c', 32)), 'another server');
});
$tests['1.0 silent remote moves cannot be reported as completed or restored'] = function () {
    fixture();
    $repo = new class extends \App\Repositories\Daemon\DaemonFileRepository {
        public bool $silent = false;
        public function renameFiles($root, $moves): void { if (!$this->silent) { parent::renameFiles($root, $moves); } }
    };
    $repo->files['Mods/a.dll'] = 'original';
    $saved = [];
    $files = new \GameNest\GameNestModManager\Services\FileTransaction($repo, '.gamenest/mod-manager/check', function ($moves) use (&$saved) { $saved = $moves; });
    $files->move('Mods/a.dll', 'Mods/b.dll');
    $repo->silent = true;
    fails(fn () => $files->rollback(), 'could not be verified');
    check(empty($saved[0]['restored']) && $repo->files['Mods/b.dll'] === 'original', 'Silent rollback marked restored or lost backup');
    $repo->silent = false;
    $files->recover($saved);
    check($repo->files['Mods/a.dll'] === 'original' && !isset($repo->files['Mods/b.dll']), 'Recovery retry failed');
};
$tests['1.0 same-size corruption during a remote move is detected'] = function () {
    fixture();
    $repo = new class extends \App\Repositories\Daemon\DaemonFileRepository {
        public function renameFiles($root, $moves): void {
            parent::renameFiles($root, $moves);
            $this->files[$moves[0]['to']] = 'corrupt!';
        }
    };
    $repo->files['Mods/a.dll'] = 'original';
    $files = new \GameNest\GameNestModManager\Services\FileTransaction($repo, '.gamenest/mod-manager/check', static function ($moves) {});
    fails(fn () => $files->move('Mods/a.dll', 'Mods/b.dll'), 'content changed');
    check(isset($files->moves[0]['sha256']), 'Recovery checksum missing');
};
$tests['1.0 parent update upgrades its pinned dependency in the same transaction'] = function () {
    [$server, $repo, $provider, $manifest, , $engine] = fixture();
    $provider->dependencyMap[77] = [['id' => '3561559', 'file_id' => '10']];
    $engine->run($server, 'install', 'modio:77');
    $provider->dependencyMap[77] = [['id' => '3561559', 'file_id' => '20']];
    $provider->catalog[77]['latest_file'] = ['id' => 200, 'version' => '2.0', 'download_url' => 'https://fixture/77/200'];
    $repo->archives['https://fixture/77/200'] = ['Mods/77/main.dll' => 'parent v2'];
    $engine->run($server, 'update', 'modio:77');
    check((string) $manifest->mods($server)['modio:3561559']['file_id'] === '20', 'Required dependency not upgraded');
    check($repo->files['Mods/77/main.dll'] === 'parent v2' && $repo->files['Mods/3561559/main.dll'] === 'dependency v2 binary', 'Parent/dependency version split');
};
$tests['1.0 dependency upgrade cannot break another installed parent'] = function () {
    [$server, $repo, $provider, $manifest, , $engine] = fixture();
    $provider->dependencyMap[77] = $provider->dependencyMap[88] = [['id' => '3561559', 'file_id' => '10']];
    $engine->run($server, 'install', 'modio:77'); $engine->run($server, 'install', 'modio:88');
    $before = $repo->files; $beforeMods = $manifest->mods($server); $moves = $repo->moves;
    $provider->dependencyMap[77] = [['id' => '3561559', 'file_id' => '20']];
    fails(fn () => $engine->run($server, 'update', 'modio:77'), 'required version');
    check($repo->files === $before && $manifest->mods($server) === $beforeMods && $repo->moves === $moves, 'Shared dependency conflict changed live state');
};
fiveTest('1.0 enabled game definitions require provider identities and typed metadata', function () {
    fixture();
    $GLOBALS['configOverrides']['gamenest-mod-manager.providers'] = (require __DIR__ . '/../config/gamenest-mod-manager.php')['providers'];
    $store = new \GameNest\GameNestModManager\Services\GameDefinitionStore;
    $snapshot = $store->snapshot(); $game = game070(); $game['key'] = 'metadata-fixture';
    $game['sources'] = ['nexus' => ['metadata' => []]]; $game['enabled'] = false;
    $saved = $store->save($game, $snapshot['revision'], true);
    fails(fn () => $store->toggle($game['key'], $saved['revision']), 'requires source metadata');
    check(!$store->snapshot()['definitions'][$game['key']]['enabled'], 'Invalid definition was activated');
    $game['enabled'] = true; $game['sources']['nexus']['metadata']['domain'] = '7daystodie';
    $saved = $store->save($game, $saved['revision'], false);
    check($saved['definitions'][$game['key']]['enabled'], 'Valid definition did not activate');
    $game['sources'] = ['steam-workshop' => ['metadata' => ['app_id' => 'not-a-number']]];
    fails(fn () => $store->save($game, $saved['revision'], false), 'metadata type');
});

$tests['1.0 batched file moves journal and verify every file'] = function () {
    fixture();

    $repo = new \App\Repositories\Daemon\DaemonFileRepository;
    $repo->files['Mods/a.dll'] = 'alpha';
    $repo->files['Mods/b.dll'] = 'bravo';

    $saved = [];

    $files = new \GameNest\GameNestModManager\Services\FileTransaction(
        $repo,
        '.gamenest/mod-manager/check',
        function ($moves) use (&$saved) {
            $saved = $moves;
        }
    );

    $files->moveMany([
        [
            'from' => 'Mods/a.dll',
            'to' => '.gamenest/mod-manager/check/backup/a.dll',
        ],
        [
            'from' => 'Mods/b.dll',
            'to' => '.gamenest/mod-manager/check/backup/b.dll',
        ],
    ]);

    check(
        !isset(
            $repo->files['Mods/a.dll'],
            $repo->files['Mods/b.dll']
        ),
        'Batch left original files behind'
    );

    check(
        ($repo->files['.gamenest/mod-manager/check/backup/a.dll'] ?? null) === 'alpha'
        && ($repo->files['.gamenest/mod-manager/check/backup/b.dll'] ?? null) === 'bravo',
        'Batch destinations are incorrect'
    );

    check(
        count($saved) === 2
        && isset($saved[0]['sha256'])
        && isset($saved[1]['sha256']),
        'Batch journal did not persist every identity'
    );
};

$tests['1.0 silent batched remote moves cannot be reported as completed'] = function () {
    fixture();

    $repo = new class extends \App\Repositories\Daemon\DaemonFileRepository {
        public function renameFiles($root, $moves): void
        {
            // Simulate Wings accepting the request without performing it.
        }
    };

    $repo->files['Mods/a.dll'] = 'alpha';
    $repo->files['Mods/b.dll'] = 'bravo';

    $saved = [];

    $files = new \GameNest\GameNestModManager\Services\FileTransaction(
        $repo,
        '.gamenest/mod-manager/check',
        function ($moves) use (&$saved) {
            $saved = $moves;
        }
    );

    fails(
        fn () => $files->moveMany([
            [
                'from' => 'Mods/a.dll',
                'to' => 'Mods/c.dll',
            ],
            [
                'from' => 'Mods/b.dll',
                'to' => 'Mods/d.dll',
            ],
        ]),
        'could not be verified'
    );

    check(
        count($saved) === 2
        && isset($repo->files['Mods/a.dll'])
        && isset($repo->files['Mods/b.dll']),
        'Silent batch was not safely journaled'
    );
};

$tests['1.0 partial batched move failure remains recoverable'] = function () {
    fixture();

    $repo = new \App\Repositories\Daemon\DaemonFileRepository;
    $repo->files['Mods/a.dll'] = 'alpha';
    $repo->files['Mods/b.dll'] = 'bravo';
    $repo->failAt = 2;

    $saved = [];

    $files = new \GameNest\GameNestModManager\Services\FileTransaction(
        $repo,
        '.gamenest/mod-manager/check',
        function ($moves) use (&$saved) {
            $saved = $moves;
        }
    );

    fails(
        fn () => $files->moveMany([
            [
                'from' => 'Mods/a.dll',
                'to' => 'Mods/c.dll',
            ],
            [
                'from' => 'Mods/b.dll',
                'to' => 'Mods/d.dll',
            ],
        ]),
        'Injected move failure'
    );

    check(
        count($saved) === 2,
        'Partial batch did not journal every intended move'
    );

    $repo->failAt = null;

    $recovery = new \GameNest\GameNestModManager\Services\FileTransaction(
        $repo,
        '.gamenest/mod-manager/check',
        function ($moves) use (&$saved) {
            $saved = $moves;
        }
    );

    $recovery->recover($saved);

    check(
        ($repo->files['Mods/a.dll'] ?? null) === 'alpha'
        && ($repo->files['Mods/b.dll'] ?? null) === 'bravo'
        && !isset(
            $repo->files['Mods/c.dll'],
            $repo->files['Mods/d.dll']
        ),
        'Partial batch recovery failed'
    );
};

$tests['1.0 batched move detects same-size corruption'] = function () {
    fixture();

    $repo = new class extends \App\Repositories\Daemon\DaemonFileRepository {
        public function renameFiles($root, $moves): void
        {
            parent::renameFiles($root, $moves);

            $this->files[$moves[1]['to']] = 'xxxxx';
        }
    };

    $repo->files['Mods/a.dll'] = 'alpha';
    $repo->files['Mods/b.dll'] = 'bravo';

    $files = new \GameNest\GameNestModManager\Services\FileTransaction(
        $repo,
        '.gamenest/mod-manager/check',
        static function ($moves) {}
    );

    fails(
        fn () => $files->moveMany([
            [
                'from' => 'Mods/a.dll',
                'to' => 'Mods/c.dll',
            ],
            [
                'from' => 'Mods/b.dll',
                'to' => 'Mods/d.dll',
            ],
        ]),
        'content changed'
    );

    check(
        count($files->moves) === 2
        && isset(
            $files->moves[0]['sha256'],
            $files->moves[1]['sha256']
        ),
        'Batch corruption recovery identities are incomplete'
    );
};

$tests['1.0 batched move rejects overlapping move graphs'] = function () {
    fixture();

    $repo = new \App\Repositories\Daemon\DaemonFileRepository;
    $repo->files['Mods/a.dll'] = 'alpha';
    $repo->files['Mods/b.dll'] = 'bravo';

    $files = new \GameNest\GameNestModManager\Services\FileTransaction(
        $repo,
        '.gamenest/mod-manager/check',
        static function ($moves) {}
    );

    fails(
        fn () => $files->moveMany([
            [
                'from' => 'Mods/a.dll',
                'to' => 'Mods/b.dll',
            ],
            [
                'from' => 'Mods/b.dll',
                'to' => 'Mods/c.dll',
            ],
        ]),
        'Overlapping'
    );
};
