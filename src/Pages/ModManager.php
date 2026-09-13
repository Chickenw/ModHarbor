<?php

namespace GameNest\GameNestModManager\Pages;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use GameNest\GameNestModManager\Providers\ModIoProvider;
use GameNest\GameNestModManager\Providers\UModProvider;
use GameNest\GameNestModManager\Providers\GitHubProvider;
use GameNest\GameNestModManager\Services\AdapterRegistry;
use GameNest\GameNestModManager\Services\ProviderContext;
use GameNest\GameNestModManager\Services\SourceRegistry;
use GameNest\GameNestModManager\Services\DiscoveryQuery;
use GameNest\GameNestModManager\Services\ProviderCapabilities;
use GameNest\GameNestModManager\Services\ManifestService;
use GameNest\GameNestModManager\Services\ConfigRevisionStore;
use GameNest\GameNestModManager\Services\ModHealthService;
use RuntimeException;
use GameNest\GameNestModManager\Services\ModLifecycleService;
use GameNest\GameNestModManager\Services\OperationStore;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Cache;
use JsonException;
use Livewire\Attributes\Locked;
use Livewire\WithFileUploads;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use GameNest\GameNestModManager\Services\UploadPackageStore;
use Throwable;

class ModManager extends Page
{
    use WithFileUploads;
    #[Locked]
    public array $scanCandidates = [];
    public array $selectedCandidates = [];
    public string $historySearch = '';
    #[Locked]
    public string $editingConfigHash = '';
    #[Locked]
    public array $dependencyPreview = [];
    public ?string $scanError = null;
    public ?string $configError = null;

    #[Locked]
    public array $packageVersions = [];
    #[Locked]
    public string $versionKey = '';
    public string $selectedPackageVersion = '';

    public function inspectPackageVersions(string $key): void
    {
        $this->packageVersions = [];
        $this->versionKey = '';
        $this->selectedPackageVersion = '';

        try {
            $versions = app(
                \GameNest\GameNestModManager\Services\PackageVersionService::class
            )->listing($this->server, $key);

            if ($versions === []) {
                Notification::make()
                    ->title('No package versions available')
                    ->body(
                        'This provider did not return any selectable files for this package. '
                        . 'Use the provider website or Upload File when a manual download is required.'
                    )
                    ->warning()
                    ->send();

                return;
            }

            $this->packageVersions = $versions;
            $this->versionKey = $key;
            $this->dispatch('modharbor-versions-opened');
        } catch (Throwable $e) {
            Notification::make()
                ->title('Versions unavailable')
                ->body(
                    get_class($e) === \RuntimeException::class
                        ? $e->getMessage()
                        : 'Check provider access and source compatibility.'
                )
                ->danger()
                ->send();
        }
    }

    public function gameArtwork(): string
    {
        try {
            $adapter = app(AdapterRegistry::class)->forServer($this->server);
            $definition = $adapter instanceof \GameNest\GameNestModManager\Adapters\ConfiguredGameAdapter ? $adapter->definition() : [];
            return app(\GameNest\GameNestModManager\Services\GameArtworkService::class)->resolve($definition);
        } catch (Throwable) {
            return \GameNest\GameNestModManager\Services\GameArtworkService::fallback();
        }
    }

    public function closePackageVersions(): void
    {
        $this->packageVersions = []; $this->versionKey = ''; $this->selectedPackageVersion = '';
    }

    public function applyPackageVersion(): void
    {
        $selected = trim((string) $this->selectedPackageVersion);

        if ($selected === '' || $this->versionKey === '') {
            Notification::make()
                ->title('Select a package version')
                ->warning()
                ->send();

            return;
        }

        $choice = array_values(array_filter(
            $this->packageVersions,
            static fn (array $file): bool =>
                (string) ($file['id'] ?? '') === $selected
        ));

        if (count($choice) !== 1) {
            Notification::make()
                ->title('Selected version is unavailable')
                ->body('Refresh the available versions and select the file again.')
                ->danger()
                ->send();

            return;
        }

        try {
            $mods = app(ManifestService::class)->mods($this->server);

            app(ModLifecycleService::class)->run(
                $this->server,
                isset($mods[$this->versionKey]) ? 'update' : 'install',
                $this->versionKey,
                $choice[0]['id']
            );

            $this->versionKey = '';
            $this->packageVersions = [];
            $this->selectedPackageVersion = '';

            $this->loadInstalledMods();

            $this->checkUpdates(
                $this->manifestError === null
                    ? $this->installedMods
                    : null
            );

            Notification::make()
                ->title('Selected version installed')
                ->success()
                ->send();
        } catch (Throwable $exception) {
            $this->loadInstalledMods();

            Notification::make()
                ->title('Version change could not complete')
                ->body(
                    $exception instanceof \RuntimeException
                        ? $exception->getMessage()
                        : 'Review History. The release may be unavailable, incompatible, or require different dependencies.'
                )
                ->danger()
                ->send();
        }
    }

    public array $selectedInstalled = [];
    public string $bulkAction = 'update';
    #[Locked]
    public array $bulkResult = [];

    public function runSelectedMods(): void
    {
        try {
            $this->bulkResult = app(\GameNest\GameNestModManager\Services\ModBatchService::class)
                ->run($this->server, $this->bulkAction, $this->selectedInstalled);
            $this->selectedInstalled = [];
            $this->loadInstalledMods();
            $this->checkUpdates();
        } catch (Throwable) {
            Notification::make()->title('Batch could not start')->body('Refresh the selection and check permissions and recovery status.')->danger()->send();
        }
    }

    public function hydrate(): void
    {
        $tenant = Filament::getTenant();
        abort_unless($tenant instanceof Server && $tenant->uuid === $this->server->uuid, 403);
        Gate::authorize('file.read', $this->server);
        Gate::authorize('file.read-content', $this->server);
    }

    public function pendingRestarts(): array
    {
        return app(\GameNest\GameNestModManager\Services\RestartTracker::class)->pending($this->server);
    }

    public function confirmServerStarted(): void
    {
        try {
            app(\GameNest\GameNestModManager\Services\RestartTracker::class)->confirm($this->server);
            Notification::make()->title('Server start confirmed')->success()->send();
        } catch (Throwable) {
            Notification::make()->title('Unable to confirm server start')->body('Check start permission, running state and unresolved recovery operations.')->danger()->send();
        }
    }

    public function recoveryState(): array
    {
        Gate::authorize('file.read', $this->server);
        Gate::authorize('file.read-content', $this->server);
        try { return app(OperationStore::class)->unresolved($this->server); }
        catch (Throwable) { return [['id' => '', 'name' => 'Journal unavailable', 'status' => 'recovery_required',
            'message' => 'The operation journal cannot be verified. Administrator review is required.']]; }
    }

    public function scanExistingMods(): void
    {
        $this->scanCandidates = []; $this->selectedCandidates = []; $this->scanError = null;
        try { $this->scanCandidates = app(\GameNest\GameNestModManager\Services\ManagedFileScanner::class)->scan($this->server); }
        catch (Throwable) { $this->scanError = 'Scan failed. Check server connectivity, permissions and adapter roots.'; }
    }

    public function adoptSelectedMods(): void
    {
        try {
            app(\GameNest\GameNestModManager\Services\ExistingModService::class)->adopt(
                $this->server, $this->selectedCandidates, $this->adoptVersionLabel);
            $this->loadInstalledMods(); $this->scanExistingMods(); $this->refreshHealth(false);
            Notification::make()->title('Selected files adopted')->success()->send();
        } catch (Throwable $exception) {
            Notification::make()->title('Adoption failed')->body($exception instanceof RuntimeException
                ? $exception->getMessage() : 'Unable to adopt the selected files safely.')->danger()->send();
        }
    }

    public function installedDependencies(string $key): array
    {
        $mods = app(ManifestService::class)->mods($this->server);
        $rows = $mods[$key]['dependency_specs'] ?? [];
        foreach ($mods[$key]['required_by'] ?? [] as $parent) {
            $rows[] = ['type' => 'required_by', 'key' => $parent, 'constraint' => '', 'file_id' => ''];
        }
        return $rows;
    }

    protected static ?int $navigationSort = 1;

    protected string $view =
        'gamenest-mod-manager::pages.mod-manager';

    #[Locked]
    public Server $server;

    #[Locked]
    public string $game = 'Unknown';

    public string $eggName = 'Unknown';

    public string $activeTab = 'browse';

    #[Locked]
    public array $providers = [];

    #[Locked]
    public array $updateResults = [];

    #[Locked]
    public array $history = [];

    public ?string $updatesCheckedAt = null;
    public ?string $pendingAction = null;
    public ?string $pendingKey = null;
    public ?string $pendingName = null;
    public ?string $pendingProvider = null;
    public ?string $pendingVersion = null;
    public ?string $pendingSourceVersion = null;
    public ?string $manifestError = null;

    public array $health = [];

    public ?string $healthCheckedAt = null;

    public ?string $detailsKey = null;

    public string $historyActionFilter = 'all';

    public string $historyStatusFilter = 'all';

    public array $configRevisions = [];

    public string $search = '';

    public array $mods = [];

    public bool $searched = false;

    public string $modioSort = 'hot';

    public string $modioPeriod = 'all';

    
    public array $modioTagGroups = [];

    public array $modioSelectedTags = [];

public int $modioPage = 1;

    public int $modioPerPage = 24;

    public int $modioTotal = 0;

    public string $umodSort = 'updated';

    public int $umodPage = 1;

    public int $umodTotal = 0;

    public int $umodLastPage = 1;

    public int $umodPerPage = 10;

