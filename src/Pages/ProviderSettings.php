<?php

namespace GameNest\GameNestModManager\Pages;

use Filament\Notifications\Notification;
use Filament\Pages\Page;
use GameNest\GameNestModManager\Services\ProviderSettingsStore;
use GameNest\GameNestModManager\Services\ProviderConnectionService;
use GameNest\GameNestModManager\Services\SourceRegistry;
use Livewire\Attributes\Locked;
use RuntimeException;
use Throwable;

class ProviderSettings extends Page
{
    use \Livewire\WithFileUploads;

    public $settingsImport = null;

    public function exportAllSettings(): mixed
    {
        $this->authorizeSettings();
        try {
            $store = app(ProviderSettingsStore::class);
            $data = [];
            foreach (app(SourceRegistry::class)->definitions() as $key => $definition) {
                $fields = $definition['credential_fields'] ?? [];
                if (!$fields) { continue; }
                foreach ($fields as $field => $schema) {
                    $data[$key][$field] = $store->get($key, $field,
                        config('gamenest-mod-manager.' . $key . '.' . $field, $schema['default'] ?? ''));
                }
            }
            $json = json_encode(['format' => 'modharbor-provider-settings', 'version' => 1,
                'providers' => $data], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            return response()->streamDownload(static function () use ($json) { echo $json; },
                'modharbor-providers-' . date('Ymd-His') . '.json',
                ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store']);
        } catch (Throwable) {
            Notification::make()->title('Export failed')->body('Unable to read saved provider settings. No backup was exported.')->danger()->send();
            return null;
        }
    }

    public function importAllSettings(): void
    {
        $this->authorizeSettings();
        $this->validate(['settingsImport' => 'required|file|max:256']);
        $saved = 0;
        try {
            $data = json_decode(file_get_contents($this->settingsImport->getRealPath()), true, 32, JSON_THROW_ON_ERROR);
            $definitions = app(SourceRegistry::class)->definitions();
            if (!is_array($data) || ($data['format'] ?? '') !== 'modharbor-provider-settings'
                || ($data['version'] ?? null) !== 1 || !is_array($data['providers'] ?? null)
                || !$data['providers']) {
                throw new RuntimeException('Invalid backup.');
            }
            $plans = [];
            $store = app(ProviderSettingsStore::class);
            foreach ($data['providers'] as $key => $values) {
                $fields = $definitions[$key]['credential_fields'] ?? [];
                if (!$fields || !is_array($values) || array_diff_key($values, $fields)) {
                    throw new RuntimeException('Unknown provider or field.');
                }
                $merged = [];
                foreach ($fields as $field => $schema) {
                    $value = $values[$field] ?? $store->get($key, $field,
                        config('gamenest-mod-manager.' . $key . '.' . $field, $schema['default'] ?? ''));
                    if (!is_scalar($value) || strlen((string) $value) > 16384) {
                        throw new RuntimeException('Invalid value.');
                    }
                    $type = $schema['type'] ?? 'text';
                    if ($type === 'secret' && trim((string) $value) === '') {
                        $value = $store->get($key, $field, '');
                    }
                    if (!empty($schema['required']) && trim((string) $value) === '') {
                        throw new RuntimeException('Required field missing.');
                    }
                    if ($type === 'number' && $value !== '' && !preg_match('/^-?[0-9]+$/D', (string) $value)) {
                        throw new RuntimeException('Invalid number.');
                    }
                    if ($type === 'boolean' && filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === null) {
                        throw new RuntimeException('Invalid boolean.');
                    }
                    $merged[$field] = $value;
                }
                if ($key === 'modio' && isset($merged['api_path'])) {
                    $merged['api_path'] = ProviderConnectionService::modioBase($merged['api_path']);
                }
                $plans[$key] = [$merged, $fields];
            }
            foreach ($plans as $key => [$values, $fields]) {
                $store->save($key, $values, $fields);
                $saved++;
            }
            $this->loadProviders();
            Notification::make()->title('Provider settings imported')->body($saved . ' providers saved. Test each connection before use.')->success()->send();
        } catch (Throwable) {
            Notification::make()->title('Import could not finish')
                ->body($saved . ' providers saved. Check the backup format, required fields and storage permissions. Previously saved providers remain applied.')
                ->danger()->send();
        } finally {
            if ($this->settingsImport) { $this->settingsImport->delete(); }
            $this->settingsImport = null;
        }
    }

    protected string $view =
        'gamenest-mod-manager::pages.provider-settings';

    protected static ?int $navigationSort = 3;

    #[Locked]
    public array $providers = [];

    public array $values = [];

    #[Locked]
    public array $configured = [];

    #[Locked]
    public array $status = [];

    public array $revealed = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null
            && method_exists($user, 'isRootAdmin')
            && $user->isRootAdmin();
    }

    public static function getNavigationParentItem(): ?string
    {
        return 'ModHarbor';
    }

    public static function getNavigationLabel(): string
    {
        return 'Provider Settings';
    }
public function getTitle(): string
    {
        return 'ModHarbor Provider Settings';
    }

    public function mount(): void
    {
        $this->authorizeSettings();
        $this->loadProviders();
    }

    public function revealSecret(
        string $provider,
        string $field
    ): void {
        $this->authorizeSettings();

        $definitions =
            app(SourceRegistry::class)->definitions();

        $definition =
            $definitions[$provider] ?? null;

        $fieldDefinition =
            is_array($definition)
                ? (
                    $definition['credential_fields'][$field]
                    ?? null
                )
                : null;

        if (
            !is_array($fieldDefinition)
            || strtolower(
                trim(
                    (string) (
                        $fieldDefinition['type']
                        ?? ''
                    )
                )
            ) !== 'secret'
        ) {
            abort(404);
        }

        $fallback = config(
            'gamenest-mod-manager.'
            . $provider
            . '.'
            . $field,
            ''
        );

        $this->values[$provider][$field] =
            (string) app(
                ProviderSettingsStore::class
            )->get(
                $provider,
                $field,
                $fallback
            );

        $this->revealed[$provider][$field] = true;
    }

    public function hideSecret(
        string $provider,
        string $field
    ): void {
        $this->authorizeSettings();

        $this->values[$provider][$field] = '';
        $this->revealed[$provider][$field] = false;
    }

    public function saveProvider(string $provider): void
    {
        $this->authorizeSettings();

        try {
            $definitions =
                app(SourceRegistry::class)->definitions();

            $definition =
                $definitions[$provider] ?? null;

            if (!is_array($definition)) {
                throw new RuntimeException(
                    'Unknown provider.'
                );
            }

            $fields = (array) (
                $definition['credential_fields']
                ?? []
            );

            if ($fields === []) {
                throw new RuntimeException(
                    'This provider has no global settings.'
                );
            }

            $values =
                (array) ($this->values[$provider] ?? []);

            $connection = app(ProviderConnectionService::class);
            $values = $connection->values($provider, $definition, $values);
            if ($connection->status($definition, $values) === 'Not configured') {
                throw new RuntimeException('Configure the required authentication settings.');
            }
            if ($provider === 'modio') {
                $values['api_path'] = ProviderConnectionService::modioBase($values['api_path']);
            }

            app(ProviderSettingsStore::class)->save(
                $provider,
                $values,
                $fields
            );

            $this->loadProviders();

            Notification::make()
                ->title(
                    ($definition['label'] ?? $provider)
                    . ' Settings Saved'
                )
                ->body(
                    'ModHarbor securely saved the provider settings.'
                )
                ->success()
                ->send();

        } catch (Throwable $exception) {
            Notification::make()
                ->title('Unable to Save Provider Settings')
                ->body('Settings could not be saved. Check required fields and the provider configuration.')
                ->danger()
                ->send();
        }
    }

    public function testProvider(string $provider): void
    {
        $this->authorizeSettings();
        $definition = app(SourceRegistry::class)->definition($provider);
        $result = app(ProviderConnectionService::class)->test($provider, $definition, (array) ($this->values[$provider] ?? []));
        $this->status[$provider] = $result['status'];
        Notification::make()->title($result['status'])->body($result['message'])->send();
    }

    private function loadProviders(): void
    {
        $definitions =
            app(SourceRegistry::class)->definitions();

        $store =
            app(ProviderSettingsStore::class);

        $providers = [];
        $values = [];
        $configured = [];
        $status = [];

        foreach ($definitions as $key => $definition) {
            if (!is_array($definition)) {
                continue;
            }

            $fields = (array) (
                $definition['credential_fields']
                ?? []
            );

            /*
             * Provider Settings is only for sources that actually
             * declare global configuration. Direct downloads and
             * uploads are install sources, not global connections.
             */
            if ($fields === []) {
                continue;
            }

            $providers[$key] = [
                'label' =>
                    (string) (
                        $definition['label']
                        ?? $key
                    ),
                'fields' => $fields,
            ];

            $configuredCount = 0;
            $fieldCount = 0;

            foreach ($fields as $fieldKey => $field) {
                if (
                    !is_string($fieldKey)
                    || !is_array($field)
                ) {
                    continue;
                }

                $fieldCount++;

                $fallback = config(
                    'gamenest-mod-manager.'
                    . $key
                    . '.'
                    . $fieldKey,
                    $field['default'] ?? ''
                );

                $current = $store->get(
                    $key,
                    $fieldKey,
                    $fallback
                );

                $type = strtolower(
                    trim(
                        (string) (
                            $field['type']
                            ?? 'text'
                        )
                    )
                );

                if ($type === 'secret') {
                    /*
                     * Never send saved secrets back into
                     * Livewire/browser state.
                     */
                    $values[$key][$fieldKey] = '';
                } else {
                    $values[$key][$fieldKey] =
                        is_bool($current)
                            ? ($current ? '1' : '0')
                            : (string) $current;
                }

                $isConfigured =
                    is_bool($current)
                        ? true
                        : trim((string) $current) !== '';

                $configured[$key][$fieldKey] =
                    $isConfigured;

                if ($isConfigured) {
                    $configuredCount++;
                }
            }

            $connection = app(ProviderConnectionService::class);
            $status[$key] = $connection->status($definition, $connection->values($key, $definition));

        }

        $this->providers = $providers;
        $this->values = $values;
        $this->configured = $configured;
        $this->status = $status;
        $this->revealed = [];
    }

    private function authorizeSettings(): void
    {
        abort_unless(static::canAccess(), 403);
    }
}
