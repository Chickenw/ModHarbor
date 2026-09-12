<?php

namespace GameNest\GameNestModManager\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Throwable;

class ModHealthService
{
    public function __construct(
        protected ManifestService $manifest,
        protected OperationStore $operations,
        protected UploadPackageStore $uploads,
    ) {
    }

    public function scan(Server $server, ?array $mods = null): array
    {
        Gate::authorize('file.read', $server);
        Gate::authorize('file.read-content', $server);

        $result = [
            'status' => 'healthy',
            'manifest' => 'healthy',
            'manifest_message' => 'Manifest is healthy.',
            'managed' => 0,
            'healthy' => 0,
            'issues' => 0,
            'missing_files' => 0,
            'unmanaged' => 0,
            'stale_runtime' => 0,
            'interrupted' => 0,
            'runtime' => null,
            'mods' => [],
            'unmanaged_files' => [],
            'stale_runtime_files' => [],
            'operation_issues' => [],
            'checked_at' => now()->toIso8601String(),
        ];

        if ($mods === null) {
            try {
                $manifest = $this->manifest->read($server);
                $mods = (array) ($manifest['mods'] ?? []);
            } catch (Throwable $exception) {
                $result['status'] = 'broken';
                $result['manifest'] = 'broken';
                $result['manifest_message'] =
                    get_class($exception) === RuntimeException::class
                        ? $exception->getMessage()
                        : 'The ModHarbor manifest could not be read safely.';

                return $result;
            }
        }

        $result['managed'] = count($mods);

        $repo = app(DaemonFileRepository::class)
            ->setServer($server);

        $files = new FileTransaction(
            $repo,
            '.gamenest/mod-manager/health',
            static function (array $moves): void {
            }
        );

        /*
         * Resolve every normal managed path first, then stat them in one
         * short-lived filesystem snapshot. This avoids repeatedly walking
         * the same parent directories for each managed file while keeping
         * verification fresh for every health scan.
         */
        $managedPathPlans = [];
        $managedStatPaths = [];
        $managedDrivers = [];
        $managedDriverErrors = [];

        foreach ($mods as $key => $entry) {
            $provider = (string) ($entry['provider'] ?? '');
            $enabled = !empty($entry['enabled']);
            $driver = null;

            try {
                $driver = app(ModLifecycleService::class)
                    ->driver($server, $provider);
            } catch (Throwable) {
                $managedDriverErrors[$key] = true;
            }

            $managedDrivers[$key] = $driver;

            foreach ((array) ($entry['paths'] ?? []) as $path) {
                $path = (string) $path;

                try {
                    FileTransaction::path($path);

                    $actual = $path;

                    if (
                        !$enabled
                        && $driver !== null
                        && !$driver->preservePath($path)
                    ) {
                        $actual =
                            '.gamenest/mod-manager/disabled/'
                            . hash('sha256', $key)
                            . '/'
                            . $path;
                    }

                    $managedPathPlans[$key][] = [
                        'path' => $path,
                        'actual' => $actual,
                        'valid' => true,
                    ];

                    $managedStatPaths[$actual] = true;
                } catch (Throwable) {
                    $managedPathPlans[$key][] = [
                        'path' => $path,
                        'actual' => $path,
                        'valid' => false,
                    ];
                }
            }
        }

        $managedStats = $managedStatPaths === []
            ? []
            : $files->statMany(array_keys($managedStatPaths));

        foreach ($mods as $key => $entry) {
            $issues = [];
            $missing = [];
            $present = [];
            $provider = (string) ($entry['provider'] ?? '');
            $enabled = !empty($entry['enabled']);
            $driver = $managedDrivers[$key] ?? null;

            if (!empty($managedDriverErrors[$key])) {
                $issues[] = 'Lifecycle support is unavailable for this provider.';
            }

            foreach (($managedPathPlans[$key] ?? []) as $plan) {
                $path = $plan['path'];
                $actual = $plan['actual'];

                if (empty($plan['valid'])) {
                    $missing[] = $path;
                    $issues[] =
                        'Could not verify managed path '
                        . $path
                        . '.';
                    continue;
                }

                $stat = $managedStats[$actual] ?? null;

                if ($stat === null || empty($stat['file'])) {
                    $missing[] = $actual;
                } else {
                    $present[] = $actual;
                }
            }

            if ($missing !== []) {
                $issues[] =
                    count($missing) === 1
                        ? '1 managed file is missing.'
                        : count($missing) . ' managed files are missing.';

                $result['missing_files'] += count($missing);
            }

            $requiredBy = array_values(
                array_filter(
                    (array) ($entry['required_by'] ?? []),
                    static fn ($parent): bool =>
                        is_string($parent) && $parent !== ''
                )
            );

            foreach ($requiredBy as $parentKey) {
                if (!isset($mods[$parentKey])) {
                    $issues[] =
                        'Dependency metadata references a missing parent package.';
                }
            }

            if (!empty($entry['dependency'])) {
                foreach ($requiredBy as $parentKey) {
                    $parent = $mods[$parentKey] ?? null;

                    if (
                        is_array($parent)
                        && !empty($parent['enabled'])
                        && empty($entry['enabled'])
                    ) {
                        $issues[] =
                            'This dependency is disabled while a required parent is enabled.';
                        break;
                    }
                }
            }

            $sourceAvailable = true;
            $sourceMessage = null;

            if ($provider === 'existing') {
                try {
                    $sourceId = (string) ($entry['provider_id'] ?? '');
                    if (!preg_match('/^[a-f0-9]{40}$/D', $sourceId)) { throw new RuntimeException('Invalid snapshot reference.'); }
                    foreach ($entry['paths'] as $path) {
                        $snapshot = '.gamenest/mod-manager/adopted/' . $sourceId . '/' . $path;
                        if (empty($files->stat($snapshot)['file']) || !hash_equals($entry['snapshot_hashes'][$path] ?? '',
                            hash('sha256', $repo->getContent($snapshot, 8 * 1024 * 1024)))) {
                            throw new RuntimeException('Snapshot is unavailable.');
                        }
                    }
                    $sourceMessage = 'Verified retained adoption snapshot is available.';
                } catch (Throwable) {
                    $sourceAvailable = false;
                    $sourceMessage = 'The retained adoption snapshot is missing or changed; repair is unavailable.';
                    $issues[] = $sourceMessage;
                }
            }

            if ($provider === 'upload') {
                $sourceId = trim(
                    (string) ($entry['provider_id'] ?? '')
                );

                try {
                    if ($sourceId === '') {
                        throw new RuntimeException(
                            'Missing retained upload source identifier.'
                        );
                    }

                    $package = $this->uploads->get($sourceId);

                    if (!is_array($package)) {
                        throw new RuntimeException(
                            'Retained upload package is unavailable.'
                        );
                    }

                    $this->uploads->contents($sourceId);

                    $sourceMessage =
                        'Private reinstall source is available.';
                } catch (Throwable) {
                    $sourceAvailable = false;
                    $sourceMessage =
                        'The retained private source is missing, so automatic repair/reinstall is unavailable.';
                    $issues[] = $sourceMessage;
                }
            }

            $status = $issues === []
                ? 'healthy'
                : 'warning';

            $repairable =
                $driver !== null
                && $sourceAvailable
                && $missing !== [];

            if ($status === 'healthy') {
                $result['healthy']++;
            } else {
                $result['issues']++;
            }

            $result['mods'][$key] = [
                'key' => $key,
                'name' => (string) ($entry['name'] ?? $key),
                'provider' => $provider,
                'version' => (string) ($entry['version'] ?? ''),
                'enabled' => $enabled,
                'dependency' => !empty($entry['dependency']),
                'status' => $status,
                'issues' => array_values(array_unique($issues)),
                'missing' => array_values(array_unique($missing)),
                'present' => array_values(array_unique($present)),
                'managed_count' => count((array) ($entry['paths'] ?? [])),
                'source_available' => $sourceAvailable,
                'source_message' => $sourceMessage,
                'repairable' => $repairable,
            ];
        }

        try {
            foreach ($this->operations->all($server) as $operation) {
                $status = (string) ($operation['status'] ?? '');

                if (!in_array(
                    $status,
                    ['running', 'recovery_required'],
                    true
                )) {
                    continue;
                }

                $result['interrupted']++;
                $result['operation_issues'][] = [
                    'id' => (string) ($operation['id'] ?? ''),
                    'name' => (string) ($operation['name'] ?? 'Unknown mod'),
                    'status' => $status,
                    'message' => (string) (
                        $operation['message']
                        ?? 'Administrator recovery is required.'
                    ),
                ];
            }
        } catch (Throwable $exception) {
            $result['operation_issues'][] = [
                'id' => '',
                'name' => 'Operation journal',
                'status' => 'warning',
                'message' =>
                    'Operation history could not be verified safely.',
            ];
        }

        $adapter = app(AdapterRegistry::class)
            ->forServer($server);

        if ($adapter?->key() === 'rust') {
            $this->scanRust(
                $files,
                $mods,
                $result
            );
        }

        if ($result['interrupted'] > 0) {
            $result['status'] = 'broken';
        } elseif (
            $result['issues'] > 0
            || $result['unmanaged'] > 0
            || $result['stale_runtime'] > 0
        ) {
            $result['status'] = 'warning';
        }

        try {
            $directorySnapshot = [];

            $result['existing_files'] = app(ManagedFileScanner::class)->scan(
                $server,
                false,
                $mods,
                $directorySnapshot
            );

            $result['unmanaged'] = count(array_filter(
                $result['existing_files'],
                fn ($row) => $row['status'] === 'unmanaged'
            ));

            $result['config_health'] = app(ConfigManagementService::class)->health(
                $server,
                $mods,
                $directorySnapshot
            );
            $result['config_issues'] = count(array_filter($result['config_health'], fn ($row) => $row['validation'] !== 'valid'));
            if (($result['unmanaged'] || $result['config_issues']) && $result['status'] === 'healthy') {
                $result['status'] = 'warning';
            }
        } catch (Throwable) {
            $result['discovery_error'] = 'Existing files or configurations could not be inspected safely.';
            if ($result['status'] === 'healthy') { $result['status'] = 'warning'; }
        }

        return $result;
    }

