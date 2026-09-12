<?php

namespace GameNest\GameNestModManager\Services;

use Illuminate\Support\Facades\Crypt;
use RuntimeException;
use Throwable;

class ProviderSettingsStore
{
    /** Presence only; no values leave this method. Null at the caller means unavailable. */
    public function configurationStatus(string $provider, array $fields, array $requireAny = []): bool
    {
        if (!$fields) { return true; }
        $present = [];
        foreach ($fields as $key => $field) {
            $value = $this->get($provider, $key, config('gamenest-mod-manager.' . $provider . '.' . $key, $field['default'] ?? ''));
            $hasValue = is_bool($value) || (is_scalar($value) && trim((string) $value) !== '');
            if (!empty($field['required']) && !$hasValue) { return false; }
            if ($hasValue) { $present[] = $key; }
        }
        return !$requireAny || (bool) array_intersect($requireAny, $present);
    }

    /** Export checks fail closed if configured secrets cannot be read. */
    public function assertPortable(array $data): void
    {
        $secrets = [];
        foreach ((array) config('gamenest-mod-manager.providers', []) as $provider => $definition) {
            foreach ($definition['credential_fields'] ?? [] as $key => $field) {
                if (($field['type'] ?? '') !== 'secret') { continue; }
                $value = $this->get($provider, $key, config('gamenest-mod-manager.' . $provider . '.' . $key, ''));
                if (is_string($value) && $value !== '') { $secrets[] = $value; }
            }
        }
        PortableDefinitionGuard::check($data, $secrets);
    }

    public function settings(string $provider): array
    {
        $provider = $this->normalizeProvider($provider);

        if ($provider === 'modio') {
            $this->migrateLegacyModIoSettings();
        }

        $path = $this->path($provider);

        if (!is_file($path)) {
            return [];
        }

        $contents = file_get_contents($path);

        if ($contents === false || trim($contents) === '') {
            return [];
        }

        $data = json_decode($contents, true);

        if (!is_array($data)) {
            throw new RuntimeException('Saved provider settings are invalid. Restore a backup before editing.');
        }

        $result = [];

        foreach ($data as $key => $entry) {
            if (!is_string($key)) {
                continue;
            }

            if (is_array($entry) && ($entry['encrypted'] ?? false) === true) {
                $value = trim((string) ($entry['value'] ?? ''));

                if ($value === '') {
                    $result[$key] = '';
                    continue;
                }

                try {
                    $result[$key] = Crypt::decryptString($value);
                } catch (Throwable) {
                    throw new RuntimeException(
                        'Unable to decrypt saved ' . $provider . ' provider settings.'
                    );
                }

                continue;
            }

            if (is_array($entry) && array_key_exists('value', $entry)) {
                $result[$key] = $entry['value'];
                continue;
            }

            // Compatibility with simple/plain development values.
            $result[$key] = $entry;
        }

        return $result;
    }

    public function get(
        string $provider,
        string $name,
        mixed $fallback = null
    ): mixed {
        $settings = $this->settings($provider);

        return array_key_exists($name, $settings)
            ? $settings[$name]
            : $fallback;
    }

    public function configured(string $provider, string $name): bool
    {
        $value = $this->get($provider, $name, '');

        if (is_bool($value)) {
            return true;
        }

        return trim((string) $value) !== '';
    }

    public function save(
        string $provider,
        array $values,
        array $fieldDefinitions
    ): void {
        $provider = $this->normalizeProvider($provider);

        $existing = $this->settings($provider);
        $stored = [];

        foreach ($fieldDefinitions as $key => $field) {
            if (!is_string($key) || !is_array($field)) {
                continue;
            }

            $type = strtolower(trim((string) ($field['type'] ?? 'text')));
            $secret = $type === 'secret';
            $raw = $values[$key] ?? null;

            /*
             * Blank secret fields retain the current saved credential.
             * This prevents decrypted secrets from being sent to Livewire.
             */
            if ($secret && trim((string) $raw) === '') {
                $raw = $existing[$key] ?? '';
            }

            if ($type === 'boolean') {
                $value = filter_var(
                    $raw,
                    FILTER_VALIDATE_BOOLEAN,
                    FILTER_NULL_ON_FAILURE
                );

                $value ??= false;
            } elseif ($type === 'number') {
                $raw = trim((string) $raw);

                if ($raw === '') {
                    $value = '';
                } elseif (preg_match('/^-?[0-9]+$/D', $raw) !== 1) {
                    throw new RuntimeException(
                        ($field['label'] ?? $key) . ' must be a whole number.'
                    );
                } else {
                    $value = (int) $raw;
                }
            } else {
                $value = trim((string) ($raw ?? ''));
            }

            if (
                ($field['required'] ?? false)
                && ($value === '' || $value === null)
            ) {
                throw new RuntimeException(
                    ($field['label'] ?? $key)
                    . ' is required for '
                    . $provider
                    . '.'
                );
            }

            $stored[$key] = [
                'encrypted' => $secret,
                'value' => $secret && $value !== ''
                    ? Crypt::encryptString((string) $value)
                    : $value,
            ];
        }

        $directory = dirname($this->path($provider));

        if (!is_dir($directory)) {
            $parent = dirname($directory);
            $owner = @fileowner($parent);
            $group = @filegroup($parent);

            if (
                !mkdir($directory, 0750, true)
                && !is_dir($directory)
            ) {
                throw new RuntimeException(
                    'Unable to create the ModHarbor provider settings directory.'
                );
            }

            if ($owner !== false) {
                @chown($directory, $owner);
            }

            if ($group !== false) {
                @chgrp($directory, $group);
            }

            @chmod($directory, 0750);
        }

        $json = json_encode(
            $stored,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        );

        if ($json === false) {
            throw new RuntimeException(
                'Unable to encode provider settings.'
            );
        }

        $this->writeAtomic($this->path($provider), $json . PHP_EOL);

    }

