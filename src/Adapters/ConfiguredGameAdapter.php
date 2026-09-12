<?php

namespace GameNest\GameNestModManager\Adapters;

use App\Models\Server;
use GameNest\GameNestModManager\Services\GameCapabilityRegistry;
use GameNest\GameNestModManager\Services\GameDefinition;

final class ConfiguredGameAdapter extends AbstractGameAdapter
{
    private array $definition;

    public function __construct(
        GameDefinition $definition
    ) {
        $this->definition =
            $definition->data();
    }

    public function definition(): array
    {
        return $this->definition;
    }

    public function key(): string
    {
        return $this->definition['key'];
    }

    public function name(): string
    {
        return $this->definition['name'];
    }

    public function capability(): string
    {
        return $this->definition['capability'];
    }

    public function enabled(): bool
    {
        return $this->definition['enabled'];
    }

    protected function eggIdentifiers(): array
    {
        return
            $this->definition['detection']['egg_names'];
    }

    protected function behaviorAdapter(): ?\GameNest\GameNestModManager\Contracts\GameAdapter
    {
        return app(
            GameCapabilityRegistry::class
        )->behaviorAdapter(
            $this->capability()
        );
    }

    public function matches(
        Server $server
    ): bool {
        $server->loadMissing('egg');

        $rules =
            $this->definition['detection'];

        // Normalize configured IDs to int so strict matching is reliable
        // even if an older catalog stored numeric strings.
        $configuredIds = array_values(
            array_filter(
                array_map(
                    static function ($id): int {
                        if (is_int($id)) {
                            return $id;
                        }
                        if (is_string($id) && preg_match('/^[1-9][0-9]{0,9}$/D', $id)) {
                            return (int) $id;
                        }
                        return 0;
                    },
                    (array) ($rules['egg_ids'] ?? [])
                ),
                static fn (int $id): bool => $id >= 1
            )
        );

        $eggId = 0;
        foreach ([
            $server->egg_id ?? null,
            method_exists($server, 'getAttribute') ? $server->getAttribute('egg_id') : null,
            $server->egg->id ?? null,
            (isset($server->egg) && is_object($server->egg) && method_exists($server->egg, 'getAttribute'))
                ? $server->egg->getAttribute('id')
                : null,
        ] as $candidate) {
            if (is_numeric($candidate) && (int) $candidate >= 1) {
                $eggId = (int) $candidate;
                break;
            }
        }

        if (
            $configuredIds !== []
            && $eggId >= 1
            && in_array($eggId, $configuredIds, true)
        ) {
            return true;
        }

        $eggName = strtolower(
            trim(
                (string) (
                    $server->egg?->name
                    ?? ''
                )
            )
        );

        if (
            $eggName !== ''
            && in_array(
                $eggName,
                array_map(
                    static fn ($name) =>
                        strtolower(
                            trim((string) $name)
                        ),
                    $rules['egg_names']
                ),
                true
            )
        ) {
            return true;
        }

        foreach (
            $rules['egg_name_contains']
            ?? []
            as $fragment
        ) {
            $fragment = strtolower(
                trim((string) $fragment)
            );

            if (
                $eggName !== ''
                && $fragment !== ''
                && str_contains(
                    $eggName,
                    $fragment
                )
            ) {
                return true;
            }
        }

        return false;
    }

    public function supports(
        Server $server
    ): bool {
        return $this->enabled()
            && $this->matches($server);
    }

    public function metadata(): array
    {
        return array_replace(
            parent::metadata(),
            [
                'configured' => true,
                'schema_version' => 1,
                'steam_app_id' =>
                    $this->definition['steam_app_id'],
                'behavior' =>
                    $this->definition['behavior'],
                'capability' =>
                    $this->capability(),
            ]
        );
    }

    public function features(): array
    {
        $features =
            parent::features();

        $behavior =
            $this->behaviorAdapter();

        if ($behavior !== null) {
            $features = array_replace(
                $features,
                $behavior->features()
            );
        }

        return $features;
    }

    public function sources(): array
    {
        $result = [];

        foreach (
            $this->definition['sources']
            as $key => $source
        ) {
            $result[$key] =
                array_replace(
                    [
                        'steam_app_id' =>
                            $this->definition['steam_app_id'],
                    ],
                    $source['metadata'],
                    [
                        'extensions' =>
                            $this->definition['package_types'],

                        'accept' =>
                            implode(
                                ',',
                                array_map(
                                    static fn ($ext) =>
                                        '.' . $ext,
                                    $this->definition['package_types']
                                )
                            ),
                    ]
                );
        }

        return $result;
    }

    public function modDirectories(): array
    {
        return
            $this->definition['mod_directories'];
    }

    public function configDirectories(): array
    {
        return
            $this->definition['config_directories'];
    }

    public function scanRules(
        Server $server
    ): array {
        $behavior =
            $this->behaviorAdapter();

        if (
            $behavior instanceof
            \GameNest\GameNestModManager\Contracts\ManagedFilesAdapter
        ) {
            return $behavior->scanRules(
                $server
            );
        }

        return array_map(
            static function ($root): array {
                $exclude = [
                    '.*',
                    '*.bak*',
                ];

                if (str_contains(strtolower((string) $root), 'bepinex')) {
                    $exclude = array_merge($exclude, [
                        'core',
                        'patchers',
                        'cache',
                        'config',
                        'unity-libs',
                        'unhollowed',
                        'interop',
                        'changelog.md',
                        'changelog.txt',
                        'changelog',
                        'manifest.json',
                        'icon.png',
                        'readme.md',
                        'readme.txt',
                        'license',
                        'license.md',
                        'license.txt',
                        'logoutput.log',
                        '0Harmony.dll',
                        'BepInEx.dll',
                        'BepInEx.Core.dll',
                        'BepInEx.Preloader.dll',
                        'HarmonyXInterop.dll',
                        'Mono.Cecil.dll',
                        'MonoMod.RuntimeDetour.dll',
                        'MonoMod.Utils.dll',
                    ]);
                }

                return [
                    'root' => $root,
                    'patterns' => [
                        '*.dll',
                        '*.cs',
                        '*.jar',
                        '*.pak',
                        '*.xml',
                    ],
                    'exclude' => $exclude,
                    'depth' => 4,
                ];
            },
            $this->modDirectories()
        );
    }

    public function configRules(
        Server $server
    ): array {
        if (isset($this->definition['config_rules'])) { return $this->definition['config_rules']; }
        $behavior =
            $this->behaviorAdapter();

        if (
            $behavior instanceof
            \GameNest\GameNestModManager\Contracts\ManagedFilesAdapter
        ) {
            return $behavior->configRules(
                $server
            );
        }

        return parent::configRules(
            $server
        );
    }

    public function validateConfig(
        string $path,
        string $contents
    ): void {
        $behavior =
            $this->behaviorAdapter();

        if (
            $behavior instanceof
            \GameNest\GameNestModManager\Contracts\ManagedFilesAdapter
        ) {
            $behavior->validateConfig(
                $path,
                $contents
            );
        }
    }
}
