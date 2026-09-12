<?php

namespace GameNest\GameNestModManager\Services;

use RuntimeException;

class EcoUploadLifecycleDriver
    extends AbstractUploadArchiveLifecycleDriver
{
    protected function deploymentRoots(): array
    {
        return [
            'Mods',
            'UserCode',
            'Configs',
        ];
    }

    protected function archiveDeploymentMap(
        FileTransaction $files,
        ?SourceContext $source = null
    ): array {
        return [
            'Mods' => 'Mods',
            'UserCode' => 'Mods/UserCode',
            'Configs' => 'Configs',
        ];
    }

    public function validatePath(string $path): void
    {
        parent::validatePath($path);

        if (
            preg_match(
                '#^Mods/(__core__|Eco\.[^/]+)(/|$)#i',
                $path
            )
        ) {
            throw new RuntimeException(
                'Uploaded package attempts to replace an Eco core mod path.'
            );
        }
    }

    public function preservePath(string $path): bool
    {
        return (
            str_starts_with(
                $path,
                'Configs/'
            )
            && !str_ends_with(
                strtolower($path),
                '.eco.template'
            )
        ) || (bool) preg_match(
            '/\.(eco|json|cfg|ini|toml|ya?ml)$/i',
            $path
        );
    }

    public function prepare(
        string|int $id,
        string|int|null $fileId,
        FileTransaction $files,
        ?SourceContext $source = null
    ): array {
        $prepared = parent::prepare(
            $id,
            $fileId,
            $files,
            $source
        );

        foreach (
            $prepared['files']
            as $target => $source
        ) {
            if (
                !str_starts_with(
                    $target,
                    'Configs/'
                )
                || !str_ends_with(
                    strtolower($target),
                    '.eco.template'
                )
            ) {
                continue;
            }

            $active =
                substr($target, 0, -9);

            if (
                isset(
                    $prepared['files'][$active]
                )
                || $files->stat($active) !== null
            ) {
                continue;
            }

            $generated =
                $files->root
                . '/generated/upload/'
                . hash(
                    'sha256',
                    $active
                );

            $files->mkdir(
                dirname($generated)
            );

            $files->repo->putContent(
                $generated,
                $files->repo->getContent(
                    $source,
                    4 * 1024 * 1024
                )
            );

            $prepared['files'][$active] =
                $generated;
        }

        return $prepared;
    }
}
