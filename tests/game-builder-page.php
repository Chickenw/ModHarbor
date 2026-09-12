<?php

/** Standalone page action/authorization tests. Real framework compatibility is checked by pelican-blade.php. */
namespace App\Models {
    if (!\class_exists(__NAMESPACE__ . '\\Server', false)) {
        class Server {
            public $egg;
            public $egg_id = 0;
            public string $uuid = 'server-one';
            public function loadMissing($relations): void {}
        }
    }
}
namespace Filament\Pages {
    class Page { public function fill($values) {} }
}
namespace Livewire { trait WithFileUploads {} }

namespace Illuminate\Support {
    class Str
    {
        public static function slug($value): string
        {
            $value = strtolower(trim((string) $value));
            $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
            return trim($value, '-');
        }
    }
}
namespace Livewire\Features\SupportFileUploads {
    class TemporaryUploadedFile {
        public function __construct(private string $path, private string $name) {}
        public function getRealPath() { return $this->path; }
        public function getClientOriginalName() { return $this->name; }
        public function getSize() { return filesize($this->path); }
    }
}
namespace {
    use GameNest\GameNestModManager\Pages\GameSetup;
    use GameNest\GameNestModManager\Services\GameDefinitionStore;
    function env($key, $default = null) { return $default; }
    spl_autoload_register(function ($class) {
        $prefix = 'GameNest\\GameNestModManager\\';
        if (str_starts_with($class, $prefix)) { require __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php'; }
    });
    $settings = require __DIR__ . '/../config/gamenest-mod-manager.php';
    $directory = sys_get_temp_dir() . '/modharbor-page-' . bin2hex(random_bytes(8));
    $store = new GameDefinitionStore($directory);
    $admin = true;
    function auth() { return new class { public function user() { return new class { public function isRootAdmin(): bool { return $GLOBALS['admin']; } }; } }; }
    function abort_unless($allowed, $code): void { if (!$allowed) { throw new RuntimeException((string) $code); } }
    function storage_path($path) { return $GLOBALS['directory'] . '/' . $path; }
    function app($class) {
        if (isset($GLOBALS['pageServices'][$class])) { return $GLOBALS['pageServices'][$class]; }

        if ($class === GameDefinitionStore::class) {
            return $GLOBALS['store'];
        }

        if ($class === \GameNest\GameNestModManager\Services\SourceRegistry::class) {
            $adapters = new \GameNest\GameNestModManager\Services\AdapterRegistry;

            return new $class(
                $adapters,
                new \GameNest\GameNestModManager\Services\ProviderContext($adapters)
            );
        }

        if ($class === \GameNest\GameNestModManager\Services\ProviderGameIdentityService::class) {
            return new $class(
                new \GameNest\GameNestModManager\Services\ProviderHttpClient,
                new \GameNest\GameNestModManager\Services\ProviderSettingsStore
            );
        }

        if ($class === \GameNest\GameNestModManager\Services\GameCatalogSearchService::class) {
            return new $class(
                new \GameNest\GameNestModManager\Services\ProviderHttpClient
            );
        }

        return new $class;
    }
    function config($key, $default = null) { return $GLOBALS['settings'][substr($key, strlen('gamenest-mod-manager.'))] ?? $default; }
    function response() { return new class { public function streamDownload($callback, $filename, $headers) { ob_start(); $callback(); return ['name' => $filename, 'contents' => ob_get_clean(), 'headers' => $headers]; } }; }
    function checkPage($condition, $message): void { if (!$condition) { throw new RuntimeException($message); } }
    $page = new GameSetup;
    $page->mount();
    foreach (['nexus', '7daystodiemods', 'steam-workshop', 'curseforge', 'modrinth'] as $provider) {
        checkPage(isset($page->providerOptions()[$provider]), 'Registered provider missing in Game Builder: ' . $provider);
    }

    // Game Builder seeds first-party definitions on first read. Import must
    // leave that existing catalog completely unchanged until Save is used.
    $baseline = $store->snapshot();
    $baselineCount = count($baseline['definitions']);

    $page->importFile = new \Livewire\Features\SupportFileUploads\TemporaryUploadedFile(__DIR__ . '/../examples/7-days-to-die.modharbor.json', '7-days-to-die.modharbor.json');
    $page->importGame();
    checkPage($page->form['steam_app_id'] === 251570 && !$page->form['enabled'], 'Import did not produce disabled draft');
    checkPage($store->snapshot() === $baseline, 'Import wrote before review');

    checkPage($page->form['key'] === '7-days-to-die', 'Example identity differs from shipped definition');
    checkPage(str_contains($page->message, 'already exists'), 'Missing import collision review');
    $page->saveGame();
    checkPage($store->snapshot() === $baseline, 'Collision overwrote shipped definition');
    $page->form['key'] = 'fixture-seven-days';
    $page->form['enabled'] = true;
    $page->form['artwork_url'] = 'https://example.com/custom-game.jpg';
    $page->saveGame();
    checkPage(
        count($store->all()) === $baselineCount + 1,
        'Page could not save definition: ' . $page->message
    );
    checkPage(!$store->all()['fixture-seven-days']->data()['enabled'], 'Imported draft was activated on first save');
    $export = $page->exportGame('fixture-seven-days');
    checkPage(json_decode($export['contents'], true)['artwork_url'] === 'https://example.com/custom-game.jpg', 'Artwork override lost on export');
    checkPage($export['name'] === 'fixture-seven-days.modharbor.json', 'Wrong export filename');
    $store->import($export['contents']);
    checkPage($export['headers']['Cache-Control'] === 'no-store', 'Export may be cached');
    $GLOBALS['pageServices'][\GameNest\GameNestModManager\Services\ProviderSettingsStore::class] = new class extends \GameNest\GameNestModManager\Services\ProviderSettingsStore {
        public function assertPortable(array $data): void { throw new RuntimeException('Credential check unavailable'); }
    };
    checkPage($page->exportGame('fixture-seven-days') === null && str_contains($page->message, 'Export blocked'), 'Export did not fail closed with review guidance');
    unset($GLOBALS['pageServices'][\GameNest\GameNestModManager\Services\ProviderSettingsStore::class]);
    $page->editGame('fixture-seven-days');
    checkPage($page->form['artwork_url'] === 'https://example.com/custom-game.jpg', 'Artwork override lost on edit');
    $saved = $store->snapshot();
    $page->form['artwork_url'] = 'javascript:alert(1)';
    $page->saveGame();
    checkPage($store->snapshot() === $saved, 'Unsafe artwork override was persisted');
    $page->form['artwork_url'] = '';
    $page->form['name'] = 'Seven Days to Die'; $page->form['key'] = 'tampered-key';
    $page->saveGame();
    checkPage(isset($store->all()['fixture-seven-days']) && !isset($store->all()['tampered-key']), 'Editing changed locked key');
    $page->toggleGame('fixture-seven-days');
    checkPage($store->all()['fixture-seven-days']->data()['enabled'], 'Toggle failed');
    $page->deleteGame('fixture-seven-days');
    checkPage(
        count($store->all()) === $baselineCount + 1,
        'Deleted enabled definition'
    );
    $before = $store->snapshot();
    $admin = false;
    checkPage(!GameSetup::canAccess(), 'Non-root user can access page');
    foreach (['mount'=>[], 'newGame'=>[], 'editGame'=>['fixture-seven-days'], 'saveGame'=>[], 'toggleGame'=>['fixture-seven-days'], 'deleteGame'=>['fixture-seven-days'], 'importGame'=>[], 'exportGame'=>['fixture-seven-days'], 'providerOptions'=>[], 'deploymentStatus'=>[[], 'upload']] as $method => $args) {
        $denied = false;
        try { $page->$method(...$args); } catch (RuntimeException $exception) { $denied = $exception->getMessage() === '403'; }
        checkPage($denied, 'Unauthorized action did not fail closed: ' . $method);
    }
    checkPage($store->snapshot() === $before, 'Denied actions changed catalog');
    $admin = true;
    $page->toggleGame('fixture-seven-days');
    $page->deleteGame('fixture-seven-days');

    $afterDelete = $store->snapshot();
    checkPage(
        $afterDelete['definitions'] === $baseline['definitions'],
        'Disabled delete did not restore the seeded catalog'
    );

    // Egg ID 5: save, reload, and adapter match without relying on egg name.
    $page->newGame();
    $page->form['key'] = 'egg-id-fixture';
    $page->form['name'] = 'Egg ID Fixture';
    $page->form['capability'] = 'generic';
    $page->eggNames = '';
    $page->eggNameContains = '';
    $page->eggIds = "5\n";
    $page->modDirectories = "Mods";
    $page->configDirectories = "Configs";
    $page->allowedSources = ['upload'];
    $page->sourceMetadata = ['upload' => '{}'];
    $page->form['package_types'] = ['zip'];
    $page->form['deployment'] = ['strategy' => 'archive', 'target' => 'Mods', 'archive_prefix' => ''];
    $page->form['behavior'] = ['install_while_running' => false, 'restart_required' => true];
    $page->saveGame();
    checkPage(isset($store->all()['egg-id-fixture']), 'Egg ID fixture did not save: ' . $page->message);
    $savedEgg = $store->all()['egg-id-fixture']->data();
    checkPage($savedEgg['detection']['egg_ids'] === [5], 'Egg ID 5 was not persisted as integer list');
    $page->editGame('egg-id-fixture');
    checkPage(trim($page->eggIds) === '5', 'Egg ID 5 did not reload into the editor');
    $definition = \GameNest\GameNestModManager\Services\GameDefinition::validate(
        $savedEgg,
        array_keys((array) config('gamenest-mod-manager.providers', []))
    );
    $adapter = new \GameNest\GameNestModManager\Adapters\ConfiguredGameAdapter($definition);
    $server = new class extends \App\Models\Server {
        public $egg_id = 5;
        public function __construct() {
            $this->egg = (object) ['id' => 5, 'name' => 'Something Else'];
        }
        public function loadMissing($relations): void {}
    };
    checkPage($adapter->matches($server), 'ConfiguredGameAdapter did not match Egg ID 5');
    $page->deleteGame('egg-id-fixture');

    echo "Page CRUD, import/export, immutable key, and 10 authorization checks passed.\n";
}
