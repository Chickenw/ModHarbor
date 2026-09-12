<?php

/** Standalone behavioral tests: php tests/run.php. No panel, credentials, or live server. */
namespace App\Models {
    class Server {
        public string $uuid = 'test-server';
        public int $egg_id = 0;
        public object $egg;
        public function __construct() { $this->egg = (object) ['name' => 'Eco', 'id' => 0]; }
        public function loadMissing($relations): void {}
        public function getAttribute($key) { return $this->{$key} ?? null; }
    }
}
namespace Illuminate\Support\Facades {
    class Gate {
        public static bool $allow = true;
        public static function authorize($ability, $server): void {
            if (!self::$allow) { throw new \RuntimeException('Denied'); }
        }
    }
}
namespace App\Repositories\Daemon {
    class DaemonServerRepository {
        public string $state = 'offline';
        public function setServer($server): static { return $this; }
        public function getDetails(): array { return ['state' => $this->state]; }
    }
    class DaemonFileRepository {
        public array $files = [];
        public array $dirs = ['' => true, 'Configs' => true, 'Mods' => true];
        public array $archives = [];
        public int $moves = 0;
        public ?int $failAt = null;
        public bool $failAfterMove = false;
        public bool $unavailable = false;
        public array $symlinks = [];
        public function setServer($server): static { return $this; }
        public function getDirectory($path): array {
            if ($this->unavailable) { throw new \RuntimeException('Connection failed'); }
            $path = trim($path, '/');
            if (!isset($this->dirs[$path])) { throw new \RuntimeException('Missing directory: ' . $path); }
            $result = [];
            foreach (array_merge(array_keys($this->dirs), array_keys($this->files)) as $entry) {
                if ($entry !== '' && ($path === '' ? dirname($entry) === '.' : dirname($entry) === $path)) {
                    $result[] = ['name' => basename($entry), 'file' => array_key_exists($entry, $this->files),
                        'directory' => isset($this->dirs[$entry]), 'size' => strlen($this->files[$entry] ?? ''),
                        'symlink' => isset($this->symlinks[$entry])];
                }
            }
            return $result;
        }
        public function createDirectory($name, $root): void { $this->dirs[ltrim(trim($root, '/') . '/' . $name, '/')] = true; }
        public function getContent($path, $limit = null): string {
            $path = ltrim($path, '/');
            if (!isset($this->files[$path])) { throw new \RuntimeException('Missing file: ' . $path); }
            return $this->files[$path];
        }
        public function putContent($path, $content): void { $this->files[ltrim($path, '/')] = $content; }
        public function renameFiles($root, $moves): void {
            foreach ($moves as $move) {
                $this->moves++;
                $fail = $this->moves === $this->failAt;
                if ($fail && !$this->failAfterMove) { throw new \RuntimeException('Injected move failure'); }
                if (!isset($this->files[$move['from']]) || isset($this->files[$move['to']])) { throw new \RuntimeException('Move conflict'); }
                $this->files[$move['to']] = $this->files[$move['from']];
                unset($this->files[$move['from']]);
                if ($fail) { throw new \RuntimeException('Injected timeout after move'); }
            }
        }
        public function deleteFiles($root, $names): void {
            foreach ($names as $name) {
                $path = ltrim(trim($root, '/') . '/' . $name, '/');
                foreach (array_keys($this->files) as $file) { if ($file === $path || str_starts_with($file, $path . '/')) { unset($this->files[$file]); } }
                foreach (array_keys($this->dirs) as $dir) { if ($dir === $path || str_starts_with($dir, $path . '/')) { unset($this->dirs[$dir]); } }
            }
        }
        public function pull($url, $root, $params): void { $this->files[trim($root, '/') . '/' . $params['filename']] = $url; }
        public function decompressFile($root, $archive): void {
            $root = trim($root, '/');
            $url = $this->files[$root . '/' . $archive];
            foreach ($this->archives[$url] as $path => $content) {
                $target = $root . '/' . $path;
                $dir = dirname($target);
                while ($dir !== '.') { $this->dirs[$dir] = true; $dir = dirname($dir); }
                $this->files[$target] = $content;
            }
        }
    }
}
namespace {
    if (getenv('MODHARBOR_TEST_RUNTIME') === '1') {
        require __DIR__ . '/../runtime/vendor/autoload.php';
    }
    use App\Models\Server;
    use App\Repositories\Daemon\DaemonFileRepository;
    use App\Repositories\Daemon\DaemonServerRepository;
    use GameNest\GameNestModManager\Services\{AdapterRegistry, DiscoveryQuery, DiscoveryResult, EcoLifecycleDriver, EcoGitHubLifecycleDriver, FileTransaction, ManifestService, ModLifecycleService, OperationStore, PackageRecipeRegistry, ProviderContext, ProviderSdk, SourceContext, SourceRegistry};
    use GameNest\GameNestModManager\Providers\{ModIoProvider, GitHubProvider};

