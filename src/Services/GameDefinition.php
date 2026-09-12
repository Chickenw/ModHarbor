<?php

namespace GameNest\GameNestModManager\Services;

use RuntimeException;

/** Versioned, inert data. No class names, expressions, templates or commands are loaded. */
final class GameDefinition
{
    private function __construct(private array $data) {}

    public function data(): array { return $this->data; }

    public static function fromJson(string $json, array $providers, array $reserved = []): self
    {
        if (strlen($json) > 65536) { throw new RuntimeException('Game definition exceeds 64 KiB.'); }
        $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($data)) { throw new RuntimeException('Game definition must be an object.'); }
        return self::validate($data, $providers, $reserved);
    }

    public static function validate(array $d, array $providers, array $reserved = []): self
    {
        self::keys($d, ['schema_version', 'key', 'name', 'enabled', 'steam_app_id', 'capability', 'detection',
            'sources', 'mod_directories', 'config_directories', 'package_types', 'deployment', 'behavior', 'config_rules', 'artwork_url', 'runtime_metadata', 'steamgriddb_game_id', 'steamgriddb_grid_id']);
        if (($d['schema_version'] ?? null) !== 1) { throw new RuntimeException('Unsupported game definition schema.'); }

        $d['capability'] ??= 'generic';

        if (
            !is_string($d['capability'])
            || !preg_match('/^[a-z0-9][a-z0-9-]{1,63}$/D', $d['capability'])
            || !array_key_exists(
                $d['capability'],
                (array) config('gamenest-mod-manager.game_capabilities', [])
            )
        ) {
            throw new RuntimeException('Unknown ModHarbor game capability.');
        }

        self::key($d['key'] ?? null);

        $capabilityDefinition = (array) config(
            'gamenest-mod-manager.game_capabilities.' . $d['capability'],
            []
        );

        $allowedGameKeys = $capabilityDefinition['game_keys'] ?? '*';

        if (
            $allowedGameKeys !== '*'
            && (
                !is_array($allowedGameKeys)
                || !in_array($d['key'], $allowedGameKeys, true)
            )
        ) {
            throw new RuntimeException(
                'This ModHarbor capability is not valid for this game key.'
            );
        }
        if (in_array($d['key'], $reserved, true)) { throw new RuntimeException('This key belongs to a built-in adapter.'); }
        self::label($d['name'] ?? null);
        if (!is_bool($d['enabled'] ?? null)) { throw new RuntimeException('Enabled must be a boolean.'); }
        if (($d['steam_app_id'] ?? null) !== null && (!is_int($d['steam_app_id']) || $d['steam_app_id'] < 1 || $d['steam_app_id'] > 4294967295)) {
            throw new RuntimeException('Steam App ID must be a positive integer or null.');
        }
        $d['steam_app_id'] ??= null;
        $d['artwork_url'] ??= '';
        if ($d['artwork_url'] !== '' && !GameArtworkService::validOverride($d['artwork_url'])) {
            throw new RuntimeException('Artwork must be an HTTPS URL, panel-relative path, or @artwork/@upload reference.');
        }
        foreach (['steamgriddb_game_id', 'steamgriddb_grid_id'] as $sgdbField) {
            if (($d[$sgdbField] ?? null) !== null && (!is_int($d[$sgdbField]) || $d[$sgdbField] < 1 || $d[$sgdbField] > 2147483647)) {
                throw new RuntimeException('SteamGridDB IDs must be positive integers or null.');
            }
            $d[$sgdbField] ??= null;
        }
        self::object($d['detection'] ?? null);

        $d['detection']['egg_name_contains'] ??= [];

        self::keys(
            $d['detection'],
            [
                'egg_ids',
                'egg_names',
                'egg_name_contains',
            ]
        );

        foreach (
            [
                'egg_ids',
                'egg_names',
                'egg_name_contains',
            ]
            as $kind
        ) {
            self::list($d['detection'][$kind] ?? null);

            foreach ($d['detection'][$kind] as $item) {
                if ($kind === 'egg_ids') {
                    if (!is_int($item) || $item < 1) {
                        throw new RuntimeException(
                            'Egg IDs must be positive integers.'
                        );
                    }
                } else {
                    self::label($item);
                }
            }
        }

        if (
            !$d['detection']['egg_ids']
            && !$d['detection']['egg_names']
            && !$d['detection']['egg_name_contains']
        ) {
            throw new RuntimeException(
                'Add at least one egg ID, exact egg name, or egg-name contains rule.'
            );
        }
        foreach (['mod_directories', 'config_directories'] as $kind) {
            self::list($d[$kind] ?? null);
            foreach ($d[$kind] as $path) { self::path($path); }
        }
        if (!$d['mod_directories']) { throw new RuntimeException('At least one mod directory is required.'); }
        if (isset($d['config_rules'])) {
            self::list($d['config_rules']);
            if (count($d['config_rules']) > 32) { throw new RuntimeException('At most 32 config rules are allowed.'); }
            foreach ($d['config_rules'] as $rule) {
                self::object($rule);
                self::keys($rule, ['root', 'patterns', 'exclude', 'depth']);
                self::path($rule['root'] ?? null);
                if (!in_array($rule['root'], $d['config_directories'], true)) { throw new RuntimeException('Config rule root must be a declared config directory.'); }
                if (!is_int($rule['depth'] ?? null) || $rule['depth'] < 0 || $rule['depth'] > 8) { throw new RuntimeException('Config rule depth must be 0–8.'); }
                foreach (['patterns', 'exclude'] as $field) {
                    self::list($rule[$field] ?? null);
                    if (count($rule[$field]) > 32) { throw new RuntimeException('Too many config patterns.'); }
                    foreach ($rule[$field] as $pattern) {
                        if (!is_string($pattern) || strlen($pattern) > 80 || !preg_match('/^[A-Za-z0-9_.?* -]+$/D', $pattern)) {
                            throw new RuntimeException('Config patterns must be filename globs without paths.');
                        }
                    }
                }
                if (!$rule['patterns']) { throw new RuntimeException('A config rule needs a filename pattern.'); }
            }
        }
        self::list($d['package_types'] ?? null);
        foreach ($d['package_types'] as $type) {
            if (!is_string($type)) { throw new RuntimeException('Package types must be strings.'); }
        }
        if (!$d['package_types'] || array_diff($d['package_types'], ['zip', 'dll', 'cs', 'jar', 'pak', 'xml', 'json', 'cfg', 'txt'])) {
            throw new RuntimeException('Select supported package types.');
        }
        self::object($d['deployment'] ?? null);
        self::keys($d['deployment'], ['strategy', 'target', 'archive_prefix']);
        if (!in_array($d['deployment']['strategy'] ?? '', ['archive', 'copy', 'provider-managed'], true)) { throw new RuntimeException('Unknown deployment strategy.'); }
        self::path($d['deployment']['target'] ?? null);
        if (!in_array($d['deployment']['target'], $d['mod_directories'], true)) { throw new RuntimeException('Deployment target must be a declared mod directory.'); }
        $prefix = $d['deployment']['archive_prefix'] ?? '';
        if ($prefix !== '') { self::path($prefix); }
        $d['deployment']['archive_prefix'] = $prefix;
        if ($d['deployment']['strategy'] === 'archive' && $d['package_types'] !== ['zip']) { throw new RuntimeException('Archive extraction accepts ZIP packages only.'); }
        if ($d['deployment']['strategy'] === 'copy' && in_array('zip', $d['package_types'], true)) { throw new RuntimeException('Choose archive extraction for ZIP packages.'); }
        self::object($d['behavior'] ?? null);
        self::keys($d['behavior'], ['install_while_running', 'restart_required']);
        foreach (['install_while_running', 'restart_required'] as $key) {
            if (!is_bool($d['behavior'][$key] ?? null)) { throw new RuntimeException('Running/restart settings must be booleans.'); }
        }
        self::object($d['sources'] ?? null);
        foreach ($d['sources'] as $key => $source) {
            self::key($key);
            if (!in_array($key, $providers, true)) { throw new RuntimeException('Source is not a registered provider: ' . $key); }
            self::object($source);
            self::keys($source, ['metadata']);
            self::object($source['metadata'] ?? null);
            self::metadata($source['metadata']);
            foreach (['extensions', 'accept', 'deployment', 'behavior', 'class', 'driver', 'factory', 'enabled'] as $reservedKey) {
                if (array_key_exists($reservedKey, $source['metadata'])) { throw new RuntimeException('Reserved source metadata key: ' . $reservedKey); }
            }
        }
        $d['runtime_metadata'] ??= [];
        self::object($d['runtime_metadata']);

        foreach ($d['runtime_metadata'] as $sourceKey => $rules) {
            self::key($sourceKey);

            if (!in_array($sourceKey, $providers, true)) {
                throw new RuntimeException(
                    'Runtime metadata source is not a registered provider: ' . $sourceKey
                );
            }

            if (!array_key_exists($sourceKey, $d['sources'])) {
                throw new RuntimeException(
                    'Runtime metadata can only target an enabled game source: ' . $sourceKey
                );
            }

            self::object($rules);

            if (count($rules) > 32) {
                throw new RuntimeException(
                    'Too many runtime metadata mappings for source: ' . $sourceKey
                );
            }

            foreach ($rules as $target => $rule) {
                if (
                    !is_string($target)
                    || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $target)
                ) {
                    throw new RuntimeException(
                        'Invalid runtime metadata target.'
                    );
                }

                if (is_string($rule)) {
                    $rule = ['variable' => $rule];
                    $d['runtime_metadata'][$sourceKey][$target] = $rule;
                }

                self::object($rule);
                self::keys($rule, ['variable', 'map', 'ignore', 'list']);

                $variable = $rule['variable'] ?? null;

                if (
                    !is_string($variable)
                    || !preg_match('/^[A-Z][A-Z0-9_]{0,63}$/D', $variable)
                ) {
                    throw new RuntimeException(
                        'Runtime metadata variable must be a valid environment variable name.'
                    );
                }

                if (isset($rule['map'])) {
                    self::object($rule['map']);

                    if (count($rule['map']) > 64) {
                        throw new RuntimeException(
                            'Runtime metadata map is too large.'
                        );
                    }

                    foreach ($rule['map'] as $from => $to) {
                        if (
                            !is_string($from)
                            || $from === ''
                            || strlen($from) > 128
                        ) {
                            throw new RuntimeException(
                                'Invalid runtime metadata map key.'
                            );
                        }

                        if (
                            !is_null($to)
                            && !is_bool($to)
                            && !is_int($to)
                            && !is_float($to)
                            && !is_string($to)
                        ) {
                            throw new RuntimeException(
                                'Runtime metadata map values must be scalar JSON values.'
                            );
                        }
                    }
                }

                if (
                    array_key_exists('list', $rule)
                    && !is_bool($rule['list'])
                ) {
                    throw new RuntimeException(
                        'Runtime metadata list must be boolean.'
                    );
                }

                if (isset($rule['ignore'])) {
                    self::list($rule['ignore']);

                    foreach ($rule['ignore'] as $ignored) {
                        if (
                            !is_string($ignored)
                            && !is_int($ignored)
                            && !is_float($ignored)
                            && !is_bool($ignored)
                        ) {
                            throw new RuntimeException(
                                'Runtime metadata ignore values must be scalar.'
                            );
                        }
                    }
                }
            }
        }

        if (strlen(json_encode($d, JSON_THROW_ON_ERROR)) > 65536) { throw new RuntimeException('Game definition exceeds 64 KiB.'); }
        return new self($d);
    }

    public function json(): string
    {
        PortableDefinitionGuard::check($this->data);
        return json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }
    public static function key(mixed $key): void
    {
        if (!is_string($key) || !preg_match('/^[a-z0-9][a-z0-9-]{1,63}$/D', $key)) { throw new RuntimeException('Use a stable lowercase key of 2–64 letters, digits and hyphens.'); }
    }
    public static function path(mixed $path): string
    {
        if (!is_string($path) || strlen($path) > 240 || !preg_match('~^[A-Za-z0-9_][A-Za-z0-9_ .()+\[\]-]*(/[A-Za-z0-9_][A-Za-z0-9_ .()+\[\]-]*)*$~D', $path)) {
            throw new RuntimeException('Use a relative directory/path without hidden, absolute or traversal segments.');
        }
        foreach (explode('/', $path) as $part) {
            if ($part !== rtrim($part, '. ') || preg_match('/^(con|prn|aux|nul|com[0-9]|lpt[0-9])(\.|$)/i', $part)) { throw new RuntimeException('Unsafe path segment.'); }
        }
        return $path;
    }
    private static function keys(array $value, array $allowed): void
    {
        if (array_diff(array_keys($value), $allowed)) { throw new RuntimeException('Unknown game definition fields.'); }
    }
    private static function object(mixed $value): void
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) { throw new RuntimeException('Expected an object.'); }
    }
    private static function list(mixed $value): void
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 64) { throw new RuntimeException('Expected a list with at most 64 entries.'); }
    }
    private static function label(mixed $value): void
    {
        if (!is_string($value) || trim($value) !== $value || $value === '' || strlen($value) > 160 || preg_match('/[\x00-\x1f\x7f]/', $value)) { throw new RuntimeException('Invalid game/egg name.'); }
    }
    private static function metadata(array $value, int $depth = 0): void
    {
        PortableDefinitionGuard::check($value);
        if ($depth > 8 || count($value) > 100) { throw new RuntimeException('Source metadata is too complex.'); }
        foreach ($value as $key => $item) {
            if (is_string($key) && preg_match('/(?:api[_-]?key|token|secret|password|passwd|credential|authorization|cookie|private[_-]?key)/i', $key)) {
                throw new RuntimeException('Credentials belong in global provider settings, not portable game metadata.');
            }
            if (is_string($key) && !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $key)) { throw new RuntimeException('Invalid metadata key.'); }
            if (is_array($item)) { self::metadata($item, $depth + 1); }
            elseif (!is_null($item) && !is_bool($item) && !is_int($item) && !is_float($item) && !is_string($item)) { throw new RuntimeException('Metadata must be JSON data.'); }
            elseif (is_string($item) && (strlen($item) > 2048 || preg_match('/[\x00-\x1f\x7f]/', $item))) { throw new RuntimeException('Invalid metadata value.'); }
        }
    }
}
