<?php

namespace GameNest\GameNestModManager\Services;

use App\Models\Server;
use Filament\AvatarProviders\UiAvatarsProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Filament fallback avatars for servers whose eggs have no icon.
 * Existing egg thumbnails keep using Server::getFilamentAvatarUrl().
 */
class ServerArtworkAvatarProvider
{
    public function get(Model|Authenticatable $record): string
    {
        try {
            if ($record instanceof Server) {
                $uuid = trim((string) ($record->uuid ?? ''));
                if (
                    $uuid !== ''
                    && preg_match('/^[A-Za-z0-9-]{8,64}$/D', $uuid)
                    && app(ServerTenantArtwork::class)->matches($record)
                ) {
                    $revision = substr((string) (
                        app(\GameNest\GameNestModManager\Services\GameDefinitionStore::class)->snapshot()['revision'] ?? '0'
                    ), 0, 16);
                    return url('/modharbor/server-avatar/' . rawurlencode($uuid) . '?v=' . rawurlencode($revision));
                }
            }
        } catch (Throwable) {
        }

        return (new UiAvatarsProvider)->get($record);
    }
}
