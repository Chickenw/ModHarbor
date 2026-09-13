<?php

namespace GameNest\GameNestModManager\Providers;

use GameNest\GameNestModManager\Contracts\VersionedDownloadProvider;
use GameNest\GameNestModManager\Services\{DiscoveryQuery, DiscoveryResult, SourceContext};
use RuntimeException;

/** Public API used by the current 7DaysToDieMods website (September 2026). */
class SevenDaysModsProvider extends AbstractCatalogProvider implements VersionedDownloadProvider, \GameNest\GameNestModManager\Contracts\VersionListingProvider
{
    private const API = 'https://api.7daystodiemods.com/v1/';
    public function key(): string { return '7daystodiemods'; }
    public function name(): string { return '7DaysToDieMods.com'; }
    private function api(string $path, array $query = [], string $method = 'GET'): ?array
    {
        return $this->json(self::API . $path, $query, [], $method);
    }
    public function discover(DiscoveryQuery $query, SourceContext $source): DiscoveryResult
    {
        $this->context($source); $size = max(1, min(50, $query->perPage)); $page = max(1, $query->page);
        $params = ['q' => $query->search, 'page' => $page, 'limit' => $size,
            'sort' => in_array($query->sort, ['newest', 'updated', 'likes', 'downloads', 'views'], true) ? $query->sort : 'newest'];
        foreach (['category', 'game_version', 'server_side'] as $key) {
            $values = (array) $source->value($key, []);
            if ($values) { $params[$key] = implode(',', array_map(fn ($v) => $this->slug($v), $values)); }
        }
        $data = $this->api('mods', $params);
        if (!is_array($data['items'] ?? null) || !isset($data['total'], $data['page'], $data['limit'])) { throw new RuntimeException('7DaysToDieMods returned an invalid catalog.'); }
        return DiscoveryResult::page(array_map(fn ($p) => $this->normalize($p), $data['items']), (int) $data['total'],
            (int) $data['page'], (int) $data['limit']);
    }
    public function get(string|int $id, ?SourceContext $source = null): ?array
    {
        $source = $this->context($source); $id = $this->slug($id);
        $data = $this->api('mods/' . $id);
        if ($data === null) { return null; }
        if (($data['slug'] ?? '') !== $id && ($data['id'] ?? '') !== $id) { throw new RuntimeException('7DaysToDieMods returned another mod.'); }
        if (in_array($data['server_side']['slug'] ?? '', ['client-only', 'client-side-only'], true)) { throw new RuntimeException('This package is client-only.'); }
        foreach (['category' => 'categories', 'game_version' => 'game_versions'] as $key => $field) {
            $wanted = (array) $source->value($key, []);
            if ($wanted && !array_intersect($wanted, array_column($data[$field] ?? [], 'slug'))) { throw new RuntimeException('Package does not match configured source metadata.'); }
        }
        $sides = (array) $source->value('server_side', []);
        if ($sides && !in_array($data['server_side']['slug'] ?? '', $sides, true)) { throw new RuntimeException('Package does not match configured server compatibility.'); }
        $mod = $this->normalize($data);
        $candidates = array_values(array_filter($data['mod_files'] ?? [], fn ($f) => ($f['file_type'] ?? '') === 'main' && ($f['scan_status'] ?? '') === 'clean'));
        if (count($candidates) === 1) { $mod['latest_file'] = $this->normalizeFile($candidates[0]); }
        else { $mod['unavailable_reason'] = 'Choose the appropriate package on the provider website and use Upload File.'; }
        // Do not silently install a package with unresolved upstream requirements.
        if (!empty($data['dependencies'])) { $mod['unavailable_reason'] = 'This package lists dependencies that require review on the provider website.'; $mod['latest_file'] = []; }
        return $mod;
    }
    public function versions(string|int $id, SourceContext $source): array
    {
        $mod = $this->get($id, $source);
        if (!$mod) { return []; }
        $data = $this->api('mods/' . $this->slug($id));
        if (!empty($data['dependencies'])) { throw new RuntimeException('Review provider dependencies before selecting a file.'); }
        $files = array_values(array_filter($data['mod_files'] ?? [], fn ($f) => ($f['scan_status'] ?? '') === 'clean' && ($f['file_type'] ?? '') === 'main'));
        return array_map(fn ($f) => $this->normalizeFile($f), array_slice($files, 0, 100));
    }
    public function package(string|int $id, string|int|null $fileId, SourceContext $source): array
    {
        $mod = $this->get($id, $source);
        if (!$mod) { throw new RuntimeException('7DaysToDieMods package is unavailable.'); }
        $file = $mod['latest_file'];
        if ($fileId !== null && (string) $fileId !== (string) ($file['id'] ?? '')) {
            $choices = array_values(array_filter($this->versions($id, $source), fn ($f) => $f['id'] === (string) $fileId));
            if (count($choices) !== 1) { throw new RuntimeException('Requested 7DaysToDieMods file is no longer available.'); }
            $file = $choices[0];
        } elseif (isset($mod['unavailable_reason']) || empty($file['id'])) {
            throw new RuntimeException('7DaysToDieMods package needs manual selection on the provider website.');
        }
        $request = $this->api('mods/' . $this->slug($mod['upstream_id']) . '/files/' . $file['id'] . '/download', [], 'POST');
        $token = $request['token'] ?? '';
        $wait = $request['wait_ms'] ?? null;
        if (!is_string($token) || $token === '' || strlen($token) > 4096 || !is_numeric($wait) || $wait < 0 || $wait > 30000) {
            throw new RuntimeException('7DaysToDieMods download authorization is unavailable.');
        }
        $this->waitForDownload((int) ceil($wait));
        $claim = $this->api('mods/downloads/' . rawurlencode($token));
        $file['download_url'] = $this->url($claim['url'] ?? '');
        if ($file['download_url'] === '') { throw new RuntimeException('7DaysToDieMods did not authorize a package URL.'); }
        return $file;
    }
    protected function waitForDownload(int $milliseconds): void { if ($milliseconds > 0) { usleep($milliseconds * 1000); } }
    private function normalize(array $p): array
    {
        $slug = $this->slug($p['slug'] ?? '');
        return $this->item($slug, (string) ($p['title'] ?? ''), ['upstream_id' => $this->slug($p['id'] ?? ''),
            'summary' => $this->text($p['summary'] ?? ''), 'logo' => $this->url($p['thumbnail']['url'] ?? ''),
            'author' => $this->text($p['author']['display_name'] ?? ''), 'profile_url' => 'https://7daystodiemods.com/mods/' . $slug,
            'downloads' => (int) ($p['download_count'] ?? 0), 'date_updated' => strtotime($p['updated_at'] ?? '') ?: 0]);
    }
    private function normalizeFile(array $f): array
    {
        return $this->file($this->slug($f['id'] ?? ''), (string) ($f['filename'] ?? ''), (string) ($f['mod_version'] ?? ''), ['filesize' => (int) ($f['size'] ?? 0)]);
    }
}
