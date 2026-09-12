<?php

namespace GameNest\GameNestModManager\Services;

use GameNest\GameNestModManager\Contracts\LifecycleDriver;
use GameNest\GameNestModManager\Contracts\ModProvider;
use GameNest\GameNestModManager\Providers\UploadProvider;
use RuntimeException;

class RustUploadLifecycleDriver implements LifecycleDriver
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
    ): array {
        return [];
    }

    public function preservePath(
        string $path
    ): bool {
        return false;
    }

    public function validatePath(
        string $path
    ): void {
        $path = FileTransaction::path($path);

        if (
            !preg_match(
                '#^(carbon|oxide)/plugins/[^/]+\.cs$#i',
                $path
            )
        ) {
            throw new RuntimeException(
                'Uploaded Rust plugin path is outside the supported plugin location: '
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
        $id = (string) $id;

        $mod = $this->upload->get($id, $source);
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

        $extension = strtolower(
            pathinfo(
                $filename,
                PATHINFO_EXTENSION
            )
        );

        if ($extension === 'cs') {
            return $this->prepareCs(
                $id,
                $mod,
                $filename,
                $files,
                $source
            );
        }

        if ($extension === 'zip') {
            return $this->prepareZip(
                $id,
                $mod,
                $files,
                $source
            );
        }

        throw new RuntimeException(
            'Rust File Upload supports .cs plugins and .zip plugin bundles.'
        );
    }

    protected function prepareCs(
        string $id,
        array $mod,
        string $filename,
        FileTransaction $files,
        ?SourceContext $source = null
    ): array {
        if (
            !preg_match(
                '/^[A-Za-z0-9_.() +\-]+\.cs$/i',
                $filename
            )
        ) {
            throw new RuntimeException(
                'Invalid Rust plugin filename.'
            );
        }

        $targetRoot = app(
            RustRuntimeDetector::class
        )->pluginRoot($files);

        $target =
            $targetRoot
            . '/'
            . $filename;

        $this->validatePath($target);

        $stage =
            $files->root
            . '/staging/upload/'
            . $id
            . '/single';

        $files->mkdir($stage);

        $contents =
            $this->store->contents($id);

        if (str_contains($contents, "\0")) {
            throw new RuntimeException(
                'The uploaded Rust plugin does not appear to be valid text source.'
            );
        }

        $source =
            $stage
            . '/'
            . $filename;

        $files->repo->putContent(
            $source,
            $contents
        );

        $entry = $files->stat($source);

        if (
            $entry === null
            || empty($entry['file'])
            || !empty($entry['symlink'])
        ) {
            throw new RuntimeException(
                'The uploaded Rust plugin could not be staged safely.'
            );
        }

        $file =
            $mod['latest_file']
            ?? [];

        $file['id'] = $id;
        $file['version'] = (string) (
            $mod['latest_file']['version']
            ?? $mod['version']
            ?? 'Local'
        );

        return [
            'mod' => $mod,
            'file' => $file,
            'files' => [
                $target => $source,
            ],
        ];
    }

    protected function prepareZip(
        string $id,
        array $mod,
        FileTransaction $files,
        ?SourceContext $source = null
    ): array {
        $targetRoot = app(
            RustRuntimeDetector::class
        )->pluginRoot($files);

        $stage =
            $files->root
            . '/staging/upload/'
            . $id
            . '/archive';

        $files->mkdir($stage);

        $files->repo->putContent(
            $stage . '/archive.zip',
            $this->store->contents($id)
        );

        $files->repo->decompressFile(
            '/' . $stage,
            'archive.zip'
        );

        $files->repo->deleteFiles(
            '/' . $stage,
            ['archive.zip']
        );

        $plan = [];
        $seen = [];

        $this->collectCs(
            $files,
            $stage,
            $targetRoot,
            $plan,
            $seen,
            0
        );

        if ($plan === []) {
            throw new RuntimeException(
                'The uploaded ZIP does not contain any Rust .cs plugins.'
            );
        }

        // Use the actual plugin name for single-plugin ZIPs.
        // For bundles, use the archive/package name and clean
        // common GitHub branch suffixes.
        if (count($plan) === 1) {
            $targetPath = (string) array_key_first($plan);

            $mod['name'] = pathinfo(
                basename($targetPath),
                PATHINFO_FILENAME
            );
        } else {
            $packageName = (string) (
                $mod['name']
                ?? 'Uploaded Rust Bundle'
            );

            $packageName = preg_replace(
                '/-(?:main|master)$/i',
                '',
                $packageName
            ) ?: $packageName;

            $mod['name'] = $packageName;
        }

        $file =
            $mod['latest_file']
            ?? [];

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

    protected function collectCs(
        FileTransaction $files,
        string $directory,
        string $targetRoot,
        array &$plan,
        array &$seen,
        int $depth
    ): void {
        if ($depth > 8) {
            throw new RuntimeException(
                'Uploaded Rust ZIP exceeds the supported directory depth.'
            );
        }

        foreach (
            $files->listing($directory)
            as $entry
        ) {
            $name = (string) (
                $entry['name']
                ?? ''
            );

            if (
                $name === ''
                || $name === '.'
                || $name === '..'
            ) {
                continue;
            }

            if (!empty($entry['symlink'])) {
                throw new RuntimeException(
                    'Uploaded ZIPs containing symbolic links are not supported.'
                );
            }

            $source =
                $directory
                . '/'
                . $name;

            FileTransaction::path($source);

            if (!empty($entry['directory'])) {
                $this->collectCs(
                    $files,
                    $source,
                    $targetRoot,
                    $plan,
                    $seen,
                    $depth + 1
                );

                continue;
            }

            if (empty($entry['file'])) {
                continue;
            }

            if (
                strtolower(
                    pathinfo(
                        $name,
                        PATHINFO_EXTENSION
                    )
                ) !== 'cs'
            ) {
                continue;
            }

            if (
                !preg_match(
                    '/^[A-Za-z0-9_.() +\-]+\.cs$/i',
                    $name
                )
            ) {
                throw new RuntimeException(
                    'Invalid Rust plugin filename in uploaded ZIP.'
                );
            }

            $key = strtolower($name);

            if (isset($seen[$key])) {
                throw new RuntimeException(
                    'Uploaded ZIP contains duplicate Rust plugin filenames: '
                    . $name
                );
            }

            $seen[$key] = true;

            if (count($seen) > 100) {
                throw new RuntimeException(
                    'Uploaded ZIP contains too many Rust plugins.'
                );
            }

            $target =
                $targetRoot
                . '/'
                . $name;

            $this->validatePath($target);

            $plan[$target] =
                $source;
        }
    }
}
