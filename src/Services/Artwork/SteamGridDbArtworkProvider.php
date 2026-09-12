<?php

namespace GameNest\GameNestModManager\Services\Artwork;

use GameNest\GameNestModManager\Contracts\ArtworkProviderInterface;
use GameNest\GameNestModManager\Services\GameArtworkService;
use GameNest\GameNestModManager\Services\ProviderSettingsStore;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * SteamGridDB artwork. Used when Steam is unavailable or has no image.
 * Prefers a saved steamgriddb_game_id (no re-guessing by name). Falls back
 * to Steam App ID lookup, then name search. Requires a global API key in
 * Provider Settings (provider key: steamgriddb).
 *
 * Prefer horizontal 460x215 grids to match ModHarbor cards.
 */
class SteamGridDbArtworkProvider implements ArtworkProviderInterface
{
    private const LIMIT = 524288;
    private const BASE = 'https://www.steamgriddb.com/api/v2';
    private static int $refreshes = 0;

    public function __construct(
        private ?string $directory = null,
        private ?ProviderSettingsStore $settings = null,
    ) {}

    public function supports(array $game): bool
    {
        if ($this->apiKey() === '') {
            return false;
        }
        return $this->positiveId($game['steamgriddb_game_id'] ?? null) !== null
            || $this->positiveId($game['steam_app_id'] ?? null) !== null;
    }

    public function resolve(array $game): ?string
    {
        if (!$this->supports($game)) {
            return null;
        }

        $cacheKey = $this->cacheKey($game);
        if ($cacheKey === null) {
            return null;
        }

        $cached = null;
        $lock = null;
        try {
            $dir = $this->directory ?? storage_path('app/gamenest-mod-manager/artwork-sgdb');
            if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
                return null;
            }
            $path = $dir . '/' . $cacheKey . '.json';
            if (is_file($path) && filesize($path) <= self::LIMIT * 2) {
                $cached = json_decode((string) @file_get_contents($path), true);
            }
            $image = $this->cachedImage($cached);
            if (is_array($cached) && ($cached['retry_after'] ?? 0) > $this->now()) {
                return $image;
            }
            if (!$this->canRefresh()) {
                return $image;
            }
            $lock = @fopen($dir . '/' . $cacheKey . '.lock', 'c');
            if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
                return $image;
            }

