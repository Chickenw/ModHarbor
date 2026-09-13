<?php

namespace GameNest\GameNestModManager\Providers;

use GameNest\GameNestModManager\Contracts\DiscoverableProvider;
use GameNest\GameNestModManager\Contracts\ModProvider;
use GameNest\GameNestModManager\Services\DiscoveryQuery;
use GameNest\GameNestModManager\Services\DiscoveryResult;
use GameNest\GameNestModManager\Services\SourceContext;
use GameNest\GameNestModManager\Services\ProviderSettingsStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use GameNest\GameNestModManager\Contracts\VersionListingProvider;
use Throwable;

class ModIoProvider implements ModProvider, DiscoverableProvider, VersionListingProvider
{
    public function versions(string|int $id, SourceContext $source): array
    {
        if (!ctype_digit((string) $id) || (int) $id < 1) { throw new RuntimeException('Invalid mod.io mod ID.'); }
        $response = $this->request()->get($this->apiPath() . '/games/' . $this->gameId($source) . '/mods/' . $id . '/files', ['_limit' => 100, '_sort' => '-date_added']);
        if (!$response->successful() || !is_array($response->json()['data'] ?? null)) { throw new RuntimeException('Unable to list mod.io releases.'); }
        return array_map(fn ($f) => $this->normalizeFile($f), $response->json()['data']);
    }

    public function key(): string
    {
        return 'modio';
    }

    public function name(): string
    {
        return 'mod.io';
    }

    public function supportsSearch(): bool
    {
        return true;
    }

    public function configured(): bool
    {
        return $this->apiKey() !== ''
            || $this->accessToken() !== '';
    }

    public function search(
        string $query,
        array $options = [],
        ?SourceContext $source = null
    ): array {
        return $this->browse(
            $query,
            $options,
            $source
        )['mods'];
    }

    public function tagOptions(?SourceContext $source = null): array
    {
        $gameId =
            $this->gameId($source);

        $response =
            $this->request()->get(
                $this->apiPath() .
                '/games/' .
                $gameId .
                '/tags',
                [
                    '_limit' => 100,
                ]
            );

        if (!$response->successful()) {
            throw new RuntimeException(
                'mod.io tag request failed with HTTP ' .
                $response->status() .
                $this->errorSuffix(
                    $response->json()
                )
            );
        }

        $json =
            $response->json();

        if (!is_array($json)) {
            throw new RuntimeException(
                'mod.io returned an invalid tag response.'
            );
        }

        $groups =
            $json['data']
            ?? [];

        if (!is_array($groups)) {
            return [];
        }

        return array_values(
            array_filter(
                array_map(
                    function (array $group): ?array {
                        if (
                            (bool) ($group['hidden'] ?? false)
                            || empty($group['tags'])
                            || !is_array($group['tags'])
                        ) {
                            return null;
                        }

                        $counts =
                            is_array(
                                $group['tag_count_map']
                                ?? null
                            )
                                ? $group['tag_count_map']
                                : [];

                        $tags = [];

                        foreach ($group['tags'] as $tag) {
                            if (
                                !is_string($tag)
                                || trim($tag) === ''
                            ) {
                                continue;
                            }

                            $tags[] = [
                                'name' => $tag,
                                'count' =>
                                    (int) (
                                        $counts[$tag]
                                        ?? 0
                                    ),
                            ];
                        }

                        if ($tags === []) {
                            return null;
                        }

                        return [
                            'name' =>
                                (string) (
                                    $group['name']
                                    ?? 'Tags'
                                ),
                            'type' =>
                                (string) (
                                    $group['type']
                                    ?? 'dropdown'
                                ),
                            'locked' =>
                                (bool) (
                                    $group['locked']
                                    ?? false
                                ),
                            'tags' => $tags,
                        ];
                    },
                    array_filter(
                        $groups,
                        'is_array'
                    )
                )
            )
        );
    }

    public function discover(
        DiscoveryQuery $query,
        SourceContext $source
    ): DiscoveryResult {
        $catalog = $this->browse(
            $query->search,
            [
                'limit' => $query->perPage,
                'offset' => ($query->page - 1) * $query->perPage,
                'sort' => $query->sort,
                'period' => (string) $query->filter('period', 'all'),
                'tags' => $query->tags,
            ],
            $source
        );

        $total = max(0, (int) ($catalog['total'] ?? count($catalog['mods'] ?? [])));
        $perPage = max(1, (int) ($catalog['limit'] ?? $query->perPage));
        $offset = max(0, (int) ($catalog['offset'] ?? (($query->page - 1) * $perPage)));
        $page = max(1, (int) floor($offset / $perPage) + 1);

        return DiscoveryResult::page(
            (array) ($catalog['mods'] ?? []),
            $total,
            $page,
            $perPage,
            facets: [],
            meta: ['period' => (string) $query->filter('period', 'all')]
        );
    }

