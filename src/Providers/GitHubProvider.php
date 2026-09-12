<?php

namespace GameNest\GameNestModManager\Providers;

use GameNest\GameNestModManager\Contracts\ConfiguredDownloadProvider;
use GameNest\GameNestModManager\Contracts\ModProvider;
use GameNest\GameNestModManager\Services\SourceContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use GameNest\GameNestModManager\Contracts\VersionListingProvider;

class GitHubProvider implements ModProvider, ConfiguredDownloadProvider, VersionListingProvider
{
    public function versions(string|int $id, SourceContext $source): array
    {
        $repository = $this->repository($id);
        $files = [];
        foreach ($this->releases((int) $repository['id'], (string) $repository['full_name']) as $release) {
            foreach ($release['assets'] ?? [] as $asset) { $files[] = $asset; if (count($files) >= 100) { return $files; } }
        }
        return $files;
    }

    public function key(): string
    {
        return 'github';
    }

    public function name(): string
    {
        return 'GitHub';
    }

    public function supportsSearch(): bool
    {
        /*
         * ModHarbor deliberately uses explicit repository lookup instead of
         * broad GitHub search. Searching all GitHub for "mods" produces too
         * much unrelated content to be a safe installation workflow.
         */
        return false;
    }

    public function search(
        string $query,
        array $options = [],
        ?SourceContext $source = null
    ): array
    {
        $repository = $this->repository($query);

        return $repository ? [$repository] : [];
    }

    public function get(
        string|int $id,
        ?SourceContext $source = null
    ): ?array
    {
        $repository = $this->repository($id);

        if (!$repository) {
            return null;
        }

        $release = $this->latestRelease(
            (int) $repository['id'],
            (string) $repository['full_name']
        );

        $repository['latest_release'] = $release;

        $repository['latest_file'] =
            $release['preferred_asset'] ?? [];

        return $repository;
    }

    /**
     * Resolve owner/repository, a GitHub URL, or a numeric GitHub repo ID.
     */
    public function repository(string|int $input): array
    {
        $target = $this->normalizeRepositoryInput($input);

        return Cache::remember(
            'modharbor:github:repo:' . sha1($target),
            now()->addMinutes(15),
            function () use ($target): array {
                $response = $this->request()->get(
                    'https://api.github.com/' . $target
                );

                if (!$response->successful()) {
                    throw $this->httpFailure(
                        'Unable to retrieve the GitHub repository',
                        $response->status(),
                        $response->header('X-RateLimit-Remaining')
                    );
                }

                $json = $response->json();

                if (!is_array($json) || (int) ($json['id'] ?? 0) < 1) {
                    throw new RuntimeException(
                        'GitHub returned invalid repository information.'
                    );
                }

                return $this->normalizeRepository($json);
            }
        );
    }

    /**
     * Data used by the Browse tab.
     */
    public function inspect(string $input): array
    {
        $repository = $this->repository($input);

        $release = $this->latestRelease(
            (int) $repository['id'],
            (string) $repository['full_name']
        );

        return [
            'repository' => $repository,
            'release' => $release,
            'assets' => $release['assets'] ?? [],
        ];
    }

    /**
     * Retrieve an exact release asset. Used for pinned reinstall.
     */
    public function file(
        int $repositoryId,
        int $assetId
    ): array {
        if ($repositoryId < 1 || $assetId < 1) {
            throw new RuntimeException(
                'Invalid GitHub repository or release asset ID.'
            );
        }

        $repository = $this->repository($repositoryId);

        $releases = $this->releases(
            $repositoryId,
            (string) $repository['full_name']
        );

        foreach ($releases as $release) {
            foreach ($release['assets'] ?? [] as $asset) {
                if ((int) ($asset['id'] ?? 0) === $assetId) {
                    return $asset;
                }
            }
        }

        throw new RuntimeException(
            'The installed GitHub release asset is no longer available.'
        );
    }

