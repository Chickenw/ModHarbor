<?php

namespace GameNest\GameNestModManager\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use RuntimeException;

class ManifestService
{
    public function filename(): string
    {
        return '.gamenest-mod-manager.json';
    }

    public function read(Server $server): array
    {
        $repo = app(DaemonFileRepository::class)->setServer($server);
        $exists = false;
        foreach ($repo->getDirectory('/') as $entry) {
            if (($entry['name'] ?? '') === $this->filename()) {
                if (empty($entry['file']) || !empty($entry['symlink'])) {
                    throw new RuntimeException('The mod manifest must be a regular file.');
                }
                $exists = true;
            }
        }
        if (!$exists) {
            return $this->emptyManifest($server);
        }
        $data = json_decode($repo->getContent($this->filename(), 2 * 1024 * 1024), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['schema'] ?? null) !== 1 || !is_array($data['mods'] ?? null)
            || ($data['server_uuid'] ?? null) !== $server->uuid
            || ($data['game'] ?? null) !== $this->detectGame($server)) {
            throw new RuntimeException('The mod manifest is invalid or belongs to another server/game. Restore it before changing mods.');
        }
        foreach ($data['mods'] as $key => $entry) {
            if (!is_array($entry) || !is_array($entry['paths'] ?? null) || !is_array($entry['required_by'] ?? null)
                || !is_bool($entry['enabled'] ?? null) || !is_bool($entry['dependency'] ?? null)
                || !is_string($entry['provider'] ?? null)
                || (
                    $key !== $this->key(
                        $entry['provider'],
                        $entry['provider_id'] ?? ''
                    )
                    && !(
                        ($entry['provider'] ?? '') === 'upload'
                        && preg_match(
                            '/^upload:[a-f0-9]{40}$/D',
                            $key
                        ) === 1
                        && preg_match(
                            '/^[a-f0-9]{40}$/D',
                            (string) ($entry['provider_id'] ?? '')
                        ) === 1
                    )
                )) {
                throw new RuntimeException('Invalid managed mod entry. Restore the manifest before changing mods.');
            }
            foreach ($entry['paths'] as $path) {
                if (!is_string($path)) { throw new RuntimeException('Invalid manifest file path.'); }
                FileTransaction::path($path);
            }
            foreach ($entry['required_by'] as $parent) {
                if (!is_string($parent) || $parent === $key) {
                    throw new RuntimeException('Invalid manifest dependency reference.');
                }
            }
        }
        return $data;
    }

    public function write(
        Server $server,
        array $manifest
    ): void {
        $manifest['schema'] = 1;

        if (
            !isset($manifest['mods'])
            || !is_array($manifest['mods'])
        ) {
            $manifest['mods'] = [];
        }

        $manifest['updated_at'] =
            now()->toIso8601String();

        $json = json_encode(
            $manifest,
            JSON_PRETTY_PRINT
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
        );

        if ($json === false) {
            throw new RuntimeException(
                'Unable to encode the GameNest Mod Manager manifest.'
            );
        }

        app(DaemonFileRepository::class)
            ->setServer($server)
            ->putContent(
                $this->filename(),
                $json . PHP_EOL
            );
    }

    public function mods(
        Server $server
    ): array {
        $manifest = $this->read($server);

        return is_array(
            $manifest['mods'] ?? null
        )
            ? $manifest['mods']
            : [];
    }

    public function has(
        Server $server,
        string $key
    ): bool {
        return isset(
            $this->mods($server)[$key]
        );
    }

    public function key(
        string $provider,
        string|int $providerId
    ): string {
        return strtolower(
            trim($provider)
        ) .
        ':' .
        trim((string) $providerId);
    }

    public function put(
        Server $server,
        string $key,
        array $entry
    ): void {
        $manifest = $this->read(
            $server
        );

        $manifest['mods'][$key] =
            $entry;

        $this->write(
            $server,
            $manifest
        );
    }

    public function remove(
        Server $server,
        string $key
    ): void {
        $manifest = $this->read(
            $server
        );

        unset(
            $manifest['mods'][$key]
        );

        $this->write(
            $server,
            $manifest
        );
    }

    protected function emptyManifest(
        Server $server
    ): array {
        $server->loadMissing('egg');

        return [
            'schema' => 1,
            'game' => $this->detectGame(
                $server
            ),
            'server_uuid' =>
                $server->uuid ?? null,
            'mods' => [],
            'updated_at' => null,
        ];
    }

    protected function detectGame(
        Server $server
    ): string {
        return app(AdapterRegistry::class)->forServer($server)?->key() ?? 'unknown';
    }
}
