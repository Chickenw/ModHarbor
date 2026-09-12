<?php

namespace GameNest\GameNestModManager\Providers;

use GameNest\GameNestModManager\Contracts\VersionedDownloadProvider;
use GameNest\GameNestModManager\Services\{DiscoveryQuery, DiscoveryResult, SourceContext};
use RuntimeException;
use GameNest\GameNestModManager\Contracts\VersionListingProvider;

class ModrinthProvider extends AbstractCatalogProvider implements VersionedDownloadProvider, VersionListingProvider
{
    private const API = 'https://api.modrinth.com/v2/';
    public function versions(string|int $id, SourceContext $source): array
    {
        $mod = $this->get($id, $source);
        if (!$mod) { return []; }
        $versions = $this->api('project/' . $this->slug($mod['id']) . '/version', $this->versionQuery($source));
        if (!is_array($versions) || !array_is_list($versions)) { throw new RuntimeException('Invalid version metadata.'); }
        $versions = array_values(array_filter($versions, fn ($v) => $this->compatible($v, $source)));
        usort($versions, fn ($a, $b) => strcmp($b['date_published'] ?? '', $a['date_published'] ?? ''));
        return array_map(fn ($v) => $this->normalizeVersion($v, $mod['id']), array_slice($versions, 0, 100));
    }