    private function migrateLegacyModIoSettings(): void
    {
        $target = $this->path('modio');

        if (is_file($target)) {
            return;
        }

        $sources = [
            storage_path('app/gamenest-mod-manager/modio.json'),
            storage_path('app/gamenest-eco-enhanced/modio.json'),
        ];

        $source = null;

        foreach ($sources as $candidate) {
            if (is_file($candidate)) {
                $source = $candidate;
                break;
            }
        }

        if ($source === null) {
            return;
        }

        $contents = file_get_contents($source);

        if ($contents === false || trim($contents) === '') {
            return;
        }

        $data = json_decode($contents, true);

        if (!is_array($data)) {
            return;
        }

        $apiPath = trim(
            (string) (
                $data['api_path']
                ?? 'https://api.mod.io/v1'
            )
        );

        if ($apiPath === '') {
            $apiPath = 'https://api.mod.io/v1';
        }

        $values = [
            'api_path' => $apiPath,
            'api_key' => '',
            'access_token' => '',
        ];

        foreach (['api_key', 'access_token'] as $key) {
            $stored = trim((string) ($data[$key] ?? ''));

            if ($stored === '') {
                continue;
            }

            try {
                $values[$key] = Crypt::decryptString($stored);
            } catch (Throwable) {
                $values[$key] = $stored;
            }
        }

        $directory = dirname($target);

        if (!is_dir($directory)) {
            $parent = dirname($directory);
            $owner = @fileowner($parent);
            $group = @filegroup($parent);

            if (
                !mkdir($directory, 0750, true)
                && !is_dir($directory)
            ) {
                throw new RuntimeException(
                    'Unable to create the ModHarbor provider settings directory.'
                );
            }

            if ($owner !== false) {
                @chown($directory, $owner);
            }

            if ($group !== false) {
                @chgrp($directory, $group);
            }

            @chmod($directory, 0750);
        }

        $stored = [
            'api_path' => [
                'encrypted' => false,
                'value' => $values['api_path'],
            ],
            'api_key' => [
                'encrypted' => true,
                'value' => $values['api_key'] !== ''
                    ? Crypt::encryptString($values['api_key'])
                    : '',
            ],
            'access_token' => [
                'encrypted' => true,
                'value' => $values['access_token'] !== ''
                    ? Crypt::encryptString($values['access_token'])
                    : '',
            ],
        ];

        $json = json_encode(
            $stored,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        );

        if ($json === false) {
            throw new RuntimeException(
                'Unable to encode migrated mod.io settings.'
            );
        }

        if (
            file_put_contents(
                $target,
                $json . PHP_EOL,
                LOCK_EX
            ) === false
        ) {
            throw new RuntimeException(
                'Unable to migrate mod.io settings.'
            );
        }

        $this->secureFile($target);
    }

    private function writeAtomic(string $path, string $contents): void
    {
        $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        try {
            if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)) {
                throw new RuntimeException('Unable to save provider settings.');
            }
            $this->secureFile($temporary);
            if (!rename($temporary, $path)) { throw new RuntimeException('Unable to commit provider settings.'); }
        } finally {
            if (is_file($temporary)) { @unlink($temporary); }
        }
    }

    private function secureFile(string $path): void
    {
        $directory = dirname($path);

        /*
         * A CLI migration may be executed as root while the panel
         * itself runs as another user. Inherit ownership from the
         * provider directory so the web process can still read the
         * encrypted credential file.
         */
        $owner = @fileowner($directory);
        $group = @filegroup($directory);

        if ($owner !== false) {
            @chown($path, $owner);
        }

        if ($group !== false) {
            @chgrp($path, $group);
        }

        @chmod($path, 0640);
    }

    private function path(string $provider): string
    {
        return storage_path(
            'app/gamenest-mod-manager/providers/' . $provider . '.json'
        );
    }

    private function normalizeProvider(string $provider): string
    {
        $provider = strtolower(trim($provider));

        if (
            $provider === ''
            || preg_match('/^[a-z0-9][a-z0-9._-]*$/D', $provider) !== 1
        ) {
            throw new RuntimeException('Invalid provider key.');
        }

        return $provider;
    }
}
