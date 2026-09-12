<?php

spl_autoload_register(function ($class) {
    $prefix = 'GameNest\\GameNestModManager\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

use GameNest\GameNestModManager\Services\GameArtworkService;

class ArtworkFixture extends GameArtworkService
{
    public int $clock = 100000;
    public array $requests = [];
    public bool $fail = false;
    public string $url = 'https://shared.steamstatic.com/store_item_assets/steam/apps/440/hash/header.jpg';
    public string $bytes;
    public function __construct(string $dir) {
        parent::__construct($dir);
        $this->bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a9XkAAAAASUVORK5CYII=');
    }
    protected function now(): int { return $this->clock; }
    protected function canRefresh(): bool { return true; }
    protected function request(string $url): string {
        $this->requests[] = $url;
        if ($this->fail) { throw new RuntimeException('Network unavailable'); }
        return str_contains($url, '/api/appdetails') ? json_encode([440 => ['success' => true, 'data' => ['header_image' => $this->url]]]) : $this->bytes;
    }
}

$dir = sys_get_temp_dir() . '/modharbor-artwork-' . bin2hex(random_bytes(8));
mkdir($dir);
$n = 0;
function check(bool $ok, string $label): void {
    if (!$ok) { throw new RuntimeException('FAIL ' . $label); }
    $GLOBALS['n']++; echo 'PASS artwork: ' . $label . PHP_EOL;
}
try {
    $s = new ArtworkFixture($dir);
    foreach ([null, -1, 0, '440', '../440', 4294967296] as $id) {
        check($s->resolve(['steam_app_id' => $id]) === GameArtworkService::fallback(), 'invalid ID falls back');
    }
    check(!$s->requests, 'invalid IDs never request Steam');
    check($s->resolve(['steam_app_id' => 440, 'artwork_url' => '/images/custom.png']) === '/images/custom.png' && !$s->requests, 'manual override has first priority');
    foreach (['javascript:alert(1)', '//evil.test/x', '/\\evil.test/x', 'https://user:pass@example.com/x', "https://example.com/\nx", 'data:image/svg+xml,x'] as $bad) {
        check(!GameArtworkService::validOverride($bad), 'unsafe override rejected');
    }
    $image = $s->resolve(['steam_app_id' => 440]);
    check(str_starts_with($image, 'data:image/png;base64,'), 'valid Steam image downloaded locally');
    check(count($s->requests) === 2 && $s->requests[1] === $s->url, 'current hashed asset resolved');
    $s2 = new ArtworkFixture($dir);
    check($s2->resolve(['steam_app_id' => 440]) === $image && !$s2->requests, 'new instance reads persistent cache with no HTTP');
    $s2->clock += 2592001; $s2->fail = true;
    check($s2->resolve(['steam_app_id' => 440]) === $image, 'stale artwork survives network failure');
    $count = count($s2->requests);
    check($s2->resolve(['steam_app_id' => 440]) === $image && count($s2->requests) === $count, 'failure retry is throttled');
    $s2->resolve(['steam_app_id' => 441]); $count = count($s2->requests);
    check($s2->resolve(['steam_app_id' => 441]) === GameArtworkService::fallback() && count($s2->requests) === $count, 'missing artwork negatively cached');
    $s->url = 'https://evil.test/private';
    $s->resolve(['steam_app_id' => 442]);
    check(!in_array($s->url, $s->requests, true), 'metadata cannot redirect requests outside Steam');
    check(!GameArtworkService::steamUrl('https://steamstatic.com.evil.test/x'), 'lookalike Steam host rejected');
    $s->bytes = '<svg onload="alert(1)"/>';
    check($s->resolve(['steam_app_id' => 443]) === GameArtworkService::fallback(), 'untrusted SVG rejected');
    $s->bytes = str_repeat('x', 524289);
    check($s->resolve(['steam_app_id' => 444]) === GameArtworkService::fallback(), 'oversized image rejected');
    file_put_contents($dir . '/445.json', '{broken');
    check($s->resolve(['steam_app_id' => 445]) === GameArtworkService::fallback(), 'corrupt cache is harmless');
    $lock = fopen($dir . '/446.lock', 'c'); flock($lock, LOCK_EX);
    $count = count($s->requests);
    check($s->resolve(['steam_app_id' => 446]) === GameArtworkService::fallback() && count($s->requests) === $count, 'concurrent cold request does not duplicate download');
    flock($lock, LOCK_UN); fclose($lock);
    check($s->resolve(['steam_app_id' => 440, 'artwork_url' => 'https://example.com/custom.jpg']) === 'https://example.com/custom.jpg', 'manual override wins over populated cache');

    // Upload provider: store + resolve
    $uploadDir = $dir . '-uploads';
    @mkdir($uploadDir);
    $uploader = new \GameNest\GameNestModManager\Services\Artwork\UploadArtworkProvider($uploadDir);
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a9XkAAAAASUVORK5CYII=');
    $ref = $uploader->store($png, 'unit');
    check(str_starts_with($ref, '@upload/'), 'upload store returns @upload reference');
    $resolved = $uploader->resolve(['artwork_url' => $ref]);
    check(is_string($resolved) && str_starts_with($resolved, 'data:image/png;base64,'), 'upload resolve returns data URI');
    check(GameArtworkService::validOverride($ref), 'validOverride accepts @upload');
    check($s->resolve(['steam_app_id' => 440, 'artwork_url' => $ref]) === $resolved
        || str_starts_with((string) $s->resolve(['steam_app_id' => 440, 'artwork_url' => $ref]), 'data:image/'), 'upload override wins in resolve path');
    foreach (glob($uploadDir . '/*') as $f) { @unlink($f); }
    @rmdir($uploadDir);

    echo "$n artwork assertions, 0 failures\n";
} finally {
    foreach (glob($dir . '/*') as $file) { unlink($file); }
    rmdir($dir);
}
