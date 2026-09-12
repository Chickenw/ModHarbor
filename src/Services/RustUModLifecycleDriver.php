<?php

namespace GameNest\GameNestModManager\Services;

use GameNest\GameNestModManager\Contracts\LifecycleDriver;
use GameNest\GameNestModManager\Contracts\ModProvider;
use GameNest\GameNestModManager\Providers\UModProvider;
use RuntimeException;

class RustUModLifecycleDriver implements LifecycleDriver
{
    public function __construct(
        protected UModProvider $umod
    ) {}

    public function provider(): ModProvider
    {
        return $this->umod;
    }

    public function dependencies(
        string|int $id,
        ?SourceContext $source = null
    ): array {
        return $this->umod->requiredDependencies(
            (string) $id,
            null,
            $source
        );
    }

    public function preservePath(
        string $path
    ): bool {
        /*
         * Runtime-generated Carbon/Oxide configs are not part of the
         * installed package, so they are preserved automatically.
         */
        return false;
    }

    public function validatePath(
        string $path
    ): void {
        $path =
            FileTransaction::path(
                $path
            );

        if (
            !preg_match(
                '#^(carbon|oxide)/plugins/[^/]+\.cs$#i',
                $path
            )
        ) {
            throw new RuntimeException(
                'uMod plugin path is outside the supported Rust plugin locations: '
                . $path
            );
        }
    }

    public function prepare(
        string|int $id,
        string|int|null $fileId,
        FileTransaction $files,
        ?SourceContext $source = null
    ): array {
        $slug =
            $this->slug(
                $id
            );

        $mod =
            $this->umod->get(
                $slug,
                $source
            );

        if (
            !$mod
            || (string) ($mod['id'] ?? '')
                !== $slug
        ) {
            throw new RuntimeException(
                'Unable to retrieve the selected uMod plugin.'
            );
        }

        $file =
            $mod['latest_file']
            ?? [];

        /*
         * Reinstall uses the exact release originally installed.
         *
         * file_id format:
         *
         *     VERSION:SHA1
         */
        if (
            $fileId !== null
            && trim((string) $fileId) !== ''
        ) {
            $file =
                $this->pinnedFile(
                    $mod,
                    (string) $fileId
                );
        }

        $filename =
            trim(
                (string) (
                    $file['name']
                    ?? ''
                )
            );

        if (
            $filename === ''
            || !preg_match(
                '/^[A-Za-z0-9_.-]+\.cs$/i',
                $filename
            )
        ) {
            throw new RuntimeException(
                'uMod returned an invalid Rust plugin filename.'
            );
        }

        $downloadUrl =
            trim(
                (string) (
                    $file['download_url']
                    ?? ''
                )
            );

        if (
            !str_starts_with(
                $downloadUrl,
                'https://umod.org/plugins/'
            )
        ) {
            throw new RuntimeException(
                'uMod returned an invalid plugin download URL.'
            );
        }

        $targetRoot =
            $this->deploymentRoot(
                $files
            );

        $targetPath =
            $targetRoot
            . '/'
            . $filename;

        $this->validatePath(
            $targetPath
        );

        $stage =
            $files->root
            . '/staging/umod/'
            . sha1($slug)
            . '/'
            . sha1(
                (string) (
                    $file['id']
                    ?? ''
                )
            );

        $files->mkdir(
            $stage
        );

        $files->repo->pull(
            $downloadUrl,
            '/' . $stage,
            [
                'filename' =>
                    $filename,

                'foreground' =>
                    true,
            ]
        );

        $stagedPath =
            $stage
            . '/'
            . $filename;

        $entry =
            $files->stat(
                $stagedPath
            );

        if (
            $entry === null
            || empty($entry['file'])
            || !empty($entry['symlink'])
        ) {
            throw new RuntimeException(
                'uMod plugin could not be staged safely.'
            );
        }

        /*
         * uMod exposes a SHA-1 checksum for each release.
         * Verify the downloaded source before allowing deployment.
         */
        $checksum =
            strtolower(
                trim(
                    (string) (
                        $file['checksum']
                        ?? ''
                    )
                )
            );

        if (
            $checksum !== ''
            && preg_match(
                '/^[a-f0-9]{40}$/',
                $checksum
            )
        ) {
            $content =
                $files->repo->getContent(
                    $stagedPath,
                    8 * 1024 * 1024
                );

            if (
                sha1($content)
                !== $checksum
            ) {
                throw new RuntimeException(
                    'uMod plugin checksum verification failed.'
                );
            }
        }

        return [
            'mod' =>
                $mod,

            'file' =>
                $file,

            'files' => [
                $targetPath =>
                    $stagedPath,
            ],
        ];
    }

    protected function slug(
        string|int $id
    ): string {
        $slug =
            strtolower(
                trim(
                    (string) $id
                )
            );

        if (
            !preg_match(
                '/^[a-z0-9][a-z0-9-]*$/',
                $slug
            )
        ) {
            throw new RuntimeException(
                'Invalid uMod plugin identifier.'
            );
        }

        return $slug;
    }

    protected function pinnedFile(
        array $mod,
        string $fileId
    ): array {
        $parts =
            explode(
                ':',
                trim($fileId),
                2
            );

        if (
            count($parts) !== 2
        ) {
            throw new RuntimeException(
                'Invalid installed uMod release identifier.'
            );
        }

        [$version, $checksum] =
            $parts;

        $version =
            trim(
                $version
            );

        $checksum =
            strtolower(
                trim(
                    $checksum
                )
            );

        if (
            $version === ''
            || !preg_match(
                '/^[a-f0-9]{40}$/',
                $checksum
            )
        ) {
            throw new RuntimeException(
                'Invalid installed uMod release metadata.'
            );
        }

        $filename =
            trim(
                (string) (
                    $mod['latest_file']['name']
                    ?? ''
                )
            );

        if (
            $filename === ''
            || !str_ends_with(
                strtolower($filename),
                '.cs'
            )
        ) {
            throw new RuntimeException(
                'Unable to determine the uMod plugin filename.'
            );
        }

        $baseName =
            substr(
                $filename,
                0,
                -3
            );

        return [
            'id' =>
                $version . ':' . $checksum,

            'name' =>
                $filename,

            'filename' =>
                $filename,

            'version' =>
                $version,

            'checksum' =>
                $checksum,

            'download_url' =>
                'https://umod.org/plugins/'
                . rawurlencode($baseName)
                . '.cs?version='
                . rawurlencode($version),
        ];
    }

    protected function deploymentRoot(
        FileTransaction $files
    ): string {
        return app(
            RustRuntimeDetector::class
        )->pluginRoot($files);
    }
}
