<?php

namespace GameNest\GameNestModManager\Services;

use App\Models\Server;
use GameNest\GameNestModManager\Contracts\DiscoverableProvider;
use GameNest\GameNestModManager\Contracts\ModProvider;
use RuntimeException;

/**
 * Shared source/provider registry.
 *
 * Game adapters declare which sources exist for a game. This registry owns the
 * provider implementation, capabilities, discovery schema, and lifecycle
 * availability. No provider is allowed to infer the current game implicitly.
 */
class SourceRegistry
{
    public function __construct(
        protected AdapterRegistry $adapters,
        protected ProviderContext $contexts,
        protected ?ProviderSdk $sdk = null,
    ) {
        $this->sdk ??= new ProviderSdk;
    }

    public function definitions(): array
    {
        $definitions = config('gamenest-mod-manager.providers', []);

        if (!is_array($definitions)) {
            throw new RuntimeException('ModHarbor provider configuration is invalid.');
        }

        $normalized = [];

        foreach ($definitions as $key => $definition) {
            if (!is_string($key) || !is_array($definition)) {
                throw new RuntimeException('A configured ModHarbor provider definition is invalid.');
            }

            $normalized[$this->normalizeKey($key)] = $this->sdk->normalizeDefinition($key, $definition);
        }

        return $normalized;
    }

    public function definition(string $source): array
    {
        $source = $this->normalizeKey($source);
        $definition = $this->definitions()[$source] ?? null;

        if (!is_array($definition)) {
            throw new RuntimeException('Unknown ModHarbor source/provider: ' . $source . '.');
        }

        return $definition;
    }

    public function provider(string $source): ModProvider
    {
        $source = $this->normalizeKey($source);
        $class = trim((string) ($this->definition($source)['class'] ?? ''));
        $provider = app($class);

        if (!$provider instanceof ModProvider) {
            throw new RuntimeException($class . ' must implement the ModHarbor ModProvider contract.');
        }

        if ($this->normalizeKey($provider->key()) !== $source) {
            throw new RuntimeException('Configured provider key mismatch for ' . $source . '.');
        }

        return $provider;
    }

    public function label(string $source): string
    {
        return (string) $this->definition($source)['label'];
    }

    public function capabilities(string $source): array
    {
        return ProviderCapabilities::normalize((array) ($this->definition($source)['capabilities'] ?? []));
    }

    public function capable(string $source, string $capability): bool
    {
        $capability = strtolower(trim($capability));

        return $capability !== '' && in_array($capability, $this->capabilities($source), true);
    }

    public function discoverySchema(string $source): array
    {
        $definition = $this->definition($source);

        return is_array($definition['discovery'] ?? null)
            ? $definition['discovery']
            : [];
    }

    public function supports(Server $server, string $source): bool
    {
        $source = $this->normalizeKey($source);
        $adapter = $this->adapters->forServer($server);

        if (!$adapter || !$adapter->hasSource($source)) {
            return false;
        }

        return isset($this->definitions()[$source]);
    }

    public function sourceKeys(Server $server): array
    {
        $adapter = $this->adapters->forServer($server);

        if (!$adapter) {
            return [];
        }

        return array_values(array_filter(
            $adapter->providers(),
            fn (string $source): bool =>
                $this->supports($server, $source)
                && $this->lifecycleDriverClass($server, $source) !== null
        ));
    }

    public function browseKeys(Server $server): array
    {
        return array_values(array_filter(
            $this->sourceKeys($server),
            fn (string $source): bool => $this->capable($source, ProviderCapabilities::BROWSE)
        ));
    }

    public function discoveryKeys(Server $server): array
    {
        return array_values(array_filter(
            $this->browseKeys($server),
            function (string $source): bool {
                try {
                    return $this->capable($source, ProviderCapabilities::DISCOVER)
                        && $this->provider($source) instanceof DiscoverableProvider;
                } catch (\Throwable) {
                    return false;
                }
            }
        ));
    }

