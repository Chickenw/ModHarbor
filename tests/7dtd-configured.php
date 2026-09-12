<?php

use GameNest\GameNestModManager\Services\ConfiguredPackagePlan;
use GameNest\GameNestModManager\Services\GameDefinition;

function sevenDaysDefinition(): GameDefinition
{
    return GameDefinition::validate(
        [
            'schema_version' => 1,
            'key' => '7-days-to-die-test',
            'name' => '7 Days to Die Test',
            'enabled' => true,
            'steam_app_id' => 251570,
            'capability' => 'generic',
            'detection' => [
                'egg_ids' => [],
                'egg_names' => [
                    '7 Days to Die',
                ],
                'egg_name_contains' => [],
            ],
            'sources' => [
                'github' => [
                    'metadata' => [],
                ],
                'direct' => [
                    'metadata' => [],
                ],
                'upload' => [
                    'metadata' => [],
                ],
            ],
            'mod_directories' => [
                'Mods',
            ],
            'config_directories' => [
                'Configs',
            ],
            'package_types' => [
                'zip',
            ],
            'deployment' => [
                'strategy' => 'archive',
                'target' => 'Mods',
                'archive_prefix' => '',
            ],
            'behavior' => [
                'install_while_running' => false,
                'restart_required' => true,
            ],
        ],
        [
            'github',
            'direct',
            'upload',
        ]
    );
}

function sevenDaysZip(array $files): string
{
    if (!class_exists(\ZipArchive::class)) {
        throw new RuntimeException(
            'PHP ZIP extension is required for 7DTD tests.'
        );
    }

    $path =
        tempnam(
            sys_get_temp_dir(),
            'modharbor-7dtd-test-'
        );

    if ($path === false) {
        throw new RuntimeException(
            'Could not create ZIP fixture.'
        );
    }

    $zip = new \ZipArchive;

    if (
        $zip->open(
            $path,
            \ZipArchive::CREATE
                | \ZipArchive::OVERWRITE
        ) !== true
    ) {
        @unlink($path);

        throw new RuntimeException(
            'Could not open ZIP fixture.'
        );
    }

    foreach (
        $files
        as $name => $contents
    ) {
        if (
            !$zip->addFromString(
                $name,
                $contents
            )
        ) {
            $zip->close();
            @unlink($path);

            throw new RuntimeException(
                'Could not build ZIP fixture.'
            );
        }
    }

    $zip->close();

    $contents =
        file_get_contents($path);

    @unlink($path);

    if ($contents === false) {
        throw new RuntimeException(
            'Could not read ZIP fixture.'
        );
    }

    return $contents;
}

$tests[
    '7DTD direct archive layout deploys under Mods'
] = function () {
    $plan =
        (new ConfiguredPackagePlan)
            ->files(
                sevenDaysZip([
                    'ExampleMod/ModInfo.xml'
                        => '<xml />',
                    'ExampleMod/Config/config.xml'
                        => '<config />',
                    'ExampleMod/ExampleMod.dll'
                        => 'binary',
                ]),
                'ExampleMod.zip',
                sevenDaysDefinition()
            );

    check(
        isset(
            $plan[
                'Mods/ExampleMod/ModInfo.xml'
            ]
        ),
        '7DTD ModInfo.xml was not deployed under Mods'
    );

    check(
        isset(
            $plan[
                'Mods/ExampleMod/Config/config.xml'
            ]
        ),
        '7DTD config directory was not preserved'
    );

    check(
        isset(
            $plan[
                'Mods/ExampleMod/ExampleMod.dll'
            ]
        ),
        '7DTD DLL was not preserved'
    );
};

$tests[
    '7DTD archive containing Mods avoids Mods/Mods nesting'
] = function () {
    $plan =
        (new ConfiguredPackagePlan)
            ->files(
                sevenDaysZip([
                    'Mods/ExampleMod/ModInfo.xml'
                        => '<xml />',
                    'Mods/ExampleMod/ExampleMod.dll'
                        => 'binary',
                    'README.md'
                        => 'release notes',
                ]),
                'ExampleMod.zip',
                sevenDaysDefinition()
            );

    check(
        isset(
            $plan[
                'Mods/ExampleMod/ModInfo.xml'
            ]
        ),
        'Existing Mods root was not normalized'
    );

    check(
        !isset(
            $plan[
                'Mods/Mods/ExampleMod/ModInfo.xml'
            ]
        ),
        'Archive produced Mods/Mods nesting'
    );

    check(
        !isset(
            $plan[
                'Mods/README.md'
            ]
        ),
        'Release README leaked into managed Mods directory'
    );
};

