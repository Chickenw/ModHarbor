<?php

namespace GameNest\GameNestModManager\Services;

use App\Models\Server;
use GameNest\GameNestModManager\Adapters\ConfiguredGameAdapter;
use Throwable;

/**
 * Resolves ModHarbor game artwork for a Pelican server tenant avatar.
 * Used only when the egg itself has no icon, so existing egg images stay.
 */
class ServerTenantArtwork
{
    public function matches(object $server): bool
    {
        try {
            return app(AdapterRegistry::class)->forServer($server)
                instanceof ConfiguredGameAdapter;
        } catch (Throwable) {
            return false;
        }
    }

    public function dataUri(object $server): ?string
    {
        try {
            $adapter = app(AdapterRegistry::class)->forServer($server);
            if (!$adapter instanceof ConfiguredGameAdapter) {
                return null;
            }

            $art = app(GameArtworkService::class)->resolve(
                $adapter->definition()
            );
        } catch (Throwable) {
            return null;
        }

        if (!is_string($art) || $art === GameArtworkService::fallback()) {
            return null;
        }

        if (
            !preg_match(
                '#^data:image/(?:jpeg|png|webp);base64,[A-Za-z0-9+/]+=*$#D',
                $art
            )
        ) {
            return null;
        }

        return $art;
    }

    /**
     * @return array<string, string> server name => avatar route
     */
    public function nameMap(): array
    {
        $map = [];

        try {
            if (!class_exists(Server::class)) {
                return [];
            }

            $servers = Server::query()
                ->with('egg')
                ->orderBy('id')
                ->limit(80)
                ->get();
            $revision = substr((string) (
                app(\GameNest\GameNestModManager\Services\GameDefinitionStore::class)->snapshot()['revision'] ?? '0'
            ), 0, 16);
        } catch (Throwable) {
            return [];
        }

        foreach ($servers as $server) {
            $name = trim((string) ($server->name ?? ''));
            $uuid = trim((string) ($server->uuid ?? ''));
            if ($name === '' || $uuid === '' || !$this->matches($server)) {
                continue;
            }
            if (!preg_match('/^[A-Za-z0-9-]{8,64}$/D', $uuid)) {
                continue;
            }
            $map[$name] = '/modharbor/server-avatar/' . rawurlencode($uuid) . '?v=' . rawurlencode($revision);
        }

        return $map;
    }
}