    public function defaultBrowseKey(Server $server): string
    {
        $sources = $this->browseKeys($server);

        usort($sources, function (string $left, string $right): int {
            $leftPriority = (int) ($this->definition($left)['browse_priority'] ?? 1000);
            $rightPriority = (int) ($this->definition($right)['browse_priority'] ?? 1000);

            return $leftPriority <=> $rightPriority;
        });

        return $sources[0] ?? '';
    }

    public function context(Server $server, string $source): SourceContext
    {
        if (!$this->supports($server, $source)) {
            throw new RuntimeException('This game does not provide the ' . $source . ' ModHarbor source.');
        }

        return $this->contexts->forServer($server, $source);
    }

    /**
     * Run a provider-neutral discovery request.
     */
    public function discover(
        Server $server,
        string $source,
        DiscoveryQuery|array $query = []
    ): DiscoveryResult {
        $source = $this->normalizeKey($source);

        if (!$this->supports($server, $source)) {
            throw new RuntimeException('The ' . $source . ' source is not enabled for this game.');
        }

        if (!$this->capable($source, ProviderCapabilities::DISCOVER)) {
            throw new RuntimeException($this->label($source) . ' is a manual source and does not expose a catalog.');
        }

        $provider = $this->provider($source);

        if (!$provider instanceof DiscoverableProvider) {
            throw new RuntimeException($this->label($source) . ' does not implement the discovery provider contract.');
        }

        if (is_array($query)) {
            $query = DiscoveryQuery::fromArray($query);
        }

        $schema = $this->discoverySchema($source);
        $sorts = (array) ($schema['sorts'] ?? []);
        $defaultSort = (string) ($schema['default_sort'] ?? array_key_first($sorts) ?? '');
        $pageSizes = (array) ($schema['page_sizes'] ?? [24]);
        $defaultPageSize = (int) ($schema['default_page_size'] ?? ($pageSizes[0] ?? 24));

        $sort = $query->sort;
        if ($sort === '' || ($sorts !== [] && !array_key_exists($sort, $sorts))) {
            $sort = $defaultSort;
        }

        $perPage = in_array($query->perPage, $pageSizes, true)
            ? $query->perPage
            : max(1, $defaultPageSize);

        $normalizedQuery = new DiscoveryQuery(
            $query->search,
            $sort,
            $query->page,
            $perPage,
            $query->filters,
            $query->tags,
        );

        return $provider->discover($normalizedQuery, $this->context($server, $source));
    }

    /**
     * Public descriptor intended for UI generation and third-party developer
     * diagnostics. Game-specific source metadata is merged without exposing any
     * provider mutable state.
     */
    public function describeSource(Server $server, string $source): array
    {
        $source = $this->normalizeKey($source);
        $definition = $this->definition($source);
        $context = $this->context($server, $source);

        return [
            'key' => $source,
            'label' => $definition['label'],
            'capabilities' => $definition['capabilities'],
            'browse_priority' => $definition['browse_priority'],
            'discovery' => $definition['discovery'] ?? [],
            'game' => [
                'key' => $context->gameKey(),
                'name' => $context->gameName(),
            ],
            'source' => $context->config(),
            'lifecycle_driver' => $this->lifecycleDriverClass($server, $source),
        ];
    }

    public function descriptors(Server $server): array
    {
        $result = [];

        foreach ($this->sourceKeys($server) as $source) {
            $result[$source] = $this->describeSource($server, $source);
        }

        return $result;
    }

    public function lifecycleDriverClass(Server $server, string $source): ?string
    {
        $source = $this->normalizeKey($source);
        $adapter = $this->adapters->forServer($server);

        if (!$adapter || !$this->supports($server, $source)) {
            return null;
        }

        if (!$adapter instanceof \GameNest\GameNestModManager\Adapters\ConfiguredGameAdapter) {
            return null;
        }

        return (new ConfiguredDriverResolver)
            ->driverClass($adapter, $source);
    }

    protected function normalizeKey(string $source): string
    {
        $source = strtolower(trim($source));

        if ($source === '') {
            throw new RuntimeException('ModHarbor source/provider key cannot be empty.');
        }

        return $source;
    }
}
