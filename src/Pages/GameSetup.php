<?php

namespace GameNest\GameNestModManager\Pages;

use Filament\Pages\Page;
use GameNest\GameNestModManager\Adapters\ConfiguredGameAdapter;
use GameNest\GameNestModManager\Services\{
    ConfiguredDriverResolver,
    GameCatalogSearchService,
    GameProfileCatalog,
    GameDefinitionStore,
    ProviderGameIdentityService,
    SourceRegistry
};
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\WithFileUploads;
use RuntimeException;

class GameSetup extends Page
{
    use WithFileUploads;

    protected string $view = 'gamenest-mod-manager::pages.game-setup';
    protected static ?int $navigationSort = 2;

    #[Locked]
    public string $revision = 'empty';

    #[Locked]
    public string $editing = '';

    #[Locked]
    public array $games = [];

    #[Locked]
    public bool $importedDraft = false;

    public bool $editorOpen = false;

    public array $form = [];
    public array $allowedSources = [];
    public array $sourceMetadata = [];
    public array $sourceMetadataFields = [];

    #[Locked]
    public array $gameSearchResults = [];

    #[Locked]
    public bool $profileSelected = false;
    public string $gameSearchMessage = '';
    public bool $gameIdentitySelected = false;

    public string $eggNames = '';
    public string $eggNameContains = '';
    public string $eggIds = '';
    public string $modDirectories = 'Mods';
    public string $configDirectories = 'Configs';
    public string $configRulesJson = '';
    public string $message = '';
    public string $catalogSearch = '';
    public string $catalogFilter = 'all';

    public $importFile = null;

    /** @var mixed Temporary Livewire upload before Save Game. */
    public $artworkUpload = null;

    public function catalogStats(): array
    {
        $games = is_array($this->games) ? $this->games : [];
        $stats = ['total' => count($games), 'enabled' => 0, 'official' => 0, 'custom' => 0];

        foreach ($games as $key => $game) {
            if (!empty($game['enabled'])) {
                $stats['enabled']++;
            }

            if ($this->isOfficialDefinition((string) $key)) {
                $stats['official']++;
            } else {
                $stats['custom']++;
            }
        }

        return $stats;
    }

    public function filteredGames(): array
    {
        $query = mb_strtolower(trim($this->catalogSearch));
        $games = is_array($this->games) ? $this->games : [];

        return array_filter($games, function (mixed $game, string|int $key) use ($query): bool {
            if (!is_array($game)) {
                return false;
            }

            $official = $this->isOfficialDefinition((string) $key);
            $matchesFilter = match ($this->catalogFilter) {
                'enabled' => !empty($game['enabled']),
                'disabled' => empty($game['enabled']),
                'official' => $official,
                'custom' => !$official,
                default => true,
            };

            if (!$matchesFilter || $query === '') {
                return $matchesFilter;
            }

            $haystack = mb_strtolower(implode(' ', [
                (string) $key,
                (string) ($game['name'] ?? ''),
                implode(' ', array_keys($game['sources'] ?? [])),
            ]));

            return str_contains($haystack, $query);
        }, ARRAY_FILTER_USE_BOTH);
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null
            && method_exists($user, 'isRootAdmin')
            && $user->isRootAdmin();
    }

