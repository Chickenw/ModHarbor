# ModHarbor Provider SDK v2

The Provider SDK is the shared extension point for upstream catalogs and package
sources. Providers are deliberately game-agnostic. Every game-specific ID,
category, slug, app ID, or presentation override arrives through immutable
`SourceContext` metadata supplied by a game adapter.

## Provider registration

Register a provider in `config/gamenest-mod-manager.php`:

```php
'steam-workshop' => [
    'class' => SteamWorkshopProvider::class,
    'label' => 'Steam Workshop',
    'browse_priority' => 15,
    'capabilities' => [
        'browse',
        'discover',
        'search',
        'install',
        'update',
        'dependencies',
    ],
    'discovery' => [
        'default_sort' => 'popular',
        'sorts' => [
            'popular' => 'Popular',
            'updated' => 'Recently Updated',
        ],
        'page_sizes' => [24, 48],
        'default_page_size' => 24,
    ],
],
```

`ProviderSdk` validates registration keys, classes, capability names, discovery
contracts, sort definitions, and page sizes. Invalid registrations fail early
instead of surfacing during a user lifecycle operation.

## Capabilities

Use constants from `ProviderCapabilities` when writing shared code. Current
capabilities include `browse`, `discover`, `search`, `install`, `update`,
`dependencies`, `settings`, `inspect`, `manual`, `upload`, `replace`,
`reinstall`, and `bulk-metadata`.

`browse` means the source should appear on the Browse page. `discover` is
stronger: it means the provider exposes a real searchable/paged catalog and
must implement `DiscoverableProvider`. Manual sources can still appear on the
Browse page without pretending to expose a catalog.

## DiscoverableProvider

A catalog provider implements the normal `ModProvider` contract plus:

```php
public function discover(
    DiscoveryQuery $query,
    SourceContext $source
): DiscoveryResult;
```

`DiscoveryQuery` normalizes search text, sort key, page, page size, filters, and
tags. `DiscoveryResult` provides one common paging/result shape regardless of
whether the upstream service uses offsets, cursor pages, or numbered pages.

`SourceRegistry::discover()` validates the source and provider capabilities,
normalizes the query against the provider discovery schema, creates the correct
immutable `SourceContext`, and invokes the provider.

This means new searchable providers can use the shared Browse/Discovery layer
without adding game checks to `ModManager`.

## Manual providers

GitHub repository inspection, direct URLs, and retained file uploads are manual
sources. They implement `ModProvider` and declare `browse` plus their manual
capabilities, but they do not declare `discover`. The Browse UI can therefore
show them as source-specific workflows without catalog/search controls.

## Cache and state rules

Providers may be application singletons. Never store mutable current-game or
current-server state on a provider. Use `SourceContext` for every game-specific
value and use `SourceContext::cacheKey()` for upstream caches.

## Installed metadata

Installed Mod metadata resolution is provider-neutral. If an installed manifest
entry has a provider and provider ID, ModHarbor asks `SourceRegistry` for the
provider and calls `get()` with the correct `SourceContext`. New providers gain
Installed artwork/profile/remote metadata without adding a provider-specific
branch to the Installed page.
