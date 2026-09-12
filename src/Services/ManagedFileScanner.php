<?php

namespace GameNest\GameNestModManager\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use GameNest\GameNestModManager\Contracts\ManagedFilesAdapter;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/** Bounded read-only discovery. Missing roots are harmless; I/O errors fail closed. */
class ManagedFileScanner
{
    public function rules(Server $server, bool $configs = false): array
    {
        $adapter = app(AdapterRegistry::class)->forServer($server);
        if (!$adapter) { throw new RuntimeException('No supported game adapter.'); }
        if ($adapter instanceof ManagedFilesAdapter) {
            return $configs ? $adapter->configRules($server) : $adapter->scanRules($server);
        }
        // Legacy adapters must opt into adoption; config roots are already contractual.
        return $configs ? array_map(fn ($root) => ['root' => ltrim($root, '/'),
            'patterns' => ['*.json', '*.yaml', '*.yml', '*.toml', '*.ini', '*.cfg', '*.txt'], 'depth' => 4],
            $adapter->configDirectories()) : [];
    }

    public static function matches(string $name, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            $regex = '/^' . str_replace(['\\*', '\\?'], ['.*', '.'], preg_quote($pattern, '/')) . '$/iD';
            if (preg_match($regex, $name)) { return true; }
        }
        return false;
    }

    public function scan(
        Server $server,
        bool $configs = false,
        ?array $mods = null,
        ?array &$directorySnapshot = null
    ): array {
        Gate::authorize('file.read', $server);
        Gate::authorize('file.read-content', $server);
        $mods ??= app(ManifestService::class)->mods($server);
        $directorySnapshot ??= [];

        $files = new FileTransaction(
            app(DaemonFileRepository::class)->setServer($server),
            '.gamenest/mod-manager/scan',
            static function (array $moves): void {}
        );

        $rows = [];
        $budget = 5000;
        $ownershipIndex = $this->ownershipIndex($mods);

        foreach ($this->rules($server, $configs) as $rule) {
            $root = FileTransaction::path(ltrim($rule['root'], '/'));
            $stat = $files->stat($root);

            if ($stat === null) {
                continue;
            }

            if (!empty($stat['file'])) {
                throw new RuntimeException('Scan root must be a directory.');
            }

            $this->walk(
                $files,
                $root,
                $rule,
                0,
                $budget,
                $rows,
                $ownershipIndex,
                $directorySnapshot
            );
        }

        ksort($rows);

        return array_values($rows);
    }

    /**
     * Reuse directory listings only inside the caller-provided read-only
     * scan snapshot. No state survives the current scan operation.
     */
    private function listing(
        FileTransaction $files,
        string $path,
        array &$directorySnapshot
    ): array {
        if (!array_key_exists($path, $directorySnapshot)) {
            $directorySnapshot[$path] = $files->listing($path);
        }

        return $directorySnapshot[$path];
    }

    /**
     * Build the managed-path ownership map once per scan instead of comparing
     * every discovered file against every installed mod and managed path.
     *
     * Paths are normalized case-insensitively to preserve the scanner's
     * previous strcasecmp() ownership semantics.
     */
    private function ownershipIndex(array $mods): array
    {
        $index = [];

        foreach ($mods as $key => $mod) {
            foreach (($mod['paths'] ?? []) as $owned) {
                if (!is_string($owned)) {
                    continue;
                }

                $path = FileTransaction::path($owned);
                $lookup = strtolower($path);
                $index[$lookup][$key] = true;
            }
        }

        foreach ($index as $path => $owners) {
            $index[$path] = array_keys($owners);
        }

        return $index;
    }

    private function walk(
        FileTransaction $files,
        string $root,
        array $rule,
        int $depth,
        int &$budget,
        array &$rows,
        array $ownershipIndex,
        array &$directorySnapshot
    ): void {
        foreach ($this->listing($files, $root, $directorySnapshot) as $entry) {
            if (--$budget < 0) { throw new RuntimeException('Scan exceeded 5,000 entries; narrow adapter scan roots.'); }
            $name = (string) ($entry['name'] ?? '');
            FileTransaction::path($name);
            if (str_contains($name, '/')) { throw new RuntimeException('Invalid directory entry.'); }
            $path = FileTransaction::path($root . '/' . $name);
            if (self::matches($name, $rule['exclude'] ?? [])) { continue; }
            if (!empty($entry['symlink'])) { continue; }
            if (empty($entry['file'])) {
                if ($depth < min(8, (int) ($rule['depth'] ?? 0))) {
                    $this->walk(
                        $files,
                        $path,
                        $rule,
                        $depth + 1,
                        $budget,
                        $rows,
                        $ownershipIndex,
                        $directorySnapshot
                    );
                }
                continue;
            }
            if (!self::matches($name, $rule['patterns'] ?? [])) { continue; }
            $owners = $ownershipIndex[strtolower($path)] ?? [];
            $rows[$path] = ['id' => hash('sha256', $path), 'path' => $path, 'name' => $name,
                'size' => (int) ($entry['size'] ?? 0), 'owners' => $owners,
                'status' => $owners ? 'managed' : 'unmanaged', 'format' => strtolower(pathinfo($path, PATHINFO_EXTENSION))];
        }
    }
}
