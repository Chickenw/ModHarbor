<?php

namespace GameNest\GameNestModManager\Providers;

use GameNest\GameNestModManager\Contracts\VersionedDownloadProvider;
use GameNest\GameNestModManager\Services\{DiscoveryQuery, DiscoveryResult, SourceContext};
use RuntimeException;
use GameNest\GameNestModManager\Contracts\VersionListingProvider;

class CurseForgeProvider extends AbstractCatalogProvider implements VersionedDownloadProvider, VersionListingProvider
{
    public function versions(string|int $id, SourceContext $source): array
    {
        $mod = $this->get($id, $source);
        if (!$mod || isset($mod['unavailable_reason'])) { return []; }
        $data = $this->api('mods/' . $this->numeric($id) . '/files', $this->filters($source, false) + ['pageSize' => 50, 'index' => 0]);
        $files = array_values(array_filter($data['data'] ?? [], fn ($f) => $this->compatible($f, $source)));
        return array_map(fn ($f) => $this->normalizeFile($f, (string) $id), $files);
    }

    public function key(): string { return 'curseforge'; }
    public function name(): string { return 'CurseForge'; }
    private function api(string $path, array $query = []): ?array
    {
        return $this->json('https://api.curseforge.com/v1/' . $path, $query, ['x-api-key' => $this->setting('api_key', true)]);
    }
    public function discover(DiscoveryQuery $query, SourceContext $source): DiscoveryResult
    {
        $source = $this->context($source); $size = max(1, min(50, $query->perPage)); $page = max(1, $query->page);
        if ($page * $size > 10000) { throw new RuntimeException('CurseForge limits searches to 10,000 results.'); }
        $params = ['gameId' => $this->numeric($source->require('game_id')), 'searchFilter' => $query->search,
            'sortField' => ['featured' => 1, 'popular' => 2, 'updated' => 3, 'name' => 4, 'author' => 5, 'downloads' => 6, 'category' => 7, 'game_version' => 8, 'early_access' => 9, 'featured_released' => 10, 'newest' => 11, 'rating' => 12][$query->sort] ?? 2,
            'sortOrder' => in_array($query->sort, ['name', 'author', 'category'], true) ? 'asc' : 'desc', 'index' => ($page - 1) * $size, 'pageSize' => $size] + $this->filters($source);
        $data = $this->api('mods/search', $params);
        if (!is_array($data['data'] ?? null) || !isset($data['pagination']['totalCount'])) { throw new RuntimeException('CurseForge returned an invalid catalog.'); }
        return DiscoveryResult::page(array_map(fn ($p) => $this->normalize($p, $source), $data['data']),
            min(10000, (int) $data['pagination']['totalCount']), $page, $size);
    }
    public function get(string|int $id, ?SourceContext $source = null): ?array
    {
        $source = $this->context($source); $id = $this->numeric($id);
        $data = $this->api('mods/' . $id);
        if ($data === null) { return null; }
        $mod = $this->normalize($data['data'] ?? [], $source);
        if ($mod['id'] !== $id) { throw new RuntimeException('CurseForge returned another mod.'); }
        if (($data['data']['allowModDistribution'] ?? null) === false) { $mod['unavailable_reason'] = 'The author disabled third-party downloads.'; return $mod; }
        $files = $this->api('mods/' . $id . '/files', $this->filters($source, false) + ['pageSize' => 50, 'index' => 0]);
        if (!is_array($files['data'] ?? null)) { throw new RuntimeException('CurseForge returned invalid files.'); }
        $list = array_values(array_filter($files['data'], fn ($f) => $this->compatible($f, $source)));
        usort($list, fn ($a, $b) => strcmp((string) ($b['fileDate'] ?? ''), (string) ($a['fileDate'] ?? '')));
        if ($list) { $mod['latest_file'] = $this->normalizeFile($list[0], $id); }
        $mod['dependencies'] = $mod['latest_file']['dependencies'] ?? [];
        return $mod;
    }
    public function package(string|int $id, string|int|null $fileId, SourceContext $source): array
    {
        $mod = $this->get($id, $source);
        if (!$mod || isset($mod['unavailable_reason'])) { throw new RuntimeException('CurseForge does not permit this package download.'); }
        $file = $mod['latest_file'];
        if ($fileId !== null) {
            $data = $this->api('mods/' . $this->numeric($id) . '/files/' . $this->numeric($fileId));
            if (!$data || !$this->compatible($data['data'] ?? [], $source)) { throw new RuntimeException('Requested CurseForge file is unavailable or incompatible.'); }
            $file = $this->normalizeFile($data['data'], (string) $id);
        }
        if (empty($file['id'])) { throw new RuntimeException('No compatible CurseForge package.'); }
        if ($file['download_url'] === '') {
            $data = $this->api('mods/' . $this->numeric($id) . '/files/' . $file['id'] . '/download-url');
            $file['download_url'] = $this->url($data['data'] ?? '');
        }
        if ($file['download_url'] === '') { throw new RuntimeException('CurseForge did not authorize a direct download. Use the author’s supported download method.'); }
        return $file;
    }
    private function filters(SourceContext $source, bool $catalog = true): array
    {
        $params = [];
        foreach (($catalog ? ['category_id' => 'categoryId', 'class_id' => 'classId'] : []) + ['game_version' => 'gameVersion', 'mod_loader_type' => 'modLoaderType'] as $key => $api) {
            $value = $source->value($key);
            if ($value !== null && $value !== '') { $params[$api] = $value; }
        }
        return $params;
    }
    private function compatible(array $f, SourceContext $source): bool
    {
        if (empty($f['isAvailable']) || (!$source->value('allow_prerelease', false) && ($f['releaseType'] ?? 0) !== 1)) { return false; }
        $version = $source->value('game_version', '');
        $loader = (int) $source->value('mod_loader_type', 0);
        $loaderName = [1 => 'Forge', 2 => 'Cauldron', 3 => 'LiteLoader', 4 => 'Fabric', 5 => 'Quilt', 6 => 'NeoForge'][$loader] ?? null;
        if ($loader !== 0 && (!$loaderName || !in_array($loaderName, $f['gameVersions'] ?? [], true))) { return false; }
        return $version === '' || in_array($version, $f['gameVersions'] ?? [], true);
    }
    private function normalize(array $p, SourceContext $source): array
    {
        if ((string) ($p['gameId'] ?? '') !== $this->numeric($source->require('game_id'))) { throw new RuntimeException('CurseForge mod belongs to another game.'); }
        return $this->item($this->numeric($p['id'] ?? ''), (string) ($p['name'] ?? ''), [
            'summary' => $this->text($p['summary'] ?? ''), 'logo' => $this->url($p['logo']['url'] ?? ''),
            'profile_url' => $this->url($p['links']['websiteUrl'] ?? ''), 'author' => $this->text($p['authors'][0]['name'] ?? ''),
            'downloads' => (int) ($p['downloadCount'] ?? 0), 'date_updated' => strtotime($p['dateModified'] ?? '') ?: 0]);
    }
    private function normalizeFile(array $f, string $modId): array
    {
        if ((string) ($f['modId'] ?? '') !== $modId) { throw new RuntimeException('CurseForge file belongs to another mod.'); }
        $deps = [];
        foreach ($f['dependencies'] ?? [] as $d) {
            $type = [1 => 'suggested', 2 => 'optional', 3 => 'required', 4 => 'embedded', 5 => 'conflict', 6 => 'suggested'][$d['relationType'] ?? 0] ?? null;
            if ($type === null) { throw new RuntimeException('Unknown CurseForge dependency relationship.'); }
            if ($type !== 'embedded') { $deps[] = ['provider' => $this->key(), 'id' => $this->numeric($d['modId']), 'type' => $type]; }
        }
        $hashes = [];
        foreach ($f['hashes'] ?? [] as $hash) { if (($hash['algo'] ?? 0) === 1) { $hashes['sha1'] = $hash['value']; } }
        return $this->file($this->numeric($f['id'] ?? ''), (string) ($f['fileName'] ?? ''), (string) ($f['displayName'] ?? ''),
            ['download_url' => $this->url($f['downloadUrl'] ?? ''), 'filesize' => (int) ($f['fileLength'] ?? 0), 'hashes' => $hashes, 'dependencies' => $deps]);
    }
}
