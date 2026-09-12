<?php

namespace GameNest\GameNestModManager\Services\Artwork;

use GameNest\GameNestModManager\Contracts\ArtworkProviderInterface;
use GameNest\GameNestModManager\Services\GameArtworkService;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Steam header artwork with local cache, locking, negative caching and
 * host allow-list. Behavior is intentionally identical to the previous
 * monolithic GameArtworkService Steam path.
 */
class SteamArtworkProvider implements ArtworkProviderInterface
{
    private const LIMIT = 524288;
    private static int $refreshes = 0;

    public function __construct(private ?string $directory = null) {}

    public function supports(array $game): bool
    {
        $id = $game['steam_app_id'] ?? null;
        return is_int($id) && $id >= 1 && $id <= 4294967295;
    }

    public function resolve(array $game): ?string
    {
        $id = $game['steam_app_id'] ?? null;
        if (!is_int($id) || $id < 1 || $id > 4294967295) {
            return null;
        }

        $cached = null;
        $lock = null;
        try {
            $dir = $this->directory ?? storage_path('app/gamenest-mod-manager/artwork');
            if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
                return null;
            }
            $path = $dir . '/' . $id . '.json';
            if (is_file($path) && filesize($path) <= self::LIMIT * 2) {
                $cached = json_decode((string) @file_get_contents($path), true);
            }
            $image = $this->cachedImage($cached);
            if (is_array($cached) && ($cached['retry_after'] ?? 0) > $this->now()) {
                return $image;
            }
            // Never let a catalog full of cold images multiply network latency.
            if (!$this->canRefresh()) {
                return $image;
            }
            $lock = @fopen($dir . '/' . $id . '.lock', 'c');
            if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
                return $image;
            }
            $record = ['retry_after' => $this->now() + 21600, 'bytes' => $image ? ($cached['bytes'] ?? null) : null];
            try {
                $bytes = $this->download($id);
                if ($this->imageUri($bytes) === null) {
                    throw new RuntimeException('Invalid artwork.');
                }
                $record = ['retry_after' => $this->now() + 2592000, 'bytes' => base64_encode($bytes)];
                $image = $this->imageUri($bytes);
            } catch (Throwable) {
                /* Keep last good artwork during outages; retry after six hours. */
            }
            $tmp = tempnam($dir, 'art-');
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

    protected function canRefresh(): bool
    {
        return self::$refreshes++ < 1;
    }

    protected function now(): int
    {
        return time();
    }

    protected function download(int $id): string
    {
        // appdetails resolves current hashed assets as well as legacy Steam paths.
        try {
            $metadata = json_decode($this->request('https://store.steampowered.com/api/appdetails?appids=' . $id . '&filters=basic'), true);
        } catch (Throwable) {
            $metadata = [];
        }
        $url = $metadata[$id]['data']['header_image'] ?? '';
        if (empty($metadata[$id]['success']) || !GameArtworkService::steamUrl($url)) {
            $url = 'https://shared.steamstatic.com/store_item_assets/steam/apps/' . $id . '/header.jpg';
        }
        return $this->request($url);
    }

    protected function request(string $url): string
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