    public function browse(
        string $query = '',
        array $options = [],
        ?SourceContext $source = null
    ): array {
        $query = trim($query);

        $limit =
            (int) (
                $options['limit']
                ?? 24
            );

        $limit =
            max(
                1,
                min($limit, 50)
            );

        $offset =
            max(
                0,
                (int) (
                    $options['offset']
                    ?? 0
                )
            );

        $allowedSorts = [
            'hot' => '-downloads_today',
            'downloads' => '-downloads_total',
            'subscribers' => '-subscribers_total',
            'rating' => '-ratings_weighted_aggregate',
            'newest' => '-date_live',
            'updated' => '-date_updated',
            'name' => 'name',
        ];

        $sortKey =
            (string) (
                $options['sort']
                ?? 'hot'
            );

        $sort =
            $allowedSorts[$sortKey]
            ?? $allowedSorts['hot'];

        $allowedPeriods = [
            'all' => null,
            '7d' => 7,
            '14d' => 14,
            '28d' => 28,
            '30d' => 30,
            '3m' => 90,
            '6m' => 180,
            '1y' => 365,
        ];

        $period =
            (string) (
                $options['period']
                ?? 'all'
            );

        if (
            !array_key_exists(
                $period,
                $allowedPeriods
            )
        ) {
            $period = 'all';
        }

        $parameters = [
            '_limit' => $limit,
            '_offset' => $offset,
            '_sort' => $sort,
            'status' => 1,
        ];

        if ($query !== '') {
            $parameters['_q'] = $query;
        }

        $days =
            $allowedPeriods[$period];

        if ($days !== null) {
            $parameters['date_live-min'] =
                now()
                    ->subDays($days)
                    ->timestamp;
        }

        

        $tags =
            array_values(
                array_filter(
                    array_map(
                        fn ($tag): string =>
                            trim((string) $tag),
                        (array) (
                            $options['tags']
                            ?? []
                        )
                    ),
                    fn (string $tag): bool =>
                        $tag !== ''
                )
            );

        if ($tags !== []) {
            $parameters['tags'] =
                implode(',', $tags);
        }

        $gameId =
            $this->gameId($source);

        $response =
            $this->request()->get(
                $this->apiPath() .
                '/games/' .
                $gameId .
                '/mods',
                $parameters
            );

        if (!$response->successful()) {
            throw new RuntimeException(
                'mod.io catalog request failed with HTTP ' .
                $response->status() .
                $this->errorSuffix(
                    $response->json()
                )
            );
        }

        $json =
            $response->json();

        if (!is_array($json)) {
            throw new RuntimeException(
                'mod.io returned an invalid catalog response.'
            );
        }

        $rows =
            $json['data']
            ?? [];

        if (!is_array($rows)) {
            $rows = [];
        }

        $mods =
            array_values(
                array_map(
                    fn (array $mod): array =>
                        $this->normalizeMod($mod),
                    array_filter(
                        $rows,
                        'is_array'
                    )
                )
            );

        return [
            'mods' => $mods,

            'total' =>
                max(
                    0,
                    (int) (
                        $json['result_total']
                        ?? count($mods)
                    )
                ),

            'count' =>
                max(
                    0,
                    (int) (
                        $json['result_count']
                        ?? count($mods)
                    )
                ),

            'offset' =>
                max(
                    0,
                    (int) (
                        $json['result_offset']
                        ?? $offset
                    )
                ),

            'limit' =>
                max(
                    1,
                    (int) (
                        $json['result_limit']
                        ?? $limit
                    )
                ),
        ];
    }

    public function get(
        string|int $id,
        ?SourceContext $source = null
    ): ?array
    {
        $modId = (int) $id;

        if ($modId < 1) {
            throw new RuntimeException(
                'Invalid mod.io mod ID.'
            );
        }

        $gameId = $this->gameId($source);

        $response = $this->request()->get(
            $this->apiPath() .
            '/games/' .
            $gameId .
            '/mods/' .
            $modId
        );

        if (!$response->successful()) {
            throw new RuntimeException(
                'Unable to retrieve mod.io mod #' .
                $modId .
                ' (HTTP ' .
                $response->status() .
                ')' .
                $this->errorSuffix($response->json())
            );
        }

        $json = $response->json();

        if (!is_array($json)) {
            throw new RuntimeException(
                'mod.io returned invalid mod information.'
            );
        }

        return $this->normalizeMod($json);
    }

