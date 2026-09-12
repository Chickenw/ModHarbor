<?php

namespace GameNest\GameNestModManager\Services;

use App\Models\Server;
use GameNest\GameNestModManager\Adapters\ConfiguredGameAdapter;
use Illuminate\Support\Facades\Gate;
use Throwable;

/** Public report projection. Never serialize models, settings, journals or exception text. */
class SupportBundle
{
    private const ACTIONS = ['install', 'update', 'reinstall', 'remove', 'enable', 'disable', 'repair', 'adopt', 'config-save', 'config-restore', 'recover', 'restart-confirmed'];
    private const STATUSES = ['running', 'recovery_required', 'failed', 'completed', 'recovered'];
    private const PHASES = ['Resolve', 'Stage', 'Validate', 'Backup and Install', 'Verify', 'Journal'];

    public function collect(Server $server): array
    {
        Gate::authorize('file.read', $server);
        Gate::authorize('file.read-content', $server);
        $bundle = [
            'schema_version' => 1,
            'generated_at' => gmdate('c'),
            'modharbor' => $this->buildInfo(),
            'environment' => [
                'php' => PHP_VERSION,
                'pelican' => self::version(config('app.version')),
                'wings' => null,
                'game_version' => null,
            ],
            'privacy' => 'Free text, paths, identities, URLs, provider metadata, credentials and raw errors are omitted. Null means unavailable. Definition is diagnostic only, not importable.',
        ];
        try {
            $details = app(\App\Repositories\Daemon\DaemonServerRepository::class)->setServer($server)->getDetails();
            $bundle['server_state'] = self::choice($details['state'] ?? null, ['offline', 'running', 'starting', 'stopping']);
        } catch (Throwable) { $bundle['server_state'] = 'unavailable'; }
        try {
            $adapter = app(AdapterRegistry::class)->forServer($server);
            $bundle['game_definition'] = $adapter instanceof ConfiguredGameAdapter
                ? self::definition($adapter->definition()) : null;
            $bundle['game_resolution'] = $adapter ? 'resolved' : 'unavailable';
        } catch (Throwable) {
            $bundle['game_definition'] = null;
            $bundle['game_resolution'] = 'unavailable';
        }
        $bundle['providers'] = [];
        foreach ((array) config('gamenest-mod-manager.providers', []) as $key => $provider) {
            // Names originate from trusted registrations, never provider responses/settings.
            $configured = null;
            try {
                $configured = app(ProviderSettingsStore::class)->configurationStatus($key, $provider['credential_fields'] ?? [], $provider['credential_require_any'] ?? []);
            } catch (Throwable) { /* A decryption failure must not escape into the report. */ }
            $bundle['providers'][] = ['name' => $key, 'configured' => $configured];
        }
        try {
            $bundle['manifest'] = self::manifest(app(ManifestService::class)->read($server));
        } catch (Throwable) { $bundle['manifest'] = ['status' => 'unavailable']; }
        try {
            $store = app(OperationStore::class);
            $bundle['history'] = self::history($store->history($server));
            // Unresolved operations must remain visible even when older than the recent 100.
            $unresolved = $store->unresolved($server);
            $bundle['recovery'] = ['status' => $unresolved ? 'review_required' : 'clear',
                'count' => count($unresolved), 'operations' => self::history($unresolved)];
        } catch (Throwable) {
            $bundle['history'] = null;
            $bundle['recovery'] = ['status' => 'unavailable'];
        }
        try {
            $bundle['restart_pending_count'] = count(app(RestartTracker::class)->pending($server));
        } catch (Throwable) { $bundle['restart_pending_count'] = null; }
        return $bundle;
    }

