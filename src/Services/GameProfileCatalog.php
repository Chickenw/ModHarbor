<?php

namespace GameNest\GameNestModManager\Services;

use RuntimeException;

/** Inert recommendations; reading a profile never writes the installed catalog. */
class GameProfileCatalog
{
    public function all(): array
    {
        $seeds = array_column(config('gamenest-mod-manager.game_definition_seeds', []), null, 'key');
        $profiles = [];

        foreach (config('gamenest-mod-manager.game_profiles', []) as $key => $profile) {
            $definition = array_replace(
                $seeds[$profile['seed_key'] ?? ''] ?? [],
                $profile['defaults'] ?? []
            );
            // Profiles are suggestions, not permission to activate a game.
            $definition['enabled'] = false;
            $profiles[$key] = [
                'aliases' => $profile['aliases'] ?? [],
                'notes' => $profile['notes'] ?? '',
                'defaults' => $definition,
                'runtime_metadata_upgrades' =>
                    $profile['runtime_metadata_upgrades']
                    ?? [],
            ];
        }

        return $profiles;
    }

    public function get(string $key): array
    {
        return $this->all()[$key]
            ?? throw new RuntimeException('The selected game profile is no longer available.');
    }

    public function search(string $query): array
    {
        $query = strtolower(trim($query));
        if (strlen($query) < 2) { return []; }
        $results = [];
        foreach ($this->all() as $key => $profile) {
            foreach (array_merge([$profile['defaults']['name']], $profile['aliases']) as $name) {
                if (str_contains(strtolower($name), $query)) {
                    $results[] = $this->result($key, $profile);
                    break;
                }
            }
        }
        return $results;
    }

    public function forSteamApp(int $appId): ?array
    {
        if ($appId < 1) { return null; }
        foreach ($this->all() as $key => $profile) {
            if (($profile['defaults']['steam_app_id'] ?? null) === $appId) {
                return $this->result($key, $profile);
            }
        }
        return null;
    }

    private function result(string $key, array $profile): array
    {
        return [
            'catalog' => 'profile',
            'catalog_label' => 'Recommended setup',
            'id' => $key,
            'name' => $profile['defaults']['name'],
            'steam_app_id' => $profile['defaults']['steam_app_id'] ?? null,
        ];
    }
}
