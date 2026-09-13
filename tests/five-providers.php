<?php

use GameNest\GameNestModManager\Providers\{ModrinthProvider, CurseForgeProvider, NexusModsProvider, SevenDaysModsProvider, SteamWorkshopProvider};
use GameNest\GameNestModManager\Services\{ProviderHttpClient, DiscoveryQuery, SourceContext, SourceRegistry, ProviderContext, AdapterRegistry, GameDefinition, ConfiguredDriverResolver, ConfiguredLifecycleDriver, SteamWorkshopDriverFactory, SteamCmdDownloader, FileTransaction, SafeRemoteDownloader};
use GameNest\GameNestModManager\Adapters\ConfiguredGameAdapter;
use GameNest\GameNestModManager\Contracts\ConfiguredDownloadProvider;

class FiveHttp extends ProviderHttpClient
{
    public array $requests = [];
    public function __construct(public \Closure $handler) {}
    public function send(string $method, string $url, array $parameters = [], array $headers = []): array
    {
        $this->requests[] = compact('method', 'url', 'parameters', 'headers');
        $result = ($this->handler)($url, $parameters, $headers, $method);
        return is_array($result) && isset($result['_status'])
            ? ['status' => $result['_status'], 'body' => $result['_body'] ?? '{}']
            : ['status' => 200, 'body' => json_encode($result, JSON_THROW_ON_ERROR)];
    }
}
function fiveTest(string $name, callable $test): void
{
    $GLOBALS['tests']['Five providers: ' . $name] = static function () use ($test) {
        $old = $GLOBALS['configOverrides'] ?? []; $services = $GLOBALS['services'] ?? [];
        $GLOBALS['configOverrides'] = ['gamenest-mod-manager.providers' => (require __DIR__ . '/../config/gamenest-mod-manager.php')['providers']];
        try { $test(); } finally { $GLOBALS['configOverrides'] = $old; $GLOBALS['services'] = $services; }
    };
}
function fiveSource(string $provider, array $meta = []): SourceContext { return new SourceContext('fixture-game', 'Fixture Game', $provider, $meta); }
function fiveAuth(): void
{
    foreach (['nexus', 'curseforge', 'steam-workshop'] as $key) { $GLOBALS['configOverrides']['gamenest-mod-manager.' . $key . '.api_key'] = 'PRIVATE-TEST-KEY'; }
}
function fiveVersion(array $overrides = []): array
{
    return array_replace(['id' => 'v1', 'project_id' => 'project1', 'version_number' => '1.0', 'version_type' => 'release',
        'date_published' => '2026-09-01T00:00:00Z', 'loaders' => ['fabric'], 'game_versions' => ['1.21.1'], 'dependencies' => [],
        'files' => [['filename' => 'example.jar', 'url' => 'https://cdn.modrinth.com/example.jar', 'size' => 7, 'primary' => true, 'hashes' => ['sha512' => hash('sha512', 'package')]]]], $overrides);
}
function fiveModrinth(?\Closure $override = null): array
{
    $http = new FiveHttp(function ($url, $params) use ($override) {
        if ($override && ($result = $override($url, $params)) !== null) { return $result; }
        if (str_ends_with($url, '/search')) { return ['hits' => [['project_id' => 'project1', 'title' => 'Example', 'description' => 'Summary']], 'total_hits' => 1]; }
        if (str_ends_with($url, '/project/project1/version')) { return [fiveVersion()]; }
        if (str_contains($url, '/version/')) { return fiveVersion(['id' => basename($url)]); }
        return ['id' => 'project1', 'slug' => 'example', 'title' => 'Example', 'description' => 'Summary', 'project_type' => 'mod', 'server_side' => 'required'];
    });
    return [new ModrinthProvider($http), $http, fiveSource('modrinth', ['loaders' => ['fabric'], 'game_versions' => ['1.21.1']])];
}
function fiveCurseFile(array $overrides = []): array
{
    return array_replace(['id' => 20, 'modId' => 10, 'fileName' => 'example.jar', 'displayName' => '1.0', 'fileLength' => 7,
        'isAvailable' => true, 'releaseType' => 1, 'gameVersions' => ['1.21.1', 'Fabric'], 'fileDate' => '2026-09-01',
        'downloadUrl' => null, 'dependencies' => [], 'hashes' => [['algo' => 1, 'value' => sha1('package')]]], $overrides);
}
function fiveCurse(?\Closure $override = null): array
{
    fiveAuth();
    $http = new FiveHttp(function ($url, $params) use ($override) {
        if ($override && ($result = $override($url, $params)) !== null) { return $result; }
        $mod = ['id' => 10, 'gameId' => 432, 'name' => 'Example', 'summary' => 'Summary', 'allowModDistribution' => true];
        if (str_ends_with($url, '/mods/search')) { return ['data' => [$mod], 'pagination' => ['totalCount' => 1]]; }
        if (str_ends_with($url, '/download-url')) { return ['data' => 'https://edge.forgecdn.net/example.jar']; }
        if (str_ends_with($url, '/files')) { return ['data' => [fiveCurseFile()]]; }
        if (str_contains($url, '/files/')) { return ['data' => fiveCurseFile()]; }
        return ['data' => $mod];
    });
    return [new CurseForgeProvider($http), $http, fiveSource('curseforge', ['game_id' => 432, 'game_version' => '1.21.1', 'mod_loader_type' => 4])];
}
function fiveNexus(?\Closure $override = null): array
{
    fiveAuth();
    $http = new FiveHttp(function ($url, $params, $headers, $method) use ($override) {
        if ($override && ($result = $override($url, $params, $headers, $method)) !== null) { return $result; }

        $mod = ['mod_id' => 10, 'domain_name' => 'examplegame', 'name' => 'Example', 'summary' => 'Summary'];
        $file = ['file_id' => 20, 'file_name' => 'example.zip', 'version' => '1.0', 'category_id' => 1];

        if ($url === 'https://api.nexusmods.com/v2/graphql') {
            $search = '';
            foreach (($params['variables']['filter']['filter'] ?? []) as $filter) {
                if (isset($filter['name']['value'])) {
                    $search = (string) $filter['name']['value'];
                }
            }

            $nodes = stripos('Example Summary', $search) !== false || $search === ''
                ? [[
                    'modId' => 10,
                    'name' => 'Example',
                    'summary' => 'Summary',
                    'author' => 'Fixture Author',
                    'pictureUrl' => 'https://staticdelivery.nexusmods.com/example.jpg',
                    'downloads' => 123,
                    'updatedAt' => '2026-09-01T00:00:00Z',
                    'createdAt' => '2026-08-01T00:00:00Z',
                    'game' => [
                        'domainName' => 'examplegame',
                        'name' => 'Example Game',
                        'id' => 1,
                    ],
                ]]
                : [];

            return [
                'data' => [
                    'mods' => [
                        'totalCount' => count($nodes),
                        'nodes' => $nodes,
                    ],
                ],
            ];
        }

        if (str_ends_with($url, '/download_link.json')) { return [['URI' => 'https://cdn.nexusmods.com/example.zip?signature=PRIVATE-SIGNED-LINK']]; }
        if (str_ends_with($url, '/files.json')) { return ['files' => [$file]]; }
        if (str_contains($url, '/files/')) { return $file; }

        return $mod;
    });

    return [new NexusModsProvider($http), $http, fiveSource('nexus', ['domain' => 'examplegame'])];
}
class FiveSevenProvider extends SevenDaysModsProvider
{
    public int $waited = 0;
    protected function waitForDownload(int $milliseconds): void { $this->waited += $milliseconds; }
}
function fiveSeven(?\Closure $override = null): array
{
    $http = new FiveHttp(function ($url) use ($override) {
        if ($override && ($result = $override($url)) !== null) { return $result; }
        $mod = ['id' => 'UPSTREAM1', 'slug' => 'example', 'title' => 'Example', 'summary' => 'Summary',
            'mod_files' => [['id' => 'FILE1', 'filename' => 'example.zip', 'mod_version' => '1.0', 'file_type' => 'main', 'scan_status' => 'clean']]];
        if (str_ends_with($url, '/download')) { return ['token' => 'PRIVATE-TOKEN', 'wait_ms' => 5000]; }
        if (str_contains($url, '/downloads/')) { return ['url' => 'https://downloads.7daystodiemods.com/example.zip?signature=PRIVATE']; }
        if (str_ends_with($url, '/mods')) { return ['items' => [$mod], 'total' => 1, 'page' => 1, 'limit' => 20]; }
        return $mod;
    });
    return [new FiveSevenProvider($http), $http, fiveSource('7daystodiemods')];
}
function fiveSteam(?\Closure $override = null): array
{
    fiveAuth();
    $http = new FiveHttp(function ($url, $params) use ($override) {
        if ($override && ($result = $override($url, $params)) !== null) { return $result; }
        return ['response' => ['total' => 1, 'publishedfiledetails' => [['result' => 1, 'publishedfileid' => '123', 'consumer_appid' => 456,
            'title' => 'Example', 'time_updated' => 1000, 'filetype' => 0, 'visibility' => 0, 'num_children' => 0, 'children' => []]]]];
    });
    return [new SteamWorkshopProvider($http), $http, fiveSource('steam-workshop', ['app_id' => 456])];
}