    private function authorizeBuilder(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public static function getNavigationParentItem(): ?string
    {
        return 'ModHarbor';
    }

    public static function getNavigationLabel(): string
    {
        return 'Game Setup';
    }
public function getTitle(): string
    {
        return 'ModHarbor Game Builder';
    }

    public function mount(): void
    {
        $this->resetEditorState();
        $this->editorOpen = false;
    }

    public function newGame(): void
    {
        $this->authorizeBuilder();

        $this->perform(function () {
            $this->reloadCatalog();
            $this->editorOpen = true;

            /*
             * Explicitly clear every editor-backed property before loading
             * the blank definition. This prevents Livewire's DOM morphing
             * from carrying values from the previously viewed definition
             * into the new-game editor.
             */
            $this->editing = '';

            $this->importedDraft = false;

            $this->form = [];
            $this->allowedSources = [];
            $this->sourceMetadata = [];
            $this->sourceMetadataFields = [];

            $this->gameSearchResults = [];
            $this->gameSearchMessage = '';
            $this->gameIdentitySelected = false;

            $this->eggNames = '';
            $this->eggNameContains = '';
            $this->eggIds = '';
            $this->artworkUpload = null;

            $this->modDirectories = '';
            $this->configDirectories = '';
            $this->configRulesJson = '';

            $this->fillDefinition(
                $this->blankDefinition()
            );
        });
    }

    public function cancelEdit(): void
    {
        $this->resetEditorState();
        $this->editorOpen = false;
        $this->message = 'Edit cancelled.';
    }

    private function resetEditorState(): void
    {
        $this->authorizeBuilder();
        $this->perform(function () {
            $this->reloadCatalog();
            $this->editing = '';

            $this->importedDraft = false;

            $this->form = [];
            $this->allowedSources = [];
            $this->sourceMetadata = [];
            $this->sourceMetadataFields = [];

            $this->gameSearchResults = [];
            $this->gameSearchMessage = '';
            $this->gameIdentitySelected = false;

            $this->eggNames = '';
            $this->eggNameContains = '';
            $this->eggIds = '';
            $this->artworkUpload = null;

            $this->modDirectories = '';
            $this->configDirectories = '';
            $this->configRulesJson = '';

            $this->fillDefinition(
                $this->blankDefinition()
            );
        });
    }

    public function updatedForm(mixed $value, string $key): void
    {
        if ($key !== 'name') {
            return;
        }

        $query = trim((string) $value);

        $this->gameIdentitySelected = false;

        if (strlen($query) < 2) {
            $this->gameSearchResults = [];
            $this->gameSearchMessage = '';

            return;
        }

        try {
            $this->gameSearchResults = app(
                GameCatalogSearchService::class
            )->search($query);

            $this->gameSearchMessage =
                $this->gameSearchResults === []
                    ? 'No matching games found. You can continue manually.'
                    : '';
        } catch (\Throwable $e) {
            $this->gameSearchResults = [];
            $this->gameSearchMessage = $e->getMessage();
        }
    }

    public function selectGameSearchResult(int $index): void
    {
        $this->authorizeBuilder();

        $result = $this->gameSearchResults[$index] ?? null;

        if (!is_array($result)) {
            throw new RuntimeException(
                'The selected game search result is no longer available.'
            );
        }

        if (($result['catalog'] ?? '') === 'profile') {
            $profile = app(GameProfileCatalog::class)->get((string) ($result['id'] ?? ''));
            $defaults = $profile['defaults'];
            $capabilities = config('gamenest-mod-manager.game_capabilities', []);
            $keys = $capabilities[$defaults['capability']]['game_keys'] ?? '*';
            if ($this->editing !== '' && $keys !== '*' && !in_array($this->editing, $keys, true)) {
                $this->gameSearchMessage = 'This setup requires its canonical stable key. Use New Game or edit the matching definition.';
                return;
            }

            // Replace the recommendation fields as a unit, so switching games
            // cannot retain another game's provider identities or runtime paths.
            foreach (['name', 'steam_app_id', 'capability', 'package_types', 'deployment', 'behavior'] as $field) {
                $this->form[$field] = $defaults[$field];
            }

            $this->form['runtime_metadata'] =
                (array) ($defaults['runtime_metadata'] ?? []);
            if ($this->editing === '') { $this->form['key'] = $defaults['key']; }
            $this->form['artwork_url'] = '';
            $this->modDirectories = implode("\n", $defaults['mod_directories']);
            $this->configDirectories = implode("\n", $defaults['config_directories']);
            $this->configRulesJson = isset($defaults['config_rules'])
                ? json_encode($defaults['config_rules'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
                : '';
            $this->allowedSources = array_values(array_intersect(
                array_keys($defaults['sources']), array_keys($this->providerOptions())
            ));
            $this->sourceMetadata = [];
            $this->sourceMetadataFields = [];
            foreach ($this->allowedSources as $provider) {
                $this->sourceMetadata[$provider] = json_encode(
                    $defaults['sources'][$provider]['metadata'] ?: (object) [],
                    JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
                );
            }
            $this->profileSelected = true;
            $this->gameIdentitySelected = true;
            $this->gameSearchResults = [];
            $this->gameSearchMessage = 'Applied recommended setup for ' . $defaults['name']
                . '. All values remain editable. Review server detection and provider settings before saving. '
                . $profile['notes'];
            return;
        }

        $name = trim((string) ($result['name'] ?? ''));
        $steamAppId = (int) ($result['steam_app_id'] ?? 0);

        if ($name === '' || $steamAppId < 1) {
            throw new RuntimeException(
                'The selected game identity is invalid.'
            );
        }

        if ($this->profileSelected) {
            $blank = $this->blankDefinition();
            foreach (['capability', 'package_types', 'deployment', 'behavior', 'runtime_metadata'] as $field) {
                $this->form[$field] = $blank[$field];
            }
            if ($this->editing === '') { $this->form['key'] = ''; }
            $this->modDirectories = implode("\n", $blank['mod_directories']);
            $this->configDirectories = implode("\n", $blank['config_directories']);
            $this->configRulesJson = '';
            $this->profileSelected = false;
        }

        $this->allowedSources = [];
        $this->sourceMetadata = [];
        $this->sourceMetadataFields = [];

        $this->form['name'] = $name;
        $this->form['steam_app_id'] = $steamAppId;

        /*
         * Leave artwork_url empty so the existing Steam artwork service
         * resolves and caches artwork from steam_app_id.
         */
        $this->form['artwork_url'] = '';

        if (trim((string) $this->eggNames) === '') {
            $this->eggNames = $name;
        }

        $enabled = $this->applySteamGameSourceDefaults(
            $name,
            $steamAppId
        );

        $this->gameIdentitySelected = true;
        $this->gameSearchResults = [];
        $labels = [];
        $options = $this->providerOptions();
        foreach ($enabled as $key) {
            $labels[] = (string) ($options[$key]['label'] ?? $key);
        }
        $this->gameSearchMessage =
            'Selected ' . $name . ' from Steam.'
            . ($labels === []
                ? ''
                : ' Enabled sources: ' . implode(', ', $labels) . '.');
    }

    /**
     * Enable the portable Steam defaults, then layer on discovered catalog IDs.
     *
     * Generic sources (GitHub / Direct / Upload) are always useful.
     * Steam Workshop is enabled when the selected Steam App ID is present.
     * Nexus (and later providers) are checked only when identity discovery
     * reports a supported match.
     *
     * @return array<int, string>
     */
    private function applySteamGameSourceDefaults(
        string $gameName,
        int $steamAppId
    ): array {
        $options = $this->providerOptions();
        $enabled = [];

        foreach (['github', 'direct', 'upload'] as $key) {
            if (isset($options[$key]) && $key !== 'steamgriddb') {
                $enabled[] = $key;
            }
        }

        $this->discoverSelectedGameProviderMetadata(
            $gameName,
            $steamAppId
        );

        $slug = strtolower(trim($gameName));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        foreach ($options as $key => $definition) {
            if (!is_array($definition)) {
                continue;
            }
            $fields = $definition['metadata_fields'] ?? [];
            if (!is_array($fields) || !isset($fields['community']) || $slug === '') {
                continue;
            }
            $meta = $this->decodedSourceMetadata((string) $key);
            if (($meta['community'] ?? '') === '' || $meta['community'] === null) {
                $meta['community'] = $slug;
                $this->sourceMetadata[$key] = json_encode(
                    $meta,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                );
            }
        }

        foreach ($this->sourceMetadata as $provider => $json) {
            $provider = (string) $provider;
            if ($provider === '' || !isset($options[$provider])) {
                continue;
            }
            $decoded = json_decode((string) $json, true);
            if (!is_array($decoded) || $decoded === []) {
                continue;
            }
            if (($decoded['community'] ?? null) === $slug) {
                unset($decoded['community']);
            }
            $hasIdentity = false;
            foreach ($decoded as $value) {
                if ($value !== null && $value !== '' && $value !== []) {
                    $hasIdentity = true;
                    break;
                }
            }
            if ($hasIdentity && !in_array($provider, $enabled, true)) {
                $enabled[] = $provider;
            }
        }

        $this->allowedSources = array_values(array_unique($enabled));

        return $this->allowedSources;
    }

    private function discoverSelectedGameProviderMetadata(
        string $gameName,
        int $steamAppId
    ): void {
        $service = app(ProviderGameIdentityService::class);

        foreach (array_keys($this->providerOptions()) as $provider) {
            if ((string) $provider === 'steamgriddb') {
                continue;
            }
            try {
                $result = $service->discover(
                    (string) $provider,
                    $gameName,
                    $steamAppId
                );
            } catch (\Throwable) {
                continue;
            }

            if (($result['status'] ?? '') !== 'supported') {
                continue;
            }

            $metadata = $this->decodedSourceMetadata(
                (string) $provider
            );

            foreach ((array) ($result['metadata'] ?? []) as $key => $value) {
                if (
                    !array_key_exists($key, $metadata)
                    || $metadata[$key] === ''
                    || $metadata[$key] === null
                ) {
                    $metadata[$key] = $value;
                }
            }

            $this->sourceMetadata[$provider] = json_encode(
                $metadata ?: (object) [],
                JSON_PRETTY_PRINT
                | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR
            );
        }
    }

    /**
     * Return the public metadata schema for one configured provider.
     */
    public function providerMetadataSchema(string $provider): array
    {
        $definition = $this->providerOptions()[$provider] ?? [];

        return (array) ($definition['metadata_fields'] ?? []);
    }

    /**
     * Read one provider metadata value for the structured Game Builder UI.
     */
    public function providerMetadataValue(string $provider, string $field): mixed
    {
        $metadata = $this->decodedSourceMetadata($provider);

        $schema = $this->providerMetadataSchema($provider);
        $fieldSchema = (array) ($schema[$field] ?? []);

        if (!array_key_exists($field, $metadata)) {
            $inheritFrom = trim((string) ($fieldSchema['inherit_from'] ?? ''));

            if ($inheritFrom !== '' && array_key_exists($inheritFrom, $this->form)) {
                $inherited = $this->form[$inheritFrom];

                if ($inherited !== '' && $inherited !== null) {
                    return $inherited;
                }
            }
        }

        $value = $metadata[$field] ?? null;

        if (is_array($value)) {
            return implode("\\n", array_map('strval', $value));
        }

        return $value;
    }

    /**
     * Update one provider metadata field while retaining the provider's
     * other portable metadata.
     */
    public function updateProviderMetadataField(
        string $provider,
        string $field,
        mixed $value
    ): void {
        $this->authorizeBuilder();

        $schema = $this->providerMetadataSchema($provider);

        if (!isset($schema[$field])) {
            return;
        }

        $metadata = $this->decodedSourceMetadata($provider);
        $type = $schema[$field]['type'] ?? 'text';

        if ($type === 'number') {
            $value = trim((string) $value);
            $value = $value === '' ? null : (int) $value;
        } elseif ($type === 'boolean') {
            $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
        } elseif ($type === 'list') {
            $value = array_values(
                array_filter(
                    array_map(
                        'trim',
                        preg_split('/[\\r\\n,]+/', (string) $value) ?: []
                    ),
                    static fn (string $item): bool => $item !== ''
                )
            );
        } else {
            $value = trim((string) $value);
        }

        if ($value === null || $value === '' || $value === []) {
            unset($metadata[$field]);
        } else {
            $metadata[$field] = $value;
        }

        $this->sourceMetadata[$provider] = json_encode(
            $metadata ?: (object) [],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    /**
     * Decode the portable source metadata currently held by Game Builder.
     */
    private function decodedSourceMetadata(string $provider): array
    {
        $raw = trim((string) ($this->sourceMetadata[$provider] ?? ''));

        if ($raw === '') {
            return [];
        }

        $decoded = json_decode(
            $raw,
            true,
            12,
            JSON_THROW_ON_ERROR
        );

        return is_array($decoded) ? $decoded : [];
    }

    public function suggestedStableKey(): string
    {
        if ($this->editing !== '') {
            return $this->editing;
        }

        $existing = trim((string) ($this->form['key'] ?? ''));

        if ($existing !== '') {
            return $existing;
        }

        $name = trim((string) ($this->form['name'] ?? ''));

        if ($name === '') {
            return '';
        }

        return Str::slug($name);
    }

    public function editGame(string $key): void
    {
        $this->authorizeBuilder();

        $this->perform(function () use ($key) {
            $this->reloadCatalog();

            if (!isset($this->games[$key])) {
                throw new RuntimeException('Game no longer exists.');
            }

            $this->editing = $key;
            $this->importedDraft = false;
            $this->editorOpen = true;
            $this->fillDefinition($this->games[$key]);
        });
    }

    public function saveGame(): void
    {
        $this->authorizeBuilder();

        $this->perform(function () {
            $d = $this->form;
            $d['schema_version'] = 1;
            if ($this->importedDraft) { $d['enabled'] = false; }

            if ($this->editing !== '') {
                // Existing definitions keep their immutable stable key.
                $d['key'] = $this->editing;
            } else {
                /*
                 * Always normalize the stable key at save time.
                 *
                 * This intentionally does not trust the raw Livewire value.
                 * An administrator may leave it blank or enter a friendly
                 * value such as "7 Days to Die"; both become:
                 *
                 *     7-days-to-die
                 */
                $rawKey = trim((string) ($d['key'] ?? ''));

                if ($rawKey === '') {
                    $rawKey = trim((string) ($d['name'] ?? ''));
                }

                $d['key'] = Str::slug($rawKey);

                if ($d['key'] === '') {
                    throw new RuntimeException(
                        'Enter a game name so ModHarbor can create a stable key.'
                    );
                }

                /*
                 * GameDefinition requires:
                 * - lowercase
                 * - starts with a letter or digit
                 * - letters, digits and hyphens only
                 * - 2 through 64 characters
                 */
                if (
                    !preg_match('/^[a-z0-9][a-z0-9-]{1,63}$/D', $d['key'])
                ) {
                    throw new RuntimeException(
                        'Could not create a valid stable key from the game name.'
                    );
                }
            }

            $steam = $d['steam_app_id'] ?? '';

            if (
                $steam !== ''
                && $steam !== null
                && !preg_match('/^[1-9][0-9]{0,9}$/D', (string) $steam)
            ) {
                throw new RuntimeException('Enter a numeric Steam App ID.');
            }

            $d['steam_app_id'] = ($steam === '' || $steam === null)
                ? null
                : (int) $steam;

            $sgdb = $d['steamgriddb_game_id'] ?? ($this->form['steamgriddb_game_id'] ?? '');
            if ($sgdb === '' || $sgdb === null) {
                $d['steamgriddb_game_id'] = null;
            } elseif (!preg_match('/^[1-9][0-9]{0,9}$/D', (string) $sgdb)) {
                throw new RuntimeException('SteamGridDB game ID must be a positive integer.');
            } else {
                $d['steamgriddb_game_id'] = (int) $sgdb;
            }
            $d['steamgriddb_grid_id'] = null;

            if ($this->artworkUpload !== null) {
                $file = $this->artworkUpload;
                $bytes = method_exists($file, 'get') ? (string) $file->get() : file_get_contents($file->getRealPath());
                if (!is_string($bytes) || $bytes === '') {
                    throw new RuntimeException('Unable to read uploaded artwork.');
                }
                $d['artwork_url'] = (new \GameNest\GameNestModManager\Services\Artwork\UploadArtworkProvider())
                    ->store($bytes, (string) ($d['key'] ?? 'game'));
                $this->artworkUpload = null;
            }

            $ids = $this->lines($this->eggIds);

            foreach ($ids as $id) {
                if (!preg_match('/^[1-9][0-9]{0,9}$/D', $id)) {
                    throw new RuntimeException('Egg IDs must be positive integers.');
                }
            }

            $d['detection'] = [
                'egg_names' =>
                    $this->lines($this->eggNames),

                'egg_name_contains' =>
                    $this->lines(
                        $this->eggNameContains
                    ),

                'egg_ids' =>
                    array_map(
                        'intval',
                        $ids
                    ),
            ];

            $d['mod_directories'] = $this->lines($this->modDirectories);
            $d['config_directories'] = $this->lines($this->configDirectories);
            if (trim($this->configRulesJson) !== '') {
                $d['config_rules'] = json_decode($this->configRulesJson, true, 16, JSON_THROW_ON_ERROR);
            } else { unset($d['config_rules']); }

            /*
             * Re-run provider identity discovery at save time.
             *
             * An administrator may select the canonical game before enabling
             * providers, or enable providers later from another tab. Provider
             * metadata therefore cannot depend on UI interaction order.
             * A selected profile already supplies its identities; do not
             * rediscover values the administrator has edited or removed.
             */
            $gameName = trim((string) ($d['name'] ?? ''));
            $gameSteamAppId = (int) ($d['steam_app_id'] ?? 0);

            if ($gameName !== '' && !$this->profileSelected) {
                $this->discoverSelectedGameProviderMetadata(
                    $gameName,
                    $gameSteamAppId
                );
            }

            $d['sources'] = [];

            foreach ($this->allowedSources as $key) {
                $metadata = json_decode(
                    trim($this->sourceMetadata[$key] ?? '') ?: '{}',
                    true,
                    12,
                    JSON_THROW_ON_ERROR
                );

                /*
                 * Provider metadata may inherit portable values from the
                 * game definition. The provider registry declares those
                 * relationships so this shared flow stays provider-neutral.
                 */
                foreach ($this->providerMetadataSchema($key) as $field => $fieldSchema) {
                    if (array_key_exists($field, $metadata)) {
                        continue;
                    }

                    $inheritFrom = trim((string) ($fieldSchema['inherit_from'] ?? ''));

                    if (
                        $inheritFrom !== ''
                        && array_key_exists($inheritFrom, $d)
                        && $d[$inheritFrom] !== ''
                        && $d[$inheritFrom] !== null
                    ) {
                        $metadata[$field] = $d[$inheritFrom];
                    }
                }

                $d['sources'][$key] = [
                    'metadata' => $metadata,
                ];
            }

            /*
             * Runtime mappings belong only to providers that remain enabled
             * for this definition. Removing a provider in Game Builder must
             * also remove its runtime metadata rather than making validation
             * fail or silently resurrecting that provider later.
             */
            $d['runtime_metadata'] = array_intersect_key(
                (array) ($d['runtime_metadata'] ?? []),
                $d['sources']
            );

            app(GameDefinitionStore::class)->save(
                $d,
                $this->revision,
                $this->editing === ''
            );

            $this->editing = $d['key'];
            $this->importedDraft = false;
            $this->reloadCatalog();
            $this->fillDefinition($this->games[$d['key']]);

            $this->message = 'Game saved. Reload the server Mods page to resolve its definition.';
        });
    }

    public function toggleGame(string $key): void
    {
        $this->authorizeBuilder();

        $this->perform(function () use ($key) {
            app(GameDefinitionStore::class)->toggle($key, $this->revision);

            $this->reloadCatalog();
            $this->message = 'Game availability updated.';
        });
    }

    public function deleteGame(string $key): void
    {
        $this->authorizeBuilder();

        $this->perform(function () use ($key) {
            app(GameDefinitionStore::class)->delete($key, $this->revision);

            $this->reloadCatalog();

            if ($this->editing === $key) {
                $this->editing = '';
                $this->editorOpen = false;
                $this->fillDefinition($this->blankDefinition());
            }

            $this->message = 'Definition deleted. Server files were not changed.';
        });
    }

    public function importGame(): void
    {
        $this->authorizeBuilder();

        $this->perform(function () {
            if (
                !$this->importFile instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile
                || $this->importFile->getSize() > 65536
                || !str_ends_with(
                    $this->importFile->getClientOriginalName(),
                    '.modharbor.json'
                )
            ) {
                throw new RuntimeException(
                    'Select a .modharbor.json file no larger than 64 KiB.'
                );
            }

            $d = app(GameDefinitionStore::class)
                ->import(file_get_contents($this->importFile->getRealPath()))
                ->data();

            // Imports are always reviewed before they can become active.
            $d['enabled'] = false;

            $this->editing = '';
            $this->importedDraft = true;
            $this->editorOpen = true;
            $this->fillDefinition($d);
            $this->importFile = null;

            $catalog = app(GameDefinitionStore::class)->snapshot();
            $this->revision = $catalog['revision'];
            $this->games = $catalog['definitions'];
            $this->message = isset($this->games[$d['key']])
                ? 'Imported for review only. This stable key already exists; Save will not overwrite it. Cancel and edit the existing definition, or choose a unique key and review detection rules to avoid overlapping games.'
                : 'Imported for review. Save creates a disabled game. Review detection rules for overlap with existing games before enabling.';
        });
    }

    public function exportGame(string $key): mixed
    {
        $this->authorizeBuilder();

        $snapshot = app(GameDefinitionStore::class)->snapshot();

        abort_unless(isset($snapshot['definitions'][$key]), 404);

        try {
            app(\GameNest\GameNestModManager\Services\ProviderSettingsStore::class)->assertPortable($snapshot['definitions'][$key]);
            $definition = $snapshot['definitions'][$key];
            // Portable exports keep SteamGridDB IDs but never local upload refs or bytes.
            $artwork = (string) ($definition['artwork_url'] ?? '');
            if (str_starts_with($artwork, '@upload/')) {
                $definition['artwork_url'] = '';
            }
            $json = app(GameDefinitionStore::class)
                ->validate($definition)
                ->json();
        } catch (\Throwable) {
            $this->message = 'Export blocked. Remove credentials, authorization data and URL parameters from the definition, and check that Provider Settings can be read.';
            return null;
        }

        return response()->streamDownload(
            static function () use ($json) {
                echo $json;
            },
            $key . '.modharbor.json',
            ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']
        );
    }

    public function isOfficialDefinition(string $key): bool
    {
        $key = trim($key);

        if ($key === '') {
            return false;
        }

        $seeds =
            config(
                'gamenest-mod-manager.game_definition_seeds'
            );

        if (!is_array($seeds)) {
            return false;
        }

        foreach ($seeds as $seed) {
            if (
                is_array($seed)
                && (
                    $seed['key']
                    ?? null
                ) === $key
            ) {
                return true;
            }
        }

        return false;
    }

    public function providerOptions(): array
    {
        $this->authorizeBuilder();

        $definitions = app(SourceRegistry::class)->definitions();

        // Artwork-only integrations (empty capabilities) are not mod sources.
        return array_filter(
            $definitions,
            static function (array $definition): bool {
                $capabilities = $definition['capabilities'] ?? [];
                if (!is_array($capabilities) || $capabilities === []) {
                    return false;
                }

                return array_intersect(
                    $capabilities,
                    ['browse', 'discover', 'search', 'install', 'upload', 'manual']
                ) !== [];
            }
        );
    }

    public function deploymentStatus(array $data, string $source): string
    {
        $this->authorizeBuilder();

        $adapter = new ConfiguredGameAdapter(
            app(GameDefinitionStore::class)->validate(
                array_replace($data, ['enabled' => true])
            )
        );

        return (new ConfiguredDriverResolver)->driverClass($adapter, $source)
            ? 'Deployment available'
            : 'Metadata only — compatible provider integration required';
    }

    private function reloadCatalog(): void
    {
        $catalog = app(GameDefinitionStore::class)->snapshot();

        $this->revision = $catalog['revision'];
        $this->games = $catalog['definitions'];
    }

    private function fillDefinition(array $d): void
    {
        $this->gameSearchResults = [];
        $this->gameSearchMessage = '';
        $this->gameIdentitySelected = false;
        $this->profileSelected = false;
        $d['capability'] ??= 'generic';

        $this->form = array_intersect_key(
            $d,
            array_flip([
                'key',
                'name',
                'steam_app_id',
                'artwork_url',
                'steamgriddb_game_id',
                'steamgriddb_grid_id',
                'enabled',
                'capability',
                'package_types',
                'deployment',
                'behavior',
                'runtime_metadata',
            ])
        );

        $this->form['runtime_metadata'] ??= [];

        $this->eggNames =
            implode(
                "\n",
                $d['detection']['egg_names']
            );

        $this->eggNameContains =
            implode(
                "\n",
                $d['detection']['egg_name_contains']
                ?? []
            );

        $this->eggIds =
            implode(
                "\n",
                array_map(
                    'strval',
                    $d['detection']['egg_ids'] ?? []
                )
            );
        $this->modDirectories = implode("\n", $d['mod_directories']);
        $this->configRulesJson = isset($d['config_rules']) ? json_encode($d['config_rules'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '';
        $this->configDirectories = implode("\n", $d['config_directories']);

        $this->allowedSources = array_keys($d['sources']);
        $this->sourceMetadata = [];

        foreach ($d['sources'] as $key => $source) {
            $this->sourceMetadata[$key] = json_encode(
                $source['metadata'] ?: (object) [],
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
            );
        }
    }


    public function useAutomaticArtwork(): void
    {
        $this->authorizeBuilder();
        $this->artworkUpload = null;
        $this->form['artwork_url'] = '';
        $this->message = 'Manual artwork cleared. Save Game to apply automatic Steam → SteamGridDB → placeholder resolution.';
    }

    public function updatedArtworkUpload(): void
    {
        $this->authorizeBuilder();
        // Validation only; bytes are applied on save.
        if ($this->artworkUpload === null) {
            return;
        }
        try {
            $file = $this->artworkUpload;
            $size = method_exists($file, 'getSize') ? (int) $file->getSize() : 0;
            if ($size < 1 || $size > 524288) {
                $this->artworkUpload = null;
                $this->message = 'Artwork must be between 1 byte and 512 KiB.';
                return;
            }
            $mime = method_exists($file, 'getMimeType') ? (string) $file->getMimeType() : '';
            if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
                $this->artworkUpload = null;
                $this->message = 'Artwork must be PNG, JPEG or WebP.';
            }
        } catch (\Throwable) {
            $this->artworkUpload = null;
            $this->message = 'Unable to read the uploaded artwork file.';
        }
    }

    public function eggIconFor(array $game): ?string
    {
        try {
            $rules = $game['detection'] ?? [];

            $eggIds = array_values(
                array_filter(
                    array_map(
                        'intval',
                        $rules['egg_ids'] ?? []
                    ),
                    static fn (int $id): bool =>
                        $id > 0
                )
            );

            /*
             * Exact Pelican egg IDs are the strongest match.
             */
            if ($eggIds !== []) {
                foreach ($eggIds as $eggId) {
                    $egg = \App\Models\Egg::query()
                        ->find($eggId);

                    if (
                        $egg
                        && is_string($egg->icon)
                        && trim($egg->icon) !== ''
                    ) {
                        return $egg->icon;
                    }
                }
            }

            /*
             * There are normally only a small number of eggs on a panel.
             * Comparing names in PHP keeps this independent of the panel's
             * database collation and works the same on SQLite/MariaDB.
             */
            $eggs = \App\Models\Egg::query()
                ->orderBy('id')
                ->get();

            $exactNames = array_values(
                array_filter(
                    array_map(
                        static fn ($name): string =>
                            strtolower(
                                trim((string) $name)
                            ),
                        $rules['egg_names'] ?? []
                    ),
                    static fn (string $name): bool =>
                        $name !== ''
                )
            );

            foreach ($eggs as $egg) {
                $eggName = strtolower(
                    trim((string) $egg->name)
                );

                if (
                    $eggName !== ''
                    && in_array(
                        $eggName,
                        $exactNames,
                        true
                    )
                    && is_string($egg->icon)
                    && trim($egg->icon) !== ''
                ) {
                    return $egg->icon;
                }
            }

            $contains = array_values(
                array_filter(
                    array_map(
                        static fn ($fragment): string =>
                            strtolower(
                                trim((string) $fragment)
                            ),
                        $rules['egg_name_contains'] ?? []
                    ),
                    static fn (string $fragment): bool =>
                        $fragment !== ''
                )
            );

            foreach ($eggs as $egg) {
                $eggName = strtolower(
                    trim((string) $egg->name)
                );

                foreach ($contains as $fragment) {
                    if (
                        $eggName !== ''
                        && str_contains(
                            $eggName,
                            $fragment
                        )
                        && is_string($egg->icon)
                        && trim($egg->icon) !== ''
                    ) {
                        return $egg->icon;
                    }
                }
            }
        } catch (\Throwable) {
            /*
             * An icon is cosmetic. Never allow icon lookup trouble to make
             * Game Builder unavailable.
             */
            return null;
        }

        return null;
    }

    private function blankDefinition(): array
    {
        return [
            'key' => '',
            'name' => '',
            'steam_app_id' => null,
            'artwork_url' => '',
            'steamgriddb_game_id' => null,
            'steamgriddb_grid_id' => null,
            'enabled' => false,
            'capability' => 'generic',

            'detection' => [
                'egg_names' => [],
                'egg_name_contains' => [],
                'egg_ids' => [],
            ],

            'sources' => [],
            'runtime_metadata' => [],

            'mod_directories' => ['Mods'],
            'config_directories' => ['Configs'],
            'package_types' => ['zip'],

            'deployment' => [
                'strategy' => 'archive',
                'target' => 'Mods',
                'archive_prefix' => '',
            ],

            'behavior' => [
                'install_while_running' => false,
                'restart_required' => true,
            ],
        ];
    }

    private function lines(string $value): array
    {
        return array_values(
            array_filter(
                array_map(
                    'trim',
                    preg_split('/\r?\n/', $value)
                ),
                fn ($line) => $line !== ''
            )
        );
    }

    private function perform(callable $action): void
    {
        $this->message = '';

        try {
            $action();
        } catch (\JsonException) {
            $this->message =
                'Invalid JSON. Check definition or source metadata syntax.';
        } catch (RuntimeException $e) {
            $this->message = $e->getMessage();
        }
    }
}
