<?php

namespace GameNest\GameNestModManager\Services;

use GameNest\GameNestModManager\Contracts\LifecycleDriver;
use GameNest\GameNestModManager\Contracts\ModProvider;
use GameNest\GameNestModManager\Providers\DirectDownloadProvider;
use RuntimeException;

class RustDirectLifecycleDriver implements LifecycleDriver
{
    public function __construct(
        protected DirectDownloadProvider $direct
    ) {}

    public function provider(): ModProvider
    {
        return $this->direct;
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
                'Direct Rust plugin path is outside the supported plugin locations: '
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
        $mod = $this->direct->get($id, $source);

        if (!$mod) {
            throw new RuntimeException(
                'Unable to prepare the direct download.'
            );
        }

        $file = $mod['latest_file'] ?? [];

        $filename = trim(
            (string) (
                $file['filename']
                ?? $file['name']
                ?? ''
            )
        );

        $url = $this->direct->validateUrl(
            (string) (
                $file['download_url']
                ?? ''
            )
        );

        $extension = strtolower(
            pathinfo(
                $filename,
                PATHINFO_EXTENSION
            )
        );

        if ($extension === 'cs') {
            return $this->prepareSinglePlugin(
                $id,
                $mod,
                $file,
                $url,
                $filename,
                $files,
                $source
            );
        }

        if ($extension === 'zip') {
            return $this->preparePluginArchive(
                $id,
                $mod,
                $file,
                $url,
                $files,
                $source
            );
        }

        throw new RuntimeException(
            'Rust Direct Download supports .cs plugins and .zip plugin bundles.'
        );
    }

    protected function prepareSinglePlugin(
        string|int $id,
        array $mod,
        array $file,
        string $url,
        string $filename,
        FileTransaction $files,
        ?SourceContext $source = null
    ): array {
        if (
            !preg_match(
                '/^[A-Za-z0-9_.-]+\.cs$/i',
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

        $targetPath =
            $targetRoot
            . '/'
            . $filename;

        $this->validatePath($targetPath);

        $stage =
            $files->root
            . '/staging/direct/'
            . sha1((string) $id)
            . '/single';

        $files->mkdir($stage);

        $this->downloadToStage(
            $url,
            $stage,
            $filename,
            $files
        );

        $stagedPath =
            $stage
            . '/'
            . $filename;

        $entry = $files->stat($stagedPath);

        if (
            $entry === null
            || empty($entry['file'])
            || !empty($entry['symlink'])
        ) {
            throw new RuntimeException(
                'Direct Rust plugin could not be staged safely.'
            );
        }

        $file['id'] = (string) $id;
        $file['version'] = 'Direct';

        return [
            'mod' => $mod,
            'file' => $file,
            'files' => [
                $targetPath => $stagedPath,
            ],
        ];
    }

    protected function preparePluginArchive(
        string|int $id,
        array $mod,
        array $file,
        string $url,
        FileTransaction $files,
        ?SourceContext $source = null
    ): array {
        $targetRoot = app(
            RustRuntimeDetector::class
        )->pluginRoot($files);

        $stage =
            $files->root
            . '/staging/direct/'
            . sha1((string) $id)
            . '/archive';

        $files->mkdir($stage);

        $this->downloadToStage(
            $url,
            $stage,
            'archive.zip',
            $files
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

        $this->collectPlugins(
            $files,
            $stage,
            $targetRoot,
            $plan,
            $seen,
            0
        );

        if ($plan === []) {
            throw new RuntimeException(
                'The Direct Download ZIP does not contain any Rust .cs plugins.'
            );
        }

        $file['id'] = (string) $id;
        $file['version'] = 'Direct';

        return [
            'mod' => $mod,
            'file' => $file,
            'files' => $plan,
        ];
    }

    /**
     * Download a Direct source through the Pelican panel and write it into
     * the normal ModHarbor transaction staging area.
     *
     * Wings remote pull requires Content-Length and therefore cannot handle
     * some otherwise valid HTTPS sources such as raw.githubusercontent.com.
     */
    protected function downloadToStage(
        string $url,
        string $stage,
        string $filename,
        FileTransaction $files
    ): void {
        $maxBytes = str_ends_with(
            strtolower($filename),
            '.zip'
        )
            ? 64 * 1024 * 1024
            : 8 * 1024 * 1024;

        $content = app(
            SafeRemoteDownloader::class
        )->download(
            $url,
            $maxBytes
        );

        if (
            str_ends_with(
                strtolower($filename),
                '.cs'
            )
            && str_contains(
                $content,
                "\0"
            )
        ) {
            throw new RuntimeException(
                'Direct Download returned invalid Rust plugin content.'
            );
        }

        try {
            $files->repo->putContent(
                $stage . '/' . $filename,
                $content
            );
        } catch (\Throwable $exception) {
            throw new RuntimeException(
                'Direct Download was retrieved but could not be staged safely.',
                0,
                $exception
            );
        }

        $entry = $files->stat(
            $stage . '/' . $filename
        );

        if (
            $entry === null
            || empty($entry['file'])
            || !empty($entry['symlink'])
        ) {
            throw new RuntimeException(
                'Direct Download could not be staged safely.'
            );
        }
    }

    protected function collectPlugins(
        FileTransaction $files,
        string $directory,
        string $targetRoot,
        array &$plan,
        array &$seen,
        int $depth
    ): void {
        if ($depth > 8) {
            throw new RuntimeException(
                'Direct Download ZIP exceeds the supported directory depth.'
            );
        }

        $directory = FileTransaction::path($directory);

        $entries = $files->repo->getDirectory(
            '/' . ltrim($directory, '/')
        );

        foreach ($entries as $entry) {
            $name = trim(
                (string) (
                    $entry['name']
                    ?? ''
                )
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
                    'Direct Download ZIPs containing symbolic links are not supported.'
                );
            }

            $sourcePath = FileTransaction::path(
                $directory
                . '/'
                . $name
            );

            if (!empty($entry['directory'])) {
                $this->collectPlugins(
                    $files,
                    $sourcePath,
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
                    '/^[A-Za-z0-9_.-]+\.cs$/i',
                    $name
                )
            ) {
                throw new RuntimeException(
                    'Direct Download ZIP contains an invalid Rust plugin filename.'
                );
            }

            $key = strtolower($name);

            if (isset($seen[$key])) {
                throw new RuntimeException(
                    'Direct Download ZIP contains duplicate Rust plugin filenames: '
                    . $name
                );
            }

            $seen[$key] = true;

            if (count($seen) > 100) {
                throw new RuntimeException(
                    'Direct Download ZIP contains too many Rust plugin files.'
                );
            }

            $targetPath =
                $targetRoot
                . '/'
                . $name;

            $this->validatePath($targetPath);

            $plan[$targetPath] =
                $sourcePath;
        }
    }
}
