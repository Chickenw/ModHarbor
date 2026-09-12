<?php

namespace GameNest\GameNestModManager\Adapters;

class EcoAdapter extends AbstractGameAdapter
{
    public function key(): string
    {
        return 'eco';
    }

    public function name(): string
    {
        return 'Eco';
    }

    public function metadata(): array
    {
        return array_replace(parent::metadata(), [
            'family' => 'survival',
            'plugin_runtime' => 'Eco ModKit',
            'docs_slug' => 'eco',
        ]);
    }

    protected function eggIdentifiers(): array
    {
        return [
            'eco',
        ];
    }

    public function sources(): array
    {
        return [
            'modio' => [
                'game_name' => 'Eco',
                'name_id' => 'eco',
                'discovery' => [
                    'title' => 'Eco Mod Catalog',
                    'description' => 'Browse Eco mods from mod.io with game-aware deployment and dependency handling.',
                    'search_placeholder' => 'Search Eco mods...',
                ],
            ],

            'github' => [],

            'upload' => [
                'extensions' => ['zip'],
                'accept' => '.zip',
            ],
        ];
    }

    public function scanRules(\App\Models\Server $server): array
    {
        return [['root' => 'Mods', 'patterns' => ['*.dll', '*.cs'],
            'exclude' => ['__core__', 'Eco.Shared*', 'Eco.Core*', 'Eco.Gameplay*', '.*'], 'depth' => 5]];
    }

    public function configRules(\App\Models\Server $server): array
    {
        return [['root' => 'Configs', 'patterns' => ['*.eco', '*.json', '*.yaml', '*.yml', '*.toml', '*.ini', '*.cfg', '*.txt'],
            'exclude' => ['.*', '*.template', '*.bak*'], 'depth' => 2],
            ['root' => 'Mods', 'patterns' => ['*.json', '*.yaml', '*.yml', '*.toml', '*.ini', '*.cfg', '*.txt'],
            'exclude' => ['__core__', '.*', '*.template', '*.bak*'], 'depth' => 5]];
    }

    public function validateConfig(string $path, string $contents): void
    {
        if (str_ends_with(strtolower($path), '.eco')) { json_decode($contents, true, 512, JSON_THROW_ON_ERROR); }
    }

    public function modDirectories(): array
    {
        return [
            '/Mods',
            '/Mods/UserCode',
        ];
    }

    public function configDirectories(): array
    {
        return [
            '/Configs',
            '/Mods',
            '/Mods/UserCode',
        ];
    }
}