    public array $umodSuggestionCache = [];


    public array $installedMods = [];

    public string $installedSearch = '';

    public string $installedSort = 'updates';

    public string $installedProviderFilter = 'all';
    public string $installedStatusFilter = 'all';


    public string $browseProvider = 'modio';

    /** Universal Browse & Discovery v2 state. */
    #[Locked]
    public array $discoverySchema = [];
    public int $discoveryPage = 1;
    public int $discoveryPerPage = 24;
    public int $discoveryTotal = 0;
    public int $discoveryLastPage = 1;
    public string $discoverySort = '';
    public array $discoveryFilters = [];
    #[Locked]
    public array $sourceDescriptors = [];


    public string $githubRepository = '';

    public ?array $githubResult = null;

    public array $githubAssets = [];

    public bool $githubInspected = false;

    public string $directUrl = '';

    public $uploadFile = null;

    public string $uploadVersionLabel = '';

    public string $replaceUploadVersionLabel = '';

    public string $adoptVersionLabel = 'Adopted';

    public array $adoptableRustPlugins = [];

    public $replaceUploadFile = null;

    public ?string $replaceUploadKey = null;

    public string $rustRuntime = 'Unknown';

    public array $modConfigs = [];

    public ?string $editingConfigPath = null;

    public string $editingConfigContent = '';


    public function mount(): void
    {
        $server = Filament::getTenant();

        abort_unless(
            $server instanceof Server,
            404
        );

        Gate::authorize('file.read', $server);
        Gate::authorize('file.read-content', $server);
        $this->server = $server;

        $this->server->loadMissing([
            'egg',
            'allocation',
            'allocations',
            'node',
        ]);

        $adapter =
            app(AdapterRegistry::class)
                ->forServer(
                    $this->server
                );

        abort_unless(
            $adapter !== null,
            404
        );

        $this->eggName =
            $this->server->egg?->name
            ?? 'Unknown';

        $this->game =
            $adapter->name();

        $sources = app(SourceRegistry::class);

        $this->providers =
            $sources->sourceKeys(
                $this->server
            );

        $this->sourceDescriptors = $sources->descriptors($this->server);

        if ($this->game === 'Rust') {
            $this->rustRuntime =
                $this->detectRustRuntime();
        }

        $this->browseProvider =
            $sources->defaultBrowseKey(
                $this->server
            );

        $this->loadDiscoverySchema($this->browseProvider);

        $this->loadInstalledMods();

        if (in_array('modio', $this->providers, true)) {
            $this->loadModioTagGroups();

            if (
                $this->browseProvider === 'modio'
                && app(ModIoProvider::class)->configured()
            ) {
                $this->browseMods(false);
            }
        }

        if (
            $this->browseProvider === 'umod'
            && in_array('umod', $this->providers, true)
        ) {
            $this->browseUMod(false);
        }
        if (
            !in_array($this->browseProvider, ['', 'modio', 'umod'], true)
            && $sources->capable($this->browseProvider, ProviderCapabilities::DISCOVER)
        ) {
            $this->browseDiscovery(false);
        }
    }

    public static function canAccess(): bool
    {
        $server =
            Filament::getTenant();

        if (
            !$server instanceof Server
        ) {
            return false;
        }

        return app(
            AdapterRegistry::class
        )->supports($server);
    }
    public static function getNavigationParentItem(): ?string
    {
        return 'ModHarbor';
    }

    public static function getNavigationLabel(): string
    {
        return 'Mods';
    }

    public function getTitle(): string
    {
        return 'ModHarbor';
    }

    public function setTab(
    string $tab
): void {
    Gate::authorize('file.read', $this->server);
    Gate::authorize('file.read-content', $this->server);

    if (
        !in_array(
            $tab,
            [
                'browse',
                'installed',
                'configs',
                'history',
            ],
            true
        )
    ) {
        return;
    }

    $this->activeTab = $tab;
    $this->pendingAction = null;
    $this->pendingKey = null;

    if ($tab === 'history') {
        $this->history = app(OperationStore::class)
            ->history($this->server);
    }

    if ($tab === 'installed') {
        /*
         * Keep the Installed tab responsive. Manifest and provider
         * update information are refreshed automatically; deeper
         * file integrity verification remains available through
         * Verify All.
         */
        $this->loadInstalledMods();
        $this->checkUpdates(
            $this->manifestError === null ? $this->installedMods : null
        );
    }

    if ($tab === 'configs') {
        $this->loadInstalledMods();
        $this->loadModConfigs();
    }
}

public function setBrowseProvider(string $provider): void
{
    $sources = app(SourceRegistry::class);

    if (
        !in_array($provider, $this->providers, true)
        || !$sources->capable($provider, ProviderCapabilities::BROWSE)
    ) {
        return;
    }

    $this->browseProvider = $provider;
    $this->mods = [];
    $this->searched = false;
    $this->loadDiscoverySchema($provider);

    if ($provider === 'modio') {
        $this->modioPage = 1;
    } elseif ($provider === 'umod') {
        $this->umodPage = 1;
    }

    if ($sources->capable($provider, ProviderCapabilities::DISCOVER)) {
        $this->browseDiscovery(false);
    }
}

    protected function loadDiscoverySchema(string $provider): void
    {
        $this->discoverySchema = [];
        $this->discoveryFilters = [];
        $this->discoverySort = '';
        $this->discoveryPage = 1;
        $this->discoveryTotal = 0;
        $this->discoveryLastPage = 1;

        if ($provider === '') {
            return;
        }

        try {
            $descriptor = app(SourceRegistry::class)
                ->describeSource($this->server, $provider);

            $providerSchema = (array) ($descriptor['discovery'] ?? []);
            $sourceSchema = (array) ($descriptor['source']['discovery'] ?? []);

            $this->discoverySchema = array_replace_recursive(
                $providerSchema,
                $sourceSchema
            );

            $this->discoverySort = trim((string) (
                $this->discoverySchema['default_sort'] ?? ''
            ));

            $pageSizes = (array) ($this->discoverySchema['page_sizes'] ?? [24]);
            $this->discoveryPerPage = max(1, (int) (
                $this->discoverySchema['default_page_size'] ?? ($pageSizes[0] ?? 24)
            ));

            foreach ((array) ($this->discoverySchema['filters'] ?? []) as $key => $definition) {
                if (!is_string($key) || !is_array($definition)) {
                    continue;
                }

                $this->discoveryFilters[$key] = (string) ($definition['default'] ?? '');
            }
        } catch (Throwable) {
            $this->discoverySchema = [];
        }
    }

    public function discoveryTitle(): string
    {
        return trim((string) ($this->discoverySchema['title'] ?? ''))
            ?: $this->providerLabel($this->browseProvider) . ' Catalog';
    }

    public function discoveryDescription(): string
    {
        return trim((string) ($this->discoverySchema['description'] ?? ''))
            ?: 'Search and browse packages available for this game.';
    }

    public function discoverySearchPlaceholder(): string
    {
        return trim((string) ($this->discoverySchema['search_placeholder'] ?? ''))
            ?: 'Search ' . $this->providerLabel($this->browseProvider) . '...';
    }

