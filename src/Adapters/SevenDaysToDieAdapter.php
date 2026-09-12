<?php

namespace GameNest\GameNestModManager\Adapters;

class SevenDaysToDieAdapter extends AbstractGameAdapter
{
    public function key(): string
    {
        return '7daystodie';
    }

    public function name(): string
    {
        return '7 Days to Die';
    }

    public function metadata(): array
    {
        return array_replace(parent::metadata(), [
            'family' => 'survival',
            'plugin_runtime' => 'Mods folder',
            'docs_slug' => '7-days-to-die',
            'status' => 'adapter-ready',
        ]);
    }

    protected function eggIdentifiers(): array
    {
        return [
            '7 days to die',
            '7days to die',
            '7daystodie',
            '7dtd',
        ];
    }

    public function sources(): array
    {
        return [
            /*
             * Placeholder for a dedicated 7DTD catalog/API provider.
             * The adapter API is ready even though that provider is not
             * part of the current Eco/Rust v1 production scope.
             */
            '7dtd' => [],

            'github' => [],

            'direct' => [
                'extensions' => ['zip'],
            ],

            'upload' => [
                'extensions' => ['zip'],
                'accept' => '.zip',
            ],
        ];
    }

    public function scanRules(\App\Models\Server $server): array
    {
        return [['root' => 'Mods', 'patterns' => ['*.dll', '*.xml'], 'exclude' => ['.*'], 'depth' => 4]];
    }

    public function modDirectories(): array
    {
        return [
            '/Mods',
        ];
    }

    public function configDirectories(): array
    {
        return [
            '/Mods',
        ];
    }
}
