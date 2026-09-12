<?php

namespace GameNest\GameNestModManager\Services;

use App\Models\Server;
use RuntimeException;

class ExistingModService
{
    public function adopt(Server $server, array $paths, string $version = 'Adopted'): array
    {
        $paths = array_values(array_unique($paths));
        if (count(array_unique(array_map('strtolower', $paths))) !== count($paths)) {
            throw new RuntimeException('Selected paths differ only by letter case. Adopt them separately after resolving the conflict.');
        }
        if (!$paths || count($paths) > 50 || strlen($version) > 120 || preg_match('/[\x00-\x1f]/', $version)) {
            throw new RuntimeException('Select 1–50 files and a valid version label.');
        }
        return app(ManagedMutation::class)->run($server, 'adopt', function ($files, &$journal) use ($server, $paths, $version) {
            $candidates = array_column(app(ManagedFileScanner::class)->scan($server), null, 'path');
            $after = $journal['manifest_before']; $total = 0;
            foreach ($paths as $path) {
                FileTransaction::path($path);
                $candidate = $candidates[$path] ?? null;
                if (!$candidate || $candidate['owners']) { throw new RuntimeException('Candidate is missing or already managed. Scan again.'); }
                $stat = $files->stat($path);
                if (!$stat || empty($stat['file'])) { throw new RuntimeException('Candidate is no longer a regular file.'); }
                $contents = $files->repo->getContent($path, 8 * 1024 * 1024);
                $total += strlen($contents);
                if (strlen($contents) > 8 * 1024 * 1024 || $total > 32 * 1024 * 1024) {
                    throw new RuntimeException('Adoption is limited to 8 MB per file and 32 MB per batch.');
                }
                $id = bin2hex(random_bytes(20)); $key = 'existing:' . $id;
                // Private server-side snapshot participates in rollback and needs no new provider credentials.
                $snapshot = '.gamenest/mod-manager/adopted/' . $id . '/' . $path;
                ManagedMutation::replace($files, $snapshot, $contents);
                if ($files->repo->getContent($path, 8 * 1024 * 1024) !== $contents) {
                    throw new RuntimeException('Candidate changed during adoption.');
                }
                $now = now()->toIso8601String();
                $after['mods'][$key] = ['provider' => 'existing', 'provider_id' => $id, 'file_id' => $id,
                    'name' => pathinfo($path, PATHINFO_FILENAME), 'version' => $version ?: 'Adopted',
                    'enabled' => true, 'dependency' => false, 'required_by' => [], 'paths' => [$path],
                    'source_type' => 'adopted', 'snapshot_hashes' => [$path => hash('sha256', $contents)],
                    'installed_at' => $now, 'updated_at' => $now];
            }
            if (app(ManifestService::class)->read($server) !== $journal['manifest_before']) {
                throw new RuntimeException('Manifest changed during adoption.');
            }
            ManagedMutation::stopped($server);
            ManagedMutation::replace($files, app(ManifestService::class)->filename(),
                json_encode($after, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);
            $journal['name'] = count($paths) . ' existing mod file(s)';
            $journal['affected_files'] = $paths;
            $journal['transitions'] = AuditTrail::transitions($journal['manifest_before']['mods'], $after['mods']);
        });
    }
}