    public function json(Server $server): string
    {
        return json_encode($this->collect($server), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    public function buildInfo(): array
    {
        $root = dirname(__DIR__, 2);
        $plugin = json_decode(file_get_contents($root . '/plugin.json'), true, 16, JSON_THROW_ON_ERROR);
        $build = is_file($root . '/build-info.json')
            ? json_decode(file_get_contents($root . '/build-info.json'), true) : [];
        $commit = $build['commit'] ?? null;
        return ['version' => self::version($plugin['version'] ?? null),
            'commit' => is_string($commit) && preg_match('/^[a-f0-9]{40}$/D', $commit) ? $commit : null];
    }

    /** Keep the definition's structure while withholding every administrator-supplied string. */
    public static function definition(array $d): array
    {
        $paths = array_values($d['mod_directories'] ?? []);
        $target = array_search($d['deployment']['target'] ?? null, $paths, true);
        return [
            'schema_version' => 1,
            'enabled' => ($d['enabled'] ?? null) === true,
            'steam_app_id' => is_int($d['steam_app_id'] ?? null) ? $d['steam_app_id'] : null,
            'capability' => self::choice($d['capability'] ?? null, array_keys((array) config('gamenest-mod-manager.game_capabilities', []))),
            'detection' => ['egg_id_count' => count($d['detection']['egg_ids'] ?? []),
                'exact_name_count' => count($d['detection']['egg_names'] ?? []),
                'name_contains_count' => count($d['detection']['egg_name_contains'] ?? [])],
            'mod_directory_count' => count($paths),
            'config_directory_count' => count($d['config_directories'] ?? []),
            'config_rule_count' => count($d['config_rules'] ?? []),
            'package_types' => array_values(array_intersect(['zip', 'dll', 'cs', 'jar', 'pak', 'xml', 'json', 'cfg', 'txt'], $d['package_types'] ?? [])),
            'deployment' => ['strategy' => self::choice($d['deployment']['strategy'] ?? null, ['archive', 'copy', 'provider-managed']),
                'target_directory_index' => $target === false ? null : $target,
                'archive_prefix_configured' => ($d['deployment']['archive_prefix'] ?? '') !== ''],
            'behavior' => ['install_while_running' => ($d['behavior']['install_while_running'] ?? null) === true,
                'restart_required' => ($d['behavior']['restart_required'] ?? null) === true],
            'sources' => array_values(array_intersect(array_keys((array) config('gamenest-mod-manager.providers', [])), array_keys($d['sources'] ?? []))),
            'artwork_override_configured' => ($d['artwork_url'] ?? '') !== '',
        ];
    }

    public static function manifest(array $manifest): array
    {
        $summary = ['status' => 'available', 'managed_count' => 0, 'enabled_count' => 0, 'dependency_count' => 0, 'file_count' => 0];
        foreach ($manifest['mods'] ?? [] as $mod) {
            $summary['managed_count']++;
            $summary['enabled_count'] += ($mod['enabled'] ?? null) === true ? 1 : 0;
            $summary['dependency_count'] += ($mod['dependency'] ?? null) === true ? 1 : 0;
            $summary['file_count'] += count($mod['paths'] ?? []);
        }
        return $summary;
    }

    /** Input is OperationStore's display projection; free-text fields are deliberately not copied. */
    public static function history(array $rows): array
    {
        return array_values(array_map(static function (array $row): array {
            $status = self::choice($row['status'] ?? null, self::STATUSES);
            return [
                'action' => self::choice($row['action'] ?? null, self::ACTIONS),
                'status' => $status,
                'error_type' => isset($row['error_type'])
                    ? self::choice($row['error_type'], ['RuntimeException', 'JsonException', 'InvalidArgumentException', 'LogicException', 'TypeError', 'ErrorException']) : null,
                'result' => match ($status) {
                    'failed' => 'Operation failed; rollback recorded.',
                    'recovery_required' => 'Recovery incomplete; administrator review required.',
                    'running' => 'Operation running or interrupted; check recovery state.',
                    'completed' => 'Operation completed.',
                    'recovered' => 'Recovery completed.',
                    default => 'Unknown operation result.',
                },
                'duration_ms' => self::duration($row['duration_ms'] ?? null),
                'phase' => self::choice($row['phase'] ?? null, self::PHASES),
                'phases' => array_values(array_map(static fn ($phase) => [
                    'name' => self::choice(is_array($phase) ? ($phase['name'] ?? null) : null, self::PHASES),
                    'duration_ms' => self::duration(is_array($phase) ? ($phase['duration_ms'] ?? null) : null),
                ], array_slice(is_array($row['phases'] ?? null) ? $row['phases'] : [], 0, 32))),
                'restart_required' => ($row['restart_required'] ?? null) === true,
                'move_count' => is_int($row['move_count'] ?? null) ? max(0, $row['move_count']) : null,
            ];
        }, array_slice($rows, 0, 100)));
    }

    private static function choice(mixed $value, array $allowed): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : 'unknown';
    }

    private static function duration(mixed $value): ?float
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0
            ? round((float) $value, 2) : null;
    }

    private static function version(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^v?[0-9]+\.[0-9]+\.[0-9]+(?:[-+][a-zA-Z0-9.]+)?$/D', $value) ? $value : null;
    }
}
