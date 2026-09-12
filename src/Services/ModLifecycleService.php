<?php

namespace GameNest\GameNestModManager\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use App\Repositories\Daemon\DaemonServerRepository;
use GameNest\GameNestModManager\Contracts\LifecycleDriver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Throwable;

class ModLifecycleService
{
    protected PackageRecipeRegistry $recipes;

    protected ProviderContext $contexts;

    public function __construct(
        protected AdapterRegistry $adapters,
        protected ManifestService $manifest,
        protected OperationStore $store,
        ?PackageRecipeRegistry $recipes = null,
        ?ProviderContext $contexts = null
    ) {
        $this->recipes = $recipes ?? new PackageRecipeRegistry();
        $this->contexts = $contexts ?? new ProviderContext($adapters);
    }

    protected function sourceContext(
        Server $server,
        string $provider
    ): SourceContext {
        return $this->contexts->forServer(
            $server,
            $provider
        );
    }

    public function driver(Server $server, string $provider): LifecycleDriver
    {
        $adapter = $this->adapters->forServer($server);
        if ($provider === 'existing' && $adapter) { return new ExistingModDriver($server); }
        if (!$adapter instanceof \GameNest\GameNestModManager\Adapters\ConfiguredGameAdapter) {
            throw new RuntimeException(
                'Lifecycle support requires an enabled Game Builder definition.'
            );
        }

        return (new ConfiguredDriverResolver)
            ->create($server, $adapter, $provider);
    }

    public function supported(Server $server, string $provider): bool
    {
        $adapter = $this->adapters->forServer($server);
        if ($provider === 'existing') { return $adapter !== null; }
        if (!$adapter instanceof \GameNest\GameNestModManager\Adapters\ConfiguredGameAdapter) {
            return false;
        }

        return (new ConfiguredDriverResolver)
            ->driverClass($adapter, $provider) !== null;
    }

    public function updates(Server $server, ?array $mods = null): array
    {
        Gate::authorize('file.read', $server);
        Gate::authorize('file.read-content', $server);
        $rows = [];
        $mods ??= $this->manifest->mods($server);
        $dependencyCache = [];

        foreach ($mods as $key => $entry) {
            $row = [
                'name' => $entry['name'] ?? $key,
                'current' => $entry['version'] ?? '',
                'latest' => '',
                'available' => false,
                'status' => 'Not supported yet',
                'dependency' =>
                    !empty($entry['dependency']),
                'required_by' =>
                    array_values(
                        (array) (
                            $entry['required_by']
                            ?? []
                        )
                    ),
                'requirements' =>
                    $this->dependencyRequirementDetails(
                        $server,
                        $key,
                        $entry,
                        $mods,
                        $dependencyCache
                    ),
            ];
            if (in_array($entry['provider'], ['direct', 'upload', 'existing'], true)) {
                $row['status'] = 'Manual source - no update feed';
                $rows[$key] = $row;
                continue;
            }
            if ($this->supported($server, $entry['provider'])) {
                try {
                    $driver = $this->driver(
                        $server,
                        $entry['provider']
                    );

                    $source = $this->sourceContext(
                        $server,
                        (string) $entry['provider']
                    );

                    $mod = $driver->provider()->get(
                        $entry['provider_id'],
                        $source
                    );

                    $file = $mod['latest_file'] ?? [];

                    $row['provider_latest'] =
                        $file['version']
                        ?? '';

                    $requiredFile =
                        $this->requiredDependencyFile(
                            $server,
                            $key,
                            $entry,
                            $mods,
                            $dependencyCache
                        );

                    if ($requiredFile !== null) {
                        $file = $requiredFile;
                        $row['constrained'] = true;
                    } else {
                        $row['constrained'] = false;
                    }

                    if (empty($file['id'])) {
                        throw new RuntimeException(
                            'No downloadable release'
                        );
                    }

                    $row['latest'] =
                        $file['version']
                        ?: ('File #' . $file['id']);

                    $row['available'] =
                        (string) $file['id']
                        !== (string) (
                            $entry['file_id']
                            ?? ''
                        );

                    $row['status'] =
                        $row['available']
                            ? 'Update available'
                            : 'Up to date';
                } catch (Throwable $e) {
                    $row['status'] = 'Check failed — try again';
                }
            }
            $rows[$key] = $row;
        }
        return $rows;
    }

    protected function dependencyRequirementDetails(
        Server $server,
        string $dependencyKey,
        array $entry,
        array $mods,
        array &$dependencyCache
    ): array {
        if (empty($entry['dependency'])) {
            return [];
        }

        $requirements = [];

        foreach (($entry['required_by'] ?? []) as $parentKey) {
            $parent = $mods[$parentKey] ?? null;

            if (!$parent) {
                continue;
            }

            $parentProvider =
                trim((string) ($parent['provider'] ?? ''));

            if ($parentProvider === '') {
                continue;
            }

            try {
                $driver = $this->driver(
                    $server,
                    $parentProvider
                );

                foreach (
                    $this->cachedDependencies(
                        $server,
                        $parentProvider,
                        $parent['provider_id'],
                        $dependencyCache,
                        $driver
                    )
                    as $dependency
                ) {
                    if (!is_array($dependency)) {
                        continue;
                    }

                    $dependencyId =
                        trim((string) (
                            $dependency['id']
                            ?? ''
                        ));

                    if ($dependencyId === '') {
                        continue;
                    }

                    if (
                        $parentProvider
                        . ':'
                        . $dependencyId
                        !== $dependencyKey
                    ) {
                        continue;
                    }

                    $requirements[] = [
                        'parent_key' => $parentKey,
                        'parent_name' =>
                            $parent['name']
                            ?? $parentKey,
                        'constraint' =>
                            trim((string) (
                                $dependency['constraint']
                                ?? ''
                            )),
                        'file_id' =>
                            trim((string) (
                                $dependency['file_id']
                                ?? ''
                            )),
                    ];
                }
            } catch (Throwable) {
                continue;
            }
        }

        return $requirements;
    }

    protected function cachedDependencies(
        Server $server,
        string $provider,
        string|int $providerId,
        array &$cache,
        LifecycleDriver $driver
    ): array {
        $cacheKey = strtolower(trim($provider))
            . ':'
            . trim((string) $providerId);

        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        return $cache[$cacheKey] = $driver->dependencies(
            $providerId,
            $this->sourceContext(
                $server,
                $provider
            )
        );
    }

    protected function requiredDependencyFile(
        Server $server,
        string $dependencyKey,
        array $entry,
        array $mods,
        array &$dependencyCache
    ): ?array {
        if (empty($entry['dependency'])) {
            return null;
        }

        foreach (($entry['required_by'] ?? []) as $parentKey) {
            $parent = $mods[$parentKey] ?? null;

            if (!$parent) {
                continue;
            }

            if (($parent['provider'] ?? '') !== ($entry['provider'] ?? '')) {
                continue;
            }

            $driver = $this->driver(
                $server,
                $parent['provider']
            );

            foreach (
                $this->cachedDependencies(
                    $server,
                    (string) $parent['provider'],
                    $parent['provider_id'],
                    $dependencyCache,
                    $driver
                )
                as $dependency
            ) {
                if (!is_array($dependency)) {
                    continue;
                }

                $id = (string) ($dependency['id'] ?? '');

                if (
                    $parent['provider'] . ':' . $id
                    !== $dependencyKey
                ) {
                    continue;
                }

                $fileId = trim(
                    (string) ($dependency['file_id'] ?? '')
                );

                if ($fileId === '') {
                    continue;
                }

                [$version] = array_pad(
                    explode(':', $fileId, 2),
                    2,
                    ''
                );

                return [
                    'id' => $fileId,
                    'version' => $version,
                    'download_url' => 'managed-dependency',
                ];
            }
        }

        return null;
    }