    protected function scanRust(
        FileTransaction $files,
        array $mods,
        array &$result
    ): void {
        try {
            $runtime = app(RustRuntimeDetector::class)
                ->detect($files);

            $result['runtime'] = match ($runtime) {
                RustRuntimeDetector::CARBON => 'Carbon',
                RustRuntimeDetector::OXIDE => 'Oxide',
                default => 'Vanilla',
            };

            if ($runtime === RustRuntimeDetector::VANILLA) {
                return;
            }

            $activeRoot =
                $runtime === RustRuntimeDetector::CARBON
                    ? 'carbon/plugins'
                    : 'oxide/plugins';

            $staleRoot =
                $runtime === RustRuntimeDetector::CARBON
                    ? 'oxide/plugins'
                    : 'carbon/plugins';

            $managedActual = [];

            foreach ($mods as $key => $entry) {
                foreach ((array) ($entry['paths'] ?? []) as $path) {
                    $path = (string) $path;

                    if (
                        strtolower(
                            pathinfo(
                                $path,
                                PATHINFO_EXTENSION
                            )
                        ) !== 'cs'
                    ) {
                        continue;
                    }

                    if (!empty($entry['enabled'])) {
                        $managedActual[strtolower($path)] = true;
                    } else {
                        $managedActual[
                            strtolower(
                                '.gamenest/mod-manager/disabled/'
                                . hash('sha256', $key)
                                . '/'
                                . $path
                            )
                        ] = true;
                    }
                }
            }

            try {
                foreach ($files->listing($activeRoot) as $entry) {
                    if (
                        !is_array($entry)
                        || empty($entry['file'])
                        || !empty($entry['symlink'])
                    ) {
                        continue;
                    }

                    $name = trim(
                        (string) ($entry['name'] ?? '')
                    );

                    if (
                        $name === ''
                        || strtolower(
                            pathinfo(
                                $name,
                                PATHINFO_EXTENSION
                            )
                        ) !== 'cs'
                    ) {
                        continue;
                    }

                    $path = $activeRoot . '/' . $name;

                    if (!isset(
                        $managedActual[strtolower($path)]
                    )) {
                        $result['unmanaged_files'][] = $path;
                    }
                }
            } catch (Throwable) {
            }

            try {
                if ($files->stat($staleRoot) !== null) {
                    foreach ($files->listing($staleRoot) as $entry) {
                        if (
                            !is_array($entry)
                            || empty($entry['file'])
                            || !empty($entry['symlink'])
                        ) {
                            continue;
                        }

                        $name = trim(
                            (string) ($entry['name'] ?? '')
                        );

                        if (
                            $name === ''
                            || strtolower(
                                pathinfo(
                                    $name,
                                    PATHINFO_EXTENSION
                                )
                            ) !== 'cs'
                        ) {
                            continue;
                        }

                        $result['stale_runtime_files'][] =
                            $staleRoot . '/' . $name;
                    }
                }
            } catch (Throwable) {
            }

            $result['unmanaged_files'] =
                array_values(
                    array_unique(
                        $result['unmanaged_files']
                    )
                );

            $result['stale_runtime_files'] =
                array_values(
                    array_unique(
                        $result['stale_runtime_files']
                    )
                );

            $result['unmanaged'] =
                count($result['unmanaged_files']);

            $result['stale_runtime'] =
                count($result['stale_runtime_files']);
        } catch (Throwable) {
            $result['runtime'] = 'Unknown';
        }
    }
}