fiveTest('expanded catalog sort menus map to native provider requests', function () {
    foreach (['featured' => 1, 'popular' => 2, 'updated' => 3, 'name' => 4, 'author' => 5, 'downloads' => 6, 'category' => 7, 'game_version' => 8, 'early_access' => 9, 'featured_released' => 10, 'newest' => 11, 'rating' => 12] as $sort => $field) {
        [$provider, $http, $source] = fiveCurse();
        $provider->discover(new DiscoveryQuery(sort: $sort, page: 2), $source);
        $params = $http->requests[0]['parameters'];
        check($params['sortField'] === $field && $params['index'] === 24, 'CurseForge sort/pagination mismatch: ' . $sort);
        check($params['sortOrder'] === (in_array($sort, ['name', 'author', 'category'], true) ? 'asc' : 'desc'), 'CurseForge direction mismatch');
    }
    foreach (['popular' => 0, 'newest' => 1, 'updated' => 21, 'subscribers' => 9, 'votes' => 11, 'relevance' => 12, 'trend_today' => 3, 'trend_week' => 3] as $sort => $field) {
        [$provider, $http, $source] = fiveSteam();
        $provider->discover(new DiscoveryQuery(sort: $sort, page: 2), $source);
        $params = json_decode($http->requests[0]['parameters']['input_json'], true, 512, JSON_THROW_ON_ERROR);
        check($params['query_type'] === $field && $params['page'] === 2 && $params['appid'] === 456, 'Steam sort/scope mismatch: ' . $sort);
        if (str_starts_with($sort, 'trend_')) {
            check($params['days'] === ($sort === 'trend_today' ? 1 : 7) && $params['include_recent_votes_only'], 'Steam trend range mismatch');
        } else { check(!isset($params['days']), 'Trend range leaked to a different Steam sort'); }
    }
    foreach (['newest', 'updated', 'likes', 'downloads', 'views'] as $sort) {
        [$provider, $http, $source] = fiveSeven();
        $provider->discover(new DiscoveryQuery(sort: $sort), $source);
        check($http->requests[0]['parameters']['sort'] === $sort, '7Days sort mismatch');
    }
    foreach (['updated' => 'last-updated', 'newest' => 'newest', 'downloads' => 'most-downloaded', 'rating' => 'top-rated'] as $sort => $field) {
        $http = new FiveHttp(fn () => ['count' => 0, 'results' => []]);
        $provider = new \GameNest\GameNestModManager\Providers\ThunderstoreProvider($http);
        $provider->discover(new DiscoveryQuery(sort: $sort, page: 2), fiveSource('thunderstore', ['community' => 'valheim']));
        check($http->requests[0]['parameters']['ordering'] === $field && $http->requests[0]['parameters']['page'] === 2, 'Thunderstore sort mismatch');
    }
});

