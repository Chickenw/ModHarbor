<?php

namespace GameNest\GameNestModManager\Services;

use RuntimeException;
use Symfony\Component\Process\Process;

/** Dedicated administrator-owned SteamCMD installation, serialized across servers. */
class SteamCmdDownloader
{
    public function download(string $appId, string $itemId): array
    {
        foreach ([$appId, $itemId] as $id) {
            if (!preg_match('/^[1-9][0-9]{0,19}$/D', $id)) { throw new RuntimeException('Invalid Workshop identifier.'); }
        }
        $configuredRoot = app(ProviderSettingsStore::class)->get(
            'steam-workshop',
            'steamcmd_root',
            config(
                'gamenest-mod-manager.steam-workshop.steamcmd_root',
                ''
            )
        );

        $root = realpath((string) $configuredRoot);
        if (!$root || !is_file($root . '/steamcmd.sh') || !class_exists(Process::class)) {
            throw new RuntimeException('Configure a dedicated SteamCMD installation in global provider settings.');
        }
        $lock = fopen($root . '/.modharbor.lock', 'c');
        if (!$lock) { throw new RuntimeException('SteamCMD installation is not writable by the panel worker.'); }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) { throw new RuntimeException('Another Workshop download is running. Retry shortly.'); }
            $content = $root . '/steamapps/workshop/content/' . $appId . '/' . $itemId;
            // A dedicated cache can retain files removed in a newer Workshop revision.
            // Clear only this validated item while holding the global download lock.
            foreach (['steamapps', 'steamapps/workshop', 'steamapps/workshop/content', 'steamapps/workshop/content/' . $appId, 'steamapps/workshop/content/' . $appId . '/' . $itemId] as $part) {
                if (is_link($root . '/' . $part)) { throw new RuntimeException('Workshop cache contains a symbolic link.'); }
            }
            if (is_dir($content)) { $this->clearItem($content, $root); }
            $process = new Process([$root . '/steamcmd.sh', '+login', 'anonymous', '+workshop_download_item', $appId, $itemId, 'validate', '+quit'], $root);
            $process->setTimeout(300);
            try { $process->run(); } catch (\Throwable) { throw new RuntimeException('SteamCMD download timed out or could not start.'); }
            if (!$process->isSuccessful() || !str_contains($process->getOutput(), 'Success. Downloaded item ' . $itemId)) {
                throw new RuntimeException('SteamCMD could not download this item anonymously. It may require game ownership or a Steam login; use a manually obtained package.');
            }
            foreach (['steamapps', 'steamapps/workshop', 'steamapps/workshop/content', 'steamapps/workshop/content/' . $appId, 'steamapps/workshop/content/' . $appId . '/' . $itemId] as $part) {
                if (is_link($root . '/' . $part)) { throw new RuntimeException('Workshop content contains a symbolic link.'); }
            }
            return $this->readFiles($content);
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
    private function clearItem(string $directory, string $installation): void
    {
        $resolved = realpath($directory);
        if (!$resolved || !str_starts_with($resolved, $installation . DIRECTORY_SEPARATOR . 'steamapps' . DIRECTORY_SEPARATOR . 'workshop' . DIRECTORY_SEPARATOR . 'content' . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Workshop cache lies outside the dedicated installation.');
        }
        $entries = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($resolved, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            if ($entry->isLink() || count($entries) >= 20000) { throw new RuntimeException('Workshop cache is unsafe to refresh.'); }
            $entries[] = [$entry->getPathname(), $entry->isDir()];
        }
        foreach ($entries as [$path, $directoryEntry]) {
            if (!($directoryEntry ? rmdir($path) : unlink($path))) { throw new RuntimeException('Workshop cache could not be refreshed.'); }
        }
        if (!rmdir($resolved)) { throw new RuntimeException('Workshop cache could not be refreshed.'); }
    }
    public function readFiles(string $directory): array
    {
        $root = realpath($directory);
        if (!$root || is_link($directory)) { throw new RuntimeException('SteamCMD produced no safe content directory.'); }
        $files = []; $bytes = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            if ($entry->isLink() || !$entry->isFile()) { throw new RuntimeException('Workshop content contains an unsupported file.'); }
            $path = str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1));
            FileTransaction::path($path);
            $bytes += $entry->getSize();
            if ($bytes > ConfiguredPackagePlan::MAX_BYTES || count($files) >= 10000) { throw new RuntimeException('Workshop package exceeds managed deployment limits.'); }
            $body = file_get_contents($entry->getPathname());
            if ($body === false || strlen($body) !== $entry->getSize()) { throw new RuntimeException('Workshop package could not be read completely.'); }
            $files[$path] = $body;
        }
        if (!$files) { throw new RuntimeException('Workshop package is empty.'); }
        return $files;
    }
}
