<?php

namespace GameNest\GameNestModManager\Services\Artwork;

use GameNest\GameNestModManager\Contracts\ArtworkProviderInterface;
use RuntimeException;

/**
 * Resolves admin-uploaded artwork stored under the private artwork-uploads
 * directory. References use the form @upload/<safe-name>.
 *
 * Manual HTTPS/relative overrides remain the responsibility of
 * ManualArtworkProvider; this provider only handles local uploads.
 */
class UploadArtworkProvider implements ArtworkProviderInterface
{
    private const LIMIT = 524288;

    public function __construct(private ?string $directory = null) {}

    public function supports(array $game): bool
    {
        $override = $game['artwork_url'] ?? '';
        return is_string($override) && str_starts_with($override, '@upload/');
    }

    public function resolve(array $game): ?string
    {
        $reference = $game['artwork_url'] ?? '';
        if (!is_string($reference) || !preg_match('~^@upload/([a-zA-Z0-9][a-zA-Z0-9._-]*)$~D', $reference, $match)) {
            return null;
        }

        $dir = $this->directory ?? storage_path('app/gamenest-mod-manager/artwork-uploads');
        $path = $dir . '/' . $match[1];

        if (!is_file($path) || filesize($path) > self::LIMIT) {
            return null;
        }

        $bytes = @file_get_contents($path);
        if (!is_string($bytes) || $bytes === '') {
            return null;
        }

        return $this->imageUri($bytes);
    }

    /**
     * Persist an uploaded image and return the @upload/ reference.
     * Callers (Game Builder) must enforce authorization before invoking this.
     */
    public function store(string $bytes, string $preferredName = 'upload'): string
    {
        if ($bytes === '' || strlen($bytes) > self::LIMIT) {
            throw new RuntimeException('Uploaded artwork exceeds the size limit.');
        }
        $info = @getimagesizefromstring($bytes);
        if (!$info || !in_array($info['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true)
            || $info[0] > 4096 || $info[1] > 4096 || $info[0] < 1 || $info[1] < 1) {
            throw new RuntimeException('Uploaded artwork must be a JPEG, PNG or WebP image.');
        }

        $ext = match ($info['mime']) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            default => 'webp',
        };
        $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '', $preferredName) ?: 'upload';
        $safe = substr($safe, 0, 48);
        $name = $safe . '-' . bin2hex(random_bytes(6)) . '.' . $ext;

        $dir = $this->directory ?? storage_path('app/gamenest-mod-manager/artwork-uploads');
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to store uploaded artwork.');
        }
        $path = $dir . '/' . $name;
        if (file_put_contents($path, $bytes) === false) {
            throw new RuntimeException('Unable to store uploaded artwork.');
        }
        @chmod($path, 0600);

        return '@upload/' . $name;
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
