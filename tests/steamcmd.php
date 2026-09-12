<?php

namespace Symfony\Component\Process {
    class Process {
        public static array $args = [];
        public static bool $success = true;
        public function __construct(private array $arguments, private string $cwd) { self::$args = $arguments; }
        public function setTimeout($seconds) { if ($seconds !== 300) { throw new \RuntimeException('Missing process timeout'); } }
        public function run() {
            if (!self::$success) { return; }
            $dir = $this->cwd . '/steamapps/workshop/content/456/123';
            if (!is_dir($dir)) { mkdir($dir, 0700, true); }
            file_put_contents($dir . '/current.dll', 'workshop');
        }
        public function isSuccessful() { return self::$success; }
        public function getOutput() { return 'Success. Downloaded item 123'; }
    }
}
namespace {
    spl_autoload_register(function ($class) {
        $prefix = 'GameNest\\GameNestModManager\\';
        if (str_starts_with($class, $prefix)) { require __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php'; }
    });
    $root = sys_get_temp_dir() . '/modharbor-steamcmd-' . bin2hex(random_bytes(8));
    mkdir($root, 0700, true); file_put_contents($root . '/steamcmd.sh', 'fixture');
    function app($class) { return new class extends \GameNest\GameNestModManager\Services\ProviderSettingsStore {
        public function get(string $provider, string $name, mixed $fallback = null): mixed { return $fallback; }
    }; }
    function config($key, $default = null) { return $GLOBALS['root']; }
    function expectFailure($callback): void {
        try { $callback(); } catch (RuntimeException) { return; }
        throw new LogicException('Expected failure');
    }
    $downloader = new \GameNest\GameNestModManager\Services\SteamCmdDownloader;
    $files = $downloader->download('456', '123');
    if ($files !== ['current.dll' => 'workshop']) { throw new LogicException('Wrong content'); }
    $args = \Symfony\Component\Process\Process::$args;
    if (array_slice($args, 1) !== ['+login', 'anonymous', '+workshop_download_item', '456', '123', 'validate', '+quit']) { throw new LogicException('Unsafe command arguments'); }
    file_put_contents($root . '/steamapps/workshop/content/456/123/stale.dll', 'stale');
    $files = $downloader->download('456', '123');
    if (isset($files['stale.dll'])) { throw new LogicException('Stale Workshop cache deployed'); }
    $lock = fopen($root . '/.modharbor.lock', 'c'); flock($lock, LOCK_EX);
    expectFailure(fn () => $downloader->download('456', '123'));
    flock($lock, LOCK_UN); fclose($lock);
    \Symfony\Component\Process\Process::$success = false;
    expectFailure(fn () => $downloader->download('456', '123'));
    expectFailure(fn () => $downloader->download('456', '123;exit'));
    // Cleanup only this test's freshly created temporary directory.
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
    rmdir($root);
    echo "SteamCMD argument, lock, stale-cache, successful-content and failure checks passed.\n";
}
