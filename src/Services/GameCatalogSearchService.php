<?php

namespace GameNest\GameNestModManager\Services;

use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/**
 * Universal canonical game search for Game Builder.
 *
 * Shared UI consumes a provider-neutral result shape.
 * Local profiles are supplemented by Steam identities.
 */
class GameCatalogSearchService
{
    private const RESULT_LIMIT = 10;

    public function __construct(
        private ProviderHttpClient $http
    ) {}

    public function search(string $query): array
    {
        $query = trim($query);

        if (strlen($query) < 2) {
            return [];
        }

        $catalog = new GameProfileCatalog;
        $results = $catalog->search($query);
        try {
            $remote = Cache::remember(
                'modharbor:game-search:' . sha1(strtolower($query)),
                now()->addHours(6),
                fn (): array => $this->searchSteam($query)
            );
        } catch (Throwable $e) {
            if ($results === []) { throw $e; }
            return array_slice($results, 0, self::RESULT_LIMIT);
        }

        foreach ($remote as $result) {
            $result = $catalog->forSteamApp((int) ($result['steam_app_id'] ?? 0)) ?? $result;
            $duplicate = false;
            foreach ($results as $existing) {
                if ($existing['catalog'] === $result['catalog'] && $existing['id'] === $result['id']) {
                    $duplicate = true;
                    break;
                }
            }
            if (!$duplicate) { $results[] = $result; }
        }
        return array_slice($results, 0, self::RESULT_LIMIT);
    }

    private function searchSteam(string $query): array
    {
        try {
            $response = $this->http->send(
                'GET',
                'https://store.steampowered.com/api/storesearch/',
                [
                    'term' => $query,
                    'l' => 'english',
                    'cc' => 'US',
                ]
            );

            if (($response['status'] ?? 0) !== 200) {
                throw new RuntimeException(
                    'Steam game search returned HTTP '
                    . ($response['status'] ?? 0)
                    . '.'
                );
            }

            $payload = json_decode(
                (string) ($response['body'] ?? ''),
                true,
                32,
                JSON_THROW_ON_ERROR
            );

            $results = [];

            foreach (
                array_slice(
                    (array) ($payload['items'] ?? []),
                    0,
                    self::RESULT_LIMIT
                ) as $item
            ) {
                $appId = (int) ($item['id'] ?? 0);
                $name = trim((string) ($item['name'] ?? ''));

                if ($appId < 1 || $name === '') {
                    continue;
                }

                $results[] = [
                    'catalog' => 'steam',
                    'catalog_label' => 'Steam',
                    'id' => (string) $appId,
                    'name' => $name,
                    'steam_app_id' => $appId,
                ];
            }

            return $results;
        } catch (Throwable) {
            throw new RuntimeException(
                'Game search is temporarily unavailable. '
                . 'You can still enter the game manually.'
            );
        }
    }
}