    public function key(): string { return 'modrinth'; }
    public function name(): string { return 'Modrinth'; }
    private function api(string $path, array $query = []): ?array
    {
        $token = $this->setting('token');
        return $this->json(self::API . $path, $query, $token === '' ? [] : ['Authorization' => $token]);
    }
    public function discover(DiscoveryQuery $query, SourceContext $source): DiscoveryResult
    {
        $this->context($source);
        $facets = [['project_type:' . $this->slug($source->value('project_type', 'mod'))]];
        foreach (['loaders' => 'categories', 'game_versions' => 'versions', 'categories' => 'categories'] as $key => $facet) {
            $values = (array) $source->value($key, []);
            if ($values !== []) { $facets[] = array_map(fn ($v) => $facet . ':' . (string) $v, $values); }
        }
        $sort = in_array($query->sort, ['relevance', 'downloads', 'follows', 'newest', 'updated'], true) ? $query->sort : 'relevance';
        $size = max(1, min(100, $query->perPage)); $page = max(1, $query->page);
        $data = $this->api('search', ['query' => $query->search, 'index' => $sort,
            'offset' => ($page - 1) * $size, 'limit' => $size, 'facets' => json_encode($facets, JSON_THROW_ON_ERROR)]);
        if (!is_array($data['hits'] ?? null) || !isset($data['total_hits'])) { throw new RuntimeException('Modrinth returned an invalid catalog.'); }
        return DiscoveryResult::page(array_map(fn ($p) => $this->normalize($p), $data['hits']), (int) $data['total_hits'], $page, $size);
    }
    public function get(string|int $id, ?SourceContext $source = null): ?array
    {
        $source = $this->context($source);
        $project = $this->api('project/' . $this->slug($id));
        if ($project === null) { return null; }
        if (($project['project_type'] ?? '') !== $source->value('project_type', 'mod')) { throw new RuntimeException('Modrinth project type does not match this source.'); }
        if (($project['server_side'] ?? '') === 'unsupported') { throw new RuntimeException('This Modrinth project does not support servers.'); }
        $mod = $this->normalize($project);
        $versions = $this->api('project/' . $this->slug($mod['id']) . '/version', $this->versionQuery($source));
        if (!is_array($versions) || !array_is_list($versions)) { throw new RuntimeException('Modrinth returned invalid versions.'); }
        $versions = array_values(array_filter($versions, fn ($v) => $this->compatible($v, $source)));
        usort($versions, fn ($a, $b) => strcmp((string) ($b['date_published'] ?? ''), (string) ($a['date_published'] ?? '')));
        if ($versions !== []) { $mod['latest_file'] = $this->normalizeVersion($versions[0], $mod['id']); }
        $mod['dependencies'] = $mod['latest_file']['dependencies'] ?? [];
        return $mod;
    }
    public function package(string|int $id, string|int|null $fileId, SourceContext $source): array
    {
        $mod = $this->get($id, $source);
        if (!$mod) { throw new RuntimeException('Modrinth project is unavailable.'); }
        if ($fileId === null) { $file = $mod['latest_file']; }
        else {
            $version = $this->api('version/' . $this->slug($fileId));
            if (!$version || !$this->compatible($version, $source)) { throw new RuntimeException('Requested Modrinth version is unavailable or incompatible.'); }
            $file = $this->normalizeVersion($version, $mod['id']);
        }
        if (empty($file['download_url'])) { throw new RuntimeException('No compatible Modrinth package is available.'); }
        return $file;
    }
    private function versionQuery(SourceContext $source): array
    {
        $query = [];
        foreach (['loaders', 'game_versions'] as $key) {
            $values = (array) $source->value($key, []);
            if ($values !== []) { $query[$key] = json_encode(array_values($values), JSON_THROW_ON_ERROR); }
        }
        return $query;
    }
    private function compatible(array $version, SourceContext $source): bool
    {
        if (!$source->value('allow_prerelease', false) && ($version['version_type'] ?? '') !== 'release') { return false; }
        foreach (['loaders', 'game_versions'] as $key) {
            $wanted = (array) $source->value($key, []);
            if ($wanted && !array_intersect($wanted, (array) ($version[$key] ?? []))) { return false; }
        }
        return true;
    }
    private function normalize(array $p): array
    {
        $id = $this->slug($p['project_id'] ?? $p['id'] ?? '');
        return $this->item($id, (string) ($p['title'] ?? ''), ['summary' => $this->text($p['description'] ?? ''),
            'author' => $this->text($p['author'] ?? ''), 'logo' => $this->url($p['icon_url'] ?? ''),
            'profile_url' => 'https://modrinth.com/project/' . $id, 'downloads' => (int) ($p['downloads'] ?? 0),
            'date_updated' => strtotime($p['updated'] ?? $p['date_modified'] ?? '') ?: 0]);
    }
    private function normalizeVersion(array $v, string $project): array
    {
        if (($v['project_id'] ?? '') !== $project) { throw new RuntimeException('Modrinth version belongs to another project.'); }
        $files = array_values(array_filter((array) ($v['files'] ?? []), fn ($f) => empty($f['file_type'])));
        $primary = array_values(array_filter($files, fn ($f) => !empty($f['primary'])));
        if (count($primary) === 1) { $f = $primary[0]; }
        elseif (count($files) === 1) { $f = $files[0]; }
        else { throw new RuntimeException('Modrinth version has no unambiguous primary package.'); }
        $deps = [];
        foreach ((array) ($v['dependencies'] ?? []) as $d) {
            $type = $d['dependency_type'] ?? '';
            if ($type === 'embedded') { continue; }
            if (!in_array($type, ['required', 'optional', 'incompatible'], true)) { throw new RuntimeException('Unknown Modrinth dependency relationship.'); }
            $depId = $d['project_id'] ?? '';
            if (!$depId && !empty($d['version_id'])) {
                $dep = $this->api('version/' . $this->slug($d['version_id']));
                $depId = $dep['project_id'] ?? '';
            }
            if (!$depId) {
                if ($type === 'required') { throw new RuntimeException('Required Modrinth file dependency needs manual installation.'); }
                continue;
            }
            $deps[] = ['id' => $this->slug($depId), 'provider' => $this->key(),
                'type' => $type === 'incompatible' ? 'conflict' : $type, 'file_id' => (string) ($d['version_id'] ?? '')];
        }
        return $this->file($this->slug($v['id'] ?? ''), (string) ($f['filename'] ?? ''), (string) ($v['version_number'] ?? ''),
            ['download_url' => $this->url($f['url'] ?? ''), 'filesize' => (int) ($f['size'] ?? 0),
                'hashes' => array_intersect_key((array) ($f['hashes'] ?? []), array_flip(['sha1', 'sha512'])), 'dependencies' => $deps]);
    }
}
