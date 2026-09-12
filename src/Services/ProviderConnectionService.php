<?php

namespace GameNest\GameNestModManager\Services;

use RuntimeException;
use Throwable;

/** Read-only probes; neither upstream response bodies nor exception chains leave this boundary. */
class ProviderConnectionService
{
    public function __construct(protected ProviderSettingsStore $store, protected ProviderHttpClient $http) {}

    public function values(string $provider, array $definition, array $draft = []): array
    {
        $values = [];
        foreach ($definition['credential_fields'] ?? [] as $key => $field) {
            $saved = $this->store->get($provider, $key,
                config('gamenest-mod-manager.' . $provider . '.' . $key, $field['default'] ?? '') ?? $field['default'] ?? '');
            $value = $draft[$key] ?? $saved;
            if (($field['type'] ?? '') === 'secret' && $value === '') { $value = $saved; }
            if (!is_scalar($value) && $value !== null) { throw new RuntimeException('Invalid provider setting.'); }
            $values[$key] = trim((string) $value);
        }
        return $values;
    }

    public function status(array $definition, array $values): string
    {
        foreach ($definition['credential_fields'] ?? [] as $key => $field) {
            if (!empty($field['required']) && ($values[$key] ?? '') === '') { return 'Not configured'; }
        }
        $required = $definition['credential_require_any'] ?? [];
        if ($required && !array_filter(array_intersect_key($values, array_flip($required)), fn ($v) => $v !== '')) {
            return 'Not configured';
        }
        return 'Configured';
    }

    public static function modioBase(string $url): string
    {
        $url = rtrim(trim($url), '/');
        if (!preg_match('~^https://(?:api\.mod\.io|api\.test\.mod\.io|[gu]-[1-9][0-9]*\.modapi\.io)/v1$~D', $url)) {
            throw new RuntimeException('Use an official mod.io HTTPS API URL ending in /v1.');
        }
        return $url;
    }

    public function test(string $provider, array $definition, array $draft = []): array
    {
        try {
            $v = $this->values($provider, $definition, $draft);
            if ($this->status($definition, $v) === 'Not configured') {
                return ['status' => 'Not configured', 'message' => 'Enter the required provider settings first.'];
            }
            $headers = []; $params = []; $method = 'GET';
            switch ($provider) {
                case 'modio':
                    $url = self::modioBase($v['api_path'] ?? '') . '/games';
                    $params = ['_limit' => 1];
                    if (!empty($v['api_key'])) { $params['api_key'] = $v['api_key']; }
                    break;
                case 'nexus':
                    $url = 'https://api.nexusmods.com/v1/users/validate.json';
                    $headers['apikey'] = $v['api_key'];
                    break;
                case 'curseforge':
                    $url = 'https://api.curseforge.com/v1/games';
                    $headers['x-api-key'] = $v['api_key']; $params = ['pageSize' => 1];
                    break;
                case 'github':
                    $url = 'https://api.github.com/' . (!empty($v['token']) ? 'user' : 'rate_limit');
                    if (!empty($v['token'])) { $headers['Authorization'] = 'Bearer ' . $v['token']; }
                    break;
                case 'modrinth':
                    $url = 'https://api.modrinth.com/v2/' . (!empty($v['token']) ? 'user' : 'tag/category');
                    if (!empty($v['token'])) { $headers['Authorization'] = $v['token']; }
                    break;
                case 'steam-workshop':
                    $url = 'https://api.steampowered.com/ISteamWebAPIUtil/GetSupportedAPIList/v1/';
                    $params = ['key' => $v['api_key'] ?? ''];
                    break;
                case 'umod':
                    $url = 'https://umod.org/plugins/search.json'; $params = ['query' => '', 'page' => 1];
                    break;
                case '7daystodiemods':
                    $url = 'https://api.7daystodiemods.com/v1/mods'; $params = ['limit' => 1];
                    break;
                case 'steamgriddb':
                    $url = 'https://www.steamgriddb.com/api/v2/search/autocomplete/minecraft';
                    $headers['Authorization'] = 'Bearer ' . ($v['api_key'] ?? '');
                    break;
                case 'thunderstore':
                    $url = 'https://thunderstore.io/api/experimental/community/';
                    break;
                default:
                    return ['status' => 'Not applicable', 'message' => 'This source has no remote connection to test.'];
            }
            $result = $this->http->send($method, $url, $params, $headers);
            if ($result['status'] < 200 || $result['status'] >= 300 || !is_array(json_decode($result['body'], true))) {
                return ['status' => 'Failed', 'message' => 'The provider rejected the check or returned invalid metadata. Check credentials, access and rate limits.'];
            }
            return ['status' => 'Connected', 'message' => $provider === 'steam-workshop'
                ? 'Steam API is reachable. This does not verify key permissions, SteamCMD downloads or game ownership.'
                : 'Read-only API check passed. Game compatibility and download entitlement require a package test.'];
        } catch (Throwable) {
            return ['status' => 'Failed', 'message' => 'Connection check failed. Review provider settings and network access.'];
        }
    }
}
