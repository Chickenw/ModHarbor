<?php

namespace GameNest\GameNestModManager\Services;

use RuntimeException;
use ZipArchive;

/**
 * Builds a safe, provider-neutral deployment plan for Game Builder packages.
 *
 * Game-specific layout is supplied by GameDefinition. ZIP normalization here
 * is intentionally universal:
 *
 *   MyMod/...                  -> Mods/MyMod/...
 *   Mods/MyMod/...             -> Mods/MyMod/...
 *   ReleaseName/Mods/MyMod/... -> Mods/MyMod/...
 *
 * If a ZIP contains the configured deployment directory, unrelated files such
 * as README.md or release notes are ignored rather than copied into the game's
 * managed mod directory.
 *
 * Explicit archive_prefix remains strict and overrides automatic normalization.
 */
class ConfiguredPackagePlan
{
    public const MAX_BYTES = 33554432;

    public function files(
        string $contents,
        string $filename,
        GameDefinition $definition
    ): array {
        $d = $definition->data();

        GameDefinition::path($filename);

        if (
            str_contains($filename, '/')
            || strlen($contents) < 1
            || strlen($contents) > self::MAX_BYTES
        ) {
            throw new RuntimeException(
                'Invalid package name or size.'
            );
        }

        $extension = strtolower(
            pathinfo($filename, PATHINFO_EXTENSION)
        );

        if (
            !in_array(
                $extension,
                $d['package_types'],
                true
            )
        ) {
            throw new RuntimeException(
                'Package type is not allowed for this game.'
            );
        }

        $target = $d['deployment']['target'];

        if (
            $d['deployment']['strategy']
            === 'copy'
        ) {
            return [
                $target . '/' . $filename
                    => $contents,
            ];
        }

        if (
            $d['deployment']['strategy']
                !== 'archive'
            || $extension !== 'zip'
        ) {
            throw new RuntimeException(
                'This deployment requires a registered provider integration.'
            );
        }

        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException(
                'PHP ZIP is required for safe archive inspection.'
            );
        }

        DiskSpaceGuard::local(sys_get_temp_dir(), strlen($contents));
        $temporary = tempnam(
            sys_get_temp_dir(),
            'modharbor-'
        );

        if ($temporary === false) {
            throw new RuntimeException(
                'Cannot stage archive inspection.'
            );
        }

        $zip = new ZipArchive;
        $opened = false;

        try {
            if (
                file_put_contents(
                    $temporary,
                    $contents
                ) !== strlen($contents)
                || $zip->open($temporary) !== true
            ) {
                throw new RuntimeException(
                    'Invalid ZIP archive.'
                );
            }

            $opened = true;

            if ($zip->numFiles > 10000) {
                throw new RuntimeException(
                    'Too many archive entries.'
                );
            }

            /*
             * First pass:
             *
             * Validate every archive entry before deciding which files are
             * deployable. Even ignored README/release files must still satisfy
             * traversal, special-file, encryption, depth, and size guards.
             */
            $entries = [];
            $total = 0;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);

                if (!is_array($stat)) {
                    throw new RuntimeException(
                        'Unreadable archive entry.'
                    );
                }

                $name = (string) (
                    $stat['name']
                    ?? ''
                );

                $directory =
                    str_ends_with(
                        $name,
                        '/'
                    );

                $validatedName =
                    $directory
                        ? substr($name, 0, -1)
                        : $name;

                /*
                 * ZIPs may contain a root directory entry ending in "/".
                 * Empty root entries carry no deployable data.
                 */
                if ($validatedName === '') {
                    continue;
                }

                if ($this->isPackageSidecar($validatedName)) {
                    continue;
                }

                GameDefinition::path(
                    $validatedName
                );

                if (
                    substr_count(
                        $name,
                        '/'
                    ) > 32
                ) {
                    throw new RuntimeException(
                        'Archive is too deep.'
                    );
                }

                $opsys = 0;
                $attributes = 0;

                if (
                    !$zip->getExternalAttributesIndex(
                        $i,
                        $opsys,
                        $attributes
                    )
                ) {
                    throw new RuntimeException(
                        'Cannot inspect archive attributes.'
                    );
                }

                $type =
                    ($attributes >> 16)
                    & 0170000;

                if (
                    $opsys === 3
                    && !in_array(
                        $type,
                        [
                            0,
                            0100000,
                            0040000,
                        ],
                        true
                    )
                ) {
                    throw new RuntimeException(
                        'Archive links and special files are forbidden.'
                    );
                }

                if (
                    ($stat['encryption_method'] ?? 0)
                    !== 0
                ) {
                    throw new RuntimeException(
                        'Encrypted archives are unsupported.'
                    );
                }

                $total +=
                    (int) (
                        $stat['size']
                        ?? 0
                    );

                if ($total > self::MAX_BYTES) {
                    throw new RuntimeException(
                        'Expanded archive exceeds 32 MiB.'
                    );
                }

                if ($directory) {
                    continue;
                }