    protected function latestRelease(
        int $repositoryId,
        string $fullName
    ): array {
        $cacheKey =
            'modharbor:github:latest:' . $repositoryId;

        return Cache::remember(
            $cacheKey,
            now()->addMinutes(10),
            function () use ($fullName): array {
                $response = $this->request()->get(
                    'https://api.github.com/repos/' .
                    $fullName .
                    '/releases/latest'
                );

                if ($response->status() === 404) {
                    return [
                        'id' => 0,
                        'tag' => '',
                        'name' => '',
                        'published_at' => '',
                        'url' => '',
                        'assets' => [],
                        'preferred_asset' => [],
                    ];
                }

                if (!$response->successful()) {
                    throw $this->httpFailure(
                        'Unable to retrieve the latest GitHub release',
                        $response->status(),
                        $response->header('X-RateLimit-Remaining')
                    );
                }

                $json = $response->json();

                if (!is_array($json)) {
                    throw new RuntimeException(
                        'GitHub returned invalid release information.'
                    );
                }

                return $this->normalizeRelease($json);
            }
        );
    }

    /**
     * Cached release history lets ModHarbor locate an exact old asset for a
     * reinstall without relying on the latest release.
     */
    protected function releases(
        int $repositoryId,
        string $fullName
    ): array {
        return Cache::remember(
            'modharbor:github:releases:' . $repositoryId,
            now()->addMinutes(10),
            function () use ($fullName): array {
                $result = [];

                for ($page = 1; $page <= 10; $page++) {
                    $response = $this->request()->get(
                        'https://api.github.com/repos/' .
                        $fullName .
                        '/releases',
                        [
                            'per_page' => 100,
                            'page' => $page,
                        ]
                    );

                    if (!$response->successful()) {
                        throw $this->httpFailure(
                            'Unable to retrieve GitHub releases',
                            $response->status(),
                            $response->header(
                                'X-RateLimit-Remaining'
                            )
                        );
                    }

                    $json = $response->json();

                    if (!is_array($json)) {
                        throw new RuntimeException(
                            'GitHub returned invalid release information.'
                        );
                    }

                    foreach ($json as $release) {
                        if (is_array($release)) {
                            $result[] =
                                $this->normalizeRelease($release);
                        }
                    }

                    if (count($json) < 100) {
                        break;
                    }
                }

                return $result;
            }
        );
    }

    protected function normalizeRepositoryInput(
        string|int $input
    ): string {
        $input = trim((string) $input);

        if ($input === '') {
            throw new RuntimeException(
                'Enter a GitHub repository such as owner/repository.'
            );
        }

        if (ctype_digit($input)) {
            if ((int) $input < 1) {
                throw new RuntimeException(
                    'Invalid GitHub repository ID.'
                );
            }

            return 'repositories/' . (int) $input;
        }

        $input = preg_replace(
            '#^https?://(?:www\.)?github\.com/#i',
            '',
            $input
        );

        $input = preg_replace(
            '#^git@github\.com:#i',
            '',
            (string) $input
        );

        $input = preg_replace(
            '#\.git$#i',
            '',
            (string) $input
        );

        $input = trim((string) $input, '/');

        if (
            !preg_match(
                '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#D',
                $input
            )
        ) {
            throw new RuntimeException(
                'Use owner/repository or a GitHub repository URL.'
            );
        }

        return 'repos/' . $input;
    }

    protected function normalizeRepository(array $repo): array
    {
        $owner = is_array($repo['owner'] ?? null)
            ? $repo['owner']
            : [];

        return [
            'id' => (int) ($repo['id'] ?? 0),
            'provider' => 'github',
            'name' => trim(
                (string) (
                    $repo['name']
                    ?? 'Unknown Repository'
                )
            ),
            'full_name' => trim(
                (string) (
                    $repo['full_name']
                    ?? ''
                )
            ),
            'summary' => trim(
                (string) (
                    $repo['description']
                    ?? ''
                )
            ),
            'author' => trim(
                (string) (
                    $owner['login']
                    ?? 'Unknown'
                )
            ),
            'logo' => trim(
                (string) (
                    $owner['avatar_url']
                    ?? ''
                )
            ),
            'profile_url' => trim(
                (string) (
                    $repo['html_url']
                    ?? ''
                )
            ),
            'default_branch' => trim(
                (string) (
                    $repo['default_branch']
                    ?? ''
                )
            ),
            'date_updated' => strtotime(
                (string) (
                    $repo['updated_at']
                    ?? ''
                )
            ) ?: 0,
            'stars' => (int) (
                $repo['stargazers_count']
                ?? 0
            ),
            'downloads' => 0,
            'subscribers' => (int) (
                $repo['subscribers_count']
                ?? 0
            ),
        ];
    }