    public function browseDiscovery(bool $notify = false): void
    {
        Gate::authorize('file.read', $this->server);
        Gate::authorize('file.read-content', $this->server);

        try {
            $sources = app(SourceRegistry::class);

            if (!$sources->capable($this->browseProvider, ProviderCapabilities::DISCOVER)) {
                throw new RuntimeException(
                    $this->providerLabel($this->browseProvider) . ' does not expose a searchable catalog.'
                );
            }

            // Keep legacy provider-specific UI controls synchronized while the
            // generic discovery state becomes the stable provider SDK surface.
            if ($this->browseProvider === 'modio') {
                $this->discoveryPage = max(1, $this->modioPage);
                $this->discoveryPerPage = max(1, $this->modioPerPage);
                $this->discoverySort = $this->modioSort;
                $this->discoveryFilters['period'] = $this->modioPeriod;
                $tags = $this->activeModioTags();
            } elseif ($this->browseProvider === 'umod') {
                $this->discoveryPage = max(1, $this->umodPage);
                $this->discoveryPerPage = max(1, $this->umodPerPage);
                $this->discoverySort = $this->umodSort;
                $tags = [];
            } else {
                $tags = [];
            }

            $result = $sources->discover(
                $this->server,
                $this->browseProvider,
                new DiscoveryQuery(
                    trim($this->search),
                    $this->discoverySort,
                    $this->discoveryPage,
                    $this->discoveryPerPage,
                    $this->discoveryFilters,
                    $tags,
                )
            );

            $this->mods = $result->items;
            $this->discoveryTotal = $result->total;
            $this->discoveryPage = $result->page;
            $this->discoveryPerPage = $result->perPage;
            $this->discoveryLastPage = $result->lastPage;
            $this->searched = trim($this->search) !== '';

            if ($this->browseProvider === 'modio') {
                $this->modioTotal = $result->total;
                $this->modioPage = $result->page;
                $this->modioPerPage = $result->perPage;
            } elseif ($this->browseProvider === 'umod') {
                $this->umodTotal = $result->total;
                $this->umodPage = $result->page;
                $this->umodPerPage = $result->perPage;
                $this->umodLastPage = $result->lastPage;
            }

            $this->loadInstalledMods();

            if ($notify) {
                Notification::make()
                    ->title($this->searched ? 'Search Complete' : 'Catalog Loaded')
                    ->body(number_format($result->total) . ' package(s) matched on ' . $this->providerLabel($this->browseProvider) . '.')
                    ->success()
                    ->send();
            }
        } catch (Throwable $exception) {
            $this->mods = [];
            $this->discoveryTotal = 0;
            $this->discoveryLastPage = 1;

            if ($this->browseProvider === 'modio') {
                $this->modioTotal = 0;
            } elseif ($this->browseProvider === 'umod') {
                $this->umodTotal = 0;
                $this->umodLastPage = 1;
            }

            if ($notify) {
                Notification::make()
                    ->title($this->providerLabel($this->browseProvider) . ' Catalog Failed')
                    ->body($exception->getMessage())
                    ->danger()
                    ->send();
            }
        }
    }

public function inspectGithubRepository(): void
    {
        Gate::authorize(
            'file.read',
            $this->server
        );

        Gate::authorize(
            'file.read-content',
            $this->server
        );

        $this->githubResult = null;
        $this->githubAssets = [];
        $this->githubInspected = true;

        try {
            if (
                !in_array(
                    'github',
                    $this->providers,
                    true
                )
            ) {
                throw new RuntimeException(
                    'GitHub is not enabled for this game adapter.'
                );
            }

            $result =
                app(GitHubProvider::class)
                    ->inspect(
                        $this->githubRepository
                    );

            $this->githubResult = $result;

            $this->githubAssets =
                array_values(
                    array_filter(
                        $result['assets'] ?? [],
                        static fn ($asset): bool =>
                            is_array($asset)
                            && (int) ($asset['id'] ?? 0) > 0
                            && trim(
                                (string) (
                                    $asset['name']
                                    ?? ''
                                )
                            ) !== ''
                    )
                );

            $repositoryId =
                (int) (
                    $result['repository']['id']
                    ?? 0
                );

            foreach (
                $this->githubAssets
                as $index => $asset
            ) {
                $assetId =
                    (int) (
                        $asset['id']
                        ?? 0
                    );

                if (
                    $repositoryId < 1
                    || $assetId < 1
                ) {
                    $this->githubAssets[$index]['compatibility'] = [
                        'compatible' => false,
                        'reason' => 'Invalid GitHub release metadata.',
                        'files' => 0,
                    ];

                    continue;
                }

                $this->githubAssets[$index]['compatibility'] =
                    app(ModLifecycleService::class)
                        ->preflightGithubAsset(
                            $this->server,
                            $repositoryId,
                            $assetId
                        );
            }

            $compatible =
                count(
                    array_filter(
                        $this->githubAssets,
                        static fn (array $asset): bool =>
                            (bool) (
                                $asset['compatibility']['compatible']
                                ?? false
                            )
                    )
                );

            Notification::make()
                ->title(
                    'GitHub Repository Loaded'
                )
                ->body(
                    $compatible .
                    ' compatible release asset(s) found out of ' .
                    count($this->githubAssets) .
                    ' inspected.'
                )
                ->success()
                ->send();

        } catch (Throwable $exception) {
            Notification::make()
                ->title(
                    'GitHub Repository Lookup Failed'
                )
                ->body(
                    $exception->getMessage()
                )
                ->danger()
                ->send();
        }
    }

    public function installGithubAsset(
        int $repositoryId,
        int $assetId
    ): void {
        if (
            $repositoryId < 1
            || $assetId < 1
        ) {
            return;
        }

        $repository =
            $this->githubResult['repository']
            ?? [];

        $release =
            $this->githubResult['release']
            ?? [];

        if (
            (int) ($repository['id'] ?? 0)
            !== $repositoryId
        ) {
            return;
        }

        $asset = null;

        foreach ($this->githubAssets as $candidate) {
            if (
                is_array($candidate)
                && (int) ($candidate['id'] ?? 0)
                    === $assetId
            ) {
                $asset = $candidate;
                break;
            }
        }

        if (!is_array($asset)) {
            return;
        }

        if (
            !((bool) (
                $asset['compatibility']['compatible']
                ?? false
            ))
        ) {
            Notification::make()
                ->title('GitHub Asset Not Compatible')
                ->body(
                    (string) (
                        $asset['compatibility']['reason']
                        ?? 'This release asset is not compatible with the selected game.'
                    )
                )
                ->danger()
                ->send();

            return;
        }

        $name = trim(
            (string) (
                $asset['name']
                ?? $repository['full_name']
                ?? $repository['name']
                ?? 'GitHub Release'
            )
        );

        $version = trim(
            (string) (
                $asset['version']
                ?? $release['tag']
                ?? ''
            )
        );

        $this->requestAction(
            'install',
            'github:' . $repositoryId,
            $name,
            'github',
            $version,
            (string) $assetId
        );
    }

    public function uModSuggestionsFor(array $mod): array
    {
        if (
            strtolower(trim((string) ($mod['provider'] ?? '')))
            !== 'umod'
        ) {
            return [];
        }

        $slug = trim(
            (string) (
                $mod['provider_id']
                ?? $mod['id']
                ?? ''
            )
        );

        if (str_starts_with($slug, 'umod:')) {
            $slug = substr($slug, 5);
        }

        if ($slug === '') {
            return [];
        }

        $version = trim(
            (string) (
                $mod['version']
                ?? $mod['latest_file']['version']
                ?? ''
            )
        );

        $source = app(ProviderContext::class)
            ->forServer($this->server, 'umod');

        $cacheKey =
            $source->gameKey()
            . ':'
            . strtolower($slug)
            . '@'
            . $version;

        if (
            array_key_exists(
                $cacheKey,
                $this->umodSuggestionCache
            )
        ) {
            return $this->umodSuggestionCache[$cacheKey];
        }

        try {
            $suggestions =
                app(
                    UModProvider::class
                )->suggestedDependencies(
                    $slug,
                    $version !== '' ? $version : null,
                    $source
                );
        } catch (Throwable) {
            return $this->umodSuggestionCache[$cacheKey] = [];
        }

        $installedKeys = [];

        foreach ($this->installedMods as $installedKey => $installed) {
            if (
                is_string($installedKey)
                && str_starts_with($installedKey, 'umod:')
            ) {
                $installedKeys[$installedKey] = true;
            }

            if (!is_array($installed)) {
                continue;
            }

            $provider = strtolower(
                trim((string) ($installed['provider'] ?? ''))
            );

            $providerId = trim(
                (string) ($installed['provider_id'] ?? '')
            );

            if ($provider === 'umod' && $providerId !== '') {
                $installedKeys['umod:' . $providerId] = true;
            }
        }

        foreach ($suggestions as &$suggestion) {
            $suggestionId = trim(
                (string) ($suggestion['id'] ?? '')
            );

            $suggestion['installed'] =
                $suggestionId !== ''
                && isset(
                    $installedKeys['umod:' . $suggestionId]
                );
        }

        unset($suggestion);

        return $this->umodSuggestionCache[$cacheKey] =
            $suggestions;
    }