    public function file(
        int $modId,
        int $fileId,
        ?SourceContext $source = null
    ): array
    {
        if ($modId < 1 || $fileId < 1) {
            throw new RuntimeException('Invalid mod or release ID.');
        }
        $response = $this->request()->get($this->apiPath() . '/games/' . $this->gameId($source) . '/mods/' . $modId . '/files/' . $fileId);
        if (!$response->successful() || !is_array($response->json()) || (int) ($response->json()['id'] ?? 0) !== $fileId) {
            throw new RuntimeException('The installed release is no longer downloadable. Use Update to install the latest release.');
        }
        return $this->normalizeFile($response->json());
    }

    public function dependencies(
        int $modId,
        ?SourceContext $source = null
    ): array
    {
        if ($modId < 1) {
            throw new RuntimeException('Invalid mod.io mod ID.');
        }
        $result = [];
        for ($offset = 0; $offset < 10000; $offset += 100) {
            $response = $this->request()->get($this->apiPath() . '/games/' . $this->gameId($source) . '/mods/' . $modId . '/dependencies', [
                'recursive' => 'true', '_limit' => 100, '_offset' => $offset,
            ]);
            $json = $response->json();
            if (!$response->successful() || !is_array($json) || !is_array($json['data'] ?? null)) {
                throw new RuntimeException('Unable to retrieve the complete dependency list.');
            }
            foreach ($json['data'] as $entry) {
                $id = (int) ($entry['mod_id'] ?? 0);
                if ($id < 1 || $id === $modId) {
                    throw new RuntimeException('Invalid or cyclic mod dependency.');
                }
                $result[$id] = ['mod_id' => $id];
            }
            if ($offset + count($json['data']) >= (int) ($json['result_total'] ?? PHP_INT_MAX)
                || count($json['data']) < 100) {
                return array_values($result);
            }
        }
        throw new RuntimeException('Dependency list is too large to verify safely.');
    }

    public function settings(): array
    {
        return $this->readSettings();
    }

    public function credentialStatus(): array
    {
        $settings = $this->readSettings();

        return [
            'api_key' =>
                trim((string) ($settings['api_key'] ?? '')) !== '',
            'access_token' =>
                trim((string) ($settings['access_token'] ?? '')) !== '',
        ];
    }

    public function saveSettings(
        string $apiPath,
        string $apiKey,
        string $accessToken
    ): void {
        $apiPath = \GameNest\GameNestModManager\Services\ProviderConnectionService::modioBase($apiPath);

        if (
            $apiPath === ''
            || !str_starts_with($apiPath, 'https://')
        ) {
            throw new RuntimeException(
                'The mod.io API path must be a valid HTTPS URL.'
            );
        }

        $existing = $this->readSettings();

        $apiKey = trim($apiKey);
        $accessToken = trim($accessToken);

        if ($apiKey === '') {
            $apiKey = trim(
                (string) ($existing['api_key'] ?? '')
            );
        }

        if ($accessToken === '') {
            $accessToken = trim(
                (string) ($existing['access_token'] ?? '')
            );
        }

        if ($apiKey === '' && $accessToken === '') {
            throw new RuntimeException(
                'Enter a mod.io API key or access token.'
            );
        }

        app(ProviderSettingsStore::class)->save(
            'modio',
            [
                'api_path' => $apiPath,
                'api_key' => $apiKey,
                'access_token' => $accessToken,
            ],
            [
                'api_path' => [
                    'label' => 'API URL',
                    'type' => 'text',
                    'required' => true,
                ],
                'api_key' => [
                    'label' => 'API Key',
                    'type' => 'secret',
                    'required' => false,
                ],
                'access_token' => [
                    'label' => 'Access Token',
                    'type' => 'secret',
                    'required' => false,
                ],
            ]
        );
    }

