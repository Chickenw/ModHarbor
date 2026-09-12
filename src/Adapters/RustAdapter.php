<?php

namespace GameNest\GameNestModManager\Adapters;

class RustAdapter extends AbstractGameAdapter
{
    public function key(): string
    {
        return 'rust';
    }

    public function name(): string
    {
        return 'Rust';
    }

    public function metadata(): array
    {
        return array_replace(parent::metadata(), [
            'family' => 'survival',
            'plugin_runtime' => 'Carbon / Oxide',
            'docs_slug' => 'rust',
        ]);
    }

    public function features(): array
    {
        return array_replace(parent::features(), [
            'runtime_detection' => true,
            'existing_mod_scan' => true,
        ]);
    }

    protected function eggIdentifiers(): array
    {
        return [
            'rust',
        ];
    }

    public function sources(): array
    {
        return [
            'umod' => [
                'categories' => [
                    'rust',
                ],
                'discovery' => [
                    'title' => 'Rust Plugin Catalog',
                    'description' => 'Browse uMod plugins and let ModHarbor deploy them to the active Carbon or Oxide runtime.',
                    'search_placeholder' => 'Search Rust plugins...',
                ],
            ],

            'github' => [],

            'direct' => [
                'extensions' => ['cs', 'zip'],
            ],

            'upload' => [
                'extensions' => ['cs', 'zip'],
                'accept' => '.cs,.zip',
            ],
        ];
    }

    public function scanRules(\App\Models\Server $server): array
    {
        $files = new \GameNest\GameNestModManager\Services\FileTransaction(
            app(\App\Repositories\Daemon\DaemonFileRepository::class)->setServer($server),
            '.gamenest/mod-manager/scan', static function ($moves): void {});
        $detector = new \GameNest\GameNestModManager\Services\RustRuntimeDetector;
        if ($detector->detect($files) === $detector::VANILLA) { return []; }
        return [['root' => $detector->pluginRoot($files), 'patterns' => ['*.cs'], 'depth' => 0]];
    }

    public function modDirectories(): array
    {
        return [
            '/oxide/plugins',
            '/carbon/plugins',
        ];
    }

    public function configDirectories(): array
    {
        return [
            '/oxide/config',
            '/carbon/configs',
            '/carbon/config',
        ];
    }
}
