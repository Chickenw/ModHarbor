<?php

namespace GameNest\GameNestModManager\Services;

use App\Models\Server;
use GameNest\GameNestModManager\Contracts\LifecycleDriver;
use GameNest\GameNestModManager\Contracts\ModProvider;
use RuntimeException;

/** Provider-neutral adopted source: reinstalls exactly the retained bytes. */
class ExistingModDriver implements LifecycleDriver, ModProvider
{
    public function __construct(private Server $server) {}
    public function provider(): ModProvider { return $this; }
    public function key(): string { return 'existing'; }
    public function name(): string { return 'Existing files'; }
    public function supportsSearch(): bool { return false; }
    public function search(string $query, array $options = [], ?SourceContext $source = null): array { return []; }
    public function get(string|int $id, ?SourceContext $source = null): ?array
    {
        $entry = app(ManifestService::class)->mods($this->server)['existing:' . $id] ?? null;
        if (!$entry) { return null; }
        return ['id' => $id, 'name' => $entry['name'], 'latest_file' => ['id' => $id, 'version' => $entry['version']]];
    }
    public function dependencies(string|int $id, ?SourceContext $source = null): array { return []; }
    public function validatePath(string $path): void
    {
        FileTransaction::path($path);
        if (str_starts_with($path, '.') || str_starts_with($path, '.gamenest/')) {
            throw new RuntimeException('Internal files cannot be adopted.');
        }
        $adapter = app(AdapterRegistry::class)->forServer($this->server);
        foreach ($adapter?->modDirectories() ?? [] as $root) {
            $root = FileTransaction::path(trim($root, '/'));
            if (str_starts_with($path, $root . '/')) { return; }
        }
        throw new RuntimeException('Adopted path is outside the game adapter mod roots.');
    }
    public function preservePath(string $path): bool { return false; }
    public function prepare(string|int $id, string|int|null $fileId, FileTransaction $files, ?SourceContext $source = null): array
    {
        if (!preg_match('/^[a-f0-9]{40}$/D', (string) $id) || ($fileId !== null && (string) $fileId !== (string) $id)) {
            throw new RuntimeException('Invalid adopted snapshot.');
        }
        $entry = app(ManifestService::class)->mods($this->server)['existing:' . $id] ?? null;
        if (!$entry) { throw new RuntimeException('Adopted source is unavailable.'); }
        $prepared = [];
        foreach ($entry['paths'] as $path) {
            $this->validatePath($path);
            $snapshot = '.gamenest/mod-manager/adopted/' . $id . '/' . $path;
            if (empty($files->stat($snapshot)['file'])) { throw new RuntimeException('Retained snapshot is missing.'); }
            $contents = $files->repo->getContent($snapshot, 8 * 1024 * 1024);
            if (!hash_equals($entry['snapshot_hashes'][$path] ?? '', hash('sha256', $contents))) {
                throw new RuntimeException('Retained snapshot checksum mismatch.');
            }
            $target = $files->root . '/prepared/' . $id . '/' . $path;
            $files->mkdir(dirname($target)); $files->repo->putContent($target, $contents);
            $prepared[$path] = $target;
        }
        return ['mod' => ['id' => $id, 'name' => $entry['name']],
            'file' => ['id' => $id, 'version' => $entry['version']], 'files' => $prepared];
    }
}
