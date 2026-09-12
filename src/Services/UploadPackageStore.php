<?php

namespace GameNest\GameNestModManager\Services;

use App\Models\Server;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RuntimeException;

class UploadPackageStore
{
    protected const DISK = 'local';

    protected const ROOT =
        'gamenest-mod-manager/uploads';

    public function store(
        Server $server,
        TemporaryUploadedFile $upload,
        string $versionLabel = ''
    ): array {
        $filename = basename(
            $upload->getClientOriginalName()
        );

        $this->validateFilename($filename);

        $versionLabel =
            $this->normalizeVersionLabel(
                $versionLabel
            );

        $size = (int) $upload->getSize();

        if ($size < 1) {
            throw new RuntimeException(
                'The uploaded package is empty.'
            );
        }

        if ($size > 128 * 1024 * 1024) {
            throw new RuntimeException(
                'Uploaded packages are limited to 128 MB.'
            );
        }

        $extension = strtolower(
            pathinfo(
                $filename,
                PATHINFO_EXTENSION
            )
        );

        $id = bin2hex(random_bytes(20));

        $directory =
            self::ROOT
            . '/'
            . $server->uuid
            . '/'
            . $id;

        $packagePath =
            $directory
            . '/'
            . $filename;

        $metadataPath =
            $directory
            . '/package.json';

        $stream = fopen(
            $upload->getRealPath(),
            'rb'
        );

        if ($stream === false) {
            throw new RuntimeException(
                'Unable to read the uploaded package.'
            );
        }

        try {
            $written = Storage::disk(self::DISK)
                ->put(
                    $packagePath,
                    $stream
                );
        } finally {
            fclose($stream);
        }

        if (!$written) {
            throw new RuntimeException(
                'Unable to retain the uploaded package.'
            );
        }

        $metadata = [
            'id' => $id,
            'server_uuid' => $server->uuid,
            'name' => pathinfo(
                $filename,
                PATHINFO_FILENAME
            ),
            'filename' => $filename,
            'extension' => $extension,
            'size' => $size,
            'package_path' => $packagePath,
            'version_label' => $versionLabel,
            'source_type' => 'upload',
            'created_at' => now()->toIso8601String(),
        ];

        Storage::disk(self::DISK)->put(
            $metadataPath,
            json_encode(
                $metadata,
                JSON_PRETTY_PRINT
                | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR
            )
        );

        return $metadata;
    }

    public function get(string $id): ?array
    {
        if (
            !preg_match(
                '/^[a-f0-9]{40}$/',
                $id
            )
        ) {
            return null;
        }

        $root =
            storage_path(
                'app/private/'
                . self::ROOT
            );

        if (!is_dir($root)) {
            return null;
        }

        $matches = glob(
            $root
            . '/*/'
            . $id
            . '/package.json'
        );

        if (
            !is_array($matches)
            || count($matches) !== 1
        ) {
            return null;
        }

        $metadata = json_decode(
            file_get_contents($matches[0]),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        if (
            !is_array($metadata)
            || ($metadata['id'] ?? '') !== $id
        ) {
            return null;
        }

        return $metadata;
    }

    public function contents(
        string $id
    ): string {
        $package = $this->get($id);

        if ($package === null) {
            throw new RuntimeException(
                'The retained upload could not be found.'
            );
        }

        $path = (string) (
            $package['package_path']
            ?? ''
        );

        if (
            $path === ''
            || !Storage::disk(self::DISK)
                ->exists($path)
        ) {
            throw new RuntimeException(
                'The retained upload file is missing.'
            );
        }

        $contents = Storage::disk(self::DISK)
            ->get($path);

        if ($contents === '') {
            throw new RuntimeException(
                'The retained upload is empty.'
            );
        }

        return $contents;
    }


    public function storeContents(
        Server $server,
        string $filename,
        string $contents,
        string $versionLabel = 'Adopted'
    ): array {
        $filename = basename($filename);

        $this->validateFilename($filename);

        $versionLabel =
            $this->normalizeVersionLabel(
                $versionLabel
            );

        $size = strlen($contents);

        if ($size < 1) {
            throw new RuntimeException(
                'The existing plugin file is empty.'
            );
        }

        if ($size > 128 * 1024 * 1024) {
            throw new RuntimeException(
                'Retained packages are limited to 128 MB.'
            );
        }

        $extension = strtolower(
            pathinfo(
                $filename,
                PATHINFO_EXTENSION
            )
        );

        $id = bin2hex(random_bytes(20));

        $directory =
            self::ROOT
            . '/'
            . $server->uuid
            . '/'
            . $id;

        $packagePath =
            $directory
            . '/'
            . $filename;

        $metadataPath =
            $directory
            . '/package.json';

        if (
            !Storage::disk(self::DISK)
                ->put(
                    $packagePath,
                    $contents
                )
        ) {
            throw new RuntimeException(
                'Unable to retain the existing plugin file.'
            );
        }

        $metadata = [
            'id' => $id,
            'server_uuid' => $server->uuid,
            'name' => pathinfo(
                $filename,
                PATHINFO_FILENAME
            ),
            'filename' => $filename,
            'extension' => $extension,
            'size' => $size,
            'package_path' => $packagePath,
            'version_label' => $versionLabel,
            'source_type' => 'adopted',
            'created_at' => now()->toIso8601String(),
        ];

        Storage::disk(self::DISK)->put(
            $metadataPath,
            json_encode(
                $metadata,
                JSON_PRETTY_PRINT
                | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR
            )
        );

        return $metadata;
    }

    public function delete(
        string $id,
        ?string $serverUuid = null
    ): bool {
        $package = $this->get($id);

        if ($package === null) {
            return false;
        }

        if (
            $serverUuid !== null
            && ($package['server_uuid'] ?? null)
                !== $serverUuid
        ) {
            throw new RuntimeException(
                'The retained upload belongs to another server.'
            );
        }

        $uuid = (string) (
            $package['server_uuid']
            ?? ''
        );

        if (
            $uuid === ''
            || !preg_match(
                '/^[A-Za-z0-9-]+$/D',
                $uuid
            )
        ) {
            throw new RuntimeException(
                'The retained upload metadata is invalid.'
            );
        }

        $directory =
            self::ROOT
            . '/'
            . $uuid
            . '/'
            . $id;

        return Storage::disk(self::DISK)
            ->deleteDirectory($directory);
    }

    protected function normalizeVersionLabel(
        string $versionLabel
    ): string {
        $versionLabel = trim($versionLabel);

        if ($versionLabel === '') {
            return 'Local';
        }

        if (
            strlen($versionLabel) > 64
            || preg_match(
                '/[\x00-\x1f\x7f]/',
                $versionLabel
            )
        ) {
            throw new RuntimeException(
                'The local version label is invalid.'
            );
        }

        return $versionLabel;
    }

    protected function validateFilename(
        string $filename
    ): void {
        if (
            $filename === ''
            || $filename === '.'
            || $filename === '..'
            || strlen($filename) > 180
            || !preg_match(
                '/^[A-Za-z0-9][A-Za-z0-9._() +\-]*$/',
                $filename
            )
        ) {
            throw new RuntimeException(
                'The uploaded package has an unsupported filename.'
            );
        }
    }
}