    protected function normalizeRelease(array $release): array
    {
        $tag = trim(
            (string) (
                $release['tag_name']
                ?? ''
            )
        );

        $assets = [];

        foreach ($release['assets'] ?? [] as $asset) {
            if (!is_array($asset)) {
                continue;
            }

            $normalized =
                $this->normalizeAsset($asset, $tag);

            if ($normalized !== null) {
                $assets[] = $normalized;
            }
        }

        usort(
            $assets,
            static function (
                array $a,
                array $b
            ): int {
                $aZip = str_ends_with(
                    strtolower($a['name']),
                    '.zip'
                ) ? 1 : 0;

                $bZip = str_ends_with(
                    strtolower($b['name']),
                    '.zip'
                ) ? 1 : 0;

                if ($aZip !== $bZip) {
                    return $bZip <=> $aZip;
                }

                return strcasecmp(
                    (string) $a['name'],
                    (string) $b['name']
                );
            }
        );

        $preferred = [];

        foreach ($assets as $asset) {
            if (
                str_ends_with(
                    strtolower($asset['name']),
                    '.zip'
                )
            ) {
                $preferred = $asset;
                break;
            }
        }

        if (
            $preferred === []
            && $assets !== []
        ) {
            $preferred =
                $assets[0];
        }

        return [
            'id' => (int) ($release['id'] ?? 0),
            'tag' => $tag,
            'name' => trim(
                (string) (
                    $release['name']
                    ?? $tag
                )
            ),
            'published_at' => trim(
                (string) (
                    $release['published_at']
                    ?? ''
                )
            ),
            'url' => trim(
                (string) (
                    $release['html_url']
                    ?? ''
                )
            ),
            'assets' => $assets,
            'preferred_asset' => $preferred,
        ];
    }

    protected function normalizeAsset(
        array $asset,
        string $tag
    ): ?array {
        $id = (int) ($asset['id'] ?? 0);

        $name = trim(
            (string) (
                $asset['name']
                ?? ''
            )
        );

        $url = trim(
            (string) (
                $asset['browser_download_url']
                ?? ''
            )
        );

        if (
            $id < 1
            || $name === ''
            || !str_starts_with($url, 'https://')
        ) {
            return null;
        }

        return [
            'id' => $id,
            'name' => $name,
            'version' => $tag !== ''
                ? $tag
                : $name,
            'download_url' => $url,
            'filesize' => (int) (
                $asset['size']
                ?? 0
            ),
            'downloads' => (int) (
                $asset['download_count']
                ?? 0
            ),
            'content_type' => trim(
                (string) (
                    $asset['content_type']
                    ?? ''
                )
            ),
        ];
    }

    protected function request()
    {
        $request = Http::acceptJson()
            ->withHeaders([
                'X-GitHub-Api-Version' =>
                    '2022-11-28',
                'User-Agent' =>
                    'ModHarbor-Pelican',
            ])
            ->timeout(30)
            ->connectTimeout(10)
            ->withOptions(['allow_redirects' => false]);

        $token = trim(
            (string) app(
                \GameNest\GameNestModManager\Services\ProviderSettingsStore::class
            )->get(
                'github',
                'token',
                config(
                    'gamenest-mod-manager.github.token',
                    ''
                )
            )
        );

        if ($token !== '') {
            $request = $request->withToken($token);
        }

        return $request;
    }

    protected function httpFailure(
        string $message,
        int $status,
        ?string $remaining
    ): RuntimeException {
        if (
            $status === 403
            && (string) $remaining === '0'
        ) {
            return new RuntimeException(
                'GitHub API rate limit reached. Try again later or configure a GitHub token.'
            );
        }

        return new RuntimeException(
            $message . ' (HTTP ' . $status . ').'
        );
    }
}
