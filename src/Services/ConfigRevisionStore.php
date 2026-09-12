<?php

namespace GameNest\GameNestModManager\Services;

use App\Models\Server;
use RuntimeException;

class ConfigRevisionStore
{
    protected const KEEP = 5;

    protected function directory(
        Server $server,
        string $path
    ): string {
        $directory =
            storage_path(
                'app/gamenest-mod-manager/config-revisions/'
                . hash('sha256', (string) $server->uuid)
                . '/'
                . hash('sha256', $path)
            );

        if (
            !is_dir($directory)
            && !mkdir(
                $directory,
                0750,
                true
            )
            && !is_dir($directory)
        ) {
            throw new RuntimeException(
                'Unable to create config revision storage.'
            );
        }

        return $directory;
    }

    public function backup(
        Server $server,
        string $path,
        string $contents
    ): array {
        if (strlen($contents) > 1024 * 1024) {
            throw new RuntimeException(
                'Configuration is too large to back up safely.'
            );
        }

        $id =
            now()->format('YmdHis')
            . '-'
            . bin2hex(random_bytes(4));

        $entry = [
            'id' => $id,
            'path' => $path,
            'created_at' => now()->toIso8601String(),
            'size' => strlen($contents),
            'content' => base64_encode($contents),
        ];

        $directory =
            $this->directory(
                $server,
                $path
            );

        $json = json_encode(
            $entry,
            JSON_PRETTY_PRINT
            | JSON_UNESCAPED_SLASHES
            | JSON_THROW_ON_ERROR
        );

        $target =
            $directory
            . '/'
            . $id
            . '.json';

        if (
            file_put_contents(
                $target . '.tmp',
                $json,
                LOCK_EX
            ) === false
            || !rename(
                $target . '.tmp',
                $target
            )
        ) {
            throw new RuntimeException(
                'Unable to save the config revision.'
            );
        }

        $this->prune(
            $server,
            $path
        );

        return $entry;
    }

    public function listing(
        Server $server,
        string $path
    ): array {
        $entries = [];

        foreach (
            glob(
                $this->directory(
                    $server,
                    $path
                )
                . '/*.json'
            ) ?: []
            as $file
        ) {
            try {
                $entry = json_decode(
                    file_get_contents($file),
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );

                if (
                    !is_array($entry)
                    || ($entry['path'] ?? null) !== $path
                    || empty($entry['id'])
                ) {
                    continue;
                }

                unset($entry['content']);

                $entries[] = $entry;
            } catch (\Throwable) {
            }
        }

        usort(
            $entries,
            static fn (array $a, array $b): int =>
                strcmp(
                    (string) ($b['created_at'] ?? ''),
                    (string) ($a['created_at'] ?? '')
                )
        );

        return $entries;
    }

    public function contents(
        Server $server,
        string $path,
        string $id
    ): string {
        if (
            !preg_match(
                '/^[0-9]{14}-[a-f0-9]{8}$/D',
                $id
            )
        ) {
            throw new RuntimeException(
                'Invalid config revision.'
            );
        }

        $file =
            $this->directory(
                $server,
                $path
            )
            . '/'
            . $id
            . '.json';

        if (!is_file($file)) {
            throw new RuntimeException(
                'The selected config revision no longer exists.'
            );
        }

        $entry = json_decode(
            file_get_contents($file),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        if (
            !is_array($entry)
            || ($entry['path'] ?? null) !== $path
            || !isset($entry['content'])
        ) {
            throw new RuntimeException(
                'Invalid config revision data.'
            );
        }

        $contents = base64_decode(
            (string) $entry['content'],
            true
        );

        if ($contents === false) {
            throw new RuntimeException(
                'Invalid config revision contents.'
            );
        }

        return $contents;
    }

    protected function prune(
        Server $server,
        string $path
    ): void {
        $files =
            glob(
                $this->directory(
                    $server,
                    $path
                )
                . '/*.json'
            ) ?: [];

        rsort(
            $files,
            SORT_STRING
        );

        foreach (
            array_slice(
                $files,
                self::KEEP
            )
            as $file
        ) {
            @unlink($file);
        }
    }
}