    public function testConnectionWithSettings(
        string $apiPath,
        string $apiKey,
        string $accessToken
    ): array {
        $apiPath = \GameNest\GameNestModManager\Services\ProviderConnectionService::modioBase($apiPath);
        $apiKey = trim($apiKey);
        $accessToken = trim($accessToken);

        if (
            $apiPath === ''
            || !str_starts_with($apiPath, 'https://')
        ) {
            throw new RuntimeException(
                'The mod.io API path must be a valid HTTPS URL.'
            );
        }

        /*
         * Blank fields reuse saved credentials.
         */
        $existing = $this->readSettings();

        if ($apiKey === '') {
            $apiKey = trim(
                (string) ($existing['api_key'] ?? '')
            );
        }

        if ($accessToken === '') {
            $accessToken = trim(
                (string) ($existing['access_token'] ?? '')
            );
        }

        if ($apiKey === '' && $accessToken === '') {
            throw new RuntimeException(
                'Enter a mod.io API key or access token.'
            );
        }

        $request = Http::timeout(20)
            ->connectTimeout(10)->withOptions(['allow_redirects' => false])
            ->acceptJson();

        if ($apiKey !== '') {
            $request =
                $request->withQueryParameters([
                    'api_key' => $apiKey,
                ]);
        }

        if ($accessToken !== '') {
            $request =
                $request->withToken(
                    $accessToken
                );
        }

        $response = $request->get(
            $apiPath . '/games',
            [
                '_limit' => 1,
            ]
        );

        if (!$response->successful()) {
            throw new RuntimeException(
                'mod.io connection failed with HTTP ' .
                $response->status() .
                $this->errorSuffix(
                    $response->json()
                )
            );
        }

        return [
            'status' => $response->status(),
            'api_path' => $apiPath,
        ];
    }

    /**
     * Resolve the remote mod.io game ID from game-adapter source metadata.
     *
     * The optional source parameter allows one ModIoProvider instance to
     * safely serve multiple games without storing mutable "current game"
     * state. Shared lifecycle/UI callers must pass the server source context.
     */
    protected function gameId(
        ?SourceContext $source = null
    ): int {
        if ($source === null) {
            throw new RuntimeException(
                'mod.io operations require an explicit ModHarbor SourceContext.'
            );
        }

        if ($source->key() !== 'modio') {
            throw new RuntimeException(
                'The supplied source context is not a mod.io source.'
            );
        }

        $configuredId =
            (int) $source->value(
                'game_id',
                0
            );

        if ($configuredId > 0) {
            return $configuredId;
        }

        $nameId = strtolower(
            trim(
                (string) $source->value(
                    'name_id',
                    ''
                )
            )
        );

        $gameName = trim(
            (string) $source->value(
                'game_name',
                $source->gameName()
            )
        );

        if (
            $nameId === ''
            && $gameName === ''
        ) {
            throw new RuntimeException(
                'The mod.io source for ' .
                $source->gameName() .
                ' must define game_id, name_id, or game_name.'
            );
        }

        $identity =
            $nameId !== ''
                ? 'name-id:' . $nameId
                : 'name:' . strtolower($gameName);

        return (int) Cache::remember(
            $source->cacheKey(
                'game-id:' . sha1($identity)
            ),
            now()->addDay(),
            function () use (
                $source,
                $nameId,
                $gameName
            ): int {
                $parameters = [
                    '_limit' => 100,
                    'status' => 1,
                ];

                if ($gameName !== '') {
                    $parameters['_q'] = $gameName;
                } elseif ($nameId !== '') {
                    $parameters['_q'] = $nameId;
                }

                $response =
                    $this->request()->get(
                        $this->apiPath() .
                        '/games',
                        $parameters
                    );

                if (!$response->successful()) {
                    throw new RuntimeException(
                        'Unable to locate ' .
                        $source->gameName() .
                        ' on mod.io (HTTP ' .
                        $response->status() .
                        ')' .
                        $this->errorSuffix(
                            $response->json()
                        )
                    );
                }

                $json = $response->json();

                $games =
                    is_array($json)
                        ? ($json['data'] ?? [])
                        : [];

                if (!is_array($games)) {
                    throw new RuntimeException(
                        'mod.io returned an invalid games response.'
                    );
                }

                foreach ($games as $game) {
                    if (!is_array($game)) {
                        continue;
                    }

                    $remoteId =
                        (int) (
                            $game['id']
                            ?? 0
                        );

                    if ($remoteId < 1) {
                        continue;
                    }

                    $remoteNameId =
                        strtolower(
                            trim(
                                (string) (
                                    $game['name_id']
                                    ?? ''
                                )
                            )
                        );

                    $remoteName =
                        strtolower(
                            trim(
                                (string) (
                                    $game['name']
                                    ?? ''
                                )
                            )
                        );

                    if (
                        $nameId !== ''
                        && $remoteNameId === $nameId
                    ) {
                        return $remoteId;
                    }

                    if (
                        $nameId === ''
                        && $gameName !== ''
                        && $remoteName ===
                            strtolower($gameName)
                    ) {
                        return $remoteId;
                    }
                }

                throw new RuntimeException(
                    'Unable to resolve the mod.io game ID for ' .
                    $source->gameName() .
                    '.'
                );
            }
        );
    }