fiveTest('Nexus expanded sorts and publication/update windows preserve game scope', function () {
    foreach (['endorsements' => 'endorsements', 'unique_downloads' => 'uniqueDownloads', 'relevance' => 'relevance', 'size' => 'size', 'last_comment' => 'lastComment'] as $sort => $field) {
        [$provider, $http, $source] = fiveNexus();
        $provider->discover(new DiscoveryQuery(sort: $sort), $source);
        check($http->requests[0]['parameters']['variables']['sort'] === [[$field => ['direction' => 'DESC']]], 'Nexus sort mismatch');
    }
    foreach (['7d' => 7, '14d' => 14, '28d' => 28, '1y' => 365] as $period => $days) {
        [$provider, $http, $source] = fiveNexus();
        $before = time() - $days * 86400;
        $provider->discover(new DiscoveryQuery(filters: ['published_period' => $period, 'updated_period' => $period]), $source);
        $filters = $http->requests[0]['parameters']['variables']['filter']['filter'];
        check(isset($filters[0]['gameDomainName']), 'Nexus game scope missing');
        foreach (['createdAt', 'updatedAt'] as $field) {
            $found = array_values(array_filter($filters, fn ($filter) => isset($filter[$field])));
            check(count($found) === 1 && $found[0][$field]['op'] === 'GTE', 'Nexus native date filter missing');
            $cutoff = strtotime($found[0][$field]['value']);
            check($cutoff >= $before && $cutoff <= time() - $days * 86400, 'Nexus cutoff mismatch');
        }
    }
});