    public function installDirectDownload(): void
    {
        try {
            if (
                !in_array(
                    'direct',
                    $this->providers,
                    true
                )
            ) {
                throw new \RuntimeException(
                    'Direct Download is not enabled for this game.'
                );
            }

            $url = trim($this->directUrl);

            if ($url === '') {
                throw new \RuntimeException(
                    'Enter a direct HTTPS download URL.'
                );
            }

            $provider = app(
                \GameNest\GameNestModManager\Providers\DirectDownloadProvider::class
            );

            $id = $provider->makeId($url);

            app(
                \GameNest\GameNestModManager\Services\ModLifecycleService::class
            )->run(
                $this->server,
                'install',
                'direct:' . $id
            );

            $this->directUrl = '';
            $this->loadInstalledMods();

            if ($this->game === 'Rust') {
                $this->rustRuntime =
                    $this->detectRustRuntime();
            }

            \Filament\Notifications\Notification::make()
                ->title('Direct Download Installed')
                ->body(
                    'The package was installed and is now managed by ModHarbor.'
                )
                ->success()
                ->send();
        } catch (\Throwable $e) {
            \Filament\Notifications\Notification::make()
                ->title('Direct Download Could Not Complete')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    protected function detectRustRuntime(): string
    {
        try {
            $repo = app(
                \App\Repositories\Daemon\DaemonFileRepository::class
            )->setServer(
                $this->server
            );

            $entries =
                $repo->getDirectory('/');

            $names = [];

            foreach ($entries as $entry) {
                if (is_array($entry)) {
                    $name =
                        (string) (
                            $entry['name']
                            ?? ''
                        );
                } elseif (is_object($entry)) {
                    $name =
                        (string) (
                            $entry->name
                            ?? ''
                        );
                } else {
                    continue;
                }

                $name =
                    strtolower(
                        trim($name)
                    );

                if ($name !== '') {
                    $names[$name] = true;
                }
            }

            if (isset($names['carbon'])) {
                return 'Carbon';
            }

            if (isset($names['oxide'])) {
                return 'Oxide';
            }

            return 'Vanilla / No Plugin Framework';
        } catch (\Throwable) {
            return 'Unknown';
        }
    }

    public function installUploadedFile(): void
    {
        try {
            if (
                !in_array(
                    'upload',
                    $this->providers,
                    true
                )
            ) {
                throw new RuntimeException(
                    'File Upload is not available for this game.'
                );
            }

            if (
                !$this->uploadFile
                instanceof TemporaryUploadedFile
            ) {
                throw new RuntimeException(
                    'Choose a mod or plugin file first.'
                );
            }

            Gate::authorize(
                'file.create',
                $this->server
            );

            $originalName =
                $this->uploadFile
                    ->getClientOriginalName();

            $extension = strtolower(
                pathinfo(
                    $originalName,
                    PATHINFO_EXTENSION
                )
            );

            $allowed =
                $this->uploadExtensions();

            if ($allowed === []) {
                throw new RuntimeException(
                    'This game adapter does not declare any supported upload formats.'
                );
            }

            if (
                !in_array(
                    $extension,
                    $allowed,
                    true
                )
            ) {
                $expected = array_map(
                    static fn (string $item): string =>
                        '.' . $item,
                    $allowed
                );

                $expectedText = count($expected) === 1
                    ? $expected[0]
                    : implode(
                        ', ',
                        array_slice($expected, 0, -1)
                    ) . ' or ' . end($expected);

                throw new RuntimeException(
                    $this->game
                    . ' File Upload expects '
                    . $expectedText
                    . '.'
                );
            }

            $package = app(
                UploadPackageStore::class
            )->store(
                $this->server,
                $this->uploadFile,
                $this->uploadVersionLabel
            );

            try {
                app(
                    ModLifecycleService::class
                )->run(
                    $this->server,
                    'install',
                    'upload:'
                    . $package['id']
                );
            } catch (\Throwable $e) {
                /*
                 * Keep the retained package when lifecycle installation fails.
                 * It remains private and gives us a recovery/debug source
                 * rather than destroying a paid/private upload.
                 */
                throw $e;
            }

            $this->uploadFile = null;
            $this->uploadVersionLabel = '';

            $this->loadInstalledMods();

            if ($this->game === 'Rust') {
                $this->rustRuntime =
                    $this->detectRustRuntime();
            }

            Notification::make()
                ->title('Upload Installed')
                ->body(
                    $originalName
                    . ' is now managed by ModHarbor.'
                )
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title(
                    'File Upload Could Not Complete'
                )
                ->body(
                    $e->getMessage()
                )
                ->danger()
                ->send();
        }
    }


    public function uploadPackageDetails(
        array $installed
    ): ?array {
        if (
            ($installed['provider'] ?? '')
            !== 'upload'
        ) {
            return null;
        }

        $id = (string) (
            $installed['provider_id']
            ?? ''
        );

        if ($id === '') {
            return null;
        }

        try {
            $package =
                app(UploadPackageStore::class)
                    ->get($id);

            if (
                !$package
                || (
                    $package['server_uuid']
                    ?? null
                ) !== $this->server->uuid
            ) {
                return null;
            }

            return $package;
        } catch (Throwable) {
            return null;
        }
    }

    public function managedFileCount(
        array $installed
    ): int {
        return count(
            array_filter(
                (array) (
                    $installed['paths']
                    ?? []
                ),
                static fn ($path): bool =>
                    is_string($path)
                    && trim($path) !== ''
            )
        );
    }

    public function scanAdoptableRustPlugins(): void { $this->scanExistingMods(); }

    public function adoptExistingRustPlugin(string $path): void
    {
        $this->selectedCandidates = [$path]; $this->adoptSelectedMods();
    }

    public function providerLabel(
        string $provider
    ): string {
        try {
            return app(SourceRegistry::class)
                ->label($provider);
        } catch (Throwable) {
            return ucfirst($provider);
        }
    }

    public function sourceCapability(
        string $provider,
        string $capability
    ): bool {
        try {
            return in_array(
                $provider,
                $this->providers,
                true
            ) && app(SourceRegistry::class)
                ->capable(
                    $provider,
                    $capability
                );
        } catch (Throwable) {
            return false;
        }
    }

    public function uploadExtensions(): array
    {
        try {
            $extensions = (array) app(
                ProviderContext::class
            )->forServer(
                $this->server,
                'upload'
            )->value(
                'extensions',
                []
            );

            return array_values(
                array_unique(
                    array_filter(
                        array_map(
                            static fn (mixed $extension): string =>
                                strtolower(
                                    ltrim(
                                        trim((string) $extension),
                                        '.'
                                    )
                                ),
                            $extensions
                        ),
                        static fn (string $extension): bool =>
                            $extension !== ''
                            && preg_match(
                                '/^[a-z0-9]+$/',
                                $extension
                            ) === 1
                    )
                )
            );
        } catch (Throwable) {
            return [];
        }
    }

    public function uploadAccept(): string
    {
        try {
            $accept = trim(
                (string) app(
                    ProviderContext::class
                )->forServer(
                    $this->server,
                    'upload'
                )->value(
                    'accept',
                    ''
                )
            );

            if ($accept !== '') {
                return $accept;
            }
        } catch (Throwable) {
            // Fall through to generated accept list.
        }

        return implode(
            ',',
            array_map(
                static fn (string $extension): string =>
                    '.' . $extension,
                $this->uploadExtensions()
            )
        );
    }

    public function uploadFormatHelp(): string
    {
        $extensions = $this->uploadExtensions();

        if ($extensions === []) {
            return 'Supports packages declared by this game adapter.';
        }

        $labels = array_map(
            static fn (string $extension): string =>
                '.' . $extension,
            $extensions
        );

        if (count($labels) === 1) {
            return 'Supports ' . $labels[0] . ' mod packages.';
        }

        $last = array_pop($labels);

        return 'Supports '
            . implode(', ', $labels)
            . ' or '
            . $last
            . ' packages.';
    }



    public function browseMods(bool $notify = false): void
    {
        if ($this->browseProvider !== 'modio') {
            return;
        }

        if (!app(ModIoProvider::class)->configured()) {
            if ($notify) {
                Notification::make()
                    ->title('mod.io Catalog Failed')
                    ->body('mod.io authentication is not configured.')
                    ->danger()
                    ->send();
            }
            return;
        }

        $this->browseDiscovery($notify);
    }

    public function browseUMod(bool $notify = false): void
    {
        if ($this->browseProvider !== 'umod') {
            return;
        }

        $this->browseDiscovery($notify);
    }

    public function setUModSort(
        string $sort
    ): void {
        $allowed = [
            'updated',
            'downloads',
            'watchers',
            'newest',
            'name',
        ];

        if (
            !in_array(
                $sort,
                $allowed,
                true
            )
        ) {
            return;
        }

        $this->umodSort = $sort;
        $this->umodPage = 1;

        $this->browseUMod(false);
    }

    public function previousUModPage(): void
    {
        if ($this->umodPage <= 1) {
            return;
        }

        $this->umodPage--;

        $this->browseUMod(false);
    }

    public function nextUModPage(): void
    {
        if (
            $this->umodPage
            >= $this->umodLastPage
        ) {
            return;
        }

        $this->umodPage++;

        $this->browseUMod(false);
    }

    public function searchMods(): void
    {
        if (!app(SourceRegistry::class)->capable($this->browseProvider, ProviderCapabilities::DISCOVER)) {
            return;
        }

        if ($this->browseProvider === 'modio') {
            $this->modioPage = 1;
        } elseif ($this->browseProvider === 'umod') {
            $this->umodPage = 1;
        }

        $this->discoveryPage = 1;
        $this->browseDiscovery(true);
    }

    public function setDiscoverySort(string $sort): void
    {
        $sort = strtolower(trim($sort));
        $allowed = array_keys((array) ($this->discoverySchema['sorts'] ?? []));

        if ($sort === '' || ($allowed !== [] && !in_array($sort, $allowed, true))) {
            return;
        }

        $this->discoverySort = $sort;
        $this->discoveryPage = 1;
        $this->browseDiscovery(false);
    }

    public function setDiscoveryFilter(string $filter, string $value): void
    {
        $definition = $this->discoverySchema['filters'][$filter] ?? null;
        if (!is_array($definition)) {
            return;
        }

        $options = (array) ($definition['options'] ?? []);
        if ($options !== [] && !array_key_exists($value, $options)) {
            return;
        }

        $this->discoveryFilters[$filter] = $value;
        $this->discoveryPage = 1;
        $this->browseDiscovery(false);
    }

    public function previousDiscoveryPage(): void
    {
        if ($this->discoveryPage <= 1) {
            return;
        }

        $this->discoveryPage--;
        $this->browseDiscovery(false);
    }

    public function nextDiscoveryPage(): void
    {
        if ($this->discoveryPage >= $this->discoveryLastPage) {
            return;
        }

        $this->discoveryPage++;
        $this->browseDiscovery(false);
    }

    public function setModioSort(string $sort): void
    {
        $allowed = [
            'hot',
            'downloads',
            'subscribers',
            'rating',
            'newest',
            'updated',
            'name',
        ];

        if (!in_array($sort, $allowed, true)) {
            return;
        }

        $this->modioSort = $sort;
        $this->modioPage = 1;

        $this->browseMods(false);
    }

    public function setModioPeriod(string $period): void
    {
        $allowed = [
            'all',
            '7d',
            '14d',
            '28d',
            '30d',
            '3m',
            '6m',
            '1y',
        ];

        if (!in_array($period, $allowed, true)) {
            return;
        }

        $this->modioPeriod = $period;
        $this->modioPage = 1;

        $this->browseMods(false);
    }

    protected function loadModioTagGroups(): void
    {
        try {
            $this->modioTagGroups =
                app(ModIoProvider::class)
                    ->tagOptions(
                    app(ProviderContext::class)->forServer(
                        $this->server,
                        'modio'
                    )
                );
        } catch (Throwable) {
            $this->modioTagGroups = [];
        }
    }

    protected function activeModioTags(): array
    {
        $tags = [];

        foreach ($this->modioSelectedTags as $tag) {
            $tag = trim((string) $tag);

            if ($tag !== '') {
                $tags[] = $tag;
            }
        }

        return array_values(
            array_unique($tags)
        );
    }

    public function setModioTag(
        string $group,
        string $tag
    ): void {
        $allowed = [];

        foreach ($this->modioTagGroups as $tagGroup) {
            if (
                (string) ($tagGroup['name'] ?? '')
                !== $group
            ) {
                continue;
            }

            foreach (($tagGroup['tags'] ?? []) as $option) {
                $name =
                    trim(
                        (string) (
                            $option['name']
                            ?? ''
                        )
                    );

                if ($name !== '') {
                    $allowed[] = $name;
                }
            }

            break;
        }

        if (
            $tag !== ''
            && !in_array($tag, $allowed, true)
        ) {
            return;
        }

        if ($tag === '') {
            unset(
                $this->modioSelectedTags[$group]
            );
        } else {
            $this->modioSelectedTags[$group] = $tag;
        }

        $this->modioPage = 1;

        $this->browseMods(false);
    }

    public function clearModioTags(): void
    {
        $this->modioSelectedTags = [];
        $this->modioPage = 1;

        $this->browseMods(false);
    }

    public function refreshModioCatalog(): void
    {
        $this->modioPage = 1;

        $this->browseMods(false);
    }

    public function clearSearch(): void
    {
        $this->search = '';

        if ($this->browseProvider === 'umod') {
            $this->umodPage = 1;
            $this->browseUMod(false);

            return;
        }

        $this->modioPage = 1;

        $this->browseMods(false);
    }

    public function previousModioPage(): void
    {
        if ($this->modioPage <= 1) {
            return;
        }

        $this->modioPage--;

        $this->browseMods(false);
    }

    public function nextModioPage(): void
    {
        $lastPage =
            max(
                1,
                (int) ceil(
                    $this->modioTotal
                    / $this->modioPerPage
                )
            );

        if ($this->modioPage >= $lastPage) {
            return;
        }

        $this->modioPage++;

        $this->browseMods(false);
    }

    public function installMod(int $modId): void
    {
        $name = (string) $modId;
        $version = '';

        foreach ($this->mods as $mod) {
            if (
                !is_array($mod)
                || (int) ($mod['id'] ?? 0) !== $modId
            ) {
                continue;
            }

            $name = trim(
                (string) (
                    $mod['name']
                    ?? $mod['title']
                    ?? $modId
                )
            );

            $file =
                is_array($mod['file'] ?? null)
                    ? $mod['file']
                    : [];

            $version = trim(
                (string) (
                    $file['version']
                    ?? $mod['version']
                    ?? ''
                )
            );

            break;
        }

        $this->requestAction(
            'install',
            'modio:' . $modId,
            $name,
            'modio',
            $version
        );
    }

    public function installUMod(string $pluginId): void
    {
        $pluginId = strtolower(trim($pluginId));

        if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $pluginId)) {
            return;
        }

        $name = $pluginId;
        $version = '';

        foreach ($this->mods as $mod) {
            if (!is_array($mod)) {
                continue;
            }

            $candidateIds = array_filter([
                $mod['id'] ?? null,
                $mod['slug'] ?? null,
                $mod['name'] ?? null,
                $mod['plugin_id'] ?? null,
                $mod['provider_id'] ?? null,
            ], static fn ($value): bool =>
                is_scalar($value)
                && trim((string) $value) !== ''
            );

            $matches = false;

            foreach ($candidateIds as $candidateId) {
                if (
                    strtolower(trim((string) $candidateId))
                    === $pluginId
                ) {
                    $matches = true;
                    break;
                }
            }

            if (!$matches) {
                continue;
            }

            $name = trim(
                (string) (
                    $mod['title']
                    ?? $mod['name']
                    ?? $pluginId
                )
            );

            $version = trim(
                (string) (
                    $mod['version']
                    ?? ''
                )
            );

            break;
        }

        $this->requestAction(
            'install',
            'umod:' . $pluginId,
            $name,
            'umod',
            $version
        );
    }