                $entries[] = [
                    'index' => $i,
                    'name' => $name,
                    'size' => (int) (
                        $stat['size']
                        ?? 0
                    ),
                ];
            }

            if ($entries === []) {
                throw new RuntimeException(
                    'Archive contains no mod files.'
                );
            }

            $configuredPrefix =
                (string) (
                    $d['deployment'][
                        'archive_prefix'
                    ]
                    ?? ''
                );

            $selection =
                $this->archiveSelection(
                    $entries,
                    $target,
                    $configuredPrefix
                );

            $plan = [];
            $seen = [];

            foreach (
                $entries
                as $entry
            ) {
                $name =
                    $this->deploymentName(
                        $entry['name'],
                        $selection
                    );

                if ($name === null) {
                    continue;
                }

                $path =
                    GameDefinition::path(
                        $target
                        . '/'
                        . $name
                    );

                $folded =
                    strtolower($path);

                if (isset($seen[$folded])) {
                    throw new RuntimeException(
                        'Duplicate/case-colliding archive destination.'
                    );
                }

                foreach (
                    $seen
                    as $previous => $_
                ) {
                    if (
                        str_starts_with(
                            $folded,
                            $previous . '/'
                        )
                        || str_starts_with(
                            $previous,
                            $folded . '/'
                        )
                    ) {
                        throw new RuntimeException(
                            'Archive file/directory collision.'
                        );
                    }
                }

                $body =
                    $zip->getFromIndex(
                        $entry['index'],
                        max(
                            1,
                            $entry['size'] + 1
                        )
                    );

                if (
                    $body === false
                    || strlen($body)
                        !== $entry['size']
                ) {
                    throw new RuntimeException(
                        'Unreadable archive payload.'
                    );
                }

                $seen[$folded] = true;
                $plan[$path] = $body;
            }

            if ($plan === []) {
                throw new RuntimeException(
                    'Archive contains no mod files.'
                );
            }

            return $plan;
        } finally {
            if ($opened) {
                $zip->close();
            }

            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /**
     * Decide how an archive is mapped into the configured deployment target.
     *
     * Modes:
     *
     * strict:
     *   Game Builder explicitly supplied archive_prefix.
     *
     * target:
     *   ZIP already has "Mods/..."; strip "Mods/".
     *
     * wrapper-target:
     *   ZIP has "ReleaseName/Mods/..."; strip both wrapper and target.
     *
     * direct:
     *   ZIP contains the mod directory/files directly; place all beneath target.
     */
    private function isPackageSidecar(string $name): bool
    {
        $base = strtolower(basename(str_replace('\\', '/', $name)));

        return in_array($base, [
            'manifest.json',
            'icon.png',
            'readme.md',
            'readme.txt',
            'readme',
            'changelog.md',
            'changelog.txt',
            'changelog',
            'license',
            'license.txt',
            'license.md',
        ], true);
    }

    private function archiveSelection(
        array $entries,
        string $target,
        string $configuredPrefix
    ): array {
        if ($configuredPrefix !== '') {
            return [
                'mode' => 'strict',
                'prefix' =>
                    $configuredPrefix
                    . '/',
            ];
        }

        $targetPrefix =
            $target
            . '/';

        foreach ($entries as $entry) {
            if (
                str_starts_with(
                    $entry['name'],
                    $targetPrefix
                )
            ) {
                return [
                    'mode' => 'target',
                    'prefix' =>
                        $targetPrefix,
                ];
            }
        }

        $wrappers = [];

        foreach ($entries as $entry) {
            $parts =
                explode(
                    '/',
                    $entry['name']
                );

            if (
                count($parts) >= 3
                && $parts[1] === $target
            ) {
                $wrappers[
                    $parts[0]
                ] = true;
            }
        }

        if (count($wrappers) === 1) {
            $wrapper =
                (string) array_key_first(
                    $wrappers
                );

            return [
                'mode' =>
                    'wrapper-target',
                'prefix' =>
                    $wrapper
                    . '/'
                    . $target
                    . '/',
            ];
        }

        $leaf = basename(str_replace('\\', '/', $target));
        if ($leaf === 'BepInEx') {
            $pluginFiles = 0;
            $bepinexPluginFiles = 0;
            $looseFiles = 0;
            foreach ($entries as $entry) {
                $n = $entry['name'];
                if ($this->isPackageSidecar($n)) {
                    continue;
                }
                if (str_starts_with($n, 'BepInEx/plugins/')) {
                    $bepinexPluginFiles++;
                } elseif (str_starts_with($n, 'plugins/')) {
                    $pluginFiles++;
                } elseif (!str_contains($n, '/')) {
                    $looseFiles++;
                }
            }
            if ($bepinexPluginFiles > 0 && $pluginFiles === 0 && $looseFiles === 0) {
                return [
                    'mode' => 'target',
                    'prefix' => 'BepInEx/',
                ];
            }
            if ($looseFiles > 0 && $pluginFiles === 0 && $bepinexPluginFiles === 0) {
                return [
                    'mode' => 'bepinex-loose',
                    'prefix' => '',
                ];
            }
        }

        return [
            'mode' => 'direct',
            'prefix' => '',
        ];
    }

    private function deploymentName(
        string $name,
        array $selection
    ): ?string {
        $mode =
            (string) (
                $selection['mode']
                ?? 'direct'
            );

        $prefix =
            (string) (
                $selection['prefix']
                ?? ''
            );

        if ($mode === 'direct') {
            return $this->isPackageSidecar($name) ? null : $name;
        }

        if ($mode === 'bepinex-loose') {
            if ($this->isPackageSidecar($name) || str_contains($name, '/')) {
                return null;
            }

            return 'plugins/' . $name;
        }

        if (
            !str_starts_with(
                $name,
                $prefix
            )
        ) {
            if ($mode === 'strict') {
                throw new RuntimeException(
                    'Archive file lies outside the configured archive prefix.'
                );
            }

            /*
             * Automatic normalization deliberately ignores release notes,
             * README files, screenshots, and similar files outside the game's
             * configured deployment directory.
             */
            return null;
        }

        $name =
            substr(
                $name,
                strlen($prefix)
            );

        if ($name === '') {
            return null;
        }

        return $name;
    }
}
