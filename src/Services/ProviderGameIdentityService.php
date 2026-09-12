<?php

namespace GameNest\GameNestModManager\Services;

use RuntimeException;
use Throwable;

class ProviderGameIdentityService
{
    public function __construct(
        private ProviderHttpClient $http,
        private ProviderSettingsStore $settings,
    ) {}

    public function discover(
        string $provider,
        string $gameName,
        ?int $steamAppId = null
    ): array {
        $provider = strtolower(trim($provider));
        $gameName = trim($gameName);

        if ($provider === '' || $gameName === '') {
            return [
                'status' => 'unverified',
                'metadata' => [],
                'message' => 'Game identity is incomplete.',
            ];
        }

        return match ($provider) {
            'nexus' => $this->discoverNexus($gameName),
            'steam-workshop' => $this->discoverSteamWorkshop($steamAppId),
            'thunderstore' => $this->discoverThunderstore($gameName),
            default => [
                'status' => 'unverified',
                'metadata' => [],
                'message' => 'Automatic identity discovery is not available for this provider yet.',
            ],
        };
    }

    private function discoverSteamWorkshop(?int $steamAppId): array
    {
        $appId = (int) ($steamAppId ?? 0);
        if ($appId < 1) {
            return [
                'status' => 'unverified',
                'metadata' => [],
                'message' => 'Steam App ID is required for Steam Workshop.',
            ];
        }

        return [
            'status' => 'supported',
            'metadata' => [
                'app_id' => $appId,
            ],
            'message' => 'Steam Workshop can use App ID ' . $appId . '.',
        ];
    }

    private function discoverNexus(string $gameName): array
    {
        $apiKey = trim((string) $this->settings->get(
            'nexus',
            'api_key',
            ''
        ));

        if ($apiKey === '') {
            return [
                'status' => 'needs_configuration',
                'metadata' => [],
                'message' => 'Nexus API key is not configured.',
            ];
        }

        try {
            $response = $this->http->send(
                'GET',
                'https://api.nexusmods.com/v1/games.json',
                [],
                [
                    'apikey' => $apiKey,
                    'Application-Name' => 'ModHarbor',
                    'Application-Version' => '1.0.0-rc.3',
                ]
            );

            if (($response['status'] ?? 0) !== 200) {
                return [
                    'status' => 'unverified',
                    'metadata' => [],
                    'message' => 'Nexus could not be verified right now.',
                ];
            }

            $games = json_decode(
                (string) ($response['body'] ?? ''),
                true,
                32,
                JSON_THROW_ON_ERROR
            );

            if (!is_array($games)) {
                throw new RuntimeException('Invalid Nexus games response.');
            }

            $target = $this->normalizeName($gameName);

            $exact = [];

            foreach ($games as $game) {
                if (!is_array($game)) {
                    continue;
                }

                $name = trim((string) ($game['name'] ?? ''));
                $domain = trim((string) ($game['domain_name'] ?? ''));

                if ($name === '' || $domain === '') {
                    continue;
                }

                $normalized = $this->normalizeName($name);

                $mods = $game['mods'] ?? $game['mod_count'] ?? null;
                $modCount = is_numeric($mods) ? (int) $mods : null;

                // Exact name or exact domain only. Substring matches selected
                // unrelated games and caused Nexus to appear on every Steam pick.
                if ($normalized === $target || $this->normalizeName($domain) === $target) {
                    $exact[] = [
                        'name' => $name,
                        'domain' => $domain,
                        'mods' => $modCount,
                    ];
                }
            }

            $exact = array_values(array_filter(
                $exact,
                static fn (array $row): bool =>
                    $row['mods'] === null || (int) $row['mods'] > 0
            ));

            if (count($exact) === 1) {
                return [
                    'status' => 'supported',
                    'metadata' => [
                        'domain' => $exact[0]['domain'],
                    ],
                    'message' => 'Found on Nexus Mods as ' . $exact[0]['name']
                        . ' with published mods.',
                ];
            }

            if (count($exact) > 1) {
                return [
                    'status' => 'ambiguous',
                    'metadata' => [],
                    'message' => 'Multiple exact Nexus game matches were found.',
                ];
            }

            return [
                'status' => 'not_found',
                'metadata' => [],
                'message' => 'This game was not found on Nexus Mods, or Nexus lists no mods for it.',
            ];
        } catch (Throwable) {
            return [
                'status' => 'unverified',
                'metadata' => [],
                'message' => 'Nexus could not be verified right now.',
            ];
        }
    }


