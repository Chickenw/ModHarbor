<?php

namespace GameNest\GameNestModManager\Services;

use App\Models\Server;
use RuntimeException;

/** Panel-side journals also serve as history. Never store credentials or download URLs. */
class OperationStore
{
    public function directory(Server $server): string
    {
        $path = storage_path('app/gamenest-mod-manager/operations/' . hash('sha256', $server->uuid));
        if (!is_dir($path) && !mkdir($path, 0750, true) && !is_dir($path)) {
            throw new RuntimeException('Unable to create the mod operation journal.');
        }
        return $path;
    }

    public function exclusive(Server $server, callable $callback): mixed
    {
        $handle = fopen($this->directory($server) . '/operation.lock', 'c');
        if (!$handle) {
            throw new RuntimeException('Unable to open the mod operation lock.');
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new RuntimeException('Another mod operation is running for this server. Try again after it finishes.');
        }
        try {
            foreach ($this->all($server) as $entry) {
                if (in_array($entry['status'], ['running', 'recovery_required'], true)) {
                    throw new RuntimeException('An interrupted mod operation needs administrator recovery. See History; files and recovery journal have been retained.');
                }
            }
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Acquire the normal per-server operation lock specifically for recovery.
     *
     * Recovery is only permitted when exactly one unresolved operation exists.
     * This prevents guessing about ordering if journals ever become inconsistent.
     */
    public function recoveryExclusive(
        Server $server,
        string $operationId,
        callable $callback
    ): mixed {
        if (!preg_match('/^[a-f0-9]{24}$/D', $operationId)) {
            throw new RuntimeException(
                'Invalid recovery operation reference.'
            );
        }

        $handle = fopen(
            $this->directory($server) . '/operation.lock',
            'c'
        );

        if (!$handle) {
            throw new RuntimeException(
                'Unable to open the mod operation lock.'
            );
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            throw new RuntimeException(
                'Another mod operation is currently active. Wait for it to finish before attempting recovery.'
            );
        }

        try {
            $unresolved = [];

            foreach ($this->all($server) as $entry) {
                if (
                    in_array(
                        (string) ($entry['status'] ?? ''),
                        ['running', 'recovery_required'],
                        true
                    )
                ) {
                    $unresolved[] = $entry;
                }
            }

            if (count($unresolved) !== 1) {
                throw new RuntimeException(
                    count($unresolved) === 0
                        ? 'This operation no longer requires recovery.'
                        : 'Multiple unresolved operations exist. Automatic recovery cannot safely determine their ordering.'
                );
            }

            $entry = $unresolved[0];

            if (
                !hash_equals(
                    (string) ($entry['id'] ?? ''),
                    $operationId
                )
            ) {
                throw new RuntimeException(
                    'The requested recovery operation does not match the active recovery journal.'
                );
            }

            return $callback($entry);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function save(Server $server, array $entry): void
    {
        if (!is_string($entry['id'] ?? null) || !preg_match('/^[a-f0-9]{24}$/D', $entry['id'])) {
            throw new RuntimeException('Invalid operation journal reference.');
        }
        $path = $this->directory($server) . '/' . $entry['id'] . '.json';
        $json = json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($path . '.tmp', $json, LOCK_EX) === false || !rename($path . '.tmp', $path)) {
            throw new RuntimeException('Unable to save the mod operation journal.');
        }
    }

    public function all(Server $server): array
    {
        $entries = [];
        foreach (glob($this->directory($server) . '/*.json') ?: [] as $path) {
            $entry = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($entry) || !in_array($entry['status'] ?? '', ['running', 'recovery_required', 'failed', 'completed', 'recovered'], true)
                || !is_string($entry['id'] ?? null) || !preg_match('/^[a-f0-9]{24}$/D', $entry['id'])
                || basename($path, '.json') !== $entry['id'] || !is_string($entry['started_at'] ?? null)
                || (isset($entry['moves']) && !is_array($entry['moves']))) {
                throw new RuntimeException('Invalid operation journal; administrator review is required.');
            }
            $entries[] = $entry;
        }
        usort($entries, fn ($a, $b) => ($b['started_us'] ?? 0) <=> ($a['started_us'] ?? 0)
            ?: strcmp($b['started_at'], $a['started_at']));
        return $entries;
    }

    public function history(Server $server, string $search = '', string $action = 'all', string $status = 'all'): array
    {
        $rows = array_map([AuditTrail::class, 'display'], $this->all($server));
        $rows = array_filter($rows, fn ($row) => ($action === 'all' || $row['action'] === $action)
            && ($status === 'all' || $row['status'] === $status)
            && ($search === '' || str_contains(strtolower(json_encode($row)), strtolower($search))));
        return array_slice(array_values($rows), 0, 100);
    }

    public function unresolved(Server $server): array
    {
        return array_values(array_filter(array_map([AuditTrail::class, 'display'], $this->all($server)),
            fn ($entry) => in_array($entry['status'], ['running', 'recovery_required'], true)));
    }
}
