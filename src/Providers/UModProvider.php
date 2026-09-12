<?php

namespace GameNest\GameNestModManager\Providers;

use GameNest\GameNestModManager\Contracts\DiscoverableProvider;
use GameNest\GameNestModManager\Contracts\ModProvider;
use GameNest\GameNestModManager\Services\DiscoveryQuery;
use GameNest\GameNestModManager\Services\DiscoveryResult;
use GameNest\GameNestModManager\Services\SourceContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class UModProvider implements ModProvider, DiscoverableProvider
{
    public function key(): string
    {
        return 'umod';
    }

    public function name(): string
    {
        return 'uMod';
    }

    public function supportsSearch(): bool
    {
        return true;
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

    public function discover(
        DiscoveryQuery $query,
        SourceContext $source
    ): DiscoveryResult {
        $catalog = $this->browse(
            $query->search,
            [
                'page' => $query->page,
                'sort' => $query->sort,
                'tag' => (string) $query->filter('tag', ''),
            ],
            $source
        );

        return DiscoveryResult::page(
            (array) ($catalog['mods'] ?? []),
            max(0, (int) ($catalog['total'] ?? count($catalog['mods'] ?? []))),
            max(1, (int) ($catalog['page'] ?? $query->page)),
            max(1, (int) ($catalog['per_page'] ?? $query->perPage)),
            max(1, (int) ($catalog['last_page'] ?? 1)),
            meta: ['tag' => (string) $query->filter('tag', '')]
        );
    }

    public function browse(
        string $query = '',
        array $options = [],
        ?SourceContext $source = null
    ): array {
        $source = $this->sourceContext($source);
        $query = trim($query);

        $page = max(
            1,
            (int) ($options['page'] ?? 1)
        );

        $allowedSorts = [
            'updated' => [
                'field' => 'latest_release_at',
                'direction' => 'desc',
            ],
            'downloads' => [
                'field' => 'downloads',
                'direction' => 'desc',
            ],
            'watchers' => [
                'field' => 'watchers',
                'direction' => 'desc',
            ],
            'newest' => [
                'field' => 'published_at',
                'direction' => 'desc',
            ],
            'name' => [
                'field' => 'title',
                'direction' => 'asc',
            ],
        ];

        $sortKey =
            (string) (
                $options['sort']
                ?? 'updated'
            );

        $sort =
            $allowedSorts[$sortKey]
            ?? $allowedSorts['updated'];

        $tag = trim(
            (string) (
                $options['tag']
                ?? ''
            )
        );

        $categories = array_values(
            array_filter(
                array_map(
                    static fn (mixed $category): string =>
                        strtolower(trim((string) $category)),
                    (array) $source->value('categories', [])
                ),
                static fn (string $category): bool =>
                    $category !== ''
            )
        );

        if ($categories === []) {
            throw new RuntimeException(
                'The uMod source for ' .
                $source->gameName() .
                ' is missing required category metadata.'
            );
        }

        $parameters = [
            'query' => $query,
            'page' => $page,
            'sort' => $sort['field'],
            'sortdir' => $sort['direction'],
            'filter' => '',
            'categories' => $categories,
            'author' => '',
        ];

        /*
         * uMod's search endpoint doesn't expose a dedicated tag
         * parameter. Include selected tags in its free-text filter,
         * then apply an exact local tag check after normalization.
         */
        if ($tag !== '') {
            $parameters['query'] =
                trim(
                    $query . ' ' . $tag
                );
        }

        $response =
            Http::acceptJson()
                ->timeout(20)
                ->retry(2, 250)
                ->get(
                    'https://umod.org/plugins/search.json',
                    $parameters
                );

        if (!$response->successful()) {
            throw new RuntimeException(
                'uMod catalog request failed with HTTP '
                . $response->status()
                . '.'
            );
        }

        $json = $response->json();

        if (!is_array($json)) {
            throw new RuntimeException(
                'uMod returned an invalid catalog response.'
            );
        }

        $rows =
            is_array($json['data'] ?? null)
                ? $json['data']
                : [];

        $mods = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $mod = $this->normalizePlugin($row, $source);

            if (
                $tag !== ''
                && !in_array(
                    strtolower($tag),
                    array_map(
                        'strtolower',
                        $mod['tags']
                    ),
                    true
                )
            ) {
                continue;
            }

            $mods[] = $mod;
        }

        return [
            'mods' => $mods,
            'total' => (int) ($json['total'] ?? count($mods)),
            'count' => count($mods),
            'page' => (int) ($json['current_page'] ?? $page),
            'last_page' => max(
                1,
                (int) ($json['last_page'] ?? 1)
            ),
            'per_page' => max(
                1,
                (int) ($json['per_page'] ?? 10)
            ),
        ];
    }

    public function get(
        string|int $id,
        ?SourceContext $source = null
    ): ?array {
        $source = $this->sourceContext($source);
        $slug = trim((string) $id);

        if ($slug === '') {
            return null;
        }

        return Cache::remember(
            $source->cacheKey(
                'plugin:' . sha1($slug)
            ),
            now()->addMinutes(5),
            function () use ($slug, $source): ?array {
                $response =
                    Http::acceptJson()
                        ->timeout(20)
                        ->retry(2, 250)
                        ->get(
                            'https://umod.org/plugins/'
                            . rawurlencode($slug)
                            . '.json'
                        );

                if ($response->status() === 404) {
                    return null;
                }

                if (!$response->successful()) {
                    throw new RuntimeException(
                        'Unable to retrieve the uMod plugin. HTTP '
                        . $response->status()
                        . '.'
                    );
                }

                $json = $response->json();

                if (!is_array($json)) {
                    throw new RuntimeException(
                        'uMod returned invalid plugin information.'
                    );
                }

                return $this->normalizePlugin($json, $source);
            }
        );
    }

    public function latest(
        string $slug,
        ?SourceContext $source = null
    ): array {
        $source = $this->sourceContext($source);
        $slug = trim($slug);

        if ($slug === '') {
            throw new RuntimeException(
                'Invalid uMod plugin slug.'
            );
        }

        $response =
            Http::acceptJson()
                ->timeout(20)
                ->retry(2, 250)
                ->get(
                    'https://umod.org/plugins/'
                    . rawurlencode($slug)
                    . '/latest.json'
                );

        if (!$response->successful()) {
            throw new RuntimeException(
                'Unable to retrieve the latest uMod release. HTTP '
                . $response->status()
                . '.'
            );
        }

        $json = $response->json();

        if (!is_array($json)) {
            throw new RuntimeException(
                'uMod returned invalid release information.'
            );
        }

        return $json;
    }

    protected function normalizePlugin(
        array $plugin,
        ?SourceContext $source = null
    ): array {
        $source = $this->sourceContext($source);
        $slug = trim(
            (string) (
                $plugin['slug']
                ?? ''
            )
        );

        $name = trim(
            (string) (
                $plugin['name']
                ?? ''
            )
        );

        if ($name === '') {
            $name = str_replace(
                ' ',
                '',
                trim(
                    (string) (
                        $plugin['title']
                        ?? ''
                    )
                )
            );
        }

        $tags = [];

        $rawTags = trim(
            (string) (
                $plugin['tags_all']
                ?? ''
            )
        );

        if ($rawTags !== '') {
            foreach (
                explode(',', $rawTags)
                as $tag
            ) {
                $tag = trim($tag);

                $normalizedTag = strtolower($tag);
                $sourceCategories = array_map(
                    static fn (mixed $category): string =>
                        strtolower(trim((string) $category)),
                    (array) $source->value('categories', [])
                );

                if (
                    $tag !== ''
                    && !in_array(
                        $normalizedTag,
                        $sourceCategories,
                        true
                    )
                ) {
                    $tags[] = $tag;
                }
            }
        }

        $tags = array_values(
            array_unique($tags)
        );

        return [
            'id' => $slug,
            'provider' => 'umod',
            'provider_id' => $slug,

            'name' => $name,
            'title' => trim(
                (string) (
                    $plugin['title']
                    ?? $name
                )
            ),

            'summary' => trim(
                (string) (
                    $plugin['description']
                    ?? ''
                )
            ),

            'author' => trim(
                (string) (
                    $plugin['author']
                    ?? 'Unknown'
                )
            ),

            'logo' => trim(
                (string) (
                    $plugin['icon_url']
                    ?? ''
                )
            ),

            'profile_url' => trim(
                (string) (
                    $plugin['url']
                    ?? (
                        $slug !== ''
                            ? 'https://umod.org/plugins/' . $slug
                            : ''
                    )
                )
            ),

            'download_url' => trim(
                (string) (
                    $plugin['download_url']
                    ?? (
                        $name !== ''
                            ? 'https://umod.org/plugins/' . $name . '.cs'
                            : ''
                    )
                )
            ),

            'version' => trim(
                (string) (
                    $plugin['latest_release_version']
                    ?? ''
                )
            ),

            'version_formatted' => trim(
                (string) (
                    $plugin['latest_release_version_formatted']
                    ?? ''
                )
            ),

            'checksum' => trim(
                (string) (
                    $plugin['latest_release_version_checksum']
                    ?? ''
                )
            ),

            'released_at' => trim(
                (string) (
                    $plugin['latest_release_at_atom']
                    ?? $plugin['latest_release_at']
                    ?? ''
                )
            ),

            'downloads' => (int) (
                $plugin['downloads']
                ?? 0
            ),

            'watchers' => (int) (
                $plugin['watchers']
                ?? 0
            ),

            'tags' => $tags,

            'latest_file' => [
                'id' =>
                    trim(
                        (string) (
                            $plugin['latest_release_version']
                            ?? ''
                        )
                    )
                    . ':'
                    . trim(
                        (string) (
                            $plugin['latest_release_version_checksum']
                            ?? ''
                        )
                    ),

                'name' =>
                    $name !== ''
                        ? $name . '.cs'
                        : '',

                'filename' =>
                    $name !== ''
                        ? $name . '.cs'
                        : '',

                'version' => trim(
                    (string) (
                        $plugin['latest_release_version']
                        ?? ''
                    )
                ),

                'checksum' => trim(
                    (string) (
                        $plugin['latest_release_version_checksum']
                        ?? ''
                    )
                ),

                'download_url' =>
                    $name !== ''
                    && trim(
                        (string) (
                            $plugin['latest_release_version']
                            ?? ''
                        )
                    ) !== ''
                        ? 'https://umod.org/plugins/'
                            . rawurlencode($name)
                            . '.cs?version='
                            . rawurlencode(
                                trim(
                                    (string) (
                                        $plugin['latest_release_version']
                                        ?? ''
                                    )
                                )
                            )
                        : '',
            ],
        ];
    }

    /**
     * Return required uMod plugin slugs for the selected release.
     *
     * Suggested/optional dependencies are intentionally not installed
     * automatically.
     */
    public function requiredDependencies(
        string $slug,
        ?string $version = null,
        ?SourceContext $source = null
    ): array {
        $source = $this->sourceContext($source);
        $slug = $this->normalizeSlug($slug);

        $plugin = $this->get($slug, $source);

        if (!$plugin) {
            throw new RuntimeException(
                'Unable to retrieve uMod dependency metadata.'
            );
        }

        if ($version === null || trim($version) === '') {
            $version = trim(
                (string) (
                    $plugin['latest_file']['version']
                    ?? $plugin['version']
                    ?? ''
                )
            );
        }

        if ($version === '') {
            throw new RuntimeException(
                'Unable to determine the uMod release version.'
            );
        }

        $sourceName = $this->sourceName($plugin);

        $metadata = Cache::remember(
            $source->cacheKey(
                'static-plugin:' . strtolower($sourceName)
            ),
            now()->addHours(6),
            function () use ($sourceName): array {
                $response = Http::timeout(20)
                    ->acceptJson()
                    ->get(
                        'https://assets.umod.org/plugins/'
                        . rawurlencode($sourceName)
                        . '.json'
                    );

                if (!$response->successful()) {
                    throw new RuntimeException(
                        'Unable to retrieve uMod static plugin metadata.'
                    );
                }

                $json = $response->json();

                if (!is_array($json)) {
                    throw new RuntimeException(
                        'uMod returned invalid static plugin metadata.'
                    );
                }

                return $json;
            }
        );

        $release =
            $metadata['branches']['master'][$version]
            ?? null;

        if (!is_array($release)) {
            throw new RuntimeException(
                'uMod release dependency metadata was not found for version '
                . $version
                . '.'
            );
        }

        $requires = $release['requires'] ?? [];

        if (!is_array($requires)) {
            return [];
        }

        $dependencies = [];

        foreach ($requires as $requirementName => $requirement) {
            $name = $this->dependencyName(
                $requirement,
                is_string($requirementName)
                    ? $requirementName
                    : null
            );

            if ($name === '') {
                continue;
            }

            $dependencySlug =
                $this->slugForPluginName($name);

            if ($dependencySlug === $slug) {
                throw new RuntimeException(
                    'uMod returned a cyclic dependency.'
                );
            }

            $constraint = '';

            if (
                is_array($requirement)
                && isset($requirement['version'])
                && is_scalar($requirement['version'])
            ) {
                $constraint = trim(
                    (string) $requirement['version']
                );
            }

            $dependencies[$dependencySlug] = [
                'id' => $dependencySlug,
                'constraint' => $constraint,
            ];

            if ($constraint !== '') {
                $dependencies[$dependencySlug]['file_id'] =
                    $this->dependencyFileId(
                        $dependencySlug,
                        $constraint
                    );
            }
        }

        return array_values($dependencies);
    }

    /**
     * Return optional uMod integrations for the selected release.
     *
     * Suggestions are metadata only and are never installed automatically.
     */
    public function suggestedDependencies(
        string $slug,
        ?string $version = null,
        ?SourceContext $source = null
    ): array {
        $source = $this->sourceContext($source);
        $slug = $this->normalizeSlug($slug);

        $plugin = $this->get($slug, $source);

        if (!$plugin) {
            return [];
        }

        if ($version === null || trim($version) === '') {
            $version = trim(
                (string) (
                    $plugin['latest_file']['version']
                    ?? $plugin['version']
                    ?? ''
                )
            );
        }

        if ($version === '') {
            return [];
        }

        $sourceName = $this->sourceName($plugin);

        $metadata = Cache::remember(
            $source->cacheKey(
                'static-plugin:' . strtolower($sourceName)
            ),
            now()->addHours(6),
            function () use ($sourceName): array {
                $response = Http::timeout(20)
                    ->acceptJson()
                    ->get(
                        'https://assets.umod.org/plugins/'
                        . rawurlencode($sourceName)
                        . '.json'
                    );

                if (!$response->successful()) {
                    throw new RuntimeException(
                        'Unable to retrieve uMod optional dependency metadata.'
                    );
                }

                $json = $response->json();

                if (!is_array($json)) {
                    throw new RuntimeException(
                        'uMod returned invalid optional dependency metadata.'
                    );
                }

                return $json;
            }
        );

        $release =
            $metadata['branches']['master'][$version]
            ?? null;

        if (!is_array($release)) {
            return [];
        }

        $suggests = $release['suggests'] ?? [];

        if (!is_array($suggests)) {
            return [];
        }

        $dependencies = [];

        foreach ($suggests as $suggestionName => $suggestion) {
            $name = $this->dependencyName(
                $suggestion,
                is_string($suggestionName)
                    ? $suggestionName
                    : null
            );

            if ($name === '') {
                continue;
            }

            try {
                $dependencySlug =
                    $this->slugForPluginName($name);
            } catch (\Throwable) {
                continue;
            }

            if ($dependencySlug === $slug) {
                continue;
            }

            $constraint = '';

            if (
                is_array($suggestion)
                && isset($suggestion['version'])
                && is_scalar($suggestion['version'])
            ) {
                $constraint = trim(
                    (string) $suggestion['version']
                );
            }

            $dependencies[$dependencySlug] = [
                'id' => $dependencySlug,
                'name' => $name,
                'constraint' => $constraint,
            ];
        }

        return array_values($dependencies);
    }

    protected function versionMatches(
        string $version,
        string $constraint
    ): bool {
        $constraint = trim($constraint);

        if ($constraint === '' || $constraint === '*') {
            return true;
        }

        if (!str_contains($constraint, '*')) {
            return version_compare($version, $constraint, '==');
        }

        $prefix = rtrim($constraint, '.*');

        return $version === $prefix
            || str_starts_with($version, $prefix . '.');
    }

    protected function dependencyName(
        mixed $requirement,
        ?string $requirementName = null
    ): string {
        if (
            $requirementName !== null
            && trim($requirementName) !== ''
            && !ctype_digit($requirementName)
        ) {
            return trim($requirementName);
        }

        if (is_string($requirement)) {
            return trim($requirement);
        }

        if (!is_array($requirement)) {
            return '';
        }

        foreach (
            [
                'name',
                'plugin',
                'id',
                'slug',
                'package',
            ]
            as $key
        ) {
            if (
                isset($requirement[$key])
                && is_scalar($requirement[$key])
            ) {
                $value = trim(
                    (string) $requirement[$key]
                );

                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    protected function slugForPluginName(string $name): string
    {
        $name = trim($name);

        if ($name === '') {
            throw new RuntimeException(
                'uMod returned an empty dependency name.'
            );
        }

        $manifest = $this->pluginManifest();

        $needle = strtolower(
            preg_replace(
                '/[^a-z0-9]+/i',
                '',
                $name
            ) ?? ''
        );

        foreach ($manifest as $pluginName => $entry) {
            $candidates = [
                (string) $pluginName,
                (string) (
                    is_array($entry)
                        ? ($entry['title'] ?? '')
                        : ''
                ),
            ];

            foreach ($candidates as $candidate) {
                $normalized = strtolower(
                    preg_replace(
                        '/[^a-z0-9]+/i',
                        '',
                        $candidate
                    ) ?? ''
                );

                if (
                    $normalized !== ''
                    && $normalized === $needle
                ) {
                    $url =
                        is_array($entry)
                            ? (string) ($entry['url'] ?? '')
                            : '';

                    if (
                        preg_match(
                            '#/plugins/([a-z0-9][a-z0-9-]*)/?$#i',
                            $url,
                            $match
                        )
                    ) {
                        return strtolower($match[1]);
                    }

                    return strtolower(
                        preg_replace(
                            '/[^a-z0-9]+/i',
                            '-',
                            trim($candidate)
                        ) ?? ''
                    );
                }
            }
        }

        /*
         * Last-resort normalized slug. prepare() will still fail closed
         * if uMod does not recognize it.
         */
        $fallback = strtolower(
            trim(
                preg_replace(
                    '/[^a-z0-9]+/i',
                    '-',
                    $name
                ) ?? '',
                '-'
            )
        );

        if (
            $fallback === ''
            || !preg_match(
                '/^[a-z0-9][a-z0-9-]*$/',
                $fallback
            )
        ) {
            throw new RuntimeException(
                'Unable to resolve uMod dependency: '
                . $name
            );
        }

        return $fallback;
    }

    protected function pluginManifest(): array
    {
        return Cache::remember(
            'modharbor:umod:plugin-manifest:v1',
            now()->addHours(12),
            function (): array {
                $response = Http::timeout(30)
                    ->acceptJson()
                    ->get(
                        'https://assets.umod.org/plugins/manifest.json'
                    );

                if (!$response->successful()) {
                    throw new RuntimeException(
                        'Unable to retrieve the uMod plugin manifest.'
                    );
                }

                $json = $response->json();

                if (!is_array($json)) {
                    throw new RuntimeException(
                        'uMod returned an invalid plugin manifest.'
                    );
                }

                return $json;
            }
        );
    }

    protected function dependencyFileId(
        string $slug,
        string $constraint
    ): string {
        $plugin = $this->get($slug);

        if (!$plugin) {
            throw new RuntimeException(
                'Unable to retrieve uMod dependency ' . $slug . '.'
            );
        }

        $sourceName = $this->sourceName($plugin);

        $metadata = Cache::remember(
            'modharbor:umod:static-plugin:' . strtolower($sourceName),
            now()->addHours(6),
            function () use ($sourceName): array {
                $response = Http::timeout(20)
                    ->acceptJson()
                    ->get(
                        'https://assets.umod.org/plugins/'
                        . rawurlencode($sourceName)
                        . '.json'
                    );

                if (!$response->successful()) {
                    throw new RuntimeException(
                        'Unable to retrieve uMod dependency releases.'
                    );
                }

                $json = $response->json();

                if (!is_array($json)) {
                    throw new RuntimeException(
                        'Invalid uMod dependency release metadata.'
                    );
                }

                return $json;
            }
        );

        $releases =
            $metadata['branches']['master']
            ?? [];

        if (
            !is_array($releases)
            || $releases === []
        ) {
            throw new RuntimeException(
                'No uMod dependency releases were found.'
            );
        }

        $versions = array_keys($releases);

        usort($versions, fn ($a, $b) => version_compare($b, $a));

        foreach ($versions as $version) {
            if (!$this->versionMatches((string) $version, $constraint)) {
                continue;
            }

            $checksum = strtolower(trim((string) ($releases[$version]['checksum'] ?? '')));

            if (preg_match('/^[a-f0-9]{40}$/', $checksum)) {
                return $version . ':' . $checksum;
            }
        }

        throw new RuntimeException(
            'No uMod release of ' . $slug
            . ' satisfies version ' . $constraint . '.'
        );
    }

    protected function sourceName(array $plugin): string
    {
        $filename = trim(
            (string) (
                $plugin['latest_file']['name']
                ?? $plugin['latest_file']['filename']
                ?? ''
            )
        );

        if (
            preg_match(
                '/^([A-Za-z0-9_.-]+)\.cs$/',
                $filename,
                $match
            )
        ) {
            return $match[1];
        }

        $name = trim(
            (string) (
                $plugin['name']
                ?? ''
            )
        );

        if (
            $name !== ''
            && preg_match(
                '/^[A-Za-z0-9_.-]+$/',
                $name
            )
        ) {
            return $name;
        }

        throw new RuntimeException(
            'Unable to determine the uMod plugin source name.'
        );
    }

    protected function sourceContext(
        ?SourceContext $source = null
    ): SourceContext {
        if ($source === null) {
            throw new RuntimeException(
                'uMod operations require an explicit ModHarbor SourceContext.'
            );
        }

        if ($source->key() !== 'umod') {
            throw new RuntimeException(
                'Invalid source context supplied to the uMod provider.'
            );
        }

        return $source;
    }

    protected function normalizeSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));

        if (
            !preg_match(
                '/^[a-z0-9][a-z0-9-]*$/',
                $slug
            )
        ) {
            throw new RuntimeException(
                'Invalid uMod plugin identifier.'
            );
        }

        return $slug;
    }

}