    private function discoverThunderstore(string $gameName): array
    {
        $target = $this->normalizeName($gameName);
        $slug = strtolower(trim($gameName));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        if ($slug !== '') {
            try {
                $direct = $this->http->send(
                    'GET',
                    'https://thunderstore.io/api/cyberstorm/community/' . rawurlencode($slug) . '/'
                );
                if ((int) ($direct['status'] ?? 0) === 200) {
                    $row = json_decode((string) ($direct['body'] ?? ''), true, 32, JSON_THROW_ON_ERROR);
                    $identifier = strtolower(trim((string) ($row['identifier'] ?? $slug)));
                    $name = trim((string) ($row['name'] ?? $gameName));
                    if ($identifier !== '') {
                        return [
                            'status' => 'supported',
                            'metadata' => [
                                'community' => $identifier,
                            ],
                            'message' => 'Found on Thunderstore as ' . ($name !== '' ? $name : $identifier) . '.',
                        ];
                    }
                }
            } catch (Throwable) {
                // Fall through to the community index.
            }
        }

        try {
            $url = 'https://thunderstore.io/api/experimental/community/';
            $exact = [];
            $guard = 0;

            while (is_string($url) && $url !== '' && $guard < 12) {
                $guard++;
                $response = $this->http->send('GET', $url);
                if ((int) ($response['status'] ?? 0) !== 200) {
                    break;
                }
                $payload = json_decode((string) ($response['body'] ?? ''), true, 32, JSON_THROW_ON_ERROR);
                $rows = $payload['results'] ?? [];
                if (!is_array($rows)) {
                    break;
                }
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $identifier = strtolower(trim((string) ($row['identifier'] ?? '')));
                    $name = trim((string) ($row['name'] ?? ''));
                    if ($identifier === '') {
                        continue;
                    }
                    if (
                        $this->normalizeName($identifier) === $target
                        || $this->normalizeName($name) === $target
                    ) {
                        $exact[] = [
                            'name' => $name !== '' ? $name : $identifier,
                            'community' => $identifier,
                        ];
                    }
                }
                $next = $payload['pagination']['next_link'] ?? $payload['next'] ?? null;
                $url = is_string($next) && str_starts_with($next, 'https://thunderstore.io/')
                    ? $next
                    : '';
            }

            $unique = [];
            foreach ($exact as $row) {
                $unique[$row['community']] = $row;
            }
            $exact = array_values($unique);

            if (count($exact) === 1) {
                return [
                    'status' => 'supported',
                    'metadata' => [
                        'community' => $exact[0]['community'],
                    ],
                    'message' => 'Found on Thunderstore as ' . $exact[0]['name'] . '.',
                ];
            }
            if (count($exact) > 1) {
                return [
                    'status' => 'ambiguous',
                    'metadata' => [],
                    'message' => 'Multiple Thunderstore communities matched this game.',
                ];
            }

            return [
                'status' => 'not_found',
                'metadata' => [],
                'message' => 'This game was not found on Thunderstore.',
            ];
        } catch (Throwable) {
            return [
                'status' => 'unverified',
                'metadata' => [],
                'message' => 'Thunderstore could not be verified right now.',
            ];
        }
    }

    private function normalizeName(string $name): string
    {
        $name = strtolower(trim($name));

        return preg_replace('/[^a-z0-9]+/', '', $name) ?? '';
    }
}
