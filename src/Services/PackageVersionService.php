<?php

namespace GameNest\GameNestModManager\Services;

use App\Models\Server;
use GameNest\GameNestModManager\Contracts\VersionListingProvider;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

class PackageVersionService
{
    public function listing(Server $server, string $key): array
    {
        Gate::authorize('file.read', $server); Gate::authorize('file.read-content', $server);
        [$provider, $id] = array_pad(explode(':', $key, 2), 2, '');
        $registry = app(SourceRegistry::class);
        if (!$registry->supports($server, $provider) || $id === '') { throw new RuntimeException('Source is unavailable for this server.'); }
        if ($provider === 'upload') {
            $upload = app(UploadPackageStore::class)->get($id);
            if (!$upload || ($upload['server_uuid'] ?? '') !== $server->uuid) {
                throw new RuntimeException('Uploaded package belongs to another server.');
            }
        }
        $instance = $registry->provider($provider);
        $context = $registry->context($server, $provider);
        $versions = $instance instanceof VersionListingProvider ? $instance->versions($id, $context)
            : [$instance->get($id, $context)['latest_file'] ?? []];
        $rows = [];
        foreach (array_slice($versions, 0, 100) as $file) {
            if (empty($file['id'])) { continue; }
            // Explicit projection keeps signed URLs, tokens, raw API responses out of browser state.
            $rows[] = ['id' => (string) $file['id'], 'version' => (string) ($file['version'] ?? $file['id']),
                'filename' => (string) ($file['filename'] ?? $file['name'] ?? ''), 'filesize' => (int) ($file['filesize'] ?? 0)];
        }
        return $rows;
    }
}