$tests[
    '7DTD wrapped release Mods directory is normalized'
] = function () {
    $plan =
        (new ConfiguredPackagePlan)
            ->files(
                sevenDaysZip([
                    'ExampleMod-v2/Mods/ExampleMod/ModInfo.xml'
                        => '<xml />',
                    'ExampleMod-v2/Mods/ExampleMod/ExampleMod.dll'
                        => 'binary',
                    'ExampleMod-v2/README.md'
                        => 'release notes',
                ]),
                'ExampleMod-v2.zip',
                sevenDaysDefinition()
            );

    check(
        isset(
            $plan[
                'Mods/ExampleMod/ModInfo.xml'
            ]
        ),
        'Wrapped 7DTD Mods directory was not normalized'
    );

    check(
        isset(
            $plan[
                'Mods/ExampleMod/ExampleMod.dll'
            ]
        ),
        'Wrapped 7DTD DLL was not deployed'
    );

    check(
        count($plan) === 2,
        'Files outside wrapped Mods directory were deployed'
    );
};

function valheimBepInExDefinition(): GameDefinition
{
    return GameDefinition::validate(
        [
            'schema_version' => 1,
            'key' => 'valheim-test',
            'name' => 'Valheim Test',
            'enabled' => true,
            'steam_app_id' => 892970,
            'capability' => 'generic',
            'detection' => [
                'egg_ids' => [],
                'egg_names' => ['Valheim'],
                'egg_name_contains' => [],
            ],
            'sources' => [
                'github' => [
                    'metadata' => [],
                ],
                'direct' => [
                    'metadata' => [],
                ],
                'upload' => [
                    'metadata' => [],
                ],
            ],
            'mod_directories' => [
                'BepInEx',
                'BepInEx/plugins',
            ],
            'config_directories' => [
                'BepInEx/config',
            ],
            'package_types' => [
                'zip',
            ],
            'deployment' => [
                'strategy' => 'archive',
                'target' => 'BepInEx',
                'archive_prefix' => '',
            ],
            'behavior' => [
                'install_while_running' => false,
                'restart_required' => true,
            ],
        ],
        [
            'github',
            'direct',
            'upload',
        ]
    );
}

$tests[
    'Thunderstore plugins/ zip deploys under BepInEx/plugins'
] = function () {
    $plan = (new ConfiguredPackagePlan)->files(
        sevenDaysZip([
            'plugins/Jotunn.dll' => 'dll',
            'manifest.json' => '{}',
            'CHANGELOG.md' => 'notes',
            'icon.png' => 'png',
        ]),
        'Jotunn.zip',
        valheimBepInExDefinition()
    );

    check(isset($plan['BepInEx/plugins/Jotunn.dll']), 'Thunderstore plugins/ zip missed BepInEx/plugins');
    check(!isset($plan['BepInEx/plugins/plugins/Jotunn.dll']), 'Thunderstore plugins/ zip nested plugins/plugins');
    check(!isset($plan['BepInEx/manifest.json']), 'Thunderstore sidecar leaked into BepInEx');
};

$tests[
    'Thunderstore BepInEx/plugins zip does not double-nest'
] = function () {
    $plan = (new ConfiguredPackagePlan)->files(
        sevenDaysZip([
            'BepInEx/plugins/Jotunn.dll' => 'dll',
            'README.md' => 'notes',
        ]),
        'Jotunn.zip',
        valheimBepInExDefinition()
    );

    check(isset($plan['BepInEx/plugins/Jotunn.dll']), 'BepInEx/plugins zip was not normalized');
    check(!isset($plan['BepInEx/BepInEx/plugins/Jotunn.dll']), 'BepInEx/plugins zip double-nested');
};

$tests[
    'Thunderstore loose DLL deploys under BepInEx/plugins'
] = function () {
    $plan = (new ConfiguredPackagePlan)->files(
        sevenDaysZip([
            'Jotunn.dll' => 'dll',
            'manifest.json' => '{}',
        ]),
        'Jotunn.zip',
        valheimBepInExDefinition()
    );

    check(isset($plan['BepInEx/plugins/Jotunn.dll']), 'Loose Thunderstore DLL was not placed in plugins');
    check(!isset($plan['BepInEx/Jotunn.dll']), 'Loose Thunderstore DLL landed in BepInEx root');
};

$tests[
    'Thunderstore required dependencies skip BepInExPack'
] = function () {
    $source = file_get_contents(dirname(__DIR__) . '/src/Providers/ThunderstoreProvider.php');
    check(str_contains($source, 'bepinexpack'), 'BepInExPack skip missing');
    check(str_contains($source, "'type' => 'required'"), 'Thunderstore required dependency type missing');
    check(str_contains($source, 'requiredDependencies'), 'Thunderstore dependency parser missing');
};

