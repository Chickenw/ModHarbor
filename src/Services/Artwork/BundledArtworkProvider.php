<?php

namespace GameNest\GameNestModManager\Services\Artwork;

use GameNest\GameNestModManager\Contracts\ArtworkProviderInterface;

/**
 * Resolves @artwork/<name> references to files under resources/artwork/.
 * Traversal is prevented by the strict name regex. Temporary until
 * SteamGridDB / upload paths remove the need for bundled Mojang assets.
 */
class BundledArtworkProvider implements ArtworkProviderInterface
{
    private const LIMIT = 524288;

    public function __construct(private ?string $artworkRoot = null) {}

    public function supports(array $game): bool
    {
        $override = $game['artwork_url'] ?? '';
        return is_string($override) && str_starts_with($override, '@artwork/');
    }

    public function resolve(array $game): ?string
    {
        $reference = $game['artwork_url'] ?? '';
        if (!is_string($reference) || !preg_match('~^@artwork/([a-zA-Z0-9][a-zA-Z0-9._-]*)$~D', $reference, $match)) {
            return null;
        }

        $root = $this->artworkRoot
            ?? dirname(__DIR__, 3) . '/resources/artwork';
        $path = $root . '/' . $match[1];

        if (!is_file($path) || filesize($path) > self::LIMIT) {
            return null;
        }

        $bytes = @file_get_contents($path);
        if (!is_string($bytes) || $bytes === '') {
            return null;
        }

        return $this->imageUri($bytes);
    }

    private function imageUri(string $bytes): ?string
    {
        if (strlen($bytes) > self::LIMIT) {
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
