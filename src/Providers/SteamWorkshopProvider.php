<?php

namespace GameNest\GameNestModManager\Providers;

use GameNest\GameNestModManager\Services\{DiscoveryQuery, DiscoveryResult, SourceContext};
use RuntimeException;

class SteamWorkshopProvider extends AbstractCatalogProvider
{
    public function key(): string { return 'steam-workshop'; }
    public function name(): string { return 'Steam Workshop'; }
    public function discover(DiscoveryQuery $query, SourceContext $source): DiscoveryResult
    {
        $this->context($source); $app = $this->numeric($source->require('app_id'));
        $page = max(1, $query->page); $size = max(1, min(50, $query->perPage));
        if ($page > 1000) { throw new RuntimeException('Steam Workshop page limit reached. Narrow the search.'); }
        $input = ['appid' => (int) $app, 'page' => $page, 'numperpage' => $size,
            'query_type' => ['popular' => 0, 'newest' => 1, 'updated' => 21][$query->sort] ?? 0,
            'search_text' => $query->search, 'return_metadata' => true, 'return_short_description' => true,
            'return_previews' => true, 'return_children' => true, 'filetype' => 0,
            'requiredtags' => array_values((array) $source->value('tags', [])), 'match_all_tags' => true];
        $data = $this->json('https://api.steampowered.com/IPublishedFileService/QueryFiles/v1/',
            ['key' => $this->setting('api_key', true), 'input_json' => json_encode($input, JSON_THROW_ON_ERROR)]);
        $response = $data['response'] ?? [];
        if (!is_array($response['publishedfiledetails'] ?? null) || !isset($response['total'])) { throw new RuntimeException('Steam returned an invalid catalog.'); }
        $items = [];
        foreach ($response['publishedfiledetails'] as $p) {
            if (($p['result'] ?? 1) === 1 && (string) ($p['consumer_appid'] ?? $p['consumer_app_id'] ?? '') === $app) { $items[] = $this->normalize($p, $source); }
        }
        return DiscoveryResult::page($items, (int) $response['total'], $page, $size);
    }
    public function get(string|int $id, ?SourceContext $source = null): ?array
    {
        $source = $this->context($source); $id = $this->numeric($id);
        $data = $this->json('https://api.steampowered.com/IPublishedFileService/GetDetails/v1/',
            ['key' => $this->setting('api_key', true), 'input_json' => json_encode([
                'publishedfileids' => [$id], 'includechildren' => true, 'includetags' => true,
            ], JSON_THROW_ON_ERROR)]);
        $p = $data['response']['publishedfiledetails'][0] ?? null;
        if (!is_array($p)) { throw new RuntimeException('Steam returned invalid item details.'); }
        if (in_array($p['result'] ?? 0, [9, 15], true)) { return null; }
        if (($p['result'] ?? 0) !== 1 || (string) ($p['publishedfileid'] ?? '') !== $id) { throw new RuntimeException('Steam item lookup failed.'); }
        return $this->normalize($p, $source);
    }
    private function normalize(array $p, SourceContext $source): array
    {
        if ((string) ($p['consumer_appid'] ?? $p['consumer_app_id'] ?? '') !== $this->numeric($source->require('app_id'))) { throw new RuntimeException('Workshop item belongs to another app.'); }
        if (!empty($p['banned']) || (isset($p['visibility']) && $p['visibility'] !== 0) || ($p['file_type'] ?? $p['filetype'] ?? 0) !== 0) { throw new RuntimeException('Workshop item is not a public downloadable item.'); }
        if ((int) ($p['num_children'] ?? 0) > count($p['children'] ?? [])) { throw new RuntimeException('Steam did not return the complete Workshop dependency list.'); }
        $id = $this->numeric($p['publishedfileid'] ?? '');
        $deps = [];
        foreach ($p['children'] ?? [] as $child) { $deps[] = ['provider' => $this->key(), 'id' => $this->numeric($child['publishedfileid'] ?? ''), 'type' => 'required']; }
        return $this->item($id, (string) ($p['title'] ?? ''), ['summary' => $this->text($p['short_description'] ?? $p['description'] ?? ''),
            'logo' => $this->url($p['preview_url'] ?? ''), 'author' => $this->text($p['creator'] ?? ''),
            'profile_url' => 'https://steamcommunity.com/sharedfiles/filedetails/?id=' . $id,
            'subscribers' => (int) ($p['subscriptions'] ?? 0), 'date_updated' => (int) ($p['time_updated'] ?? 0),
            'dependencies' => $deps, 'latest_file' => ['id' => $id . ':' . (int) ($p['time_updated'] ?? 0),
                'version' => (string) ($p['time_updated'] ?? 0), 'filesize' => (int) ($p['file_size'] ?? 0), 'deployment' => 'steamcmd']]);
    }
}