    protected function installedProviderMetadata(array $mod): array
    {
        $provider = trim(
            (string) ($mod['provider'] ?? '')
        );

        $providerId = trim(
            (string) ($mod['provider_id'] ?? '')
        );

        if ($provider === '' || $providerId === '') {
            return [];
        }

        try {
            $sources = app(SourceRegistry::class);

            if (!$sources->supports($this->server, $provider)) {
                return [];
            }

            $source = $sources->context(
                $this->server,
                $provider
            );

            $gameKey = $source->gameKey();
            $providerService = $sources->provider($provider);

            return Cache::remember(
                'modharbor:installed:'
                . $this->server->uuid . ':'
                . hash('sha256', json_encode($source->config(), JSON_THROW_ON_ERROR)) . ':'
                . $gameKey
                . ':'
                . $provider
                . ':'
                . sha1($providerId),
                now()->addHour(),
                static function () use (
                    $providerService,
                    $providerId,
                    $source
                ): array {
                    try {
                        return $providerService->get(
                            $providerId,
                            $source
                        ) ?? [];
                    } catch (Throwable) {
                        return [];
                    }
                }
            );
        } catch (Throwable) {
            /*
             * Provider artwork/metadata must never break Installed.
             */
            return [];
        }
    }

    public function installedDisplayMods(): array
    {
        $mods = $this->installedMods;

        $updates = $this->updateResults;

        foreach ($mods as $key => &$mod) {
            $update = $updates[$key] ?? [];
            $metadata = $this->installedProviderMetadata($mod);

            $mod['_key'] = $key;
            $mod['_update_status'] = $update['status'] ?? 'Not checked';
            $mod['_logo'] = trim((string) ($metadata['logo'] ?? ''));
            $mod['_summary'] = trim((string) ($metadata['summary'] ?? ''));
            $mod['_author'] = trim((string) ($metadata['author'] ?? ''));
            $mod['_profile_url'] = trim((string) ($metadata['profile_url'] ?? ''));
            $mod['_provider_updated_at'] = (int) ($metadata['date_updated'] ?? 0);
            $mod['_latest'] =
                $update['latest']
                ?? null;

            $mod['_provider_latest'] =
                trim((string) (
                    $update['provider_latest']
                    ?? ''
                ));

            $mod['_constrained'] =
                !empty($update['constrained']);

            $mod['_requirements'] =
                is_array(
                    $update['requirements']
                    ?? null
                )
                    ? $update['requirements']
                    : [];

            $mod['_has_update'] = (bool) (
                $update['update_available']
                ?? $update['has_update']
                ?? $update['available']
                ?? false
            );

            /*
             * Some lifecycle providers expose current/latest but no explicit
             * boolean. Treat differing non-empty versions as an update.
             */
            if (
                !$mod['_has_update']
                && !array_key_exists('available', $update)
                && !empty($update['latest'])
                && !empty($mod['version'])
                && (string) $update['latest'] !== (string) $mod['version']
            ) {
                $mod['_has_update'] = true;
            }
        }
        unset($mod);

        $search = mb_strtolower(trim($this->installedSearch));

        if ($search !== '') {
            $mods = array_filter(
                $mods,
                static function (array $mod) use ($search): bool {
                    $haystack = mb_strtolower(
                        implode(' ', [
                            (string) ($mod['name'] ?? ''),
                            (string) ($mod['provider'] ?? ''),
                            (string) ($mod['version'] ?? ''),
                            (string) ($mod['_latest'] ?? ''),
                        ])
                    );

                    return str_contains($haystack, $search);
                }
            );
        }

        if ($this->installedProviderFilter !== 'all') {
            $mods = array_filter(
                $mods,
                fn (array $mod): bool =>
                    strtolower((string) ($mod['provider'] ?? ''))
                    === strtolower($this->installedProviderFilter)
            );
        }

        if ($this->installedStatusFilter !== 'all') {
            $status = $this->installedStatusFilter;

            $mods = array_filter(
                $mods,
                static function (array $mod) use ($status): bool {
                    return match ($status) {
                        'updates' => !empty($mod['_has_update']),
                        'disabled' => empty($mod['enabled']),
                        'dependencies' => !empty($mod['dependency']),
                        'adopted' =>
                            ($mod['source_type'] ?? '') === 'adopted'
                            || (($mod['provider'] ?? '') === 'upload' && ($mod['version'] ?? '') === 'Adopted'),
                        'current' =>
                            ($mod['_update_status'] ?? '') === 'Up to date'
                            && empty($mod['_has_update'])
                            && !empty($mod['enabled'])
                            && empty($mod['dependency']),
                        default => true,
                    };
                }
            );
        }

        uasort(
            $mods,
            function (array $a, array $b): int {
                if ($this->installedSort === 'name') {
                    return strcasecmp(
                        (string) ($a['name'] ?? ''),
                        (string) ($b['name'] ?? '')
                    );
                }

                if ($this->installedSort === 'recent') {
                    return strcmp(
                        (string) ($b['updated_at'] ?? $b['installed_at'] ?? ''),
                        (string) ($a['updated_at'] ?? $a['installed_at'] ?? '')
                    );
                }

                /*
                 * Default: updates first, then normal mods, then dependencies.
                 */
                $aUpdate = !empty($a['_has_update']) ? 1 : 0;
                $bUpdate = !empty($b['_has_update']) ? 1 : 0;

                if ($aUpdate !== $bUpdate) {
                    return $bUpdate <=> $aUpdate;
                }

                $aDependency = !empty($a['dependency']) ? 1 : 0;
                $bDependency = !empty($b['dependency']) ? 1 : 0;

                if ($aDependency !== $bDependency) {
                    return $aDependency <=> $bDependency;
                }

                return strcasecmp(
                    (string) ($a['name'] ?? ''),
                    (string) ($b['name'] ?? '')
                );
            }
        );

        return $mods;
    }

