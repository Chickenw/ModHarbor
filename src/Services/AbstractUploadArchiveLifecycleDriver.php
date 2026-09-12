<?php

namespace GameNest\GameNestModManager\Services;

use GameNest\GameNestModManager\Contracts\LifecycleDriver;
use GameNest\GameNestModManager\Contracts\ModProvider;
use GameNest\GameNestModManager\Providers\UploadProvider;
use RuntimeException;

abstract class AbstractUploadArchiveLifecycleDriver implements LifecycleDriver
{
    public function __construct(
        protected UploadProvider $upload,
        protected UploadPackageStore $store
    ) {}

    public function provider(): ModProvider
    {
        return $this->upload;
    }

    public function dependencies(
        string|int $id,
        ?SourceContext $source = null
    ): array
    {
        return [];
    }

    public function preservePath(string $path): bool
    {
        return false;
    }

    abstract protected function deploymentRoots(): array;

    /**
     * Archive source directory => managed destination directory.
     *
     * Most games deploy archive roots unchanged. Games whose package layout
     * differs from their actual server layout can normalize it here.
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
            'Uploaded package contains files outside supported mod locations: '
            . $path
        );
    }

    public function prepare(
        string|int $id,
        string|int|null $fileId,
        FileTransaction $files,
        ?SourceContext $source = null
    ): array {
        $id = (string) $id;

        $mod = $this->upload->get(
            $id,
            $source
        );
        $package = $this->store->get($id);

        if (
            $mod === null
            || $package === null
        ) {
            throw new RuntimeException(
                'The retained uploaded package could not be found.'
            );
        }

        $filename = (string) (
            $package['filename']
            ?? ''
        );

        if (
            strtolower(
                pathinfo(
                    $filename,
                    PATHINFO_EXTENSION
                )
            ) !== 'zip'
        ) {
            throw new RuntimeException(
                'This game requires an uploaded ZIP package.'
            );
        }

        $stage =
            $files->root
            . '/staging/upload/'
            . $id;

        $files->mkdir($stage);

        $files->repo->putContent(
            $stage . '/archive.zip',
            $this->store->contents($id)
        );

        $entry = $files->stat(
            $stage . '/archive.zip'
        );

        if (
            $entry === null
            || empty($entry['file'])
            || !empty($entry['symlink'])
        ) {
            throw new RuntimeException(
                'The uploaded package could not be staged safely.'
            );
        }

        $files->repo->decompressFile(
            '/' . $stage,
            'archive.zip'
        );

        $files->repo->deleteFiles(
            '/' . $stage,
            ['archive.zip']
        );

        $root = $this->deploymentRoot(
            $files,
            $stage
        );

        $plan = [];

        foreach (
            $this->archiveDeploymentMap($files, $source)
            as $sourceRoot => $targetRoot
        ) {
            $sourcePath =
                $root
                . '/'
                . $sourceRoot;

            $entry = $files->stat($sourcePath);

            if ($entry === null) {
                continue;
            }

            if (!empty($entry['file'])) {
                throw new RuntimeException(
                    'Invalid uploaded mod folder: '
                    . $sourceRoot
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
                'The uploaded ZIP contains no supported mod files.'
            );
        }

        $file = $mod['latest_file'] ?? [];
        $file['id'] = $id;
        $file['version'] = (string) (
            $mod['latest_file']['version']
            ?? $mod['version']
            ?? 'Local'
        );

        return [
            'mod' => $mod,
            'file' => $file,
            'files' => $plan,
        ];
    }

    protected function deploymentRoot(
        FileTransaction $files,
        string $stage
    ): string {
        $candidates = [$stage];

        foreach ($files->listing($stage) as $entry) {
            if (!empty($entry['symlink'])) {
                throw new RuntimeException(
                    'Uploaded archives containing symbolic links are not supported.'
                );
            }

            $name = (string) (
                $entry['name']
                ?? ''
            );

            FileTransaction::path($name);

            if (str_contains($name, '/')) {
                throw new RuntimeException(
                    'Invalid uploaded archive entry.'
                );
            }

            if (empty($entry['file'])) {
                $candidates[] =
                    $stage
                    . '/'
                    . $name;
            }
        }

        foreach ($candidates as $candidate) {
            foreach (
                $this->deploymentRoots()
                as $root
            ) {
                if (
                    $files->stat(
                        $candidate
                        . '/'
                        . $root
                    ) !== null
                ) {
                    return $candidate;
                }
            }
        }

        throw new RuntimeException(
            'The uploaded ZIP does not contain a supported mod folder.'
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
                'The uploaded archive exceeds supported file or depth limits.'
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
                    'Unsafe uploaded archive entry.'
                );
            }

            $sourcePath =
                $source
                . '/'
                . $name;

            $targetPath =
                $target
                . '/'
                . $name;

            $this->validatePath(
                $targetPath
            );

            if (!empty($entry['file'])) {
                $plan[$targetPath] =
                    $sourcePath;

                continue;
            }

            $this->collect(
                $files,
                $sourcePath,
                $targetPath,
                $plan,
                $depth + 1
            );
        }
    }
}