    spl_autoload_register(function ($class) {
        $prefix = 'GameNest\\GameNestModManager\\';
        if (str_starts_with($class, $prefix)) {
            require __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        }
    });
    function app($class) { return $GLOBALS['services'][$class] ?? new $class; }
    if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        return $default;
    }
}

function config($key) {
        if (array_key_exists($key, $GLOBALS['configOverrides'] ?? [])) { return $GLOBALS['configOverrides'][$key]; }
        return match ($key) {
            'gamenest-mod-manager.providers' => [
                'modio' => [
                    'class' => \GameNest\GameNestModManager\Providers\ModIoProvider::class,
                    'label' => 'mod.io',
                    'browse_priority' => 10,
                    'capabilities' => ['browse', 'discover', 'search', 'install', 'dependencies'],
                    'discovery' => ['default_sort' => 'hot', 'sorts' => ['hot' => 'Hot', 'name' => 'A-Z'], 'page_sizes' => [24, 48], 'default_page_size' => 24],
                ],
                'umod' => [
                    'class' => \GameNest\GameNestModManager\Providers\UModProvider::class,
                    'label' => 'uMod',
                    'browse_priority' => 20,
                    'capabilities' => ['browse', 'discover', 'search', 'install', 'dependencies'],
                    'discovery' => ['default_sort' => 'updated', 'sorts' => ['updated' => 'Recently Updated'], 'page_sizes' => [10], 'default_page_size' => 10],
                ],
                'curseforge' => [
                    'metadata_fields' => [
                        'game_id' => [
                            'type' => 'number',
                            'required' => true,
                            'min' => 1,
                        ],
                        'class_id' => ['type' => 'number'],
                        'category_id' => ['type' => 'number'],
                        'game_version' => ['type' => 'text'],
                        'mod_loader_type' => ['type' => 'number'],
                        'allow_prerelease' => ['type' => 'boolean'],
                    ],
                    'class' => \GameNest\GameNestModManager\Providers\CurseForgeProvider::class,
                    'label' => 'CurseForge',
                    'browse_priority' => 90,
                    'capabilities' => [
                        'browse',
                        'discover',
                        'search',
                        'install',
                        'update',
                        'dependencies',
                    ],
                    'metadata_example' => [
                        'game_id' => 432,
                        'class_id' => 6,
                        'game_version' => '1.21.1',
                        'mod_loader_type' => 4,
                    ],
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
                        'default_sort' => 'popular',
                        'sorts' => [
                            'popular' => 'Popular',
                            'updated' => 'Recently Updated',
                            'name' => 'A–Z',
                            'downloads' => 'Most Downloaded',
                        ],
                        'page_sizes' => [24, 48],
                    ],
                ],
                'modrinth' => [
                    'metadata_fields' => [
                        'project_type' => ['type' => 'text'],
                        'loaders' => ['type' => 'list'],
                        'game_versions' => ['type' => 'list'],
                        'categories' => ['type' => 'list'],
                        'allow_prerelease' => ['type' => 'boolean'],
                    ],
                    'class' => \GameNest\GameNestModManager\Providers\ModrinthProvider::class,
                    'label' => 'Modrinth',
                    'browse_priority' => 100,
                    'capabilities' => [
                        'browse',
                        'discover',
                        'search',
                        'install',
                        'update',
                        'dependencies',
                    ],
                    'metadata_example' => [
                        'project_type' => 'mod',
                        'loaders' => ['fabric'],
                        'game_versions' => ['1.21.1'],
                    ],
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
                        'default_sort' => 'relevance',
                        'sorts' => [
                            'relevance' => 'Relevance',
                            'downloads' => 'Most Downloaded',
                            'newest' => 'Newest',
                            'updated' => 'Recently Updated',
                        ],
                        'page_sizes' => [24, 48],
                    ],
                ],
                'github' => [
                    'class' => \GameNest\GameNestModManager\Providers\GitHubProvider::class,
                    'label' => 'GitHub',
                    'browse_priority' => 30,
                    'capabilities' => ['browse', 'inspect', 'install'],
                ],
                'direct' => [
                    'class' => \GameNest\GameNestModManager\Providers\DirectDownloadProvider::class,
                    'label' => 'Direct Download',
                    'browse_priority' => 40,
                    'capabilities' => ['browse', 'manual', 'install'],
                ],
                'upload' => [
                    'class' => \GameNest\GameNestModManager\Providers\UploadProvider::class,
                    'label' => 'Upload File',
                    'browse_priority' => 50,
                    'capabilities' => ['browse', 'upload', 'install'],
                ],
                'nexus' => [
                    'class' => \GameNest\GameNestModManager\Providers\NexusModsProvider::class,
                    'label' => 'Nexus Mods',
                    'browse_priority' => 60,
                    'capabilities' => ['browse', 'discover', 'search', 'install', 'update'],
                    'metadata_fields' => ['domain' => ['type' => 'text', 'required' => true]],
                    'metadata_example' => ['domain' => '7daystodie'],
                ],
                'thunderstore' => [
                    'class' => \GameNest\GameNestModManager\Providers\ThunderstoreProvider::class,
                    'label' => 'Thunderstore',
                    'browse_priority' => 65,
                    'capabilities' => ['browse', 'discover', 'search', 'install', 'update', 'dependencies'],
                    'metadata_fields' => ['community' => ['type' => 'text', 'required' => true]],
                    'metadata_example' => ['community' => 'valheim'],
                ],
                '7daystodiemods' => [
                    'class' => \GameNest\GameNestModManager\Providers\SevenDaysModsProvider::class,
                    'label' => '7DaysToDieMods.com',
                    'browse_priority' => 70,
                    'capabilities' => ['browse', 'discover', 'search', 'install', 'update'],
                    'metadata_fields' => ['game_version' => ['type' => 'list'], 'category' => ['type' => 'list'], 'server_side' => ['type' => 'list']],
                ],
                'steam-workshop' => [
                    'class' => \GameNest\GameNestModManager\Providers\SteamWorkshopProvider::class,
                    'label' => 'Steam Workshop',
                    'browse_priority' => 80,
                    'capabilities' => ['browse', 'discover', 'search', 'install', 'update', 'dependencies'],
                    'metadata_fields' => ['app_id' => ['type' => 'number', 'required' => true, 'min' => 1]],
                    'metadata_example' => ['app_id' => 123456],
                ],
            ],
            default => (function () use ($key) {
                $prefix = 'gamenest-mod-manager.';

                if (!str_starts_with($key, $prefix)) {
                    return null;
                }

                static $pluginConfig = null;

                if ($pluginConfig === null) {
                    $pluginConfig = require __DIR__ . '/../config/gamenest-mod-manager.php';
                }

                $path = substr($key, strlen($prefix));
                $value = $pluginConfig;

                foreach (explode('.', $path) as $segment) {
                    if (!is_array($value) || !array_key_exists($segment, $value)) {
                        return null;
                    }

                    $value = $value[$segment];
                }

                return $value;
            })(),
        };
    }
    function now() { return new class { public function toIso8601String(): string { return gmdate('c'); } public function format($format): string { return gmdate($format); } }; }
    function auth() { return new class { public function id(): int { return 1; } }; }
    function storage_path($path): string { return $GLOBALS['storage'] . '/' . $path; }

    class Provider extends ModIoProvider {
        public array $catalog = [];
        public array $dependencyMap = [77 => [3561559]];
        public array $requestedFiles = [];
        public function get(string|int $id, ?SourceContext $source = null): ?array { return $this->catalog[$id] ?? null; }
        public function dependencies(int $id, ?SourceContext $source = null): array { return array_map(fn ($n) => ['mod_id' => $n], $this->dependencyMap[$id] ?? []); }
        public function search(string $query, array $options = [], ?SourceContext $source = null): array {
            return array_values(array_filter(
                $this->catalog,
                function ($mod) use ($query) {
                    $name = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) ($mod['name'] ?? '')));
                    $wanted = strtolower(preg_replace('/[^a-z0-9]/i', '', $query));

                    return $name === $wanted;
                }
            ));
        }

        public function file(int $modId, int $fileId, ?SourceContext $source = null): array {
            $this->requestedFiles[] = $fileId;
            return ['id' => $fileId, 'version' => 'installed-release', 'download_url' => 'https://fixture/' . $modId . '/' . $fileId];
        }
    }
      class ConstraintEcoLifecycleDriver extends EcoLifecycleDriver {
          public function __construct(
              protected Provider $fixtureProvider
          ) {
              parent::__construct($fixtureProvider);
          }

          public function dependencies(
              string|int $id,
              ?SourceContext $source = null
          ): array
          {
              $items = $this->fixtureProvider->dependencyMap[(int) $id] ?? [];
              $result = [];

              foreach ($items as $item) {
                  if (is_array($item)) {
                      $dependencyId = (string) (
                          $item['id']
                          ?? $item['mod_id']
                          ?? ''
                      );

                      if ($dependencyId === '') {
                          continue;
                      }

                      $spec = array_intersect_key($item, array_flip(['provider', 'type', 'optional']));
                      $spec['id'] = $dependencyId;

                      if (!empty($item['constraint'])) {
                          $spec['constraint'] = (string) $item['constraint'];
                      }

                      if (!empty($item['file_id'])) {
                          $spec['file_id'] = (string) $item['file_id'];
                      }

                      $result[] = $spec;
                      continue;
                  }

                  $result[] = (string) $item;
              }

              return $result;
          }
      }

    class GitHubFixtureProvider extends GitHubProvider {
        public array $catalog = [];
        public array $assets = [];

        public function get(string|int $id, ?SourceContext $source = null): ?array {
            return $this->catalog[(int) $id] ?? null;
        }

        public function file(int $repositoryId, int $assetId): array {
            if (!isset($this->assets[$repositoryId][$assetId])) {
                throw new \RuntimeException('Missing GitHub fixture asset.');
            }

            return $this->assets[$repositoryId][$assetId];
        }
    }

    function fixture(): array {
        $GLOBALS['storage'] = sys_get_temp_dir() . '/gamenest-tests-' . bin2hex(random_bytes(8));
        $repo = new DaemonFileRepository;
        $provider = new Provider;
        foreach ([77 => 'DiscordLink', 3561559 => 'Mighty Moose Core', 88 => 'Other Mod'] as $id => $name) {
            $provider->catalog[$id] = ['id' => $id, 'name' => $name, 'latest_file' => ['id' => 100, 'version' => '1.0', 'download_url' => 'https://fixture/' . $id . '/100']];
            $repo->archives['https://fixture/' . $id . '/100'] = ['Mods/' . $id . '/main.dll' => 'old binary', 'Configs/' . $id . '.eco.template' => 'default'];
        }
          $repo->archives['https://fixture/3561559/10'] = [
              'Mods/3561559/main.dll' => 'dependency v1 binary',
              'Configs/3561559.eco.template' => 'dependency v1 default',
          ];

          $repo->archives['https://fixture/3561559/20'] = [
              'Mods/3561559/main.dll' => 'dependency v2 binary',
              'Configs/3561559.eco.template' => 'dependency v2 default',
          ];

        $GLOBALS['services'] = [DaemonFileRepository::class => $repo, DaemonServerRepository::class => new DaemonServerRepository, ModIoProvider::class => $provider];
        $GLOBALS['services'][EcoLifecycleDriver::class] = new ConstraintEcoLifecycleDriver($provider);
        $github = new GitHubFixtureProvider;

        $github->catalog[9001] = [
            'id' => 9001,
            'name' => 'DiscordLink',
            'full_name' => 'Eco-DiscordLink/EcoDiscordPlugin',
            'latest_file' => [
                'id' => 420,
                'name' => 'DiscordLink_4.2.0.zip',
                'version' => '4.2.0',
                'download_url' => 'https://fixture/github/discordlink',
            ],
        ];

        $github->assets[9001][420] = [
            'id' => 420,
            'name' => 'DiscordLink_4.2.0.zip',
            'version' => '4.2.0',
            'download_url' => 'https://fixture/github/discordlink',
        ];

        $repo->archives['https://fixture/github/discordlink'] = [
            'Mods/DiscordLink/Eco.DiscordLink.dll' => 'discordlink binary',
            'Configs/DiscordLink.eco.template' => 'discordlink default',
        ];

        $GLOBALS['services'][GitHubProvider::class] = $github;
        $GLOBALS['services'][EcoGitHubLifecycleDriver::class] =
            new EcoGitHubLifecycleDriver($github);

        $manifest = new ManifestService;
        $store = new OperationStore;
        $engine = new ModLifecycleService(
            new AdapterRegistry,
            $manifest,
            $store,
            new PackageRecipeRegistry
        );
        \Illuminate\Support\Facades\Gate::$allow = true;
        return [new Server, $repo, $provider, $manifest, $store, $engine];
    }
    function check($condition, $message): void { if (!$condition) { throw new \Exception($message); } }
    function fails(callable $fn, string $contains = ''): void {
        try { $fn(); } catch (\Throwable $e) {
            check($contains === '' || str_contains($e->getMessage(), $contains), 'Unexpected failure: ' . $e->getMessage());
            return;
        }
        throw new \Exception('Expected a failure');
    }
    $tests = [];
    $tests['install dependencies, manifest and history'] = function () {
        [$s,$r,$p,$m,$h,$e] = fixture(); $e->run($s,'install','modio:77'); $mods=$m->mods($s);
        check(count($mods) === 2 && $mods['modio:3561559']['required_by'] === ['modio:77'], 'Dependency edge');
        check($r->files['Configs/77.eco'] === 'default', 'Active config');
        check($h->history($s)[0]['status'] === 'completed', 'History');
        check(count(array_filter(array_keys($r->files), fn($p)=>str_contains($p,'/operations/'))) === 0, 'Staging cleanup');
    };
    $tests['required dependency cannot disable or remove'] = function () {
        [$s,$r,$p,$m,$h,$e] = fixture(); $e->run($s,'install','modio:77'); $before=$r->files;
        fails(fn()=> $e->run($s,'disable','modio:3561559'),'Dependency required');
        fails(fn()=> $e->run($s,'remove','modio:3561559'),'Dependency required'); check($r->files === $before,'Files changed');
    };
    $tests['disable and enable preserve config'] = function () {
        [$s,$r,$p,$m,$h,$e] = fixture(); $e->run($s,'install','modio:77'); $r->files['Configs/77.eco']='custom';
        $e->run($s,'disable','modio:77'); check(!$m->mods($s)['modio:77']['enabled'] && !isset($r->files['Mods/77/main.dll']),'Disable');
        $e->run($s,'enable','modio:77'); check(isset($r->files['Mods/77/main.dll']) && $r->files['Configs/77.eco']==='custom','Enable/config');
    };
    $tests['remove cleans orphan dependencies but preserves user files'] = function () {
        [$s,$r,$p,$m,$h,$e] = fixture(); $e->run($s,'install','modio:77'); $r->files['Mods/77/custom.txt']='user';
        $e->run($s,'remove','modio:77'); check($m->mods($s)===[],'Orphan retained');
        check(isset($r->files['Configs/77.eco'],$r->files['Configs/3561559.eco'],$r->files['Mods/77/custom.txt']),'User config lost');
    };
    $tests['shared dependency survives removal'] = function () {
        [$s,$r,$p,$m,$h,$e] = fixture(); $p->dependencyMap[88]=[3561559];
        $e->run($s,'install','modio:77'); $e->run($s,'install','modio:88'); $e->run($s,'remove','modio:77');
        check($m->mods($s)['modio:3561559']['required_by']===['modio:88'],'Shared dependency removed');
    };

    $tests['matching pinned dependency requirements are shared'] = function () {
        [$s,$r,$p,$m,$h,$e] = fixture();

        $p->dependencyMap[77] = [
            ['id' => '3561559', 'constraint' => '1.*', 'file_id' => '10'],
        ];

        $p->dependencyMap[88] = [
            ['id' => '3561559', 'constraint' => '1.*', 'file_id' => '10'],
        ];

        $e->run($s, 'install', 'modio:77');
        $e->run($s, 'install', 'modio:88');

        $mods = $m->mods($s);

        check(isset($mods['modio:3561559']), 'Shared dependency missing');
        check(
            $mods['modio:3561559']['required_by'] === ['modio:77', 'modio:88'],
            'Shared dependency parent edges incorrect'
        );
        check(
            (string) $mods['modio:3561559']['file_id'] === '10',
            'Shared dependency release changed'
        );
    };

    $tests['conflicting installed dependency requirement is rejected safely'] = function () {
        [$s,$r,$p,$m,$h,$e] = fixture();

        $p->dependencyMap[77] = [
            ['id' => '3561559', 'constraint' => '1.*', 'file_id' => '10'],
        ];

        $e->run($s, 'install', 'modio:77');

        $beforeMods = $m->mods($s);
        $beforeFiles = $r->files;

        $p->dependencyMap[88] = [
            ['id' => '3561559', 'constraint' => '2.*', 'file_id' => '20'],
        ];

        fails(
            fn() => $e->run($s, 'install', 'modio:88'),
            'does not satisfy required version'
        );

        check($m->mods($s) === $beforeMods, 'Manifest changed after conflict');
        check($r->files === $beforeFiles, 'Files changed after conflict');
        check(!isset($m->mods($s)['modio:88']), 'Conflicting parent partially installed');
        check(
            $m->mods($s)['modio:3561559']['required_by'] === ['modio:77'],
            'Existing dependency relationship changed'
        );
    };

    $tests['duplicate conflicting dependency specs fail before deployment'] = function () {
        [$s,$r,$p,$m,$h,$e] = fixture();

        $p->dependencyMap[77] = [
            ['id' => '3561559', 'constraint' => '1.*', 'file_id' => '10'],
            ['id' => '3561559', 'constraint' => '2.*', 'file_id' => '20'],
        ];

        fails(
            fn() => $e->run($s, 'install', 'modio:77'),
            'Dependency conflict'
        );

        check($m->mods($s) === [], 'Conflict wrote manifest entries');
        check($r->files === [], 'Conflict deployed files');
        check(count($h->history($s)) === 1, 'Failed operation history missing');
        check(
            $h->history($s)[0]['status'] === 'failed',
            'Conflict was not recorded as failed'
        );
    };

    $tests['optional suggestions are not lifecycle dependencies'] = function () {
        [$s,$r,$p,$m,$h,$e] = fixture();

        $p->dependencyMap[77] = [3561559];

        $e->run($s, 'install', 'modio:77');

        $mods = $m->mods($s);

        check(isset($mods['modio:77']), 'Primary mod missing');
        check(
            isset($mods['modio:3561559']),
            'Required dependency missing'
        );
        check(
            !isset($mods['modio:88']),
            'Optional suggestion became a lifecycle dependency'
        );
        check(
            !isset($r->files['Mods/88/main.dll']),
            'Optional suggestion deployed files'
        );
    };

    $tests['explicitly installed dependency survives removal'] = function () {
        [$s,$r,$p,$m,$h,$e] = fixture(); $e->run($s,'install','modio:3561559'); $e->run($s,'install','modio:77'); $e->run($s,'remove','modio:77');
        check(isset($m->mods($s)['modio:3561559']),'Explicit mod removed');
    };
    $tests['reinstall pins original release and retains disabled state'] = function () {
        [$s,$r,$p,$m,$h,$e] = fixture(); $e->run($s,'install','modio:77'); $e->run($s,'disable','modio:77');
        $p->catalog[77]['latest_file']['id']=200; $r->files['Configs/77.eco']='custom';
        $e->run($s,'reinstall','modio:77'); check($p->requestedFiles===[100],'Wrong release');
        check(!$m->mods($s)['modio:77']['enabled'] && $r->files['Configs/77.eco']==='custom','State/config changed');
    };
    $tests['update changes version, removes stale files and keeps disabled'] = function () {
        [$s,$r,$p,$m,$h,$e] = fixture(); $e->run($s,'install','modio:77'); $e->run($s,'disable','modio:77');
        $p->catalog[77]['latest_file']=['id'=>200,'version'=>'2.0','download_url'=>'https://fixture/new'];
        $r->archives['https://fixture/new']=['Mods/77/new.dll'=>'new','Configs/77.eco.template'=>'new default'];
        check($e->updates($s)['modio:77']['available'],'Update missing'); $e->run($s,'update','modio:77');
        $entry=$m->mods($s)['modio:77']; check($entry['file_id']===200 && !$entry['enabled'],'Version/state');
        check(!in_array('Mods/77/main.dll',$entry['paths'],true),'Stale file tracked');
        check($r->files['Configs/77.eco']==='default','Config replaced');
    };
    $tests['unmanaged conflict rolls back whole installation'] = function () {
        [$s,$r,$p,$m,$h,$e] = fixture(); $r->dirs['Mods/77']=true; $r->files['Mods/77/main.dll']='unmanaged'; $before=$r->files;
        fails(fn()=> $e->run($s,'install','modio:77'),'unmanaged'); check($r->files===$before,'Partial deployment');
    };
    $tests['failures before and after remote moves roll back'] = function () {
        foreach ([false,true] as $after) {
            for ($move=1;$move<=7;$move++) {
                [$s,$r,$p,$m,$h,$e] = fixture(); $r->failAt=$move; $r->failAfterMove=$after;
                fails(fn()=> $e->run($s,'install','modio:77')); check($r->files===[],'Rollback left files at move '.$move);
                check($h->history($s)[0]['status']==='failed','Failure history');
            }
        }
    };
    $tests['corrupt manifest and connection errors fail closed'] = function () {
        [$s,$r,$p,$m,$h,$e] = fixture(); $r->files[$m->filename()]='{bad';
        fails(fn()=> $m->read($s)); fails(fn()=> $e->run($s,'install','modio:77'));
        $r->unavailable=true; fails(fn()=> $m->read($s));
    };
    $tests['running server and denied permission prevent writes'] = function () {
        [$s,$r,$p,$m,$h,$e] = fixture(); app(DaemonServerRepository::class)->state='running';
        fails(fn()=> $e->run($s,'install','modio:77'),'Stop the game'); check($r->files===[],'Running server mutated');
        app(DaemonServerRepository::class)->state='offline'; \Illuminate\Support\Facades\Gate::$allow=false;
        fails(fn()=> $e->run($s,'install','modio:77'),'Denied'); check($r->files===[],'Unauthorized mutation');
    };
    $tests['interrupted journal blocks new operations'] = function () {
        [$s,$r,$p,$m,$h,$e] = fixture(); $h->save($s,['id'=>str_repeat('f',24),'status'=>'running','started_at'=>gmdate('c')]);
        fails(fn()=> $e->run($s,'install','modio:77'),'interrupted'); check($r->files===[],'Recovery guard');
    };
    $tests['traversal and symlinks rejected'] = function () {
        foreach (['../Mods/a','Mods/../a','/Mods/a','Mods\\a','Mods//a',"Mods/a\0b"] as $path) { fails(fn()=>FileTransaction::path($path)); }
        [$s,$r,$p,$m,$h,$e] = fixture(); $r->symlinks['Mods']=true; fails(fn()=> $e->run($s,'install','modio:77'),'Symbolic');
    };
    $tests['other games never use Eco deployment rules'] = function () {
        [$s,$r,$p,$m,$h,$e] = fixture();
        $s->egg->name = 'Rust';

        $adapter = (new AdapterRegistry)->forServer($s);

        check(
            $adapter instanceof \GameNest\GameNestModManager\Adapters\ConfiguredGameAdapter,
            'Rust did not resolve through Game Builder'
        );

        check(
            method_exists($adapter, 'capability')
                && $adapter->capability() === 'rust-carbon-oxide',
            'Rust capability was not selected'
        );

        check(
            !$e->supported($s, 'modio'),
            'Rust exposed Eco mod.io lifecycle'
        );

        fails(
            fn () => $e->run($s, 'install', 'modio:77')
        );

        check(
            $r->files === [],
            'Cross-game lifecycle mutated files'
        );
    };
    $tests['GitHub installs required mod.io dependency'] = function () {
        [$s,$r,$p,$m,$h,$e] = fixture();

        $e->run($s, 'install', 'github:9001', 420);

        $mods = $m->mods($s);

        check(
            isset($mods['github:9001']),
            'GitHub package missing'
        );

        check(
            isset($mods['modio:3561559']),
            'mod.io dependency missing'
        );

        check(
            $mods['modio:3561559']['dependency'] === true,
            'Dependency flag missing'
        );

        check(
            $mods['modio:3561559']['required_by'] === ['github:9001'],
            'Cross-provider required_by edge missing'
        );

        check(
            isset($r->files['Mods/DiscordLink/Eco.DiscordLink.dll']),
            'DiscordLink file missing'
        );

        check(
            isset($r->files['Mods/3561559/main.dll']),
            'MightyMooseCore file missing'
        );

        check(
            isset($r->files['Configs/DiscordLink.eco']),
            'DiscordLink config was not generated'
        );
    };


    $tests['source registry follows adapter capabilities and priority'] = function () {
        $server = new Server;
        $adapters = new AdapterRegistry;
        $contexts = new ProviderContext($adapters);
        $sources = new SourceRegistry($adapters, $contexts);

        check(
            $sources->sourceKeys($server) === ['modio', 'github', 'upload'],
            'Eco source registry did not follow adapter source order'
        );
        check(
            $sources->defaultBrowseKey($server) === 'modio',
            'Eco default browse source priority is incorrect'
        );
        check(
            $sources->label('github') === 'GitHub',
            'Provider label did not come from registry'
        );
        check(
            $sources->capable('upload', 'upload'),
            'Upload capability missing from provider registry'
        );
        check(
            $sources->discoveryKeys($server) === ['modio'],
            'Eco discovery registry should expose only true catalog providers'
        );
        check(
            $sources->discoverySchema('modio')['default_sort'] === 'hot',
            'Provider discovery schema was not normalized'
        );

        $upload = $contexts->forServer($server, 'upload');
        check(
            $upload->value('extensions') === ['zip'],
            'Eco upload extensions were not inherited from adapter metadata'
        );
    };

    $tests['discovery query and result normalize provider paging'] = function () {
        $query = DiscoveryQuery::fromArray([
            'query' => ' backpacks ',
            'sort' => 'UPDATED',
            'page' => 0,
            'per_page' => 500,
            'tags' => [' QoL ', '', 'QoL'],
            'filters' => ['period' => '30d'],
        ]);

        check($query->search === 'backpacks', 'Discovery search was not trimmed');
        check($query->sort === 'updated', 'Discovery sort was not normalized');
        check($query->page === 1, 'Discovery page was not clamped');
        check($query->perPage === 100, 'Discovery page size was not clamped');
        check($query->tags === ['QoL'], 'Discovery tags were not normalized');
        check($query->filter('period') === '30d', 'Discovery filter lookup failed');

        $result = DiscoveryResult::page([['id' => 1]], 51, 2, 25);
        check($result->lastPage === 3, 'Discovery last page calculation failed');
        check($result->toArray()['count'] === 1, 'Discovery result compatibility array is invalid');
    };

    $tests['adapter sdk exposes configured developer manifest'] = function () {
        $registry = new AdapterRegistry;
        $manifest = $registry->developerManifest();

        check(
            isset($manifest['eco']),
            'Eco definition missing from developer manifest'
        );

        check(
            $manifest['eco']['features']['managed_mods'] === true,
            'Adapter feature defaults missing'
        );

        check(
            in_array('modio', $manifest['eco']['sources'], true),
            'Eco sources missing from developer manifest'
        );

        check(
            isset($manifest['rust']),
            'Rust definition missing from developer manifest'
        );

        check(
            ($manifest['rust']['metadata']['capability'] ?? null)
                === 'rust-carbon-oxide',
            'Rust capability metadata missing'
        );

        check(
            in_array('umod', $manifest['rust']['sources'], true),
            'Rust sources missing from developer manifest'
        );
    };

    $tests['provider sdk validates discovery capability contract'] = function () {
        $sdk = new ProviderSdk;
        $definition = $sdk->normalizeDefinition('modio', [
            'class' => ModIoProvider::class,
            'label' => 'mod.io',
            'capabilities' => ['browse', 'discover', 'search', 'made-up-capability'],
            'discovery' => [
                'default_sort' => 'missing',
                'sorts' => ['hot' => 'Hot'],
                'page_sizes' => [0, 24, 250],
            ],
        ]);

        check($definition['capabilities'] === ['browse', 'discover', 'search'], 'Provider capabilities were not normalized');
        check($definition['discovery']['default_sort'] === 'hot', 'Invalid provider default sort was not corrected');
        check($definition['discovery']['page_sizes'] === [24], 'Provider page sizes were not validated');
    };

    require __DIR__ . '/v060.php';
    require __DIR__ . '/rust-regression.php';
  require __DIR__ . '/7dtd-configured.php';
require __DIR__ . '/provider-expansion.php';
    require __DIR__ . '/v070.php';
    require __DIR__ . '/five-providers.php';

    require __DIR__ . '/v100.php';
    require __DIR__ . '/release-readiness.php';

    $failed=0;
    foreach ($tests as $name=>$test) {
        try { $test(); echo "PASS $name\n"; } catch (\Throwable $e) { $failed++; echo "FAIL $name: {$e->getMessage()}\n{$e->getTraceAsString()}\n"; }
    }
    echo count($tests) . " scenarios, $failed failures\n";
    exit($failed ? 1 : 0);
}