fiveTest('all registrations are discoverable with correct generic or managed lifecycle', function () {
    $registry = new SourceRegistry(new AdapterRegistry, new ProviderContext(new AdapterRegistry));
    $definitions = $registry->definitions();
    $d = game070(); $d['enabled'] = true; $d['sources'] = [];
    foreach (['nexus', '7daystodiemods', 'steam-workshop', 'curseforge', 'modrinth'] as $key) {
        check(isset($definitions[$key]), 'Missing registration ' . $key);
        check($registry->provider($key) instanceof \GameNest\GameNestModManager\Contracts\DiscoverableProvider, 'Missing discover contract');
        $d['sources'][$key] = ['metadata' => []];
    }
    $adapter = new ConfiguredGameAdapter(GameDefinition::validate($d, array_keys($definitions)));
    foreach (array_keys($d['sources']) as $key) {
        check((new ConfiguredDriverResolver)->driverClass($adapter, $key) === ($key === 'steam-workshop' ? SteamWorkshopDriverFactory::class : ConfiguredLifecycleDriver::class), 'Wrong lifecycle for ' . $key);
        check(is_subclass_of($definitions[$key]['class'], ConfiguredDownloadProvider::class) === ($key !== 'steam-workshop'), 'Wrong download contract');
    }
});
fiveTest('source metadata survives portable JSON round-trip without global keys', function () {
    fiveAuth(); $definitions = config('gamenest-mod-manager.providers'); $d = game070();
    foreach (['nexus', '7daystodiemods', 'steam-workshop', 'curseforge', 'modrinth'] as $key) { $d['sources'][$key] = ['metadata' => $definitions[$key]['metadata_example']]; }
    $def = GameDefinition::validate($d, array_keys($definitions));
    check(GameDefinition::fromJson($def->json(), array_keys($definitions))->data() === $def->data(), 'Round-trip changed source metadata');
    check(!str_contains($def->json(), 'PRIVATE-TEST-KEY'), 'Global key exported');
});
fiveTest('portable metadata rejects nested and disguised credential keys', function () {
    foreach (['api_key', 'apiKey', 'TOKEN', 'client-secret', 'password', 'authorization', 'private_key'] as $key) {
        $d = game070(); $d['sources']['github']['metadata'] = ['nested' => [$key => 'PRIVATE']];
        fails(fn () => GameDefinition::validate($d, array_keys(config('gamenest-mod-manager.providers'))), 'Credentials');
    }
});
foreach (['modrinth' => 'fiveModrinth', 'curseforge' => 'fiveCurse', 'nexus' => 'fiveNexus', '7daystodiemods' => 'fiveSeven', 'steam-workshop' => 'fiveSteam'] as $key => $factory) {
    fiveTest($key . ' normalized discovery and get shape', function () use ($key, $factory) {
        [$provider,, $source] = $factory();
        $result = $provider->discover(new DiscoveryQuery, $source);
        check($result->total === 1 && count($result->items) === 1, 'Incorrect discovery page');
        $mod = $provider->get($result->items[0]['id'], $source);
        foreach (['id', 'name', 'provider', 'summary', 'author', 'logo', 'profile_url', 'date_updated', 'latest_file'] as $field) { check(array_key_exists($field, $mod), 'Missing normalized ' . $field); }
        check($mod['provider'] === $key && $mod['latest_file']['id'] !== '', 'Wrong provider/file identity');
        check(!str_contains(json_encode($mod), 'PRIVATE'), 'Credentials in get response');
    });
    fiveTest($key . ' missing and mismatched source rejected before network', function () use ($factory) {
        [$p, $http] = $factory();
        fails(fn () => $p->get('1')); fails(fn () => $p->get('1', fiveSource('wrong')));
        check($http->requests === [], 'Invalid context sent request');
    });
    fiveTest($key . ' upstream failures do not disclose response secrets', function () use ($factory) {
        [$p,, $source] = $factory(fn () => ['_status' => 403, '_body' => 'PRIVATE key=SECRET']);
        try { $p->discover(new DiscoveryQuery, $source); throw new LogicException('Expected rejection'); }
        catch (RuntimeException $e) { check(!str_contains((string) $e, 'PRIVATE') && !str_contains((string) $e, 'SECRET') && $e->getPrevious() === null, 'Secret leaked'); }
    });
}
fiveTest('credentials are global and missing keys fail before network', function () {
    foreach (['fiveNexus', 'fiveCurse', 'fiveSteam'] as $factory) {
        [$p,$http,$source] = $factory();
        $GLOBALS['configOverrides']['gamenest-mod-manager.' . $p->key() . '.api_key'] = '';
        fails(fn () => $p->discover(new DiscoveryQuery, $source), 'global');
        check(!$http->requests, 'Network used without required credential');
    }
});
fiveTest('Modrinth search facets pagination auth and immutable contexts', function () {
    [$p,$http,$source] = fiveModrinth();
    $GLOBALS['configOverrides']['gamenest-mod-manager.modrinth.token'] = 'PRIVATE-TOKEN';
    $p->discover(new DiscoveryQuery('hello', 'downloads', 2, 48), $source);
    $r = $http->requests[0]; $facets = json_decode($r['parameters']['facets'], true);
    check($r['parameters']['offset'] === 48 && $r['parameters']['limit'] === 48 && $r['headers']['Authorization'] === 'PRIVATE-TOKEN', 'Wrong request');
    check(in_array(['categories:fabric'], $facets, true) && in_array(['versions:1.21.1'], $facets, true), 'Missing compatibility facets');
    $p->discover(new DiscoveryQuery, fiveSource('modrinth', ['loaders' => ['forge']]));
    check(!str_contains($http->requests[1]['parameters']['facets'], 'fabric'), 'Cross-game context leaked');
});
fiveTest('Modrinth publication windows are sent before pagination with lifetime download sorting', function () {
    foreach (['7d' => 7, '14d' => 14, '28d' => 28, '1y' => 365] as $period => $days) {
        [$provider, $http, $source] = fiveModrinth();
        $before = time() - $days * 86400;
        $provider->discover(new DiscoveryQuery(sort: 'downloads', page: 2, perPage: 24, filters: ['published_period' => $period]), $source);
        $params = $http->requests[0]['parameters'];
        check($params['index'] === 'downloads' && $params['offset'] === 24, 'Sort or pagination changed');
        $facets = json_decode($params['facets'], true, 512, JSON_THROW_ON_ERROR);
        $date = end($facets)[0];
        check(str_starts_with($date, 'created_timestamp >= '), 'Missing native publication facet');
        $timestamp = (int) substr($date, strlen('created_timestamp >= '));
        check($timestamp >= $before && $timestamp <= time() - $days * 86400, 'Incorrect publication cutoff');
    }
    [$provider, $http, $source] = fiveModrinth();
    $provider->discover(new DiscoveryQuery(filters: ['published_period' => 'all']), $source);
    check(!str_contains($http->requests[0]['parameters']['facets'], 'created_timestamp'), 'All time unexpectedly filters dates');
});

fiveTest('CurseForge newest release uses native ReleasedDate ordering', function () {
    [$provider, $http, $source] = fiveCurse();
    $provider->discover(new DiscoveryQuery(sort: 'newest'), $source);
    check($http->requests[0]['parameters']['sortField'] === 11, 'Incorrect newest release sort');
});