            $record = [
                'retry_after' => $this->now() + ($image ? 21600 : 60),
                'bytes' => $image ? ($cached['bytes'] ?? null) : null,
            ];
            try {
                $bytes = $this->download($game);
                if ($this->imageUri($bytes) === null) {
                    throw new RuntimeException('Invalid artwork.');
                }
                $record = ['retry_after' => $this->now() + 2592000, 'bytes' => base64_encode($bytes)];
                $image = $this->imageUri($bytes);
            } catch (Throwable) {
                /* Keep last good artwork during outages. */
            }
            $tmp = tempnam($dir, 'sgdb-');
            if ($tmp !== false) {
                try {
                    if (file_put_contents($tmp, json_encode($record, JSON_THROW_ON_ERROR)) !== false) {
                        @rename($tmp, $path);
                    }
                } finally {
                    if (is_file($tmp)) {
                        @unlink($tmp);
                    }
                }
            }
            return $image;
        } catch (Throwable) {
            return $this->cachedImage($cached);
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /**
     * Search SteamGridDB by name. Used by Game Builder UI to let an admin
     * pick a stable game ID. Returns a list of [id, name, verified].
     *
     * @return list<array{id:int,name:string,verified:bool}>
     */
    public function search(string $term): array
    {
        $term = trim($term);
        if (strlen($term) < 2 || $this->apiKey() === '') {
            return [];
        }
        try {
            $encoded = rawurlencode($term);
            $payload = $this->apiGet('/search/autocomplete/' . $encoded);
            $data = $payload['data'] ?? [];
            if (!is_array($data)) {
                return [];
            }
            $results = [];
            foreach ($data as $row) {
                if (!is_array($row) || !is_int($row['id'] ?? null) || !is_string($row['name'] ?? null)) {
                    continue;
                }
                $results[] = [
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'verified' => (bool) ($row['verified'] ?? false),
                ];
                if (count($results) >= 12) {
                    break;
                }
            }
            return $results;
        } catch (Throwable) {
            return [];
        }
    }

    protected function canRefresh(): bool
    {
        return self::$refreshes++ < 1;
    }

    protected function now(): int
    {
        return time();
    }

    private function cacheKey(array $game): ?string
    {
        $sgdbId = $this->positiveId($game['steamgriddb_game_id'] ?? null);
        if ($sgdbId !== null) {
            return 'g' . $sgdbId;
        }
        $steamId = $this->positiveId($game['steam_app_id'] ?? null);
        if ($steamId !== null) {
            return 's' . $steamId;
        }
        $name = strtolower(trim((string) ($game['name'] ?? '')));
        if (strlen($name) >= 2) {
            return 'n' . substr(hash('sha256', $name), 0, 32);
        }
        return null;
    }

    private function download(array $game): string
    {
        $gameId = $this->resolveGameId($game);
        if ($gameId === null) {
            throw new RuntimeException('SteamGridDB game not found.');
        }

        $gridId = $game['steamgriddb_grid_id'] ?? null;
        if (is_int($gridId) && $gridId >= 1) {
            // Specific grid selected by admin — fetch grids and pick that id.
            $url = $this->findGridUrl($gameId, $gridId);
        } else {
            $url = $this->findGridUrl($gameId, null);
        }
        if ($url === null || !GameArtworkService::validOverride($url)) {
            throw new RuntimeException('SteamGridDB grid unavailable.');
        }
        // Only allow CDN hosts that SteamGridDB actually serves from.
        if (!$this->allowedImageHost($url)) {
            throw new RuntimeException('SteamGridDB image host rejected.');
        }
        return $this->requestBytes($url);
    }

    private function resolveGameId(array $game): ?int
    {
        $sgdbId = $this->positiveId($game['steamgriddb_game_id'] ?? null);
        if ($sgdbId !== null) {
            return $sgdbId;
        }

        $steamId = $this->positiveId($game['steam_app_id'] ?? null);
        if ($steamId !== null) {
            try {
                $payload = $this->apiGet('/games/steam/' . $steamId);
                $id = $payload['data']['id'] ?? null;
                if (is_int($id) && $id >= 1) {
                    return $id;
                }
            } catch (Throwable) {
                // fall through to name search
            }
        }

        $name = trim((string) ($game['name'] ?? ''));
        if (strlen($name) < 2) {
            return null;
        }
        $results = $this->search($name);
        return $results[0]['id'] ?? null;
    }

    private function findGridUrl(int $gameId, ?int $preferGridId): ?string
    {
        // Prefer horizontal card-sized static grids.
        $query = http_build_query([
            'dimensions' => '460x215,920x430',
            'types' => 'static',
            'nsfw' => 'false',
            'humor' => 'false',
        ]);
        try {
            $payload = $this->apiGet('/grids/game/' . $gameId . '?' . $query);
        } catch (Throwable) {
            $payload = [];
        }
        $data = $payload['data'] ?? [];
        if (!is_array($data) || $data === []) {
            // Retry without dimension filter.
            try {
                $payload = $this->apiGet('/grids/game/' . $gameId . '?types=static&nsfw=false');
                $data = $payload['data'] ?? [];
            } catch (Throwable) {
                $data = [];
            }
        }
        if (!is_array($data)) {
            return null;
        }

        if ($preferGridId !== null) {
            foreach ($data as $row) {
                if (is_array($row) && ($row['id'] ?? null) === $preferGridId && is_string($row['url'] ?? null)) {
                    return $row['url'];
                }
            }
        }

        foreach ($data as $row) {
            if (!is_array($row) || !is_string($row['url'] ?? null)) {
                continue;
            }
            // Skip animated / webp-only if mime is clearly video-like.
            $mime = $row['mime'] ?? '';
            if (is_string($mime) && str_contains($mime, 'webm')) {
                continue;
            }
            return $row['url'];
        }
        return null;
    }

    private function positiveId(mixed $value): ?int
    {
        if (is_int($value) && $value >= 1) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value) && (int) $value >= 1) {
            return (int) $value;
        }
        return null;
    }

    private static ?string $cachedKey = null;

    private function apiKey(): string
    {
        if (self::$cachedKey !== null) {
            return self::$cachedKey;
        }
        try {
            $store = $this->settings ?? new ProviderSettingsStore();
            $key = $store->get('steamgriddb', 'api_key', '');
            $key = is_string($key) ? trim($key) : '';
            if ($key !== '') {
                self::$cachedKey = $key;
            }
            return $key;
        } catch (Throwable) {
            return '';
        }
    }

    private function apiGet(string $path): array
    {
        $key = $this->apiKey();
        if ($key === '') {
            throw new RuntimeException('SteamGridDB API key not configured.');
        }
        $url = self::BASE . $path;
        $response = Http::timeout(2)->connectTimeout(1)->withHeaders([
            'Authorization' => 'Bearer ' . $key,
            'Accept' => 'application/json',
            'User-Agent' => 'ModHarbor/1.0.0-rc.3',
        ])->withOptions(['allow_redirects' => false])->get($url);

        $body = $response->body();
        if (!$response->successful() || strlen($body) > 1048576) {
            throw new RuntimeException('SteamGridDB request failed.');
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || empty($decoded['success'])) {
            throw new RuntimeException('SteamGridDB response invalid.');
        }
        return $decoded;
    }

    private function requestBytes(string $url): string
    {
        $response = Http::timeout(2)->connectTimeout(1)->withOptions([
            'allow_redirects' => false,
            'progress' => static function ($total, $downloaded) {
                if ($total > self::LIMIT || $downloaded > self::LIMIT) {
                    throw new RuntimeException('Artwork exceeds limit.');
                }
            },
        ])->get($url);
        $body = $response->body();
        if (!$response->successful() || strlen($body) > self::LIMIT) {
            throw new RuntimeException('Artwork unavailable.');
        }
        return $body;
    }

    private function allowedImageHost(string $url): bool
    {
        if (!GameArtworkService::validOverride($url)) {
            return false;
        }
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host)) {
            return false;
        }
        $host = strtolower($host);
        return $host === 'cdn2.steamgriddb.com'
            || $host === 'cdn.steamgriddb.com'
            || str_ends_with($host, '.steamgriddb.com');
    }

    private function cachedImage(mixed $record): ?string
    {
        if (!is_array($record) || !is_string($record['bytes'] ?? null)) {
            return null;
        }
        $bytes = base64_decode($record['bytes'], true);
        return $bytes === false ? null : $this->imageUri($bytes);
    }

    private function imageUri(string $bytes): ?string
    {
        if ($bytes === '' || strlen($bytes) > self::LIMIT) {
            return null;
        }
        $info = @getimagesizefromstring($bytes);
        if (!$info || !in_array($info['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true)
            || $info[0] > 4096 || $info[1] > 4096 || $info[0] < 1 || $info[1] < 1) {
            return null;
        }
        return 'data:' . $info['mime'] . ';base64,' . base64_encode($bytes);
    }
}