    protected function request()
    {
        $apiKey = $this->apiKey();
        $accessToken = $this->accessToken();

        if ($apiKey === '' && $accessToken === '') {
            throw new RuntimeException(
                'mod.io authentication is not configured. ' .
                'ModHarbor stores mod.io credentials independently for all supported game adapters.'
            );
        }

        $request = Http::timeout(20)
            ->connectTimeout(10)->withOptions(['allow_redirects' => false])
            ->acceptJson();

        if ($apiKey !== '') {
            $request = $request->withQueryParameters([
                'api_key' => $apiKey,
            ]);
        }

        if ($accessToken !== '') {
            $request = $request->withToken(
                $accessToken
            );
        }

        return $request;
    }

    protected function apiPath(): string
    {
        $settings = $this->readSettings();

        $value = trim(
            (string) (
                $settings['api_path']
                ?? 'https://api.mod.io/v1'
            )
        );

        return \GameNest\GameNestModManager\Services\ProviderConnectionService::modioBase($value);
    }

    protected function apiKey(): string
    {
        return trim(
            (string) (
                $this->readSettings()['api_key']
                ?? ''
            )
        );
    }

    protected function accessToken(): string
    {
        return trim(
            (string) (
                $this->readSettings()['access_token']
                ?? ''
            )
        );
    }

    protected function newSettingsPath(): string
    {
        return storage_path(
            'app/gamenest-mod-manager/modio.json'
        );
    }

    protected function legacySettingsPath(): string
    {
        return storage_path(
            'app/gamenest-eco-enhanced/modio.json'
        );
    }

    protected function readSettings(): array
    {
        $settings = app(
            ProviderSettingsStore::class
        )->settings('modio');

        return [
            'api_path' => trim(
                (string) (
                    $settings['api_path']
                    ?? 'https://api.mod.io/v1'
                )
            ),
            'api_key' => trim(
                (string) (
                    $settings['api_key']
                    ?? ''
                )
            ),
            'access_token' => trim(
                (string) (
                    $settings['access_token']
                    ?? ''
                )
            ),
        ];
    }

    protected function normalizeMod(
        array $mod
    ): array {
        return [
            'id' => (int) ($mod['id'] ?? 0),

            'provider' => 'modio',

            'name' => trim(
                (string) (
                    $mod['name']
                    ?? $mod['name_id']
                    ?? 'Unknown Mod'
                )
            ),

            'name_id' => trim(
                (string) (
                    $mod['name_id']
                    ?? ''
                )
            ),

            'summary' => trim(
                (string) (
                    $mod['summary']
                    ?? ''
                )
            ),

            'author' => trim(
                (string) (
                    $mod['submitted_by']['username']
                    ?? 'Unknown'
                )
            ),

            'logo' => trim(
                (string) (
                    $mod['logo']['thumb_320x180']
                    ?? $mod['logo']['thumb_640x360']
                    ?? ''
                )
            ),

            'profile_url' => trim(
                (string) (
                    $mod['profile_url']
                    ?? ''
                )
            ),

            'date_updated' => (int) (
                $mod['date_updated']
                ?? 0
            ),

            'downloads' => (int) (
                $mod['stats']['downloads_total']
                ?? 0
            ),

            'subscribers' => (int) (
                $mod['stats']['subscribers_total']
                ?? 0
            ),

            'latest_file' =>
                $this->normalizeFile(
                    is_array(
                        $mod['modfile'] ?? null
                    )
                        ? $mod['modfile']
                        : []
                ),
        ];
    }

    protected function normalizeFile(
        array $file
    ): array {
        return [
            'id' => (int) (
                $file['id']
                ?? 0
            ),

            'version' => trim(
                (string) (
                    $file['version']
                    ?? ''
                )
            ),

            'filename' => trim(
                (string) (
                    $file['filename']
                    ?? ''
                )
            ),

            'filesize' => (int) (
                $file['filesize']
                ?? 0
            ),

            'date_added' => (int) (
                $file['date_added']
                ?? 0
            ),

            'download_url' => trim(
                (string) (
                    $file['download']['binary_url']
                    ?? ''
                )
            ),
        ];
    }

    protected function errorSuffix(
        mixed $json
    ): string {
        // Upstream error messages may echo authentication or signed URLs.
        return '. Check provider access and rate limits.';
    }
}
