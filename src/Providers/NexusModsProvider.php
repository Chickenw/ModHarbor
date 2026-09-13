<?php

namespace GameNest\GameNestModManager\Providers;

use GameNest\GameNestModManager\Contracts\VersionedDownloadProvider;
use GameNest\GameNestModManager\Contracts\VersionListingProvider;
use GameNest\GameNestModManager\Services\{
    DiscoveryQuery,
    DiscoveryResult,
    ProviderHttpClient,
    SourceContext
};
use RuntimeException;

class NexusModsProvider extends AbstractCatalogProvider implements VersionedDownloadProvider, VersionListingProvider
{
    public function key(): string
    {
        return 'nexus';
    }

    public function name(): string
    {
        return 'Nexus Mods';
    }

    private function api(string $path): ?array
    {
        return $this->json(
            'https://api.nexusmods.com/v1/' . $path . '.json',
            [],
            [
                'apikey' => $this->setting('api_key', true),
                'Application-Name' => 'ModHarbor',
                'Application-Version' => '1.0.0-rc.3',
            ]
        );
    }

    private function graphql(
        string $query,
        array $variables = []
    ): array {
        $response = $this->client->send(
            'POST',
            'https://api.nexusmods.com/v2/graphql',
            [
                'query' => $query,
                'variables' => $variables,
            ],
            [
                'apikey' => $this->setting('api_key', true),
                'Application-Name' => 'ModHarbor',
                'Application-Version' => '1.0.0-rc.3',
            ]
        );

        $status = (int) ($response['status'] ?? 0);

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException(
                'Nexus Mods catalog request failed (HTTP '
                . $status
                . ').'
            );
        }

        $body = json_decode(
            (string) ($response['body'] ?? ''),
            true
        );

        if (!is_array($body)) {
            throw new RuntimeException(
                'Nexus Mods returned an invalid catalog response.'
            );
        }

        if (!empty($body['errors'])) {
            throw new RuntimeException(
                'Nexus Mods catalog request was rejected.'
            );
        }