    public function installedStats(): array
    {
        $stats = [
            'total' => count($this->installedMods),
            'updates' => 0,
            'disabled' => 0,
            'dependencies' => 0,
            'adopted' => 0,
            'providers' => 0,
        ];

        $providers = [];

        foreach ($this->installedMods as $key => $mod) {
            $provider = strtolower(trim((string) ($mod['provider'] ?? '')));
            if ($provider !== '') {
                $providers[$provider] = true;
            }

            $update = $this->updateResults[$key] ?? [];
            $hasUpdate = (bool) (
                $update['update_available']
                ?? $update['has_update']
                ?? $update['available']
                ?? false
            );

            if (!$hasUpdate && !empty($update['latest']) && !empty($mod['version'])) {
                $hasUpdate = (string) $update['latest'] !== (string) $mod['version'];
            }

            if ($hasUpdate) {
                $stats['updates']++;
            }
            if (empty($mod['enabled'])) {
                $stats['disabled']++;
            }
            if (!empty($mod['dependency'])) {
                $stats['dependencies']++;
            }
            if (
                ($mod['source_type'] ?? '') === 'adopted'
                || ($provider === 'upload' && ($mod['version'] ?? '') === 'Adopted')
            ) {
                $stats['adopted']++;
            }
        }

        $stats['providers'] = count($providers);

        return $stats;
    }

    public function installedProviderOptions(): array
    {
        $providers = [];

        foreach ($this->installedMods as $mod) {
            $provider = strtolower(trim((string) ($mod['provider'] ?? '')));
            if ($provider !== '') {
                $providers[$provider] = $this->providerLabel($provider);
            }
        }

        natcasesort($providers);

        return $providers;
    }

    public function updateAllCount(): int
    {
        $count = 0;

        foreach ($this->installedMods as $key => $mod) {
            // Explicit/top-level packages are the safest bulk targets. Their
            // lifecycle operations continue managing dependency releases.
            if (!empty($mod['dependency'])) {
                continue;
            }

            $update = $this->updateResults[$key] ?? [];
            if (!empty($update['available']) || !empty($update['update_available']) || !empty($update['has_update'])) {
                $count++;
            }
        }

        return $count;
    }

    public function requestUpdateAll(): void
    {
        if ($this->updateResults === []) {
            $this->checkUpdates();
        }

        $count = $this->updateAllCount();

        if ($count < 1) {
            Notification::make()
                ->title('Everything is up to date')
                ->body('No explicit managed mods currently have compatible updates available.')
                ->success()
                ->send();
            return;
        }

        $this->pendingAction = 'update-all';
        $this->pendingKey = '__all__';
        $this->pendingName = (string) $count;
        $this->pendingProvider = null;
        $this->pendingVersion = null;
        $this->pendingSourceVersion = null;
    }

    public function lifecycleSupported(string $provider): bool
    {
        return app(ModLifecycleService::class)->supported($this->server, $provider);
    }

    public function startReplaceUpload(string $key): void
    {
        $installed =
            $this->installedMods[$key]
            ?? null;

        if (
            !$installed
            || ($installed['provider'] ?? '') !== 'upload'
        ) {
            Notification::make()
                ->title('Replace File unavailable')
                ->body(
                    'Only mods installed through Upload File can use Replace File.'
                )
                ->danger()
                ->send();

            return;
        }

        $this->replaceUploadKey = $key;
        $this->replaceUploadFile = null;
    }

    public function cancelReplaceUpload(): void
    {
        $this->replaceUploadKey = null;
        $this->replaceUploadFile = null;
        $this->replaceUploadVersionLabel = '';
    }

    public function replaceUploadedFile(): void
    {
        try {
            $key = $this->replaceUploadKey;

            if (!$key) {
                throw new RuntimeException(
                    'Choose an Upload File mod to replace.'
                );
            }

            $installed =
                $this->installedMods[$key]
                ?? null;

            if (
                !$installed
                || ($installed['provider'] ?? '') !== 'upload'
            ) {
                throw new RuntimeException(
                    'Replace File is only available for Upload File packages.'
                );
            }

            if (
                !$this->replaceUploadFile
                instanceof TemporaryUploadedFile
            ) {
                throw new RuntimeException(
                    'Choose the replacement file first.'
                );
            }

            Gate::authorize(
                'file.create',
                $this->server
            );

            $originalName =
                $this->replaceUploadFile
                    ->getClientOriginalName();

            $extension = strtolower(
                pathinfo(
                    $originalName,
                    PATHINFO_EXTENSION
                )
            );

            $allowed = $this->uploadExtensions();

            if (!in_array($extension, $allowed, true)) {
                throw new RuntimeException(
                    $this->game . ' Replace File expects ' . $this->uploadFormatHelp()
                );
            }

            $package = app(
                UploadPackageStore::class
            )->store(
                $this->server,
                $this->replaceUploadFile,
                $this->replaceUploadVersionLabel
            );

            $result = app(
                ModLifecycleService::class
            )->run(
                $this->server,
                'replace',
                $key,
                null,
                $package['id']
            );

            $this->cancelReplaceUpload();

            $this->updateResults = [];
            $this->updatesCheckedAt = null;

            $this->loadInstalledMods();
            $this->refreshHealth(false);

            if ($this->game === 'Rust') {
                $this->rustRuntime =
                    $this->detectRustRuntime();
            }

            Notification::make()
                ->title('Replace File completed')
                ->body(
                    $this->lifecycleResultMessage(
                        $result,
                        'The uploaded mod was replaced successfully.'
                    )
                )
                ->success()
                ->send();
        } catch (Throwable $e) {
            Notification::make()
                ->title('Replace File could not complete')
                ->body(
                    get_class($e) === RuntimeException::class
                        ? $e->getMessage()
                        : 'Check server access and permissions, then try again.'
                )
                ->danger()
                ->send();
        }
    }


    public function selectedModDetails(): ?array
    {
        $key = $this->detailsKey;

        if (
            !$key
            || !isset($this->installedMods[$key])
        ) {
            return null;
        }

        $mod = $this->installedMods[$key];

        $configs = array_values(
            array_filter(
                $this->modConfigs,
                function (array $config) use ($mod): bool {
                    $configPath = strtolower(
                        (string) ($config['path'] ?? '')
                    );

                    foreach (
                        (array) ($mod['paths'] ?? [])
                        as $managedPath
                    ) {
                        $name = strtolower(
                            pathinfo(
                                basename((string) $managedPath),
                                PATHINFO_FILENAME
                            )
                        );

                        if (
                            $name !== ''
                            && str_contains(
                                $configPath,
                                $name
                            )
                        ) {
                            return true;
                        }
                    }

                    return false;
                }
            )
        );

        $history = array_values(
            array_filter(
                app(OperationStore::class)
                    ->history($this->server),
                static fn (array $event): bool =>
                    strcasecmp(
                        (string) ($event['name'] ?? ''),
                        (string) ($mod['name'] ?? '')
                    ) === 0
            )
        );

        return [
            'key' => $key,
            'mod' => $mod,
            'health' =>
                $this->health['mods'][$key]
                ?? null,
            'configs' => $configs,
            'history' => array_slice(
                $history,
                0,
                5
            ),
        ];
    }

    public function historyDisplay(): array
    {
        Gate::authorize('file.read', $this->server); Gate::authorize('file.read-content', $this->server);
        return app(OperationStore::class)->history($this->server, $this->historySearch,
            $this->historyActionFilter, $this->historyStatusFilter);
    }