    public function preflightGithubAsset(
    Server $server,
    int $repositoryId,
    int $assetId
): array
{
    foreach (
        [
            'file.read',
            'file.read-content',
            'file.create',
            'file.delete',
            'file.archive',
        ] as $permission
    ) {
        Gate::authorize(
            $permission,
            $server
        );
    }

    if (
        $repositoryId < 1
        || $assetId < 1
    ) {
        return [
            'compatible' => false,
            'reason' => 'Invalid GitHub release asset.',
            'files' => 0,
            'cached' => false,
        ];
    }

    $adapter =
        $this->adapters->forServer(
            $server
        );

    if (!$adapter) {
        return [
            'compatible' => false,
            'reason' => 'No supported game adapter was found for this server.',
            'files' => 0,
            'cached' => false,
        ];
    }

    /*
     * GitHub release asset IDs are immutable, so compatibility can be
     * safely reused for the same game adapter and release asset.
     *
     * Versioning allows us to invalidate old compatibility decisions
     * if ModHarbor's deployment rules materially change.
     */
    $cacheKey =
        'gamenest-mod-manager:github-preflight:v2:' .
        $adapter->key() .
        ':' .
        $repositoryId .
        ':' .
        $assetId;

    if ($adapter instanceof \GameNest\GameNestModManager\Adapters\ConfiguredGameAdapter) {
        $cacheKey .= ':' . hash('sha256', json_encode($adapter->definition(), JSON_THROW_ON_ERROR));
    }

    $cached =
        Cache::get(
            $cacheKey
        );

    if (
        is_array($cached)
        && array_key_exists(
            'compatible',
            $cached
        )
    ) {
        $cached['cached'] = true;

        return $cached;
    }

    $driver =
        $this->driver(
            $server,
            'github'
        );

    $preflightId =
        bin2hex(
            random_bytes(8)
        );

    $files =
        new FileTransaction(
            app(DaemonFileRepository::class)
                ->setServer($server),
            '.gamenest/mod-manager/preflight/' .
            $preflightId,
            static function (array $moves): void {
                // Preflight never moves live managed files.
            }
        );

    try {
        $files->mkdir(
            $files->root
        );

        $release =
            $driver->prepare(
                $repositoryId,
                $assetId,
                $files,
                $this->sourceContext($server, 'github')
            );

        $count =
            count(
                (array) (
                    $release['files']
                    ?? []
                )
            );

        if ($count < 1) {
            $result = [
                'compatible' => false,
                'reason' => 'No supported game-server mod files were found.',
                'files' => 0,
                'cached' => false,
            ];

            Cache::put(
                $cacheKey,
                $result,
                now()->addDay()
            );

            return $result;
        }

        $result = [
            'compatible' => true,
            'reason' =>
                $count === 1
                    ? '1 supported mod file found.'
                    : $count . ' supported mod files found.',
            'files' => $count,
            'cached' => false,
        ];

        Cache::put(
            $cacheKey,
            $result,
            now()->addDays(30)
        );

        return $result;

    } catch (RuntimeException $exception) {
        $message =
            $exception->getMessage();

        $result = [
            'compatible' => false,
            'reason' => $message,
            'files' => 0,
            'cached' => false,
        ];

        /*
         * Cache deterministic package-layout failures briefly.
         * Do not cache provider/network/permission failures.
         */
        if (
            str_contains(
                $message,
                'supported mod'
            )
            || str_contains(
                $message,
                'supported mod directories'
            )
            || str_contains(
                $message,
                'supported mod locations'
            )
            || str_contains(
                $message,
                'Invalid GitHub archive'
            )
            || str_contains(
                $message,
                'symbolic links'
            )
        ) {
            Cache::put(
                $cacheKey,
                $result,
                now()->addDay()
            );
        }

        return $result;

    } catch (Throwable) {
        return [
            'compatible' => false,
            'reason' =>
                'The release asset could not be inspected safely.',
            'files' => 0,
            'cached' => false,
        ];

    } finally {
        try {
            $files->cleanup();
        } catch (Throwable) {
            // Compatibility result remains valid if temp cleanup fails.
        }
    }
}


    /** Backward-compatible action alias; all adoption uses the shared scanner and transaction. */
    public function adoptExistingRustPlugin(Server $server, string $path, string $versionLabel = 'Adopted'): array
    {
        return app(ExistingModService::class)->adopt($server, [$path], $versionLabel);
    }

    public function dependencyPlan(Server $server, string $key, string $action = 'install'): array
    {
        Gate::authorize('file.read', $server); Gate::authorize('file.read-content', $server);
        $mods = $this->manifest->mods($server);
        if (in_array($action, ['remove', 'disable', 'enable'], true)) {
            $rows = [];
            foreach ($mods[$key]['required_by'] ?? [] as $parent) {
                $rows[] = ['key' => $parent, 'type' => 'required_by', 'constraint' => '', 'file_id' => '', 'installed' => true];
            }
            return $rows;
        }
        [$provider, $id] = array_pad(explode(':', $key, 2), 2, '');
        $driver = $this->driver($server, $provider);
        $context = $this->sourceContext($server, $provider);
        $edges = DependencyGraph::normalize($driver->dependencies($id, $context), $provider);
        foreach ($this->recipes->dependencies($context->gameKey(), $driver, $id, $context) as $recipe) {
            $edges = array_merge($edges, DependencyGraph::normalize([$this->resolveRecipeDependency($server, $recipe)], $provider));
        }
        foreach ($edges as &$edge) { $edge['installed'] = isset($mods[$edge['key']]); }
        return $edges;
    }