fiveTest('Modrinth exact version pins preserve hashes and reject another project', function () {
    [$p,,$s] = fiveModrinth(); check($p->package('project1', 'oldversion', $s)['id'] === 'oldversion', 'Pin ignored');
    [$p,,$s] = fiveModrinth(fn ($u) => str_ends_with($u, '/version/oldversion') ? fiveVersion(['id' => 'oldversion', 'project_id' => 'other']) : null);
    fails(fn () => $p->package('project1', 'oldversion', $s), 'another project');
});
fiveTest('Modrinth incompatible prerelease and client-only packages are rejected', function () {
    foreach ([['version_type' => 'beta'], ['loaders' => ['forge']], ['game_versions' => ['1.0']]] as $change) {
        [$p,,$s] = fiveModrinth(fn ($u) => str_ends_with($u, '/version/old') ? fiveVersion($change) : null);
        fails(fn () => $p->package('project1', 'old', $s), 'incompatible');
    }
    [$p,,$s] = fiveModrinth(fn ($u) => str_ends_with($u, '/project/project1') ? ['project_type' => 'mod', 'server_side' => 'unsupported'] : null);
    fails(fn () => $p->get('project1', $s), 'servers');
});
fiveTest('Modrinth ambiguous files and unresolved required files fail closed', function () {
    foreach ([['files' => [['filename' => 'a.jar'], ['filename' => 'b.jar']]], ['dependencies' => [['dependency_type' => 'required', 'file_name' => 'missing.jar']]]] as $change) {
        [$p,,$s] = fiveModrinth(fn ($u) => str_ends_with($u, '/project/project1/version') ? [fiveVersion($change)] : null);
        fails(fn () => $p->get('project1', $s));
    }
});
fiveTest('Modrinth dependency types and exact version-only dependency normalize', function () {
    [$p,,$s] = fiveModrinth(fn ($u) => str_ends_with($u, '/project/project1/version') ? [fiveVersion(['dependencies' => [
        ['project_id' => 'dep1', 'version_id' => 'depver', 'dependency_type' => 'required'],
        ['project_id' => 'dep2', 'dependency_type' => 'optional'], ['project_id' => 'dep3', 'dependency_type' => 'incompatible'], ['dependency_type' => 'embedded'],
    ]])] : null);
    $deps = $p->get('project1', $s)['dependencies'];
    check(array_column($deps, 'type') === ['required', 'optional', 'conflict'] && $deps[0]['file_id'] === 'depver', 'Dependency normalization lost semantics');
});
fiveTest('CurseForge request uses game filters and header auth', function () {
    [$p,$http,$s] = fiveCurse(); $p->discover(new DiscoveryQuery('hello', 'downloads', 2, 24), $s);
    $r = $http->requests[0];
    check($r['parameters']['gameId'] === '432' && $r['parameters']['index'] === 24 && $r['parameters']['modLoaderType'] === 4, 'Filters missing');
    check($r['headers']['x-api-key'] === 'PRIVATE-TEST-KEY' && !str_contains($r['url'], 'PRIVATE'), 'Bad auth placement');
});
fiveTest('CurseForge null URL resolves through authorized endpoint and respects opt-out', function () {
    [$p,$http,$s] = fiveCurse(); check(str_contains($p->package(10, 20, $s)['download_url'], 'forgecdn.net'), 'Download fallback missing');
    [$p,,$s] = fiveCurse(fn ($u) => str_ends_with($u, '/mods/10') ? ['data' => ['id' => 10, 'gameId' => 432, 'name' => 'Example', 'allowModDistribution' => false]] : null);
    fails(fn () => $p->package(10, null, $s), 'permit');
});
fiveTest('CurseForge rejects wrong game wrong mod wrong loader and missing download', function () {
    [$p,,$s] = fiveCurse(fn ($u) => str_ends_with($u, '/mods/10') ? ['data' => ['id' => 10, 'gameId' => 99, 'name' => 'Wrong']] : null);
    fails(fn () => $p->get(10, $s), 'another game');
    foreach ([['modId' => 99], ['gameVersions' => ['1.21.1', 'Forge']]] as $change) {
        [$p,,$s] = fiveCurse(fn ($u) => str_ends_with($u, '/files/20') ? ['data' => fiveCurseFile($change)] : null);
        fails(fn () => $p->package(10, 20, $s));
    }
    [$p,,$s] = fiveCurse(fn ($u) => str_ends_with($u, '/download-url') ? ['data' => null] : null);
    fails(fn () => $p->package(10, null, $s), 'authorize');
});
fiveTest('CurseForge dependencies preserve required optional and conflicts', function () {
    [$p,,$s] = fiveCurse(fn ($u) => str_ends_with($u, '/files') ? ['data' => [fiveCurseFile(['dependencies' => [
        ['modId' => 30, 'relationType' => 3], ['modId' => 31, 'relationType' => 2], ['modId' => 32, 'relationType' => 5], ['modId' => 33, 'relationType' => 4],
    ]])]] : null);
    check(array_column($p->get(10, $s)['dependencies'], 'type') === ['required', 'optional', 'conflict'], 'Wrong dependency types');
});
fiveTest('Nexus GraphQL catalog search is game scoped and numeric lookup remains exact REST', function () {
    [$p,$http,$s] = fiveNexus();

    $result = $p->discover(new DiscoveryQuery('Example', 'downloads', 2, 24), $s);
    check($result->total === 1 && count($result->items) === 1, 'GraphQL catalog result missing');

    $request = $http->requests[0];
    check($request['method'] === 'POST', 'Nexus catalog did not use POST');
    check($request['url'] === 'https://api.nexusmods.com/v2/graphql', 'Nexus catalog did not use GraphQL endpoint');

    $variables = $request['parameters']['variables'] ?? [];
    $filters = $variables['filter']['filter'] ?? [];

    check(
        in_array(
            ['gameDomainName' => ['value' => 'examplegame', 'op' => 'EQUALS']],
            $filters,
            true
        ),
        'Nexus GraphQL search was not scoped to the game domain'
    );

    check(
        in_array(
            ['name' => ['value' => 'Example', 'op' => 'WILDCARD']],
            $filters,
            true
        ),
        'Nexus GraphQL name search filter missing'
    );

    check(($variables['count'] ?? null) === 24, 'Nexus GraphQL page size incorrect');
    check(($variables['offset'] ?? null) === 24, 'Nexus GraphQL page offset incorrect');
    check(
        ($variables['sort'][0]['downloads']['direction'] ?? null) === 'DESC',
        'Nexus GraphQL download sort incorrect'
    );

    $before = count($http->requests);
    $numeric = $p->discover(new DiscoveryQuery('10'), $s);

    check($numeric->total === 1, 'Numeric lookup failed');
    check(count($http->requests) === $before + 2, 'Numeric lookup did not use exact REST metadata and files requests');
    check(
        str_contains($http->requests[$before]['url'], '/games/examplegame/mods/10.json'),
        'Numeric lookup did not use the configured Nexus domain'
    );
    check(
        !str_contains($http->requests[$before]['url'], '/v2/graphql'),
        'Numeric lookup unexpectedly used GraphQL'
    );
});
fiveTest('Nexus links are generated only at package time and ambiguous mains rejected', function () {
    [$p,$http,$s] = fiveNexus(); $p->get(10, $s);
    check(!str_contains(json_encode($http->requests), 'download_link'), 'Metadata fetched signed link');
    check(str_contains($p->package(10, 20, $s)['download_url'], 'signature='), 'No authorized link');
    [$p,,$s] = fiveNexus(fn ($u) => str_ends_with($u, '/files.json') ? ['files' => [['category_id' => 1], ['category_id' => 1]]] : null);
    fails(fn () => $p->package(10, null, $s), 'unambiguous');
});
fiveTest('7DaysToDieMods waits for download authorization without exposing tokens in metadata', function () {
    [$p,$http,$s] = fiveSeven(); $p->get('example', $s);
    check(count($http->requests) === 1, 'Metadata initiated download');
    $file = $p->package('example', 'FILE1', $s);
    check($p->waited === 5000 && str_contains($file['download_url'], 'downloads.7daystodiemods.com'), 'Download claim flow failed');
    check($http->requests[2]['method'] === 'POST', 'Authorization request method incorrect');
});
fiveTest('7DaysToDieMods external ambiguous unscanned and stale packages fail safely', function () {
    foreach ([[], [['id' => 'A', 'file_type' => 'main', 'scan_status' => 'pending']], [['file_type' => 'main', 'scan_status' => 'clean'], ['file_type' => 'main', 'scan_status' => 'clean']]] as $files) {
        [$p,,$s] = fiveSeven(fn ($u) => str_ends_with($u, '/mods/example') ? ['id' => 'UPSTREAM1', 'slug' => 'example', 'title' => 'Example', 'mod_files' => $files] : null);
        fails(fn () => $p->package('example', null, $s));
    }
    [$p,,$s] = fiveSeven(); fails(fn () => $p->package('example', 'OLD', $s), 'no longer');
});
fiveTest('Steam app metadata and tags are passed through with API auth', function () {
    [$p,$http] = fiveSteam(); $p->discover(new DiscoveryQuery('hello', 'newest', 2, 24), fiveSource('steam-workshop', ['app_id' => 456, 'tags' => ['Server']]));
    $r = $http->requests[0]; $input = json_decode($r['parameters']['input_json'], true);
    check($input['appid'] === 456 && $input['requiredtags'] === ['Server'] && $input['page'] === 2, 'Wrong app metadata');
    check($r['parameters']['key'] === 'PRIVATE-TEST-KEY', 'Missing Steam auth');
});
fiveTest('Steam rejects wrong app collections banned items and incomplete dependencies', function () {
    foreach ([['consumer_appid' => 999], ['filetype' => 2], ['banned' => true], ['num_children' => 2, 'children' => []]] as $change) {
        [$p,,$s] = fiveSteam(fn () => ['response' => ['publishedfiledetails' => [array_replace(['result' => 1, 'publishedfileid' => '123', 'consumer_appid' => 456, 'title' => 'Example'], $change)]]]);
        fails(fn () => $p->get(123, $s));
    }
});
fiveTest('malformed metadata and unsafe identifiers fail without reinterpretation', function () {
    foreach (['fiveModrinth', 'fiveCurse', 'fiveNexus', 'fiveSeven', 'fiveSteam'] as $factory) {
        [$p,$http,$s] = $factory(); fails(fn () => $p->get('../bad?key=PRIVATE', $s)); check(!$http->requests, 'Unsafe ID requested');
        [$p,,$s] = $factory(fn () => ['_status' => 200, '_body' => '<html>not-json</html>']); fails(fn () => $p->discover(new DiscoveryQuery, $s), 'invalid');
    }
});

