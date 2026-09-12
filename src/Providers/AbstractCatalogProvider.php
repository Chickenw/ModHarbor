<?php

namespace GameNest\GameNestModManager\Providers;

use GameNest\GameNestModManager\Contracts\DiscoverableProvider;
use GameNest\GameNestModManager\Services\{DiscoveryQuery, ProviderHttpClient, ProviderSettingsStore, SourceContext};
use RuntimeException;

abstract class AbstractCatalogProvider implements DiscoverableProvider
{
    public function __construct(protected ?ProviderHttpClient $client = null) { $this->client ??= new ProviderHttpClient; }
    public function supportsSearch(): bool { return true; }
    public function search(string $query, array $options = [], ?SourceContext $source = null): array
    {
        return $this->discover(DiscoveryQuery::fromArray(['search' => $query] + $options), $this->context($source))->items;
    }
    protected function context(?SourceContext $source): SourceContext
    {
        if (!$source || $source->key() !== $this->key()) { throw new RuntimeException('Provider requires its explicit source context.'); }
        return $source;
    }
    protected function setting(string $name, bool $required = false): string
    {
        $fallback = config(
            'gamenest-mod-manager.' . $this->key() . '.' . $name,
            ''
        );

        $value = app(ProviderSettingsStore::class)->get(
            $this->key(),
            $name,
            $fallback
        );

        $value = trim((string) $value);

        if ($required && $value === '') {
            throw new RuntimeException(
                $this->name()
                . ' requires global provider configuration: '
                . $name
                . '.'
            );
        }

        return $value;
    }
    protected function json(string $url, array $parameters = [], array $headers = [], string $method = 'GET'): ?array
    {
        $response = $this->client->send($method, $url, $parameters, $headers);
        if ($response['status'] === 404) { return null; }
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException($this->name() . ' request rejected (HTTP ' . (int) $response['status'] . '). Check provider access or retry later.');
        }
        $data = json_decode($response['body'], true);
        if (!is_array($data)) { throw new RuntimeException($this->name() . ' returned invalid metadata.'); }
        return $data;
    }
    protected function numeric(string|int $value): string
    {
        $value = (string) $value;
        if (!preg_match('/^[1-9][0-9]{0,19}$/D', $value)) { throw new RuntimeException('Invalid provider numeric identifier.'); }
        return $value;
    }
    protected function slug(string|int $value): string
    {
        $value = (string) $value;
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,159}$/D', $value)) { throw new RuntimeException('Invalid provider identifier.'); }
        return $value;
    }
    protected function text(mixed $value): string { return trim(html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8')); }
    protected function url(mixed $value): string
    {
        $value = (string) $value;
        $parts = parse_url($value);
        return is_array($parts) && ($parts['scheme'] ?? '') === 'https' && !empty($parts['host'])
            && !isset($parts['user']) && !isset($parts['pass']) && !preg_match('/[\x00-\x20\x7f]/', $value) ? $value : '';
    }
    protected function item(string|int $id, string $name, array $values = []): array
    {
        if ((string) $id === '' || $name === '') { throw new RuntimeException($this->name() . ' returned invalid item identity.'); }
        return array_replace(['id' => (string) $id, 'provider' => $this->key(), 'name' => $this->text($name),
            'summary' => '', 'author' => '', 'logo' => '', 'profile_url' => '', 'date_updated' => 0,
            'downloads' => 0, 'subscribers' => 0, 'latest_file' => [], 'dependencies' => []], $values);
    }
    protected function file(string|int $id, string $filename, string $version, array $values = []): array
    {
        if ((string) $id === '' || $filename === '' || preg_match('#[/\\\\\x00-\x1f\x7f:]#', $filename) || in_array($filename, ['.', '..'], true)) {
            throw new RuntimeException('Provider returned an unsafe package filename.');
        }
        return array_replace(['id' => (string) $id, 'filename' => $filename, 'name' => $filename,
            'version' => $version ?: (string) $id, 'download_url' => '', 'filesize' => 0, 'dependencies' => [], 'hashes' => []], $values);
    }
}