        return $body;
    }

    private function domain(SourceContext $source): string
    {
        return $this->slug(
            $this->context($source)->require('domain')
        );
    }

    public function discover(
        DiscoveryQuery $query,
        SourceContext $source
    ): DiscoveryResult {
        $domain = $this->domain($source);

        /*
         * Numeric searches remain exact REST lookups.
         */
        $search = trim($query->search);

        if ($search !== '' && ctype_digit($search)) {
            $mod = $this->get($search, $source);

            $items = $mod ? [$mod] : [];

            return DiscoveryResult::page(
                $items,
                count($items),
                1,
                max(1, $query->perPage),
                meta: [
                    'search_scope' =>
                        'Exact Nexus mod ID lookup.',
                ]
            );
        }

        /*
         * Nexus GraphQL supports real game-scoped catalog search,
         * sorting and offset pagination.
         */
        $size = max(
            1,
            min(50, $query->perPage)
        );

        $page = max(
            1,
            $query->page
        );

        $offset = ($page - 1) * $size;

        $filters = [
            [
                'gameDomainName' => [
                    'value' => $domain,
                    'op' => 'EQUALS',
                ],
            ],
        ];

        if ($search !== '') {
            $filters[] = [
                'name' => [
                    'value' => $search,
                    'op' => 'WILDCARD',
                ],
            ];
        }

        foreach (['published_period' => 'createdAt', 'updated_period' => 'updatedAt'] as $key => $field) {
            $period = (string) $query->filter($key, 'all');
            $days = ['7d' => 7, '14d' => 14, '28d' => 28, '1y' => 365][$period] ?? null;
            if ($days !== null) {
                $filters[] = [$field => ['value' => gmdate('Y-m-d\TH:i:s\Z', time() - $days * 86400), 'op' => 'GTE']];
            }
        }
        $filter = [
            'filter' => $filters,
            'op' => 'AND',
        ];

        $sort = match ($query->sort) {
            'endorsements' => [['endorsements' => ['direction' => 'DESC']]],
            'unique_downloads' => [['uniqueDownloads' => ['direction' => 'DESC']]],
            'relevance' => [['relevance' => ['direction' => 'DESC']]],
            'size' => [['size' => ['direction' => 'DESC']]],
            'last_comment' => [['lastComment' => ['direction' => 'DESC']]],
            'newest' => [
                [
                    'createdAt' => [
                        'direction' => 'DESC',
                    ],
                ],
            ],

            'downloads' => [
                [
                    'downloads' => [
                        'direction' => 'DESC',
                    ],
                ],
            ],

            'name' => [
                [
                    'name' => [
                        'direction' => 'ASC',
                    ],
                ],
            ],

            'updated' => [
                [
                    'updatedAt' => [
                        'direction' => 'DESC',
                    ],
                ],
            ],

            default => [
                [
                    'updatedAt' => [
                        'direction' => 'DESC',
                    ],
                ],
            ],
        };

        $gql = <<<'GQL'
query ModHarborNexusMods(
    $filter: ModsFilter,
    $sort: [ModsSort!],
    $count: Int,
    $offset: Int
) {
    mods(
        filter: $filter,
        sort: $sort,
        count: $count,
        offset: $offset
    ) {
        totalCount
        nodes {
            modId
            name
            summary
            author
            pictureUrl
            downloads
            updatedAt
            createdAt
            game {
                domainName
                name
                id
            }
        }
    }
}
GQL;

        $response = $this->graphql(
            $gql,
            [
                'filter' => $filter,
                'sort' => $sort,
                'count' => $size,
                'offset' => $offset,
            ]
        );

        $result = $response['data']['mods'] ?? null;

        if (!is_array($result)) {
            throw new RuntimeException(
                'Nexus Mods returned an invalid catalog.'
            );
        }

        $rows = $result['nodes'] ?? [];

        if (!is_array($rows)) {
            throw new RuntimeException(
                'Nexus Mods returned invalid catalog items.'
            );
        }

        $items = [];

        foreach ($rows as $record) {
            if (!is_array($record)) {
                continue;
            }

            try {
                $items[] = $this->normalizeGraphql(
                    $record,
                    $domain
                );
            } catch (RuntimeException) {
                /*
                 * Nexus may expose stale/unavailable catalog records.
                 * One malformed record must not break the whole page.
                 */
                continue;
            }
        }

        $total = max(
            0,
            (int) ($result['totalCount'] ?? count($items))
        );

        return DiscoveryResult::page(
            $items,
            $total,
            $page,
            $size,
            meta: [
                'search_scope' =>
                    'Full Nexus Mods catalog for this game.',
            ]
        );
    }

    public function get(
        string|int $id,
        ?SourceContext $source = null
    ): ?array {
        $source = $this->context($source);
        $domain = $this->domain($source);
        $id = $this->numeric($id);

        $p = $this->api(
            'games/' . $domain . '/mods/' . $id
        );

        if ($p === null) {
            return null;
        }

        $mod = $this->normalize(
            $p,
            $domain
        );

        if ($mod['id'] !== $id) {
            throw new RuntimeException(
                'Nexus returned another mod.'
            );
        }

        $data = $this->api(
            'games/'
            . $domain
            . '/mods/'
            . $id
            . '/files'
        );

        if (!is_array($data['files'] ?? null)) {
            throw new RuntimeException(
                'Nexus returned invalid files.'
            );
        }

        $main = array_values(
            array_filter(
                $data['files'],
                fn ($f) =>
                    ($f['category_id'] ?? 0) === 1
            )
        );

        if (count($main) === 1) {
            $mod['latest_file'] =
                $this->normalizeFile($main[0]);
        } elseif (count($main) > 1) {
            $mod['unavailable_reason'] =
                'Multiple main packages; select and upload the appropriate file.';
        }

        return $mod;
    }

    public function versions(
        string|int $id,
        SourceContext $source
    ): array {
        $this->get($id, $source);

        $data = $this->api(
            'games/'
            . $this->domain($source)
            . '/mods/'
            . $this->numeric($id)
            . '/files'
        );

        $files = array_values(
            array_filter(
                $data['files'] ?? [],
                fn ($f) => in_array(
                    $f['category_id'] ?? 0,
                    [1, 2, 3, 4, 6],
                    true
                )
            )
        );

        return array_map(
            fn ($f) => $this->normalizeFile($f),
            array_slice($files, 0, 100)
        );
    }

    public function package(
        string|int $id,
        string|int|null $fileId,
        SourceContext $source
    ): array {
        $domain = $this->domain($source);
        $id = $this->numeric($id);

        if ($fileId === null) {
            $file = $this->get(
                $id,
                $source
            )['latest_file'] ?? [];
        } else {
            $data = $this->api(
                'games/'
                . $domain
                . '/mods/'
                . $id
                . '/files/'
                . $this->numeric($fileId)
            );

            if (
                !$data
                || !in_array(
                    $data['category_id'] ?? 0,
                    [1, 2, 3, 4, 6],
                    true
                )
            ) {
                throw new RuntimeException(
                    'Nexus file is unavailable.'
                );
            }

            $file = $this->normalizeFile($data);
        }

        if (empty($file['id'])) {
            throw new RuntimeException(
                'Nexus has no unambiguous main package. Download the appropriate file on Nexus and use Upload File.'
            );
        }

        /*
         * Nexus direct download-link generation can require
         * eligible/Premium API access.
         */
        $downloadUrl =
            'https://api.nexusmods.com/v1/games/'
            . $domain
            . '/mods/'
            . $id
            . '/files/'
            . $file['id']
            . '/download_link.json';

        $response = $this->client->send(
            'GET',
            $downloadUrl,
            [],
            [
                'apikey' => $this->setting('api_key', true),
                'Application-Name' => 'ModHarbor',
                'Application-Version' => '1.0.0-rc.3',
            ]
        );

        $status = (int) ($response['status'] ?? 0);

        if ($status === 401) {
            throw new RuntimeException(
                'Nexus rejected the configured API key. Check the Nexus API key in Provider Settings.'
            );
        }

        if ($status === 403) {
            throw new RuntimeException(
                'Nexus direct installation is not available for this account. Open this mod on Nexus, download the file there, then install it with Upload File.'
            );
        }

        if ($status === 404) {
            throw new RuntimeException(
                'This Nexus file is no longer available for direct download. Open the mod on Nexus and select another file.'
            );
        }

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException(
                'Nexus could not generate a direct download link (HTTP '
                . $status
                . '). Try again later or download the file on Nexus and use Upload File.'
            );
        }

        $links = json_decode(
            (string) ($response['body'] ?? ''),
            true
        );

        if (!is_array($links)) {
            throw new RuntimeException(
                'Nexus returned an invalid download-link response.'
            );
        }

        $file['download_url'] = $this->url(
            $links[0]['URI'] ?? ''
        );

        if ($file['download_url'] === '') {
            throw new RuntimeException(
                'Nexus did not provide a usable direct download link. Download the file on Nexus and use Upload File.'
            );
        }

        return $file;
    }

    private function normalize(
        array $p,
        string $domain
    ): array {
        if (
            isset($p['domain_name'])
            && $p['domain_name'] !== $domain
        ) {
            throw new RuntimeException(
                'Nexus mod belongs to another domain.'
            );
        }

        $id = $this->numeric(
            $p['mod_id'] ?? ''
        );

        return $this->item(
            $id,
            (string) ($p['name'] ?? ''),
            [
                'summary' =>
                    $this->text($p['summary'] ?? ''),

                'author' =>
                    $this->text($p['author'] ?? ''),

                'logo' =>
                    $this->url($p['picture_url'] ?? ''),

                'profile_url' =>
                    'https://www.nexusmods.com/'
                    . $domain
                    . '/mods/'
                    . $id,

                'date_updated' =>
                    (int) ($p['updated_timestamp'] ?? 0),

                'downloads' =>
                    (int) ($p['mod_downloads'] ?? 0),
            ]
        );
    }

    private function normalizeGraphql(
        array $p,
        string $domain
    ): array {
        $actualDomain = trim(
            (string) ($p['game']['domainName'] ?? '')
        );

        if (
            $actualDomain !== ''
            && $actualDomain !== $domain
        ) {
            throw new RuntimeException(
                'Nexus mod belongs to another domain.'
            );
        }

        $id = $this->numeric(
            $p['modId'] ?? ''
        );

        $name = trim(
            (string) ($p['name'] ?? '')
        );

        if ($name === '') {
            throw new RuntimeException(
                'Nexus Mods returned invalid item identity.'
            );
        }

        $updated = trim(
            (string) ($p['updatedAt'] ?? '')
        );

        return $this->item(
            $id,
            $name,
            [
                'summary' =>
                    $this->text($p['summary'] ?? ''),

                'author' =>
                    $this->text($p['author'] ?? ''),

                'logo' =>
                    $this->url($p['pictureUrl'] ?? ''),

                'profile_url' =>
                    'https://www.nexusmods.com/'
                    . $domain
                    . '/mods/'
                    . $id,

                'date_updated' =>
                    $updated !== ''
                        ? ((int) strtotime($updated))
                        : 0,

                'downloads' =>
                    (int) ($p['downloads'] ?? 0),
            ]
        );
    }

    private function normalizeFile(array $f): array
    {
        return $this->file(
            $this->numeric(
                $f['file_id'] ?? ''
            ),
            (string) ($f['file_name'] ?? ''),
            (string) ($f['version'] ?? ''),
            [
                'filesize' =>
                    (int) ($f['size_in_bytes'] ?? 0),
            ]
        );
    }
}
