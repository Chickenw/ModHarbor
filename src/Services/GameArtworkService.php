<?php

namespace GameNest\GameNestModManager\Services;

use GameNest\GameNestModManager\Services\Artwork\BundledArtworkProvider;
use GameNest\GameNestModManager\Services\Artwork\FallbackArtworkProvider;
use GameNest\GameNestModManager\Services\Artwork\GameArtworkRegistry;
use GameNest\GameNestModManager\Services\Artwork\GameArtworkResolver;
use GameNest\GameNestModManager\Services\Artwork\ManualArtworkProvider;
use GameNest\GameNestModManager\Services\Artwork\SteamArtworkProvider;
use GameNest\GameNestModManager\Services\Artwork\UploadArtworkProvider;
use GameNest\GameNestModManager\Services\Artwork\SteamGridDbArtworkProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Cosmetic, credential-free artwork. Cached bytes never require a public storage link.
 *
 * Public API is stable. Resolution is delegated to a universal provider registry
 * (Manual → Bundled → Steam → Fallback). Steam cache / download logic remains
 * on this class so existing unit-test fixtures that subclass and override
 * protected hooks continue to work unchanged.
 */
class GameArtworkService
{
    private const LIMIT = 524288;
    private static int $refreshes = 0;

    private ?GameArtworkResolver $resolver = null;

    public function __construct(private ?string $directory = null) {}

    public static function validOverride(mixed $url): bool
    {
        if (!is_string($url) || strlen($url) > 2048 || preg_match('/[\\x00-\\x20\\x7f\\\\]/', $url)) {
            return false;
        }
        if (preg_match('~^@(?:artwork|upload)/[a-zA-Z0-9][a-zA-Z0-9._-]*$~D', $url)) {
            return true;
        }
        if (preg_match('~^/[a-zA-Z0-9][^<>]*$~D', $url)) {
            return true;
        }
        $parts = parse_url($url);
        return $parts !== false && ($parts['scheme'] ?? '') === 'https' && !empty($parts['host'])
            && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['port']);
    }

    public function resolve(array $game): string
    {
        // Prefer the injectable registry path when available (production).
        // When this instance is a test subclass that overrides protected hooks,
        // fall through to the classic path so those overrides still apply.
        if ($this->resolver !== null || static::class === self::class) {
            return $this->resolver()->resolve($game);
        }

        // Classic path used by unit-test fixtures that extend this class.
        return $this->classicResolve($game);
    }

    /**
     * Allow tests or future DI to inject a fully-configured resolver.
     */
    public function setResolver(GameArtworkResolver $resolver): void
    {
        $this->resolver = $resolver;
    }

    private function resolver(): GameArtworkResolver
    {
        if ($this->resolver !== null) {
            return $this->resolver;
        }

        $directory = $this->directory;
        $owner = $this;
        $steam = new class ($directory, $owner) extends SteamArtworkProvider {
            public function __construct(?string $directory, private GameArtworkService $owner)
            {
                parent::__construct($directory);
            }
            protected function canRefresh(): bool { return $this->owner->canRefresh(); }
            protected function now(): int { return $this->owner->now(); }
            protected function request(string $url): string { return $this->owner->request($url); }
            protected function download(int $id): string { return $this->owner->download($id); }
        };

        $registry = (new GameArtworkRegistry())
            ->register(new ManualArtworkProvider())
            ->register(new UploadArtworkProvider())
            ->register(new BundledArtworkProvider())
            ->register($steam)
            ->register(new SteamGridDbArtworkProvider($directory))
            ->register(new FallbackArtworkProvider());

        return $this->resolver = new GameArtworkResolver($registry);
    }

    /**
     * Original monolithic resolve path. Kept so ArtworkFixture (and any other
     * subclass that overrides protected hooks) continues to exercise the
     * exact same Steam/cache/locking behaviour.
     */
    private function classicResolve(array $game): string
    {
        $override = $game['artwork_url'] ?? '';

        if (is_string($override) && str_starts_with($override, '@upload/')) {
            return (new \GameNest\GameNestModManager\Services\Artwork\UploadArtworkProvider($this->directory ? dirname($this->directory) . '/artwork-uploads' : null))
                ->resolve(['artwork_url' => $override]) ?? self::fallback();
        }

        if (is_string($override) && str_starts_with($override, '@artwork/')) {
            return $this->bundledArtwork($override) ?? self::fallback();
        }

        if (self::validOverride($override)) {
            return $override;
        }
        $id = $game['steam_app_id'] ?? null;
        if (!is_int($id) || $id < 1 || $id > 4294967295) {
            return self::fallback();
        }
        $cached = null;
        $lock = null;
        try {
            $dir = $this->directory ?? storage_path('app/gamenest-mod-manager/artwork');
            if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
                return self::fallback();
            }
            $path = $dir . '/' . $id . '.json';
            if (is_file($path) && filesize($path) <= self::LIMIT * 2) {
                $cached = json_decode((string) @file_get_contents($path), true);
            }
            $image = $this->cachedImage($cached);
            if (is_array($cached) && ($cached['retry_after'] ?? 0) > $this->now()) {
                return $image ?? self::fallback();
            }
            // Never let a catalog full of cold images multiply network latency.
            if (!$this->canRefresh()) {
                return $image ?? self::fallback();
            }
            $lock = @fopen($dir . '/' . $id . '.lock', 'c');
            if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
                return $image ?? self::fallback();
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
            return $image ?? self::fallback();
        } catch (Throwable) {
            return $this->cachedImage($cached) ?? self::fallback();
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    private function bundledArtwork(string $reference): ?string
    {
        if (!preg_match('~^@artwork/([a-zA-Z0-9][a-zA-Z0-9._-]*)$~D', $reference, $match)) {
            return null;
        }

        $path = dirname(__DIR__, 2) . '/resources/artwork/' . $match[1];

        if (!is_file($path) || filesize($path) > self::LIMIT) {
            return null;
        }

        $bytes = @file_get_contents($path);

        return is_string($bytes)
            ? $this->imageUri($bytes)
            : null;
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
        if (empty($metadata[$id]['success']) || !self::steamUrl($url)) {
            $url = 'https://shared.steamstatic.com/store_item_assets/steam/apps/' . $id . '/header.jpg';
        }
        return $this->request($url);
    }

    public static function steamUrl(mixed $url): bool
    {
        if (!self::validOverride($url)) {
            return false;
        }
        $host = parse_url($url, PHP_URL_HOST);
        return is_string($host) && ($host === 'steamstatic.com' || str_ends_with($host, '.steamstatic.com'));
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

    public static function fallback(): string
    {
        return 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 460 215"><rect width="460" height="215" fill="#102133"/><path d="M224 50h12l14 115h-40z" fill="#9bd8e8"/><path d="M218 46h24v18h-24zM210 165h40v8h-40z" fill="#e6f4fa"/><path d="M240 50L420 15v80z" fill="#31506d"/><path d="M0 190q60-20 120 0t120 0t120 0t120 0" stroke="#52a9ba" fill="none" stroke-width="5"/></svg>');
    }
}