    public function dependencyPreview(Server $server, string $key, string $action): array
    {
        if (in_array($action, ['remove', 'disable', 'enable'], true)) {
            return $this->dependencyPlan($server, $key, $action);
        }
        $rows = []; $seen = [];
        $walk = function ($node, $depth) use (&$walk, &$rows, &$seen, $server, $action) {
            if (isset($seen[$node])) { return; }
            if (count($seen) >= 100) { throw new RuntimeException('Dependency preview exceeds 100 packages.'); }
            $seen[$node] = true;
            foreach ($this->dependencyPlan($server, $node, $action) as $edge) {
                $rows[] = $edge + ['parent' => $node, 'depth' => $depth];
                if ($edge['type'] === 'required' && !$edge['installed']) { $walk($edge['key'], $depth + 1); }
            }
        };
        $walk($key, 0);
        return $rows;
    }

public function run(
        Server $server,
        string $action,
        string $key,
        string|int|null $installFileId = null,
        string|int|null $replacementProviderId = null
    ): array
    {
        $auditAction = $action;
        if ($action === 'repair') { $action = 'reinstall'; }
        if (!in_array($action, ['install', 'enable', 'disable', 'remove', 'reinstall', 'update', 'replace'], true)) {
            throw new RuntimeException('Unsupported mod action.');
        }
        foreach (['file.read', 'file.read-content', 'file.create', 'file.update', 'file.delete'] as $permission) {
            Gate::authorize($permission, $server);
        }
        if (in_array($action, ['install', 'reinstall', 'update', 'replace'], true)) {
            Gate::authorize('file.archive', $server);
        }
        if (!preg_match('/^([a-z0-9_-]+):([^\x00-\x1f\x7f]+)$/D', $key, $match)) {
            throw new RuntimeException('Invalid mod identifier.');
        }
        $driver = $this->driver($server, $match[1]);
        return $this->store->exclusive(
            $server,
            function () use (
                $server,
                $action,
                $key,
                $driver,
                $match,
                $installFileId,
                $replacementProviderId,
                $auditAction
            ) {
            $this->requireStopped($server);
            DiskSpaceGuard::server($server, 0);
            $configured = $this->adapters->forServer($server);
            $definitionBefore = $configured instanceof \GameNest\GameNestModManager\Adapters\ConfiguredGameAdapter ? $configured->definition() : null;
            if ($definitionBefore !== null) { $driver = $this->driver($server, $match[1]); }
            $before = $this->manifest->read($server);
            $mods = $before['mods'];
            if (($action === 'install') === isset($mods[$key])) {
                throw new RuntimeException(
                    $action === 'install'
                        ? 'This mod is already installed.'
                        : 'This mod is not installed.'
                );
            }

            $previousUploadProviderId =
                $action === 'replace'
                    ? (
                        $mods[$key]['provider_id']
                        ?? null
                    )
                    : null;

            if ($action === 'replace') {
                if (($mods[$key]['provider'] ?? '') !== 'upload') {
                    throw new RuntimeException(
                        'Replace File is only available for Upload File packages.'
                    );
                }

                if ($replacementProviderId === null || $replacementProviderId === '') {
                    throw new RuntimeException(
                        'A replacement upload package was not supplied.'
                    );
                }
            }
            $id = bin2hex(random_bytes(12));
            $journal = [
                'id' => $id, 'action' => $auditAction, 'name' => $mods[$key]['name'] ?? $key,
                'status' => 'running', 'started_at' => now()->toIso8601String(),
                'started_us' => microtime(true),
                'actor' => (string) (auth()->id() ?? 'system'), 'moves' => [], 'changes' => [],
                'manifest_before' => $before, 'message' => 'Operation started.',
                'game_definition' => $definitionBefore,
                'restart_required' => $definitionBefore['behavior']['restart_required'] ?? true,
            ];
            $this->store->save($server, $journal);
            $files = new FileTransaction(app(DaemonFileRepository::class)->setServer($server), '.gamenest/mod-manager/operations/' . $id,
                function (array $moves) use ($server, &$journal) {
                    $journal['moves'] = $moves;
                    $this->store->save($server, $journal);
                });
            $phase = function (string $name) use ($server, &$journal): void {
                $nowUs = microtime(true);

                if (!empty($journal['phases'])) {
                    $previous = array_key_last($journal['phases']);

                    if (
                        $previous !== null
                        && isset($journal['phases'][$previous]['started_us'])
                        && !isset($journal['phases'][$previous]['duration_ms'])
                    ) {
                        $journal['phases'][$previous]['duration_ms'] = round(
                            max(
                                0,
                                $nowUs
                                - (float) $journal['phases'][$previous]['started_us']
                            ) * 1000,
                            2
                        );
                    }
                }

                $journal['phase'] = $name;
                $journal['phases'][] = [
                    'name' => $name,
                    'at' => now()->toIso8601String(),
                    'started_us' => $nowUs,
                ];

                $this->store->save($server, $journal);
            };

            $finishTiming = function () use (&$journal): void {
                $nowUs = microtime(true);

                if (!empty($journal['phases'])) {
                    $last = array_key_last($journal['phases']);

                    if (
                        $last !== null
                        && isset($journal['phases'][$last]['started_us'])
                        && !isset($journal['phases'][$last]['duration_ms'])
                    ) {
                        $journal['phases'][$last]['duration_ms'] = round(
                            max(
                                0,
                                $nowUs
                                - (float) $journal['phases'][$last]['started_us']
                            ) * 1000,
                            2
                        );
                    }
                }

                if (isset($journal['started_us'])) {
                    $journal['duration_ms'] = round(
                        max(
                            0,
                            $nowUs - (float) $journal['started_us']
                        ) * 1000,
                        2
                    );
                }
            };

            $cleanupPaths = [];

            try {
                $phase('Resolve');
                $files->mkdir($files->root);
                if (in_array($action, ['install', 'reinstall', 'update', 'replace'], true)) {
                    $deployProviderId = match ($action) {
                        'replace' =>
                            $replacementProviderId,

                        'reinstall' =>
                            $mods[$key]['provider_id']
                                ?? $match[2],

                        default =>
                            $match[2],
                    };

                    $this->deploy(
                        $server,
                        $files,
                        $driver,
                        $mods,
                        $key,
                        $deployProviderId,
                        $action,
                        $installFileId,
                        $phase
                    );
                } elseif ($action === 'remove') {
                    $this->removeMods(
                        $server,
                        $files,
                        $mods,
                        $key,
                        $cleanupPaths
                    );
                } else {
                    $this->toggle($files, $driver, $mods, $key, $action === 'enable');
                }
                /*
                 * Filesystem changes are staged, but the new manifest has not
                 * been committed yet. Prove the resulting managed state first.
                 *
                 * This verifier is universal: every supported game/provider
                 * passes through the same lifecycle safety gate.
                 */
                $this->requireStopped($server);

                $phase('Verify');
                DependencyGraph::validate($mods);
                $journal['verification'] =
                    $this->verifyLifecycleResult(
                        $server,
                        $files,
                        (array) ($before['mods'] ?? []),
                        $mods
                    );

                /*
                 * Check again after filesystem verification. The game server
                 * must remain stopped for the entire commit boundary.
                 */
                $this->requireStopped($server);

                // Refuse to clobber external manifest edits made while downloads were running.
                $currentAdapter = $this->adapters->forServer($server);
                $currentDefinition = $currentAdapter instanceof \GameNest\GameNestModManager\Adapters\ConfiguredGameAdapter ? $currentAdapter->definition() : null;
                if ($definitionBefore !== $currentDefinition) { throw new RuntimeException('Game definition changed during the operation.'); }
                if ($this->manifest->read($server) !== $before) {
                    throw new RuntimeException('The manifest changed outside this operation.');
                }
                $phase('Journal');
                $after = $before;
                $after['mods'] = $mods;
                $after['updated_at'] = now()->toIso8601String();
                $json = json_encode($after, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $files->repo->putContent($files->root . '/manifest-new.json', $json . PHP_EOL);
                if ($files->stat($this->manifest->filename()) !== null) {
                    $files->move($this->manifest->filename(), $files->root . '/manifest-before.json');
                }
                $files->move($files->root . '/manifest-new.json', $this->manifest->filename());
                $journal['name'] = $mods[$key]['name'] ?? $journal['name'];
                foreach (array_unique(array_merge(array_keys($before['mods']), array_keys($mods))) as $changedKey) {
                    if (($before['mods'][$changedKey] ?? null) !== ($mods[$changedKey] ?? null)) {
                        $journal['changes'][] = $mods[$changedKey]['name'] ?? $before['mods'][$changedKey]['name'] ?? $changedKey;
                    }
                }
                $journal['transitions'] = AuditTrail::transitions($before['mods'], $mods);
                $journal['status'] = 'completed';

                $journal['message'] =
                    'Completed. Active configuration files were preserved.';

                if (
                    isset($mods[$key])
                    && (
                        $mods[$key]['provider']
                        ?? ''
                    ) === 'upload'
                ) {
                    $currentSourceId =
                        (string) (
                            $mods[$key]['provider_id']
                            ?? ''
                        );

                    $currentPackage =
                        $currentSourceId !== ''
                            ? app(
                                UploadPackageStore::class
                            )->get($currentSourceId)
                            : null;

                    $currentFilename =
                        (string) (
                            $currentPackage['filename']
                            ?? $mods[$key]['name']
                            ?? 'private upload'
                        );

                    $journal['source_after'] =
                        $currentSourceId;

                    if (
                        $previousUploadProviderId
                        !== null
                    ) {
                        $journal['source_before'] =
                            (string)
                            $previousUploadProviderId;
                    }

                    $journal['message'] =
                        match ($action) {
                            'install' =>
                                'Installed private upload '
                                . $currentFilename
                                . '.',

                            'replace' =>
                                'Replaced private upload with '
                                . $currentFilename
                                . '. Active configuration files were preserved.',

                            'reinstall' =>
                                'Reinstalled from retained private upload '
                                . $currentFilename
                                . '.',

                            default =>
                                $journal['message'],
                        };
                }

                $journal['finished_at'] =
                    now()->toIso8601String();

                $finishTiming();

                $this->store->save(
                    $server,
                    $journal
                );
            } catch (Throwable $e) {
                $journal['error_type'] = get_class($e);
                $journal['status'] = 'failed';
                $journal['message'] = 'Operation failed; file changes were rolled back.';
                try {
                    $files->rollback();
                } catch (Throwable) {
                    $journal['status'] = 'recovery_required';
                    $journal['message'] = 'Automatic recovery could not finish. Keep the game stopped and contact an administrator; recovery files are retained.';
                }
                $journal['finished_at'] = now()->toIso8601String();
                $finishTiming();
                $this->store->save($server, $journal);
                if ($journal['status'] === 'failed') {
                    try { $files->cleanup(); } catch (Throwable) {}
                }
                // Provider HTTP exceptions can contain signed URLs and credentials.
                $detail = get_class($e) === RuntimeException::class ? ' ' . $e->getMessage() : '';
                throw new RuntimeException($journal['message'] . $detail);
            }

            if (
                $action === 'remove'
                && $cleanupPaths !== []
            ) {
                try {
                    $files->cleanupEmptyParents(
                        array_values(
                            array_unique($cleanupPaths)
                        )
                    );
                } catch (Throwable) {
                    /*
                     * The uninstall itself already succeeded. Folder cleanup
                     * must never turn a successful removal into a rollback.
                     */
                    $journal['message'] .=
                        ' Some empty mod folders could not be cleaned automatically.';

                    $this->store->save(
                        $server,
                        $journal
                    );
                }
            }

            try {
                $files->cleanup();
            } catch (Throwable) {
                $journal['message'] .=
                    ' Temporary files could not be cleaned; administrator cleanup is needed.';

                $this->store->save(
                    $server,
                    $journal
                );
            }

            if (
                $action === 'replace'
                && $previousUploadProviderId
                    !== null
                && isset($mods[$key])
                && (
                    $mods[$key]['provider']
                    ?? ''
                ) === 'upload'
            ) {
                $newUploadProviderId =
                    (string) (
                        $mods[$key]['provider_id']
                        ?? ''
                    );

                $oldUploadProviderId =
                    (string)
                    $previousUploadProviderId;

                if (
                    $oldUploadProviderId !== ''
                    && $newUploadProviderId !== ''
                    && $oldUploadProviderId
                        !== $newUploadProviderId
                ) {
                    try {
                        $deleted =
                            app(
                                UploadPackageStore::class
                            )->delete(
                                $oldUploadProviderId,
                                $server->uuid
                            );

                        if ($deleted) {
                            $journal['old_source_cleaned'] =
                                true;
                        } else {
                            $journal['message'] .=
                                ' The previous retained source was already unavailable.';
                        }
                    } catch (Throwable) {
                        $journal['message'] .=
                            ' The previous retained source could not be cleaned automatically.';
                    }

                    try {
                        $this->store->save(
                            $server,
                            $journal
                        );
                    } catch (Throwable) {
                    }
                }
            }

            return $journal;
        });
    }


    public function recoverOperation(
        Server $server,
        string $operationId
    ): array {
        foreach (
            [
                'file.read',
                'file.read-content',
                'file.create',
                'file.update',
                'file.delete',
            ]
            as $permission
        ) {
            Gate::authorize($permission, $server);
        }

        return $this->store->recoveryExclusive(
            $server,
            $operationId,
            function (array $journal) use (
                $server,
                $operationId
            ): array {
                $this->requireStopped($server, false);

                $files = new FileTransaction(
                    app(DaemonFileRepository::class)
                        ->setServer($server),
                    '.gamenest/mod-manager/operations/'
                        . $operationId,
                    function (array $moves) use ($server, &$journal): void {
                        $journal['moves'] = $moves; $this->store->save($server, $journal);
                    }
                );

                try {
                    $moves = $journal['moves'] ?? [];

                    if (!is_array($moves)) {
                        throw new RuntimeException(
                            'The recovery journal contains invalid move data.'
                        );
                    }

                    $files->recover($moves);

                    $expectedManifest =
                        $journal['manifest_before']
                        ?? null;

                    if (!is_array($expectedManifest)) {
                        throw new RuntimeException(
                            'The recovery journal does not contain a valid previous manifest.'
                        );
                    }

                    if (
                        $this->manifest->read($server)
                        !== $expectedManifest
                    ) {
                        throw new RuntimeException(
                            'Recovery restored the recorded file moves, but the manifest does not match the pre-operation state.'
                        );
                    }

                    /*
                     * Only remove transaction staging after rollback and
                     * manifest verification have both succeeded.
                     */
                    $files->cleanup();

                    $journal['status'] = 'recovered';
                    $journal['finished_at'] =
                        now()->toIso8601String();
                    $journal['recovered_at'] =
                        now()->toIso8601String();
                    $journal['recovered_by'] =
                        (string) (auth()->id() ?? 'system');
                    $journal['message'] =
                        'Interrupted operation was safely rolled back and verified.';

                    $this->store->save(
                        $server,
                        $journal
                    );

                    $recovery = [
                        'id' => bin2hex(random_bytes(12)),
                        'action' => 'recovery',
                        'recovery_of' => $operationId,
                        'affected_files' => AuditTrail::display($journal)['affected_files'],
                        'name' => (string) (
                            $journal['name']
                            ?? 'Interrupted operation'
                        ),
                        'status' => 'completed',
                        'started_at' =>
                            now()->toIso8601String(),
                        'started_us' =>
                            microtime(true),
                        'finished_at' =>
                            now()->toIso8601String(),
                        'actor' =>
                            (string) (
                                auth()->id()
                                ?? 'system'
                            ),
                        'moves' => [],
                        'changes' =>
                            array_values(
                                (array) (
                                    $journal['changes']
                                    ?? []
                                )
                            ),
                        'message' =>
                            'Recovered operation '
                            . $operationId
                            . ' by safely restoring its pre-operation filesystem and manifest state.',
                    ];

                    $this->store->save(
                        $server,
                        $recovery
                    );

                    return $recovery;
                } catch (Throwable $exception) {
                    $journal['status'] =
                        'recovery_required';

                    $journal['finished_at'] =
                        now()->toIso8601String();

                    $journal['message'] =
                        'Automatic recovery could not verify a safe rollback. Keep the game server stopped; manual administrator review is still required.';

                    try {
                        $this->store->save(
                            $server,
                            $journal
                        );
                    } catch (Throwable) {
                    }

                    $detail =
                        get_class($exception)
                        === RuntimeException::class
                            ? ' '
                                . $exception->getMessage()
                            : '';

                    throw new RuntimeException(
                        $journal['message']
                        . $detail
                    );
                }
            }
        );
    }


    public function repair(
        Server $server,
        string $key
    ): array {
        $result = $this->run(
            $server,
            'repair',
            $key
        );

        $result['action'] = 'repair';
        $result['message'] =
            'Repair completed. Managed files were redeployed from the retained/provider source while active configuration files were preserved.';

        try {
            $this->store->save(
                $server,
                $result
            );
        } catch (Throwable) {
        }

        return $result;
    }

    /**
     * Verify the complete managed filesystem and dependency state before the
     * new manifest is allowed to replace the previous manifest.
     *
     * This is game-independent. Game/provider drivers remain responsible only
     * for path validation and preserved-file rules.
     */
    protected function verifyLifecycleResult(
        Server $server,
        FileTransaction $files,
        array $beforeMods,
        array $afterMods
    ): array {
        $owners = [];
        $managedFiles = 0;
        $retiredFiles = 0;
        $dependencyLinks = 0;

        /*
         * First prove that every path the NEW manifest intends to own exists
         * exactly where its driver says it should exist.
         */
        foreach ($afterMods as $key => $entry) {
            if (
                !is_array($entry)
                || !is_array($entry['paths'] ?? null)
                || !is_array($entry['required_by'] ?? null)
            ) {
                throw new RuntimeException(
                    'Lifecycle verification found invalid managed package metadata.'
                );
            }

            $driver = $this->driver(
                $server,
                (string) ($entry['provider'] ?? '')
            );

            foreach ($entry['paths'] as $path) {
                if (!is_string($path)) {
                    throw new RuntimeException(
                        'Lifecycle verification found an invalid managed file path.'
                    );
                }

                $driver->validatePath($path);

                /*
                 * Logical ownership must remain unique even if one package is
                 * disabled and therefore physically stored elsewhere.
                 */
                if (
                    isset($owners[$path])
                    && $owners[$path] !== $key
                ) {
                    throw new RuntimeException(
                        'Lifecycle verification found duplicate ownership of managed path: '
                        . $path
                    );
                }

                $owners[$path] = $key;

                $actual = $this->actual(
                    $key,
                    $entry,
                    $path,
                    $driver
                );

                $stat = $files->stat($actual);

                if (
                    $stat === null
                    || empty($stat['file'])
                ) {
                    throw new RuntimeException(
                        'Lifecycle verification could not find deployed managed file: '
                        . $path
                    );
                }

                $managedFiles++;
            }

            /*
             * Every required_by reference must still point at a real managed
             * package. Also refuse an enabled parent whose dependency is
             * disabled.
             */
            foreach ($entry['required_by'] as $parentKey) {
                if (
                    !is_string($parentKey)
                    || $parentKey === $key
                    || !isset($afterMods[$parentKey])
                ) {
                    throw new RuntimeException(
                        'Lifecycle verification found an invalid dependency relationship.'
                    );
                }

                if (
                    !empty($afterMods[$parentKey]['enabled'])
                    && empty($entry['enabled'])
                ) {
                    throw new RuntimeException(
                        'Lifecycle verification found an enabled package with a disabled dependency: '
                        . ($entry['name'] ?? $key)
                    );
                }

                $dependencyLinks++;
            }
        }

        /*
         * Next prove that paths owned by the OLD manifest but no longer owned
         * by the same package were actually retired.
         *
         * We never delete or inspect arbitrary neighboring files here. Only
         * paths explicitly owned by the previous ModHarbor manifest qualify.
         */
        foreach ($beforeMods as $key => $oldEntry) {
            if (
                !is_array($oldEntry)
                || !is_array($oldEntry['paths'] ?? null)
            ) {
                throw new RuntimeException(
                    'Lifecycle verification found invalid previous package metadata.'
                );
            }

            $driver = $this->driver(
                $server,
                (string) ($oldEntry['provider'] ?? '')
            );

            $newEntry =
                $afterMods[$key]
                ?? null;

            $newPaths =
                is_array($newEntry)
                    ? (array) ($newEntry['paths'] ?? [])
                    : [];

            foreach ($oldEntry['paths'] as $path) {
                if (!is_string($path)) {
                    throw new RuntimeException(
                        'Lifecycle verification found an invalid previous managed path.'
                    );
                }

                $driver->validatePath($path);

                /*
                 * Runtime/user configuration belongs to the user and is never
                 * considered an obsolete managed payload.
                 */
                if ($driver->preservePath($path)) {
                    continue;
                }

                /*
                 * The package still owns this logical path, so the new-file
                 * verification above already proved its replacement exists.
                 */
                if (
                    $newEntry !== null
                    && in_array(
                        $path,
                        $newPaths,
                        true
                    )
                ) {
                    continue;
                }

                $actual = $this->actual(
                    $key,
                    $oldEntry,
                    $path,
                    $driver
                );

                if ($files->stat($actual) !== null) {
                    throw new RuntimeException(
                        'Lifecycle verification found an obsolete managed file that was not retired: '
                        . $path
                    );
                }

                $retiredFiles++;
            }
        }

        return [
            'packages' => count($afterMods),
            'managed_files' => $managedFiles,
            'retired_files' => $retiredFiles,
            'dependency_links' => $dependencyLinks,
            'verified_at' => now()->toIso8601String(),
        ];
    }


    protected function requireStopped(Server $server, bool $allowConfigured = true): void
    {
        $state = app(DaemonServerRepository::class)->setServer($server)->getDetails()['state'] ?? '';
        $adapter = $this->adapters->forServer($server);
        if ($allowConfigured && $state === 'running' && $adapter instanceof \GameNest\GameNestModManager\Adapters\ConfiguredGameAdapter
            && $adapter->definition()['behavior']['install_while_running']) { return; }
        if ($state !== 'offline') {
            throw new RuntimeException('Stop the game server before changing mods. ModHarbor will not stop or restart it automatically.');
        }
    }

    protected function requiredBy(array $mods, string $key): array
    {
        return array_values(array_filter($mods[$key]['required_by'], fn ($parent) => isset($mods[$parent])));
    }

    protected function protect(array $mods, string $key): void
    {
        $parents = $this->requiredBy($mods, $key);
        if ($parents) {
            throw new RuntimeException('Dependency required by ' . implode(', ', array_map(fn ($p) => $mods[$p]['name'] ?? $p, $parents)) . '.');
        }
    }

    protected function actual(string $key, array $entry, string $path, LifecycleDriver $driver): string
    {
        $driver->validatePath($path);
        return $entry['enabled'] || $driver->preservePath($path) ? $path : '.gamenest/mod-manager/disabled/' . hash('sha256', $key) . '/' . $path;
    }

    protected function assertExclusive(array $mods, string $key, string $path): void
    {
        foreach ($mods as $otherKey => $entry) {
            if ($otherKey !== $key && in_array($path, $entry['paths'], true)) {
                throw new RuntimeException(
                    'ModHarbor cannot change '
                    . $path
                    . ' because it is already owned by another managed mod.'
                );
            }
        }
    }

    protected function toggle(FileTransaction $files, LifecycleDriver $driver, array &$mods, string $key, bool $enabled): void
    {
        $entry = $mods[$key];
        if ($entry['enabled'] === $enabled) {
            throw new RuntimeException('This mod is already ' . ($enabled ? 'enabled.' : 'disabled.'));
        }
        if (!$enabled) {
            $this->protect($mods, $key);
        } else {
            foreach ($mods as $dependency) {
                if (in_array($key, $dependency['required_by'], true) && !$dependency['enabled']) {
                    throw new RuntimeException('Enable dependency ' . $dependency['name'] . ' first.');
                }
            }
        }
        $next = $entry;
        $next['enabled'] = $enabled;
        $moves = [];
        foreach ($entry['paths'] as $path) {
            $driver->validatePath($path);
            if ($driver->preservePath($path)) { continue; }
            $this->assertExclusive($mods, $key, $path);
            $from = $this->actual($key, $entry, $path, $driver);
            $to = $this->actual($key, $next, $path, $driver);
            if ($files->stat($from) === null || $files->stat($to) !== null) {
                throw new RuntimeException('Cannot toggle a missing or conflicting file: ' . $path);
            }
            $moves[] = [$from, $to];
        }
        foreach ($moves as [$from, $to]) { $files->move($from, $to); }
        $next['updated_at'] = now()->toIso8601String();
        $mods[$key] = $next;
    }

    protected function removeMods(
        Server $server,
        FileTransaction $files,
        array &$mods,
        string $key,
        array &$cleanupPaths
    ): void
    {
        $this->protect($mods, $key);

        $queue = [$key];

        while ($queue) {
            $remove = array_shift($queue);

            if (!isset($mods[$remove])) {
                continue;
            }

            $entry = $mods[$remove];

            $entryDriver = $this->driver(
                $server,
                (string) $entry['provider']
            );

            foreach ($entry['paths'] as $path) {
                $entryDriver->validatePath($path);

                if ($entryDriver->preservePath($path)) {
                    continue;
                }

                $this->assertExclusive(
                    $mods,
                    $remove,
                    $path
                );

                $actual = $this->actual(
                    $remove,
                    $entry,
                    $path,
                    $entryDriver
                );

                if ($files->stat($actual) !== null) {
                    $files->move(
                        $actual,
                        $files->root .
                        '/backup/' .
                        hash('sha256', $remove) .
                        '/' .
                        $path
                    );

                    $cleanupPaths[] = $actual;
                }
            }

            unset($mods[$remove]);

            foreach (
                $mods
                as $dependencyKey => &$dependency
            ) {
                if (
                    !in_array(
                        $remove,
                        $dependency['required_by'],
                        true
                    )
                ) {
                    continue;
                }

                $dependency['required_by'] =
                    array_values(
                        array_diff(
                            $dependency['required_by'],
                            [$remove]
                        )
                    );

                $dependency['updated_at'] =
                    now()->toIso8601String();

                if (
                    $dependency['dependency']
                    && !$dependency['required_by']
                    && !in_array(
                        $dependencyKey,
                        $queue,
                        true
                    )
                ) {
                    /*
                     * The orphan may belong to another provider.
                     * Its own driver will be resolved when dequeued.
                     */
                    $queue[] = $dependencyKey;
                }
            }

            unset($dependency);
        }
    }

    protected function dependencyNeedsReplacement(array $edge, array $installed): bool
    {
        $pin = (string) ($edge['file_id'] ?? '');
        return $pin !== '' ? $pin !== (string) ($installed['file_id'] ?? '')
            : !DependencyGraph::satisfies((string) ($installed['version'] ?? ''), (string) ($edge['constraint'] ?? ''));
    }

    protected function deploy(
        Server $server,
        FileTransaction $files,
        LifecycleDriver $driver,
        array &$mods,
        string $key,
        string|int $id,
        string $action,
        string|int|null $installFileId = null,
        ?callable $phase = null
    ): void
    {
        $previous = $mods[$key] ?? null;

        /*
     * Parent packages may be updated normally even when they depend on
     * packages from another provider.
     *
     * Protect only packages that are themselves required by an installed
     * package from another provider.
     */
    if ($previous) {
        foreach (($previous['required_by'] ?? []) as $requiredByKey) {
            $requiringPackage = $mods[$requiredByKey] ?? null;

            if (
                $requiringPackage
                && ($requiringPackage['provider'] ?? null)
                    !== $driver->provider()->key()
            ) {
                throw new RuntimeException(
                    'Cross-provider dependencies prevent this dependency from being replaced directly.'
                );
            }
        }
    }

    $adapter =
            $this->adapters->forServer($server);

        if (!$adapter) {
            throw new RuntimeException(
                'No ModHarbor game adapter is available for this server.'
            );
        }

        $source = $this->sourceContext(
            $server,
            $driver->provider()->key()
        );

        /*
         * Native provider dependencies are still supported exactly as before.
         */
        $dependencySpecs = [];
        $edgeMap = [];
        if ($action === 'reinstall') {
            $edgeMap[$key] = $previous['dependency_specs'] ?? [];
            foreach ($mods as $dependency) {
                if (in_array($key, $dependency['required_by'], true)) {
                    $dependencySpecs[] = ['provider' => $dependency['provider'], 'id' => (string) $dependency['provider_id']];
                }
            }
        } else {
            $visiting = []; $visited = [];
            $collect = function (string $node) use (&$collect, &$visiting, &$visited, &$dependencySpecs, &$edgeMap, $server, $mods, $action, $key) {
                if (isset($visiting[$node])) { throw new RuntimeException('Cyclic required dependencies.'); }
                if (isset($visited[$node])) { return; }
                if (count($visited) + count($visiting) >= 100) { throw new RuntimeException('Dependency graph exceeds 100 packages.'); }
                $visiting[$node] = true;
                $edges = $this->dependencyPlan($server, $node);
                $edgeMap[$node] = $edges;
                foreach ($edges as $edge) {
                    if ($edge['type'] === 'conflict' && isset($mods[$edge['key']])
                        && DependencyGraph::satisfies((string) $mods[$edge['key']]['version'], $edge['constraint'])) {
                        throw new RuntimeException('Conflicting installed mod: ' . $edge['key']);
                    }
                    if ($edge['type'] !== 'required') { continue; }
                    $dependencySpecs[] = $edge;
                    if (!isset($mods[$edge['key']]) || ($action === 'update'
                        && in_array($key, $mods[$edge['key']]['required_by'] ?? [], true)
                        && $this->dependencyNeedsReplacement($edge, $mods[$edge['key']]))) { $collect($edge['key']); }
                }
                unset($visiting[$node]); $visited[$node] = true;
            };
            $collect($driver->provider()->key() . ':' . $id);
        }

        /*
         * De-duplicate provider:id pairs.
         */
        $uniqueDependencies = [];

        foreach ($dependencySpecs as $spec) {
            $provider =
                (string) ($spec['provider'] ?? '');

            $dependencyId =
                (string) ($spec['id'] ?? '');

            if (
                $provider === ''
                || $dependencyId === ''
            ) {
                throw new RuntimeException(
                    'Invalid package dependency metadata.'
                );
            }

            $dependencyKey =
                $provider .
                ':' .
                $dependencyId;

            if ($dependencyKey === $key) {
                throw new RuntimeException(
                    'Cyclic dependency.'
                );
            }

            $incomingFileId =
                trim((string) ($spec['file_id'] ?? ''));

            $incomingConstraint =
                trim((string) ($spec['constraint'] ?? ''));

            if (isset($uniqueDependencies[$dependencyKey])) {
                $existingFileId =
                    trim((string) (
                        $uniqueDependencies[$dependencyKey]['file_id']
                        ?? ''
                    ));

                $existingConstraint =
                    trim((string) (
                        $uniqueDependencies[$dependencyKey]['constraint']
                        ?? ''
                    ));

                if (
                    $existingFileId !== ''
                    && $incomingFileId !== ''
                    && $existingFileId !== $incomingFileId
                ) {
                    throw new RuntimeException(
                        'Dependency conflict for '
                        . $dependencyKey
                        . ': required versions '
                        . ($existingConstraint !== ''
                            ? $existingConstraint
                            : $existingFileId)
                        . ' and '
                        . ($incomingConstraint !== ''
                            ? $incomingConstraint
                            : $incomingFileId)
                        . ' cannot both be satisfied.'
                    );
                }

                if (
                    $existingConstraint !== ''
                    && $incomingConstraint !== ''
                    && $existingConstraint !== $incomingConstraint
                    && $existingFileId === ''
                    && $incomingFileId === ''
                ) {
                    throw new RuntimeException(
                        'Dependency conflict for '
                        . $dependencyKey
                        . ': required versions '
                        . $existingConstraint
                        . ' and '
                        . $incomingConstraint
                        . ' cannot both be satisfied.'
                    );
                }

                if (
                    $existingFileId === ''
                    && $incomingFileId !== ''
                ) {
                    $uniqueDependencies[$dependencyKey]['file_id'] =
                        $incomingFileId;
                }

                if (
                    $existingConstraint === ''
                    && $incomingConstraint !== ''
                ) {
                    $uniqueDependencies[$dependencyKey]['constraint'] =
                        $incomingConstraint;
                }

                continue;
            }

            $uniqueDependencies[$dependencyKey] = [
                'provider' => $provider,
                'id' => $dependencyId,
            ];

            if ($incomingFileId !== '') {
                $uniqueDependencies[$dependencyKey]['file_id'] =
                    $incomingFileId;
            }

            if ($incomingConstraint !== '') {
                $uniqueDependencies[$dependencyKey]['constraint'] =
                    $incomingConstraint;
            }
        }

        $dependencySpecs =
            array_values(
                $uniqueDependencies
            );

        /*
         * Each prepared package carries its OWN driver.
         *
         * This is the key change that allows:
         *
         * GitHub DiscordLink
         *       ->
         * mod.io MightyMooseCore
         */
        $phase && $phase('Stage');
        $prepared = [];

        foreach (
            $dependencySpecs
            as $spec
        ) {
            $dependencyDriver =
                $this->driver(
                    $server,
                    $spec['provider']
                );

            $dependencySource =
                $this->sourceContext(
                    $server,
                    (string) $spec['provider']
                );

            $dependencyKey =
                $spec['provider'] .
                ':' .
                $spec['id'];

            if (isset($mods[$dependencyKey]) && !($action === 'update'
                && in_array($key, $mods[$dependencyKey]['required_by'] ?? [], true)
                && $this->dependencyNeedsReplacement($spec, $mods[$dependencyKey]))) {
                DependencyGraph::assertEntry(DependencyGraph::normalize([$spec], $spec['provider'])[0], $mods[$dependencyKey]);
                $requiredFileId = trim((string) ($spec['file_id'] ?? ''));

                if (
                    $requiredFileId !== ''
                    && (string) ($mods[$dependencyKey]['file_id'] ?? '') !== $requiredFileId
                ) {
                    $constraint = trim((string) ($spec['constraint'] ?? ''));

                    throw new RuntimeException(
                        'Installed dependency '
                        . $mods[$dependencyKey]['name']
                        . ' does not satisfy required version'
                        . ($constraint !== '' ? ' ' . $constraint : '')
                        . '.'
                    );
                }

                if (
                    !$mods[$dependencyKey]['enabled']
                    && ($previous['enabled'] ?? true)
                ) {
                    throw new RuntimeException(
                        'Enable dependency ' .
                        $mods[$dependencyKey]['name'] .
                        ' first.'
                    );
                }

                foreach (
                    $mods[$dependencyKey]['paths']
                    as $path
                ) {
                    if (
                        $dependencyDriver
                            ->preservePath($path)
                    ) {
                        continue;
                    }

                    $entry = $files->stat(
                        $this->actual(
                            $dependencyKey,
                            $mods[$dependencyKey],
                            $path,
                            $dependencyDriver
                        )
                    );

                    if (
                        $entry === null
                        || empty($entry['file'])
                    ) {
                        throw new RuntimeException(
                            'Reinstall dependency ' .
                            $mods[$dependencyKey]['name'] .
                            ' to restore its missing files first.'
                        );
                    }
                }

                continue;
            }

            $prepared[$dependencyKey] = [
                'driver' => $dependencyDriver,
                'release' =>
                    $dependencyDriver->prepare(
                        $spec['id'],
                        $spec['file_id'] ?? null,
                        $files,
                        $dependencySource
                    ),
            ];
        }

        $selectedFileId = match ($action) {
            'reinstall' =>
                $previous['file_id']
                    ?? '',
            'install', 'update' =>
                $installFileId,
            default =>
                null,
        };

        $prepared[$key] = [
            'driver' => $driver,
            'release' =>
                $driver->prepare(
                    $id,
                    $selectedFileId,
                    $files,
                    $source
                ),
        ];

        $phase && $phase('Validate');
        // Validate all selected releases and persisted reverse constraints before touching live files.
        $prospective = $mods;
        foreach ($prepared as $preparedKey => $item) {
            $release = $item['release'];
            if (array_key_exists('dependency_specs', $release)) {
                $actualEdges = DependencyGraph::normalize($release['dependency_specs'], $item['driver']->provider()->key());
                $plannedEdges = DependencyGraph::normalize($edgeMap[$preparedKey] ?? $mods[$preparedKey]['dependency_specs'] ?? [], $item['driver']->provider()->key());
                $sortEdges = static function (array $edges): array {
                    usort($edges, static fn ($a, $b) => strcmp(json_encode($a), json_encode($b)));
                    return $edges;
                };
                if ($sortEdges($actualEdges) !== $sortEdges($plannedEdges)) {
                    throw new RuntimeException('Selected package dependencies differ from the reviewed plan. Refresh or select a compatible release.');
                }
            }
            $prospective[$preparedKey] = array_merge($mods[$preparedKey] ?? [], [
                'version' => $release['file']['version'], 'file_id' => (string) $release['file']['id'],
                'enabled' => $mods[$preparedKey]['enabled'] ?? true,
                'dependency_specs' => $edgeMap[$preparedKey] ?? $mods[$preparedKey]['dependency_specs'] ?? [],
            ]);
        }
        DependencyGraph::validate($prospective);

        $rootRelease =
            $prepared[$key]['release'];

        if (
            $action === 'update'
            && (string) $rootRelease['file']['id']
                === (string) $previous['file_id']
        ) {
            throw new RuntimeException(
                'This mod is already up to date.'
            );
        }

        /*
         * Preflight EVERY package before moving a single installed file.
         */
        $targets = [];

        foreach (
            $prepared
            as $modKey => $preparedEntry
        ) {
            $itemDriver =
                $preparedEntry['driver'];

            $release =
                $preparedEntry['release'];

            $old =
                $mods[$modKey]
                ?? null;

            $state = [
                'enabled' =>
                    $old['enabled']
                    ?? true,
            ];

            foreach (
                $release['files']
                as $path => $source
            ) {
                $itemDriver->validatePath($path);

                if (
                    $itemDriver
                        ->preservePath($path)
                ) {
                    if (
                        $files->stat($path)
                        !== null
                    ) {
                        continue;
                    }
                } else {
                    $this->assertExclusive(
                        $mods,
                        $modKey,
                        $path
                    );
                }

                $actual =
                    $this->actual(
                        $modKey,
                        $state,
                        $path,
                        $itemDriver
                    );

                if (isset($targets[$actual])) {
                    throw new RuntimeException(
                        'Packages contain overlapping files: ' .
                        $path
                    );
                }

                $targets[$actual] = true;

                if (
                    $files->stat($actual) !== null
                    && !in_array(
                        $path,
                        $old['paths'] ?? [],
                        true
                    )
                ) {
                    throw new RuntimeException(
                        'An unmanaged file already exists: ' .
                        $path
                    );
                }
            }
        }

        /*
         * We have successfully downloaded, unpacked, validated paths,
         * checked dependencies and checked conflicts.
         *
         * No installed files have been changed before this point.
         */
        $this->requireStopped($server);
        $phase && $phase('Backup and Install');

        foreach (
            $prepared
            as $modKey => $preparedEntry
        ) {
            $itemDriver =
                $preparedEntry['driver'];

            $release =
                $preparedEntry['release'];

            $old =
                $mods[$modKey]
                ?? null;

            $state = [
                'enabled' =>
                    $old['enabled']
                    ?? true,
            ];

            foreach (
                $old['paths'] ?? []
                as $path
            ) {
                $itemDriver->validatePath($path);

                if (
                    $itemDriver
                        ->preservePath($path)
                ) {
                    continue;
                }

                $this->assertExclusive(
                    $mods,
                    $modKey,
                    $path
                );

                $actual =
                    $this->actual(
                        $modKey,
                        $old,
                        $path,
                        $itemDriver
                    );

                if (
                    $files->stat($actual)
                    !== null
                ) {
                    $files->move(
                        $actual,
                        $files->root .
                        '/backup/' .
                        hash(
                            'sha256',
                            $modKey
                        ) .
                        '/' .
                        $path
                    );
                }
            }

            $paths = [];

            foreach (
                $release['files']
                as $path => $source
            ) {
                if (
                    $itemDriver
                        ->preservePath($path)
                    && $files->stat($path)
                        !== null
                ) {
                    continue;
                }

                $files->move(
                    $source,
                    $this->actual(
                        $modKey,
                        $state,
                        $path,
                        $itemDriver
                    )
                );

                if (
                    !$itemDriver
                        ->preservePath($path)
                ) {
                    $paths[] = $path;
                }
            }

            $mods[$modKey] =
                array_merge(
                    $old ?? [],
                    [
                        'provider' =>
                            $itemDriver
                                ->provider()
                                ->key(),

                        'provider_id' =>
                            $release['mod']['id'],

                        'file_id' =>
                            $release['file']['id'],

                        'name' =>
                            $release['mod']['name'],

                        'version' =>
                            $release['file']['version'],

                        'enabled' =>
                            $state['enabled'],

                        'dependency' =>
                            $old['dependency']
                            ?? ($modKey !== $key),

                        'required_by' =>
                            $old['required_by']
                            ?? [],

                        'dependency_specs' => $prospective[$modKey]['dependency_specs'],
                        'paths' =>
                            $paths,

                        'installed_at' =>
                            $old['installed_at']
                            ?? now()
                                ->toIso8601String(),

                        'updated_at' =>
                            now()
                                ->toIso8601String(),
                    ]
                );
        }

        /*
         * Remove stale parent relationships from an earlier deployment.
         */
        foreach ($mods as &$entry) {
            $beforeParents =
                $entry['required_by'];

            $entry['required_by'] =
                array_values(
                    array_diff(
                        $beforeParents,
                        [$key]
                    )
                );

            if (
                $entry['required_by']
                !== $beforeParents
            ) {
                $entry['updated_at'] =
                    now()->toIso8601String();
            }
        }

        unset($entry);

        /*
         * Add the root package as parent of every dependency regardless of
         * provider.
         */
        foreach (
            $dependencySpecs
            as $spec
        ) {
            $dependencyKey =
                $spec['provider'] .
                ':' .
                $spec['id'];

            if (
                !isset(
                    $mods[$dependencyKey]
                )
            ) {
                throw new RuntimeException(
                    'Dependency was not installed correctly: ' .
                    $dependencyKey
                );
            }

            if (
                !in_array(
                    $key,
                    $mods[$dependencyKey]['required_by'],
                    true
                )
            ) {
                $mods[$dependencyKey]['required_by'][] =
                    $key;
            }

            $mods[$dependencyKey]['updated_at'] =
                now()->toIso8601String();
        }
        // Retain direct reverse relationships for nested packages as well as legacy root links.
        foreach ($prepared as $parentKey => $_) {
            if (empty($mods[$parentKey]['dependency_specs'])) { continue; }
            foreach ($mods as &$child) {
                $child['required_by'] = array_values(array_diff($child['required_by'], [$parentKey]));
            }
            unset($child);
            foreach ($mods[$parentKey]['dependency_specs'] ?? [] as $edge) {
                if ($edge['type'] === 'required' && isset($mods[$edge['key']])) {
                    $mods[$edge['key']]['required_by'][] = $parentKey;
                    $mods[$edge['key']]['required_by'] = array_values(array_unique($mods[$edge['key']]['required_by']));
                }
            }
        }
    }

    protected function resolveRecipeDependency(
        Server $server,
        array $recipe
    ): array {
        $provider =
            trim(
                (string) (
                    $recipe['provider']
                    ?? ''
                )
            );

        if ($provider === '') {
            throw new RuntimeException(
                'Package recipe has no dependency provider.'
            );
        }

        $dependencyDriver =
            $this->driver(
                $server,
                $provider
            );

        if (!empty($recipe['id'])) {
            return [
                'provider' => $provider,
                'id' =>
                    (string) $recipe['id'],
            ];
        }

        $search =
            trim(
                (string) (
                    $recipe['search']
                    ?? ''
                )
            );

        $expectedName =
            trim(
                (string) (
                    $recipe['name']
                    ?? ''
                )
            );

        if (
            $search === ''
            || $expectedName === ''
        ) {
            throw new RuntimeException(
                'Package recipe dependency cannot be resolved.'
            );
        }

        $providerService =
            $dependencyDriver->provider();

        if (
            !$providerService
                ->supportsSearch()
        ) {
            throw new RuntimeException(
                $providerService->name() .
                ' cannot search for the required dependency.'
            );
        }

        $results =
            $providerService->search(
                $search,
                [],
                $this->sourceContext(
                    $server,
                    $provider
                )
            );

        foreach ($results as $result) {
            $resultName = strtolower(
                preg_replace(
                    '/[^a-z0-9]/i',
                    '',
                    trim(
                        (string) (
                            $result['name']
                            ?? ''
                        )
                    )
                )
            );

            $wantedName = strtolower(
                preg_replace(
                    '/[^a-z0-9]/i',
                    '',
                    $expectedName
                )
            );

            if (
                $resultName === $wantedName
                && !empty($result['id'])
            ) {
                return [
                    'provider' =>
                        $provider,

                    'id' =>
                        (string) $result['id'],
                ];
            }
        }

        throw new RuntimeException(
            'Required dependency "' .
            $expectedName .
            '" could not be found on ' .
            $providerService->name() .
            '.'
        );
    }

}
