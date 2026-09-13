<?php

namespace GameNest\GameNestModManager\Providers;

use GameNest\GameNestModManager\Contracts\ConfiguredDownloadProvider;
use GameNest\GameNestModManager\Contracts\VersionedDownloadProvider;
use GameNest\GameNestModManager\Contracts\VersionListingProvider;
use GameNest\GameNestModManager\Services\DiscoveryQuery;
use GameNest\GameNestModManager\Services\DiscoveryResult;
use GameNest\GameNestModManager\Services\SourceContext;
use RuntimeException;

class ThunderstoreProvider extends AbstractCatalogProvider implements ConfiguredDownloadProvider, VersionedDownloadProvider, VersionListingProvider
{
    public function key(): string
    {
        return 'thunderstore';
    }

    public function name(): string
    {
        return 'Thunderstore';
    }

    private function community(SourceContext $source): string
    {
        $community = strtolower(trim((string) $source->require('community')));
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $community)) {
            throw new RuntimeException('Thunderstore community is not configured.');
        }

        return $community;
    }

    public function discover(DiscoveryQuery $query, SourceContext $source): DiscoveryResult
    {
        $community = $this->community($source);
        $page = max(1, $query->page);
        $params = [
            'deprecated' => 'False',
            'nsfw' => 'False',
            'ordering' => ['downloads' => 'most-downloaded', 'newest' => 'newest', 'rating' => 'top-rated', 'updated' => 'last-updated'][$query->sort] ?? 'last-updated',
            'page' => $page,
        ];
        if ($query->search !== '') {
            $params['q'] = $query->search;
        }

        $payload = $this->json(
            'https://thunderstore.io/api/cyberstorm/listing/' . rawurlencode($community) . '/',
            $params
        );
        if (!is_array($payload)) {
            throw new RuntimeException('Thunderstore catalog is unavailable.');
        }

        $items = [];
        foreach ((array) ($payload['results'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $items[] = $this->normalizeListing($row, $community);
        }

        return DiscoveryResult::page(
            $items,
            (int) ($payload['count'] ?? count($items)),
            $page,
            max(1, count($items) ?: $query->perPage)
        );
    }

    public function get(string|int $id, ?SourceContext $source = null): ?array
    {
        $source = $this->context($source);
        $community = $this->community($source);
        [$namespace, $name] = $this->splitId($id);

        $payload = $this->json(
            'https://thunderstore.io/api/experimental/package/'
            . rawurlencode($namespace) . '/'
            . rawurlencode($name) . '/'
        );
        if (!is_array($payload)) {
            return null;
        }

        return $this->normalizePackage($payload, $community);
    }

    public function versions(string|int $id, SourceContext $source): array
    {
        $mod = $this->get($id, $source);

        return is_array($mod) ? array_values($mod['files'] ?? []) : [];
    }

    public function package(string|int $id, string|int|null $fileId, SourceContext $source): array
    {
        $mod = $this->get($id, $source);
        if (!$mod) {
            throw new RuntimeException('Thunderstore package is unavailable.');
        }

        $file = $mod['latest_file'] ?? [];
        if ($fileId !== null && $fileId !== '') {
            $choices = array_values(array_filter(
                $mod['files'] ?? [],
                static fn ($row): bool => (string) ($row['id'] ?? '') === (string) $fileId
            ));
            if (count($choices) !== 1) {
                throw new RuntimeException('Requested Thunderstore file is no longer available.');
            }
            $file = $choices[0];
        }

        if (($file['download_url'] ?? '') === '') {
            throw new RuntimeException('Thunderstore did not provide a package URL.');
        }

        return $file;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitId(string|int $id): array
    {
        $id = trim((string) $id);
        if (str_contains($id, '/')) {
            [$namespace, $name] = explode('/', $id, 2);
        } else {
            $parts = explode('-', $id, 2);
            $namespace = $parts[0] ?? '';
            $name = $parts[1] ?? '';
        }

        $namespace = $this->slug($namespace);
        $name = $this->slug($name);

        return [$namespace, $name];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeListing(array $row, string $community): array
    {
        $namespace = $this->slug($row['namespace'] ?? '');
        $name = $this->slug($row['name'] ?? '');
        $id = $namespace . '/' . $name;

        return $this->item($id, (string) ($row['name'] ?? $name), [
            'summary' => $this->text($row['description'] ?? ''),
            'author' => $this->text($row['namespace'] ?? ''),
            'logo' => $this->url($row['icon_url'] ?? ''),
            'profile_url' => 'https://thunderstore.io/c/' . rawurlencode($community) . '/p/' . rawurlencode($namespace) . '/' . rawurlencode($name) . '/',
            'downloads' => (int) ($row['download_count'] ?? 0),
            'date_updated' => strtotime((string) ($row['last_updated'] ?? '')) ?: 0,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizePackage(array $payload, string $community): array
    {
        $namespace = $this->slug($payload['namespace'] ?? '');
        $name = $this->slug($payload['name'] ?? '');
        $id = $namespace . '/' . $name;
        $latest = is_array($payload['latest'] ?? null) ? $payload['latest'] : [];
        $version = (string) ($latest['version_number'] ?? '');
        $url = $this->url($latest['download_url'] ?? '');
        $filename = $namespace . '-' . $name . '-' . preg_replace('/[^A-Za-z0-9._-]+/', '-', $version) . '.zip';

        $deps = $this->requiredDependencies($latest['dependencies'] ?? []);

        $file = [];
        if ($version !== '' && $url !== '') {
            $file = $this->file($version, $filename, $version, [
                'download_url' => $url,
                'filesize' => 0,
                'date_updated' => strtotime((string) ($latest['date_created'] ?? '')) ?: 0,
                'dependencies' => $deps,
            ]);
        }

        return $this->item($id, (string) ($payload['name'] ?? $name), [
            'summary' => $this->text($latest['description'] ?? ''),
            'author' => $this->text($payload['owner'] ?? $namespace),
            'logo' => $this->url($latest['icon'] ?? ''),
            'profile_url' => $this->url($payload['package_url'] ?? ('https://thunderstore.io/c/' . $community . '/p/' . $namespace . '/' . $name . '/')),
            'downloads' => (int) ($payload['total_downloads'] ?? 0),
            'date_updated' => strtotime((string) ($payload['date_updated'] ?? '')) ?: 0,
            'latest_file' => $file,
            'files' => $file === [] ? [] : [$file],
            'dependencies' => $deps,
        ]);
    }

    /**
     * Thunderstore versions list dependencies as "Namespace-Name-1.2.3".
     * BepInEx loader packs are skipped; they are not managed plugin zips.
     *
     * @return list<array{provider: string, id: string, type: string}>
     */
    private function requiredDependencies(mixed $raw): array
    {
        $deps = [];
        foreach ((array) $raw as $entry) {
            if (!is_string($entry) || $entry === '') {
                continue;
            }
            if (!preg_match('/^([A-Za-z0-9_]+)-([A-Za-z0-9_]+)-([0-9]+(?:[.][0-9A-Za-z]+)+)$/', $entry, $m)) {
                continue;
            }
            $name = $this->slug($m[2]);
            if (str_starts_with(strtolower($name), 'bepinexpack')) {
                continue;
            }
            $deps[] = [
                'provider' => $this->key(),
                'id' => $this->slug($m[1]) . '/' . $name,
                'type' => 'required',
            ];
        }

        return $deps;
    }
}