    public function downloadSupportBundle(): mixed
    {
        $this->hydrate();
        $json = app(\GameNest\GameNestModManager\Services\SupportBundle::class)->json($this->server);
        return response()->streamDownload(static function () use ($json) { echo $json; },
            'modharbor-support.json', ['Content-Type' => 'application/json',
                'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function refreshHealth(
        bool $notify = true,
        ?array $mods = null
    ): void {
        try {
            $this->health = app(
                ModHealthService::class
            )->scan(
                $this->server,
                $mods
            );

            $this->healthCheckedAt =
                now()->toIso8601String();

            if (!$notify) {
                return;
            }

            $status =
                $this->health['status']
                ?? 'healthy';

            $notification = Notification::make()
                ->title(
                    $status === 'healthy'
                        ? 'Verification complete'
                        : (
                            $status === 'broken'
                                ? 'Recovery issue detected'
                                : 'Verification found issues'
                        )
                )
                ->body(
                    ($this->health['managed'] ?? 0)
                    . ' managed mod(s) checked, '
                    . ($this->health['issues'] ?? 0)
                    . ' managed mod(s) with issues, '
                    . ($this->health['unmanaged'] ?? 0)
                    . ' unmanaged plugin(s).'
                );

            if ($status === 'broken') {
                $notification->danger();
            } elseif ($status === 'warning') {
                $notification->warning();
            } else {
                $notification->success();
            }

            $notification->send();
        } catch (Throwable $exception) {
            $this->health = [
                'status' => 'broken',
                'manifest' => 'unknown',
                'managed' => count($this->installedMods),
                'issues' => 0,
                'unmanaged' => 0,
                'stale_runtime' => 0,
                'runtime' => $this->rustRuntime,
                'mods' => [],
                'manifest_message' =>
                    'Health verification could not complete.',
            ];

            if ($notify) {
                Notification::make()
                    ->title('Verification failed')
                    ->body(
                        get_class($exception) === RuntimeException::class
                            ? $exception->getMessage()
                            : 'Check server access and permissions, then try again.'
                    )
                    ->danger()
                    ->send();
            }
        }
    }

    public function verifyAll(): void
    {
        $this->loadInstalledMods();

        $this->refreshHealth(
            true,
            $this->manifestError === null
                ? $this->installedMods
                : null
        );
    }

    public function retryOperationRecovery(
        string $operationId
    ): void {
        try {
            $result = app(
                ModLifecycleService::class
            )->recoverOperation(
                $this->server,
                $operationId
            );

            $this->loadInstalledMods();

            $this->history = app(
                OperationStore::class
            )->history($this->server);

            $this->refreshHealth(false);

            Notification::make()
                ->title('Recovery completed')
                ->body(
                    $result['message']
                    ?? 'The interrupted operation was safely rolled back.'
                )
                ->success()
                ->send();
        } catch (Throwable $exception) {
            $this->refreshHealth(false);

            Notification::make()
                ->title('Recovery could not complete')
                ->body(
                    get_class($exception)
                    === RuntimeException::class
                        ? $exception->getMessage()
                        : 'Automatic recovery could not safely verify the interrupted operation.'
                )
                ->danger()
                ->send();
        }
    }

    public function verifyMod(string $key): void
    {
        $this->refreshHealth(false);

        $health =
            $this->health['mods'][$key]
            ?? null;

        if (!$health) {
            Notification::make()
                ->title('Verify failed')
                ->body(
                    'The selected managed mod could not be verified.'
                )
                ->danger()
                ->send();

            return;
        }

        $issues =
            (array) ($health['issues'] ?? []);

        if ($issues === []) {
            Notification::make()
                ->title('Mod is healthy')
                ->body(
                    ($health['name'] ?? $key)
                    . ' has all expected managed files.'
                )
                ->success()
                ->send();

            return;
        }

        Notification::make()
            ->title('Verification found issues')
            ->body(
                implode(
                    ' ',
                    array_slice(
                        $issues,
                        0,
                        4
                    )
                )
            )
            ->warning()
            ->send();
    }

    public function repairMod(string $key): void
    {
        try {
            $this->refreshHealth(false);

            $health =
                $this->health['mods'][$key]
                ?? null;

            if (!$health) {
                throw new RuntimeException(
                    'The selected managed mod could not be verified.'
                );
            }

            if (empty($health['repairable'])) {
                if (empty($health['source_available'])) {
                    throw new RuntimeException(
                        'Repair is unavailable because the retained/original source is missing.'
                    );
                }

                throw new RuntimeException(
                    'This mod does not currently have missing managed files that require repair.'
                );
            }

            $result = app(
                ModLifecycleService::class
            )->repair(
                $this->server,
                $key
            );

            $this->updateResults = [];
            $this->updatesCheckedAt = null;

            $this->loadInstalledMods();
            $this->refreshHealth(false);

            Notification::make()
                ->title('Repair completed')
                ->body(
                    $this->lifecycleResultMessage(
                        $result,
                        'The managed mod was repaired successfully.'
                    )
                )
                ->success()
                ->send();
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Repair could not complete')
                ->body(
                    get_class($exception) === RuntimeException::class
                        ? $exception->getMessage()
                        : 'Check server access and permissions, then try again.'
                )
                ->danger()
                ->send();
        }
    }

    public function repairAllBroken(): void
    {
        $this->refreshHealth(false);

        $repairable = [];

        foreach (
            (array) ($this->health['mods'] ?? [])
            as $key => $health
        ) {
            if (!empty($health['repairable'])) {
                $repairable[] = $key;
            }
        }

        if ($repairable === []) {
            Notification::make()
                ->title('Nothing to repair')
                ->body(
                    'No managed mods currently have repairable missing files.'
                )
                ->success()
                ->send();

            return;
        }

        $completed = 0;
        $failed = [];

        foreach ($repairable as $key) {
            try {
                app(ModLifecycleService::class)
                    ->repair(
                        $this->server,
                        $key
                    );

                $completed++;
            } catch (Throwable) {
                $failed[] =
                    $this->installedMods[$key]['name']
                    ?? $key;
            }
        }

        $this->updateResults = [];
        $this->updatesCheckedAt = null;

        $this->loadInstalledMods();
        $this->refreshHealth(false);

        $notification = Notification::make()
            ->title(
                $failed === []
                    ? 'Repair completed'
                    : 'Repair completed with warnings'
            )
            ->body(
                $completed
                . ' mod(s) repaired.'
                . (
                    $failed !== []
                        ? ' Failed: '
                            . implode(', ', $failed)
                            . '.'
                        : ''
                )
            );

        if ($failed === []) {
            $notification->success();
        } else {
            $notification->warning();
        }

        $notification->send();
    }

    public function toggleModDetails(string $key): void
    {
        if (!isset($this->installedMods[$key])) {
            return;
        }

        $this->detailsKey =
            $this->detailsKey === $key
                ? null
                : $key;

        if ($this->health === []) {
            $this->refreshHealth(false);
        }
    }

    public function pendingActionDetails(): ?array
    {
        $action = $this->pendingAction;
        $key = $this->pendingKey;

        if (
            !$action
            || !$key
        ) {
            return null;
        }

        if ($action === 'update-all' && $key === '__all__') {
            $count = max(0, (int) $this->pendingName);

            return [
                'action' => 'update-all',
                'title' => 'Update ' . $count . ' managed mod' . ($count === 1 ? '' : 's') . '?',
                'body' => 'ModHarbor will update each explicit managed package to its latest compatible release. Each package is protected by its own transaction and verification. The batch stops immediately if any update fails or requires recovery.',
                'button' => 'Update All',
                'name' => $count . ' managed mods',
                'provider' => 'Multiple providers',
                'version' => '',
                'latest' => '',
                'destructive' => false,
            ];
        }

        if (
            $action !== 'install'
            && !isset($this->installedMods[$key])
        ) {
            return null;
        }

        if ($action === 'install') {
            [$providerKey, $providerId] = array_pad(
                explode(':', $key, 2),
                2,
                ''
            );

            $mod = [
                'name' =>
                    trim((string) $this->pendingName) !== ''
                        ? trim((string) $this->pendingName)
                        : (
                            $providerId !== ''
                                ? $providerId
                                : $key
                        ),

                'provider' =>
                    trim((string) $this->pendingProvider) !== ''
                        ? trim((string) $this->pendingProvider)
                        : (
                            $providerKey !== ''
                                ? $providerKey
                                : 'unknown'
                        ),

                'version' =>
                    trim((string) $this->pendingVersion),
            ];
        } else {
            $mod = $this->installedMods[$key];
        }

        $name = (string) (
            $mod['name']
            ?? $key
        );

        $version = trim(
            (string) (
                $mod['version']
                ?? ''
            )
        );

        $update =
            $this->updateResults[$key]
            ?? [];

        $latest = trim(
            (string) (
                $update['latest']
                ?? $update['provider_latest']
                ?? ''
            )
        );

        $provider =
            $this->providerLabel(
                (string) (
                    $mod['provider']
                    ?? 'unknown'
                )
            );

        $details = match ($action) {
            'install' => [
                'title' =>
                    'Install ' . $name . '?',

                'body' =>
                    'ModHarbor will install and begin managing this package. '
                    . 'The game server must remain stopped while the managed files and manifest are committed.',

                'button' =>
                    'Install',
            ],

            'enable' => [
                'title' =>
                    'Enable ' . $name . '?',

                'body' =>
                    'ModHarbor will restore this package to its active managed location. '
                    . 'Required dependencies remain managed automatically.',

                'button' =>
                    'Enable',
            ],

            'disable' => [
                'title' =>
                    'Disable ' . $name . '?',

                'body' =>
                    'ModHarbor will move this package out of its active location without uninstalling it. '
                    . 'Its managed state and source information will be retained.',

                'button' =>
                    'Disable',
            ],

            'reinstall' => [
                'title' =>
                    'Reinstall ' . $name . '?',

                'body' =>
                    'ModHarbor will redeploy the exact release/source currently recorded for this package'
                    . ($version !== '' ? ' (' . $version . ')' : '')
                    . '. Active configuration and user data covered by the game driver are preserved.',

                'button' =>
                    'Reinstall',
            ],

            'update' => [
                'title' =>
                    'Update ' . $name . '?',

                'body' =>
                    'ModHarbor will replace the currently managed '
                    . ($version !== '' ? $version : 'release')
                    . (
                        $latest !== ''
                            ? ' with ' . $latest
                            : ' with the latest compatible ' . $provider . ' release'
                    )
                    . '. Obsolete managed files are retired transactionally and preserved configuration remains untouched.',

                'button' =>
                    'Update',
            ],

            'remove' => [
                'title' =>
                    'Remove ' . $name . '?',

                'body' =>
                    'This removes files owned by this ModHarbor package. '
                    . 'User configuration covered by preservation rules is left in place. '
                    . 'Dependencies installed only for this package are also removed when nothing else requires them.',

                'button' =>
                    'Remove',
            ],

            default => [
                'title' =>
                    ucfirst($action) . ' ' . $name . '?',

                'body' =>
                    'The game server must remain stopped until ModHarbor completes and verifies the operation.',

                'button' =>
                    ucfirst($action),
            ],
        };

        $details['action'] = $action;
        $details['name'] = $name;
        $details['provider'] = $provider;
        $details['version'] = $version;
        $details['latest'] = $latest;
        $details['destructive'] =
            $action === 'remove';

        return $details;
    }

    protected function lifecycleResultMessage(
        array $result,
        string $fallback
    ): string {
        $message = trim(
            (string) (
                $result['message']
                ?? $fallback
            )
        );

        $verification =
            $result['verification']
            ?? null;

        if (!is_array($verification)) {
            return $message;
        }

        $managed =
            (int) (
                $verification['managed_files']
                ?? 0
            );

        $retired =
            (int) (
                $verification['retired_files']
                ?? 0
            );

        $dependencies =
            (int) (
                $verification['dependency_links']
                ?? 0
            );

        return $message
            . ' Verified '
            . $managed
            . ' managed file(s), '
            . $retired
            . ' retired path(s), and '
            . $dependencies
            . ' dependency link(s).';
    }

    protected function lifecycleActionLabel(
        string $action
    ): string {
        return match ($action) {
            'install' => 'Install',
            'enable' => 'Enable',
            'disable' => 'Disable',
            'reinstall' => 'Reinstall',
            'update' => 'Update',
            'update-all' => 'Update All',
            'remove' => 'Remove',
            'replace' => 'Replace File',
            'repair' => 'Repair',
            default => ucfirst($action),
        };
    }


    public function requestAction(
        string $action,
        string $key,
        ?string $name = null,
        ?string $provider = null,
        ?string $version = null,
        ?string $sourceVersion = null
    ): void {
        if (
            !in_array(
                $action,
                [
                    'install',
                    'enable',
                    'disable',
                    'reinstall',
                    'update',
                    'remove',
                ],
                true
            )
        ) {
            return;
        }

        $this->dependencyPreview = [];
        try { $this->dependencyPreview = app(ModLifecycleService::class)->dependencyPreview($this->server, $key, $action); }
        catch (Throwable) { $this->dependencyPreview = [['type' => 'unavailable', 'key' => 'Dependencies will be rechecked before any files change.', 'constraint' => '', 'file_id' => '']]; }
        $this->pendingAction = $action;
        $this->pendingKey = $key;
        $this->pendingName =
            $name !== null
                ? trim($name)
                : null;
        $this->pendingProvider =
            $provider !== null
                ? trim($provider)
                : null;
        $this->pendingVersion =
            $version !== null
                ? trim($version)
                : null;
        $this->pendingSourceVersion =
            $sourceVersion !== null
                ? trim($sourceVersion)
                : null;
    }

    public function cancelAction(): void
    {
        $this->pendingAction = null;
        $this->pendingKey = null;
        $this->pendingName = null;
        $this->pendingProvider = null;
        $this->pendingVersion = null;
        $this->pendingSourceVersion = null;
    }

    public function confirmAction(): void
    {
        $action = $this->pendingAction;
        $key = $this->pendingKey;
        $sourceVersion = $this->pendingSourceVersion;

        $this->cancelAction();

        if (!$action || !$key) {
            return;
        }

        if ($action === 'update-all' && $key === '__all__') {
            $this->performUpdateAll();
            return;
        }

        try {
            $service = app(
                ModLifecycleService::class
            );

            if (
                $sourceVersion !== null
                && trim($sourceVersion) !== ''
            ) {
                $result = $service->run(
                    $this->server,
                    $action,
                    $key,
                    $sourceVersion
                );
            } else {
                $result = $service->run(
                    $this->server,
                    $action,
                    $key
                );
            }

            $this->updateResults = [];
            $this->updatesCheckedAt = null;

            $this->loadInstalledMods();
            $this->refreshHealth(false);

            if ($this->game === 'Rust') {
                $this->rustRuntime =
                    $this->detectRustRuntime();
            }

            Notification::make()
                ->title(
                    $this->lifecycleActionLabel($action)
                    . ' completed'
                )
                ->body(
                    $this->lifecycleResultMessage(
                        $result,
                        'The ModHarbor operation completed successfully.'
                    )
                )
                ->success()
                ->send();

        } catch (Throwable $e) {
            /*
             * Always reload after a failed lifecycle operation too.
             *
             * A normal failure may have rolled back successfully, while a
             * recovery-required failure may intentionally leave the server
             * locked. The panel should immediately reflect either state.
             */
            $this->loadInstalledMods();

            try {
                $this->refreshHealth(false);
            } catch (Throwable) {
            }

            Notification::make()
                ->title(
                    $this->lifecycleActionLabel($action)
                    . ' could not complete'
                )
                ->body(
                    get_class($e) === RuntimeException::class
                        ? $e->getMessage()
                        : 'Check server access and permissions, then try again.'
                )
                ->danger()
                ->send();
        }
    }

    protected function performUpdateAll(): void
    {
        try {
            $mods = app(ManifestService::class)->mods($this->server);
            $keys = array_keys(array_filter($mods, fn ($mod) => empty($mod['dependency'])));
            $result = $keys ? app(\GameNest\GameNestModManager\Services\ModBatchService::class)->run($this->server, 'update', $keys)
                : ['completed' => [], 'skipped' => [], 'remaining' => [], 'failed' => null];
            $this->bulkResult = $result;
            $this->loadInstalledMods(); $this->checkUpdates(
                $this->manifestError === null ? $this->installedMods : null
            ); $this->refreshHealth(false);
            Notification::make()->title($result['failed'] ? 'Update All stopped' : 'Update All completed')
                ->body(count($result['completed']) . ' mods updated. ' . ($result['failed']
                    ? 'Previously completed updates remain valid. Review History before retrying.'
                    : 'Each package and its dependencies was checked before deployment.'))->send();
        } catch (Throwable) {
            $this->loadInstalledMods();
            Notification::make()->title('Update All could not start')->body('Check permissions, server state and selected packages.')->danger()->send();
        }
    }

    public function checkUpdates(?array $mods = null): void
    {
        try {
            $this->updateResults = app(ModLifecycleService::class)->updates(
                $this->server,
                $mods
            );
            $this->updatesCheckedAt = now()->toIso8601String();
        } catch (Throwable) {
            $this->updateResults = [];
            $this->updatesCheckedAt = null;
            Notification::make()->title('Unable to check updates')->body('Check the manifest and server connection, then try again.')->danger()->send();
        }
    }

    public function refreshConfigs(): void { $this->loadModConfigs(); }

    protected function loadModConfigs(): void
    {
        $this->modConfigs = []; $this->configError = null;
        try { $this->modConfigs = app(\GameNest\GameNestModManager\Services\ConfigManagementService::class)->listing($this->server); }
        catch (Throwable) { $this->configError = 'Configuration discovery failed. Check server permissions and connectivity.'; }
    }

    public function editModConfigByIndex(int $index): void
    {
        $this->loadModConfigs();
        if (isset($this->modConfigs[$index])) { $this->editModConfig($this->modConfigs[$index]['path']); }
    }

    protected function editModConfig(string $path): void
    {
        try {
            $content = app(\GameNest\GameNestModManager\Services\ConfigManagementService::class)->read($this->server, $path);
            $this->editingConfigPath = $path; $this->editingConfigContent = $content;
            $this->editingConfigHash = hash('sha256', $content); $this->loadConfigRevisions();
        } catch (Throwable) { Notification::make()->title('Configuration could not be loaded safely')->danger()->send(); }
    }

    protected function loadConfigRevisions(): void
    {
        if ($this->editingConfigPath === null) {
            $this->configRevisions = [];

            return;
        }

        try {
            $this->configRevisions = app(
                ConfigRevisionStore::class
            )->listing(
                $this->server,
                ltrim(
                    $this->editingConfigPath,
                    '/'
                )
            );
        } catch (Throwable) {
            $this->configRevisions = [];
        }
    }

    public function reloadModConfig(): void
    {
        if ($this->editingConfigPath === null) {
            return;
        }

        $this->editModConfig($this->editingConfigPath);
    }

    public function closeModConfigEditor(): void
    {
        $this->editingConfigPath = null;
        $this->editingConfigContent = '';
    }

    public function saveModConfig(): void { $this->commitConfig(); }

    public function restoreConfigRevision(string $revisionId): void { $this->commitConfig($revisionId); }

    protected function commitConfig(?string $revisionId = null): void
    {
        try {
            if ($this->editingConfigPath === null) { throw new RuntimeException('Select a configuration first.'); }
            app(\GameNest\GameNestModManager\Services\ConfigManagementService::class)->save(
                $this->server, $this->editingConfigPath, $this->editingConfigContent, $this->editingConfigHash, $revisionId);
            $this->editModConfig($this->editingConfigPath);
            Notification::make()->title($revisionId ? 'Configuration restored' : 'Configuration saved')->success()->send();
        } catch (Throwable $exception) {
            Notification::make()->title('Configuration was not saved')->body($exception instanceof RuntimeException
                ? $exception->getMessage() : 'Validation or connection failed. Reload and check the configuration.')->danger()->send();
        }
    }

    public function loadInstalledMods(): void
    {
        Gate::authorize('file.read', $this->server);
        Gate::authorize('file.read-content', $this->server);
        $this->manifestError = null;
        try {
            $this->installedMods = app(ManifestService::class)->mods($this->server);
        } catch (Throwable) {
            $this->installedMods = [];
            $this->manifestError = 'Unable to read the mod manifest. Check the server connection or restore a valid manifest before changing mods.';
        }
    }

    public function isInstalled(
        int $modId
    ): bool {
        $key =
            app(
                ManifestService::class
            )->key(
                'modio',
                $modId
            );

        return isset(
            $this->installedMods[
                $key
            ]
        );
    }

    public function formatBytes(
        int $bytes
    ): string {
        if ($bytes <= 0) {
            return 'Unknown';
        }

        $units = [
            'B',
            'KB',
            'MB',
            'GB',
        ];

        $power = min(
            (int) floor(
                log(
                    $bytes,
                    1024
                )
            ),
            count($units) - 1
        );

        return number_format(
            $bytes /
            (1024 ** $power),
            $power > 0 ? 1 : 0
        ) .
        ' ' .
        $units[$power];
    }
}
