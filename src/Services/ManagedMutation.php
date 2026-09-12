<?php

namespace GameNest\GameNestModManager\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use App\Repositories\Daemon\DaemonServerRepository;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Throwable;

/** The same lock and move journal protect adoption and configuration commits. */
class ManagedMutation
{
    public static function stopped(Server $server): void
    {
        if ((app(DaemonServerRepository::class)->setServer($server)->getDetails()['state'] ?? '') !== 'offline') {
            throw new RuntimeException('Stop the game server before changing managed files.');
        }
    }

    public function run(Server $server, string $action, callable $callback): array
    {
        foreach (['file.read', 'file.read-content', 'file.create', 'file.update', 'file.delete'] as $permission) {
            Gate::authorize($permission, $server);
        }
        $store = app(OperationStore::class);
        return $store->exclusive($server, function () use ($server, $action, $callback, $store) {
            self::stopped($server);
            DiskSpaceGuard::server($server, 0);
            $id = bin2hex(random_bytes(12));
            $journal = ['id' => $id, 'action' => $action, 'name' => $action, 'status' => 'running',
                'started_at' => now()->toIso8601String(), 'started_us' => microtime(true),
                'actor' => (string) (auth()->id() ?? 'system'), 'moves' => [], 'changes' => [],
                'manifest_before' => app(ManifestService::class)->read($server), 'message' => 'Operation started.'];
            $store->save($server, $journal);
            $files = new FileTransaction(app(DaemonFileRepository::class)->setServer($server),
                '.gamenest/mod-manager/operations/' . $id, function ($moves) use ($store, $server, &$journal) {
                    $journal['moves'] = $moves; $store->save($server, $journal);
                });
            try {
                $files->mkdir($files->root);
                $callback($files, $journal);
                self::stopped($server);
                $journal['status'] = 'completed';
                $journal['message'] = 'Completed and verified.';
                $journal['finished_at'] = now()->toIso8601String();
                $journal['duration_ms'] = round(
                    max(0, microtime(true) - (float) $journal['started_us']) * 1000,
                    2
                );
                $store->save($server, $journal);
            } catch (Throwable $exception) {
                $journal['status'] = 'failed';
                $journal['message'] = 'Operation failed; changes rolled back.';
                $journal['error_type'] = get_class($exception);
                try { $files->rollback(); } catch (Throwable) {
                    $journal['status'] = 'recovery_required';
                    $journal['message'] = 'Rollback incomplete. Keep the server stopped and retry recovery.';
                }
                $journal['finished_at'] = now()->toIso8601String();
                $journal['duration_ms'] = round(
                    max(0, microtime(true) - (float) $journal['started_us']) * 1000,
                    2
                );
                $store->save($server, $journal);
                if ($journal['status'] === 'failed') { try { $files->cleanup(); } catch (Throwable) {} }
                $detail = '';
                foreach (['Configuration changed', 'TOML editing requires', 'YAML editing requires', 'Invalid INI', 'outside adapter-approved'] as $safeMessage) {
                    if (get_class($exception) === RuntimeException::class && str_contains($exception->getMessage(), $safeMessage)) {
                        $detail = ' ' . $exception->getMessage();
                    }
                }
                throw new RuntimeException($journal['message'] . $detail);
            }
            try { $files->cleanup(); } catch (Throwable) {}
            return $journal;
        });
    }

    public static function replace(FileTransaction $files, string $path, string $contents): void
    {
        $stage = $files->root . '/new/' . hash('sha256', $path);
        $files->mkdir(dirname($stage));
        $files->repo->putContent($stage, $contents);
        if ($files->repo->getContent($stage, max(1, strlen($contents) + 1)) !== $contents) {
            throw new RuntimeException('Staged content verification failed.');
        }
        if ($files->stat($path) !== null) { $files->move($path, $files->root . '/before/' . hash('sha256', $path)); }
        $files->move($stage, $path);
        if ($files->repo->getContent($path, max(1, strlen($contents) + 1)) !== $contents) {
            throw new RuntimeException('Written content verification failed.');
        }
    }
}
