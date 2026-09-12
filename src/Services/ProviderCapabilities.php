<?php

namespace GameNest\GameNestModManager\Services;

final class ProviderCapabilities
{
    public const BROWSE = 'browse';
    public const DISCOVER = 'discover';
    public const SEARCH = 'search';
    public const INSTALL = 'install';
    public const UPDATE = 'update';
    public const DEPENDENCIES = 'dependencies';
    public const SETTINGS = 'settings';
    public const INSPECT = 'inspect';
    public const MANUAL = 'manual';
    public const UPLOAD = 'upload';
    public const REPLACE = 'replace';
    public const REINSTALL = 'reinstall';
    public const BULK_METADATA = 'bulk-metadata';

    public static function all(): array
    {
        return [
            self::BROWSE,
            self::DISCOVER,
            self::SEARCH,
            self::INSTALL,
            self::UPDATE,
            self::DEPENDENCIES,
            self::SETTINGS,
            self::INSPECT,
            self::MANUAL,
            self::UPLOAD,
            self::REPLACE,
            self::REINSTALL,
            self::BULK_METADATA,
        ];
    }

    public static function normalize(array $capabilities): array
    {
        $known = array_flip(self::all());
        $result = [];

        foreach ($capabilities as $capability) {
            $capability = strtolower(trim((string) $capability));

            if ($capability !== '' && isset($known[$capability])) {
                $result[$capability] = true;
            }
        }

        return array_keys($result);
    }
}
