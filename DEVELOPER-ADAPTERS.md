# ModHarbor Game Adapter API

ModHarbor is split into four layers:

1. **Game Adapter** — declares what a game is, how to identify its Pelican egg, which sources it supports, and game-specific source metadata.
2. **Source / Provider** — talks to an upstream catalog or package source such as mod.io, uMod, GitHub, a direct URL, or a retained upload. Providers are shared and must not store mutable "current game" state.
3. **Lifecycle Driver** — translates one provider package into the files and paths valid for one game/runtime.
4. **Shared Engine** — owns transactions, rollback/recovery, manifest ownership, dependencies, verification, update/reinstall/remove, history, config preservation, and operation safety.

The intended extension path is:

`Game Adapter -> SourceContext -> Provider -> Lifecycle Driver -> ModHarbor Engine`

## Adding a game

Create an adapter extending `AbstractGameAdapter`, then register it in `config/gamenest-mod-manager.php` under `adapters`.

An adapter declares stable source keys and metadata. Example:

```php
public function sources(): array
{
    return [
        'steam-workshop' => [
            'app_id' => 123456,
        ],
        'github' => [],
        'upload' => [
            'extensions' => ['zip'],
            'accept' => '.zip',
        ],
    ];
}
```

Do not put provider implementation classes in a game adapter. The shared provider registry owns those mappings.

## Adding a provider

Add the provider under the shared `providers` config entry with a stable key, label, class, capabilities, and browse priority.

```php
'steam-workshop' => [
    'class' => SteamWorkshopProvider::class,
    'label' => 'Steam Workshop',
    'browse_priority' => 15,
    'capabilities' => [
        'browse',
        'search',
        'install',
        'update',
        'dependencies',
    ],
],
```

The provider implements `ModProvider`. Any game-specific values must arrive through `SourceContext` rather than mutable singleton state.

```php
public function search(
    string $query,
    array $options = [],
    ?SourceContext $source = null
): array;

public function get(
    string|int $id,
    ?SourceContext $source = null
): ?array;
```

For example, a Steam Workshop provider should read `app_id` from the supplied context. A CurseForge provider could read a game slug or game ID. The provider should never contain checks such as `if ($game === 'Rust')`.

## SourceContext

`ProviderContext::forServer($server, $sourceKey)` produces an immutable `SourceContext` containing:

- game key
- game display name
- source key
- adapter-provided source configuration
- game/source namespaced cache keys

Use `require()` for metadata that must exist:

```php
$appId = (int) $source->require('app_id');
```

Use `cacheKey()` for all provider caches so two games using the same provider cannot collide:

```php
Cache::remember(
    $source->cacheKey('item:' . $remoteId),
    now()->addMinutes(10),
    fn () => ...
);
```

## Adding lifecycle support

A source does not become visible in ModHarbor for a game until both of these exist:

1. the game adapter declares the source;
2. a lifecycle driver is registered for that game/source pair.

Register drivers under `lifecycle_drivers`:

```php
'lifecycle_drivers' => [
    'mygame' => [
        'steam-workshop' => MyGameSteamWorkshopLifecycleDriver::class,
        'github' => MyGameGitHubLifecycleDriver::class,
        'upload' => MyGameUploadLifecycleDriver::class,
    ],
],
```

Lifecycle methods receive the same immutable `SourceContext` used by the provider:

```php
public function dependencies(
    string|int $id,
    ?SourceContext $source = null
): array;

public function prepare(
    string|int $id,
    string|int|null $fileId,
    FileTransaction $files,
    ?SourceContext $source = null
): array;
```

The lifecycle driver should contain only game/runtime-specific deployment rules. Do not reimplement transactions, rollback, manifest ownership, operation history, dependency orchestration, or verification inside a driver.

## Upload metadata

Upload formats belong in adapter source metadata, not UI game-name checks:

```php
'upload' => [
    'extensions' => ['cs', 'zip'],
    'accept' => '.cs,.zip',
],
```

ModHarbor uses this metadata for both browser file selection and server-side validation. The lifecycle driver remains responsible for validating archive contents and deployment paths.

## Provider capabilities

Capabilities currently describe shared UI/provider behavior. Examples include:

- `browse`
- `search`
- `inspect`
- `manual`
- `upload`
- `install`
- `update`
- `reinstall`
- `replace`
- `dependencies`
- `settings`

New provider UI should prefer capability checks through `SourceRegistry` instead of hard-coded provider lists.

## Rules for reusable integrations

- Provider classes must not know game names.
- Do not store a mutable current game/source on singleton providers.
- All provider caches must be game/source namespaced.
- Adapter metadata should contain provider-specific game IDs, slugs, categories, application IDs, and upload formats.
- Runtime concepts such as Carbon/Oxide remain lifecycle/runtime concerns, not providers.
- Shared safety features must stay in the ModHarbor engine so every game inherits them automatically.

## Adapter SDK v2

Adapters now expose developer-facing metadata and feature flags in addition to
server detection and source declarations. `AbstractGameAdapter` supplies safe
defaults, so a small adapter can still implement only its key/name, egg
identifiers, sources, and directories.

```php
public function metadata(): array
{
    return array_replace(parent::metadata(), [
        'family' => 'survival',
        'plugin_runtime' => 'My Runtime',
        'docs_slug' => 'my-game',
    ]);
}

public function features(): array
{
    return array_replace(parent::features(), [
        'runtime_detection' => true,
    ]);
}
```

`AdapterRegistry::developerManifest()` validates every registered adapter and
returns a stable manifest containing metadata, features, sources, source
definitions, mod directories, and config directories. This is intended for
diagnostics, future extension tooling, and developer documentation.

Adapters can also provide `sourceDefaults()`. Those defaults are recursively
merged into every per-source definition before `SourceContext` is created.
This is useful when several sources for the same game share common metadata.

## Discovery metadata

A game adapter may customize the presentation of a discoverable source without
teaching the provider about the game:

```php
'umod' => [
    'categories' => ['rust'],
    'discovery' => [
        'title' => 'Rust Plugin Catalog',
        'description' => 'Browse plugins for the active runtime.',
        'search_placeholder' => 'Search Rust plugins...',
    ],
],
```

Provider-owned discovery behavior such as sort keys, page sizes, and generic
filters stays in the shared provider registry. Adapter metadata owns only the
game-specific context/presentation.
