<?php

namespace GameNest\GameNestModManager\Services;

use App\Models\Server;
use GameNest\GameNestModManager\Adapters\ConfiguredGameAdapter;
use GameNest\GameNestModManager\Contracts\{LifecycleDriver, ModProvider};
use GameNest\GameNestModManager\Contracts\VersionedDownloadProvider;
use GameNest\GameNestModManager\Providers\GitHubProvider;
use RuntimeException;

/** Produce a normal lifecycle plan; shared engine owns mutations, dependencies and recovery. */
class ConfiguredLifecycleDriver implements LifecycleDriver
{
    public function __construct(private Server $server, private ConfiguredGameAdapter $adapter, private ModProvider $sourceProvider) {}
    public function provider(): ModProvider { return $this->sourceProvider; }
    public function dependencies(string|int $id, ?SourceContext $source = null): array
    {
        return $this->provider()->get($id, $source)['dependencies'] ?? [];
    }
    public function preservePath(string $path): bool
    {
        foreach ($this->adapter->configRules($this->server) as $rule) {
            $root = rtrim($rule['root'], '/');
            if (!str_starts_with($path, $root . '/')) { continue; }
            $relative = substr($path, strlen($root) + 1);
            if (substr_count($relative, '/') > ($rule['depth'] ?? 4)) { continue; }
            $name = basename($path);
            foreach ($rule['exclude'] ?? [] as $pattern) { if (ManagedFileScanner::matches($name, [$pattern])) { continue 2; } }
            foreach ($rule['patterns'] ?? [] as $pattern) { if (ManagedFileScanner::matches($name, [$pattern])) { return true; } }
        }
        return false;
    }
    public function validatePath(string $path): void
    {
        GameDefinition::path($path);
        foreach ($this->adapter->modDirectories() as $root) { if (str_starts_with($path, $root . '/')) { return; } }
        throw new RuntimeException('File lies outside configured mod directories.');
    }
    public function prepare(string|int $id, string|int|null $fileId, FileTransaction $files, ?SourceContext $source = null): array
    {
        if (!$source || $source->gameKey() !== $this->adapter->key() || $source->key() !== $this->provider()->key()) { throw new RuntimeException('Configured source context mismatch.'); }
        $mod = $this->provider()->get($id, $source);
        if (!$mod) { throw new RuntimeException('Package is unavailable.'); }
        $file = $mod['latest_file'] ?? [];
        if ($this->provider() instanceof VersionedDownloadProvider) {
            $file = $this->provider()->package($id, $fileId === '' ? null : $fileId, $source);
        }
        if ($this->provider() instanceof GitHubProvider && $fileId !== null) { $file = $this->provider()->file((int) $mod['id'], (int) $fileId); }
        if ($fileId !== null && (string) ($file['id'] ?? '') !== (string) $fileId) { throw new RuntimeException('Requested package version is unavailable.'); }
        if (empty($file['id'])) { throw new RuntimeException('No downloadable package file.'); }
        $filename = (string) ($file['filename'] ?? $file['name'] ?? '');
        if ($source->key() === 'upload') {
            $store = app(UploadPackageStore::class);
            $package = $store->get((string) $id);
            if (!$package || ($package['server_uuid'] ?? '') !== $this->server->uuid) { throw new RuntimeException('Uploaded package belongs to another server.'); }
            $contents = $store->contents((string) $id);
        } else {
            if ((int) ($file['filesize'] ?? 0) > ConfiguredPackagePlan::MAX_BYTES) { throw new RuntimeException('Package exceeds the configured deployment size limit.'); }
            try {
                $contents = app(SafeRemoteDownloader::class)->download((string) ($file['download_url'] ?? ''), ConfiguredPackagePlan::MAX_BYTES);
            } catch (\Throwable) {
                // Signed download URLs must not enter exception chains or persisted operation errors.
                throw new RuntimeException('Package download failed. Check provider access and retry.');
            }
        }
        foreach ((array) ($file['hashes'] ?? []) as $algorithm => $expected) {
            if (!in_array($algorithm, ['sha1', 'sha256', 'sha512'], true)
                || !is_string($expected) || !hash_equals(strtolower($expected), hash($algorithm, $contents))) {
                throw new RuntimeException('Package checksum verification failed.');
            }
        }
        $definition = $this->adapter->definition();
        $plan = (new ConfiguredPackagePlan)->files($contents, $filename,
            GameDefinition::validate($definition, array_keys($definition['sources'])));
        DiskSpaceGuard::server($this->server, array_sum(array_map('strlen', $plan)));
        $staged = [];
        $stage = $files->root . '/staging/configured/' . bin2hex(random_bytes(8));
        $files->mkdir($stage);
        foreach ($plan as $path => $body) {
            $this->validatePath($path);
            $temporary = $stage . '/' . hash('sha256', $path);
            $files->repo->putContent($temporary, $body);
            if ($files->repo->getContent($temporary, max(1, strlen($body) + 1)) !== $body) { throw new RuntimeException('Staged package verification failed.'); }
            $staged[$path] = $temporary;
        }
        // Download URLs may expire and may contain account-specific signatures.
        unset($file['download_url'], $mod['latest_file']['download_url']);
        $release = ['mod' => $mod, 'file' => $file, 'files' => $staged];
        if ($this->provider() instanceof VersionedDownloadProvider) { $release['dependency_specs'] = $file['dependencies'] ?? []; }
        return $release;
    }
}
