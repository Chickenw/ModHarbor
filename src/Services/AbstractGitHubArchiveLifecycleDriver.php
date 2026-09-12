<?php

namespace GameNest\GameNestModManager\Services;

use GameNest\GameNestModManager\Contracts\LifecycleDriver;
use GameNest\GameNestModManager\Contracts\ModProvider;
use GameNest\GameNestModManager\Providers\GitHubProvider;
use RuntimeException;

abstract class AbstractGitHubArchiveLifecycleDriver
    implements LifecycleDriver
{
    public function __construct(
        protected GitHubProvider $github
    ) {}

    public function provider(): ModProvider
    {
        return $this->github;
    }

    public function dependencies(
        string|int $id,
        ?SourceContext $source = null
    ): array {
        /*
         * GitHub has no universal dependency metadata.
         * Game-specific dependency support can be layered on later.
         */
        return [];
    }

    abstract protected function deploymentRoots(): array;

    /**
     * Archive source directory => managed destination directory.
     *
     * Most games use identical source/destination roots. Runtime-aware
     * drivers such as Rust can normalize legacy or alternate archive
     * layouts into the currently active framework directory.
     */
    protected function archiveDeploymentMap(
        FileTransaction $files,
        ?SourceContext $source = null
    ): array {
        $map = [];

        foreach ($this->deploymentRoots() as $root) {
            $map[$root] = $root;
        }

        return $map;
    }

    public function preservePath(string $path): bool
    {
        return false;
    }

    public function validatePath(string $path): void
    {
        $path = FileTransaction::path($path);

        foreach ($this->deploymentRoots() as $root) {
            if (
                $path === $root
                || str_starts_with(
                    $path,
                    $root . '/'
                )
            ) {
                return;
            }
        }

        throw new RuntimeException(
            'GitHub package path is outside the supported mod locations: ' .
            $path
        );
    }

    protected function singleFileExtensions(): array
    {
        return [];
    }

    protected function singleFileDeploymentRoot(
        FileTransaction $files,
        string $filename
    ): ?string {
        return null;
    }

    protected function supportsSingleFile(
        string $filename
    ): bool {
        $extension =
            strtolower(
                pathinfo(
                    $filename,
                    PATHINFO_EXTENSION
                )
            );

        return $extension !== ''
            && in_array(
                $extension,
                $this->singleFileExtensions(),
                true
            );
    }

    protected function prepareSingleFile(
        array $mod,
        array $file,
        int $repositoryId,
        FileTransaction $files,
        ?SourceContext $source = null
    ): array {
        $filename =
            trim(
                (string) (
                    $file['name']
                    ?? ''
                )
            );

        FileTransaction::path($filename);

        if (
            $filename === ''
            || str_contains($filename, '/')
            || str_contains($filename, '\\')
        ) {
            throw new RuntimeException(
                'Invalid GitHub single-file asset name.'
            );
        }

        $targetRoot =
            $this->singleFileDeploymentRoot(
                $files,
                $filename
            );

        if (
            $targetRoot === null
            || trim($targetRoot) === ''
        ) {
            throw new RuntimeException(
                'This GitHub asset type is not supported for this game.'
            );
        }

        $targetRoot =
            FileTransaction::path(
                $targetRoot
            );

        $targetPath =
            $targetRoot .
            '/' .
            $filename;

        $this->validatePath(
            $targetPath
        );

        $stage =
            $files->root .
            '/staging/github/' .
            $repositoryId .
            '/' .
            (int) ($file['id'] ?? 0);

        $files->mkdir($stage);

        $files->repo->pull(
            (string) $file['download_url'],
            '/' . $stage,
            [
                'filename' => $filename,
                'foreground' => true,
            ]
        );

        $stagedPath =
            $stage .
            '/' .
            $filename;

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
                'GitHub single-file asset could not be staged safely.'
            );
        }

        return [
            'mod' => $mod,
            'file' => $file,
            'files' => [
                $targetPath => $stagedPath,
            ],
        ];
    }

    public function prepare(
        string|int $id,
        string|int|null $fileId,
        FileTransaction $files,
        ?SourceContext $source = null
    ): array {
        $repositoryId =
            $this->repositoryId($id);

        $mod =
            $this->github->get(
                $repositoryId,
                $source
            );

        if (
            !$mod
            || (int) ($mod['id'] ?? 0)
                !== $repositoryId
        ) {
            throw new RuntimeException(
                'Unable to retrieve the selected GitHub repository.'
            );
        }

        if ($fileId !== null && $fileId !== '') {
            if (
                !ctype_digit((string) $fileId)
                || (int) $fileId < 1
            ) {
                throw new RuntimeException(
                    'Invalid GitHub release asset ID.'
                );
            }

            $file = $this->github->file(
                $repositoryId,
                (int) $fileId
            );
        } else {
            $file =
                $mod['latest_file']
                ?? [];
        }

        if (
            (int) ($file['id'] ?? 0) < 1
            || !str_starts_with(
                (string) ($file['download_url'] ?? ''),
                'https://'
            )
        ) {
            throw new RuntimeException(
                'This repository has no downloadable GitHub Release asset.'
            );
        }

        $assetName =
            trim(
                (string) (
                    $file['name']
                    ?? ''
                )
            );

        if (
            !str_ends_with(
                strtolower($assetName),
                '.zip'
            )
        ) {
            if (
                $this->supportsSingleFile(
                    $assetName
                )
            ) {
                return $this->prepareSingleFile(
                    $mod,
                    $file,
                    $repositoryId,
                    $files,
                    $source
                );
            }

            throw new RuntimeException(
                'This GitHub Release asset type is not supported for this game.'
            );
        }

        $stage =
            $files->root .
            '/staging/github/' .
            $repositoryId .
            '/' .
            (int) $file['id'];

        $files->mkdir($stage);

        $files->repo->pull(
            (string) $file['download_url'],
            '/' . $stage,
            [
                'filename' => 'archive.zip',
                'foreground' => true,
            ]
        );

        $files->repo->decompressFile(
            '/' . $stage,
            'archive.zip'
        );

        $files->repo->deleteFiles(
            '/' . $stage,
            ['archive.zip']
        );

        $root =
            $this->deploymentRoot(
                $files,
                $stage,
                $source
            );

        $plan = [];

        foreach (
            $this->archiveDeploymentMap($files, $source)
            as $sourceRoot => $targetRoot
        ) {
            $sourcePath =
                $root .
                '/' .
                $sourceRoot;

            $entry =
                $files->stat($sourcePath);

            if ($entry === null) {
                continue;
            }

            if (!empty($entry['file'])) {
                throw new RuntimeException(
                    'Invalid GitHub archive directory: ' .
                    $sourceRoot
                );
            }

            $this->collect(
                $files,
                $sourcePath,
                $targetRoot,
                $plan
            );
        }

        if ($plan === []) {
            throw new RuntimeException(
                'The GitHub Release archive does not contain a supported mod folder layout for this game.'
            );
        }

        return [
            'mod' => $mod,
            'file' => $file,
            'files' => $plan,
        ];
    }

    protected function repositoryId(
        string|int $id
    ): int {
        if (
            !ctype_digit((string) $id)
            || (int) $id < 1
        ) {
            throw new RuntimeException(
                'Invalid GitHub repository identifier.'
            );
        }

        return (int) $id;
    }

    protected function deploymentRoot(
        FileTransaction $files,
        string $stage,
        ?SourceContext $source = null
    ): string {
        $candidates = [$stage];

        foreach ($files->listing($stage) as $entry) {
            if (!empty($entry['symlink'])) {
                throw new RuntimeException(
                    'GitHub archives containing symbolic links are not supported.'
                );
            }

            $name = (string) (
                $entry['name']
                ?? ''
            );

            FileTransaction::path($name);

            if (str_contains($name, '/')) {
                throw new RuntimeException(
                    'Invalid GitHub archive entry.'
                );
            }

            if (empty($entry['file'])) {
                $candidates[] =
                    $stage .
                    '/' .
                    $name;
            }
        }

        foreach ($candidates as $candidate) {
            foreach (
                array_keys(
                    $this->archiveDeploymentMap(
                        $files,
                        $source
                    )
                )
                as $root
            ) {
                if (
                    $files->stat(
                        $candidate .
                        '/' .
                        $root
                    ) !== null
                ) {
                    return $candidate;
                }
            }
        }

        throw new RuntimeException(
            'The GitHub archive does not contain one of this game\'s supported mod directories.'
        );
    }

    protected function collect(
        FileTransaction $files,
        string $source,
        string $target,
        array &$plan,
        int $depth = 0
    ): void {
        if (
            $depth > 32
            || count($plan) > 10000
        ) {
            throw new RuntimeException(
                'The GitHub archive exceeds supported file or depth limits.'
            );
        }

        foreach (
            $files->listing($source)
            as $entry
        ) {
            $name = (string) (
                $entry['name']
                ?? ''
            );

            FileTransaction::path($name);

            if (
                str_contains($name, '/')
                || !empty($entry['symlink'])
            ) {
                throw new RuntimeException(
                    'Unsafe GitHub archive entry.'
                );
            }

            $path =
                $target .
                '/' .
                $name;

            $this->validatePath($path);

            if (!empty($entry['file'])) {
                if (isset($plan[$path])) {
                    throw new RuntimeException(
                        'GitHub archive contains duplicate files that map to the same managed path: '
                        . $path
                    );
                }

                $plan[$path] =
                    $source .
                    '/' .
                    $name;
            } else {
                $this->collect(
                    $files,
                    $source . '/' . $name,
                    $path,
                    $plan,
                    $depth + 1
                );
            }
        }
    }
}
