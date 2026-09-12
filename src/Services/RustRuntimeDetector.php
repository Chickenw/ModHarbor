<?php

namespace GameNest\GameNestModManager\Services;

use RuntimeException;

class RustRuntimeDetector
{
    public const CARBON = 'carbon';
    public const OXIDE = 'oxide';
    public const VANILLA = 'vanilla';

    public function detect(FileTransaction $files): string
    {
        /*
         * Prefer Carbon when both Carbon and stale Oxide directories exist.
         * Carbon installations can retain compatibility/legacy Oxide paths.
         */
        foreach ([
            'carbon/plugins',
            'carbon',
        ] as $path) {
            if ($files->stat($path) !== null) {
                return self::CARBON;
            }
        }

        foreach ([
            'oxide/plugins',
            'oxide',
        ] as $path) {
            if ($files->stat($path) !== null) {
                return self::OXIDE;
            }
        }

        return self::VANILLA;
    }

    public function pluginRoot(FileTransaction $files): string
    {
        return match ($this->detect($files)) {
            self::CARBON => 'carbon/plugins',
            self::OXIDE => 'oxide/plugins',

            default => throw new RuntimeException(
                'This Rust server does not have Carbon or Oxide installed. Install a supported plugin framework before installing .cs plugins.'
            ),
        };
    }

    public function label(string $runtime): string
    {
        return match ($runtime) {
            self::CARBON => 'Carbon',
            self::OXIDE => 'Oxide',
            default => 'Vanilla / No Plugin Framework',
        };
    }
}