function fiveFixture($provider, SourceContext $context, string $strategy = 'copy'): array
{
    $d = game070(); $d['sources'] = [$provider->key() => ['metadata' => $context->config()]];
    $d['deployment']['strategy'] = $strategy; $d['package_types'] = $strategy === 'copy' ? ['jar'] : ['zip'];
    $f = fixture070($d);
    // Bind both concrete production class and any instrumented fixture subclass.
    $definition = config('gamenest-mod-manager.providers')[$provider->key()];
    $GLOBALS['services'][$definition['class']] = $provider;
    $download = new Download070; $download->body = $strategy === 'copy' ? 'package' : zip070(['Example/a.dll' => 'package']);
    $GLOBALS['services'][SafeRemoteDownloader::class] = $download;
    return $f;
}
foreach (['fiveModrinth' => ['project1', 'copy', 'Mods/example.jar'], 'fiveCurse' => ['10', 'copy', 'Mods/example.jar'],
    'fiveNexus' => ['10', 'archive', 'Mods/Example/a.dll'], 'fiveSeven' => ['example', 'archive', 'Mods/Example/a.dll']] as $factory => [$id, $strategy, $path]) {
    fiveTest($factory . ' install disable enable pinned reinstall remove through real lifecycle', function () use ($factory, $id, $strategy, $path) {
        [$p,,$s] = $factory(); [$server,$repo,,$manifest,$ops,$engine] = fiveFixture($p, $s, $strategy);
        $key = $p->key() . ':' . $id;
        $engine->run($server, 'install', $key); check(($repo->files[$path] ?? '') === 'package', 'Package not installed');
        check(!str_contains(json_encode($manifest->read($server)), 'PRIVATE'), 'Manifest leaked credentials');
        $engine->run($server, 'disable', $key); check(!isset($repo->files[$path]), 'Disable failed');
        $engine->run($server, 'enable', $key); $engine->run($server, 'reinstall', $key);
        check(($repo->files[$path] ?? '') === 'package', 'Pinned reinstall failed');
        $engine->run($server, 'remove', $key); check(!isset($repo->files[$path]), 'Remove failed');
        check(count($ops->history($server)) === 5 && !str_contains(json_encode($ops->history($server)), 'PRIVATE'), 'Audit missing or secret leaked');
    });
}
fiveTest('generic package checksum failure leaves files and manifest unchanged', function () {
    [$p,,$s] = fiveModrinth(); [$server,$repo,,$manifest,,$engine] = fiveFixture($p, $s);
    app(SafeRemoteDownloader::class)->body = 'tampered';
    fails(fn () => $engine->run($server, 'install', 'modrinth:project1'), 'checksum');
    check($manifest->mods($server) === [] && !isset($repo->files['Mods/example.jar']), 'Checksum failure mutated live state');
});
fiveTest('signed download failure is sanitized in persisted operation history', function () {
    [$p,,$s] = fiveNexus(); [$server,$repo,,$manifest,$ops,$engine] = fiveFixture($p, $s, 'archive');
    $GLOBALS['services'][SafeRemoteDownloader::class] = new class extends Download070 {
        public function download(string $url, int $maxBytes): string { throw new RuntimeException('Failed URL ' . $url, 0, new RuntimeException('PRIVATE-TOKEN')); }
    };
    fails(fn () => $engine->run($server, 'install', 'nexus:10'), 'download failed');
    check(!str_contains(json_encode($ops->history($server)), 'PRIVATE'), 'Download token entered audit');
    check($manifest->mods($server) === [], 'Failed download changed manifest');
});
fiveTest('changed dependency metadata is rejected before any live move', function () {
    $changed = false;
    [$p,,$s] = fiveModrinth(function ($u) use (&$changed) {
        if (str_ends_with($u, '/version/pinned')) { return fiveVersion(['id' => 'pinned', 'dependencies' => [['project_id' => 'dep', 'dependency_type' => 'required']]]); }
        return null;
    });
    [$server,$repo,,$manifest,,$engine] = fiveFixture($p, $s);
    fails(fn () => $engine->run($server, 'install', 'modrinth:project1', 'pinned'), 'dependencies differ');
    check($repo->moves === 0 && $manifest->mods($server) === [], 'Mismatched dependency plan deployed');
});
fiveTest('shared lifecycle rolls back a partially moved catalog package', function () {
    [$p,,$s] = fiveNexus(); [$server,$repo,,$manifest,,$engine] = fiveFixture($p, $s, 'archive');
    app(SafeRemoteDownloader::class)->body = zip070(['Example/a.dll' => 'a', 'Example/b.dll' => 'b']);
    $repo->failAt = 2;
    fails(fn () => $engine->run($server, 'install', 'nexus:10'));
    check($manifest->mods($server) === [] && !isset($repo->files['Mods/Example/a.dll']), 'Partial install did not roll back');
});
class FiveSteamDownloader extends SteamCmdDownloader
{
    public array $calls = [];
    public array $contents = ['Mod/main.dll' => 'workshop'];
    public function download(string $appId, string $itemId): array { $this->calls[] = [$appId, $itemId]; return $this->contents; }
}
fiveTest('Workshop provider-managed factory stages and journals install toggle reinstall remove', function () {
    [$p,,$s] = fiveSteam(); [$server,$repo,,$manifest,$ops,$engine] = fiveFixture($p, $s, 'provider-managed');
    $download = new FiveSteamDownloader; $GLOBALS['services'][SteamCmdDownloader::class] = $download;
    $engine->run($server, 'install', 'steam-workshop:123');
    check(($repo->files['Mods/123/Mod/main.dll'] ?? '') === 'workshop', 'Workshop files not deployed');
    check($download->calls === [['456', '123']], 'Wrong Workshop app/item');
    $engine->run($server, 'disable', 'steam-workshop:123'); $engine->run($server, 'enable', 'steam-workshop:123');
    $engine->run($server, 'reinstall', 'steam-workshop:123'); $engine->run($server, 'remove', 'steam-workshop:123');
    check(!isset($repo->files['Mods/123/Mod/main.dll']) && count($ops->history($server)) === 5, 'Workshop lifecycle incomplete');
});
fiveTest('Workshop stale revision and path traversal fail before live changes', function () {
    [$p,,$s] = fiveSteam(); [$server,$repo,,$manifest,,$engine] = fiveFixture($p, $s, 'provider-managed');
    $download = new FiveSteamDownloader; $GLOBALS['services'][SteamCmdDownloader::class] = $download;
    fails(fn () => $engine->run($server, 'install', 'steam-workshop:123', '123:999'), 'older revision');
    check(!$download->calls, 'Stale revision downloaded');
    $download->contents = ['../escape.dll' => 'bad'];
    fails(fn () => $engine->run($server, 'install', 'steam-workshop:123'));
    check($repo->moves === 0 && $manifest->mods($server) === [], 'Unsafe Workshop payload deployed');
});
fiveTest('Workshop missing installation and injected identifiers fail safely', function () {
    $GLOBALS['configOverrides']['gamenest-mod-manager.steam-workshop.steamcmd_root'] = '/no-such-steamcmd';
    $downloader = new SteamCmdDownloader;
    fails(fn () => $downloader->download('456', '123'), 'dedicated');
    fails(fn () => $downloader->download('456;touch bad', '123'), 'identifier');
});
fiveTest('universal filenames accept normal provider punctuation and retain traversal guards', function () {
    foreach (['Mods/fabric-api+1.21.jar', 'Mods/Puffs Traps (2).zip', 'Mods/Example [Server]/main.xml'] as $path) { check(GameDefinition::path($path) === $path, 'Valid filename rejected'); }
    foreach (['../escape', 'Mods/../escape', 'Mods/a;touch', 'Mods/a`x', 'Mods/a$(x)', 'Mods/con.txt'] as $path) { fails(fn () => GameDefinition::path($path)); }
});
fiveTest('required catalog dependency installs its exact pin and survives shared lifecycle rules', function () {
    [$p,,$s] = fiveModrinth(function ($u) {
        if (str_ends_with($u, '/project/project1/version')) { return [fiveVersion(['dependencies' => [['project_id' => 'dependency', 'version_id' => 'depold', 'dependency_type' => 'required']]])]; }
        if (str_ends_with($u, '/project/dependency')) { return ['id' => 'dependency', 'title' => 'Dependency', 'project_type' => 'mod', 'server_side' => 'required']; }
        if (str_ends_with($u, '/project/dependency/version') || str_ends_with($u, '/version/depold')) {
            $v = fiveVersion(['id' => str_ends_with($u, '/version/depold') ? 'depold' : 'depnew', 'project_id' => 'dependency']);
            $v['files'][0]['filename'] = 'dependency.jar';
            return str_ends_with($u, '/version/depold') ? $v : [$v];
        }
        return null;
    });
    [$server,$repo,,$manifest,,$engine] = fiveFixture($p, $s);
    $engine->run($server, 'install', 'modrinth:project1');
    check($manifest->mods($server)['modrinth:dependency']['file_id'] === 'depold', 'Dependency pin lost');
    check(isset($repo->files['Mods/dependency.jar']), 'Required dependency not deployed');
    fails(fn () => $engine->run($server, 'remove', 'modrinth:dependency'));
    $engine->run($server, 'remove', 'modrinth:project1');
    check($manifest->mods($server) === [], 'Orphan catalog dependency not cleaned');
});
fiveTest('7DaysToDieMods source category version and side metadata reach the API', function () {
    [$p,$http] = fiveSeven();
    $p->discover(new DiscoveryQuery('turrets', 'updated'), fiveSource('7daystodiemods', ['category' => ['trap'], 'game_version' => ['v3'], 'server_side' => ['server-only']]));
    check($http->requests[0]['parameters']['category'] === 'trap' && $http->requests[0]['parameters']['game_version'] === 'v3'
        && $http->requests[0]['parameters']['server_side'] === 'server-only', 'Game source filters lost');
});
