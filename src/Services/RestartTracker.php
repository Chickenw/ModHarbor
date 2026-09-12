<?php

namespace GameNest\GameNestModManager\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonServerRepository;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/** Journal-derived state survives reloads and batches changes without sending power commands. */
class RestartTracker
{
    public function pending(Server $server): array
    {
        Gate::authorize('file.read', $server);
        Gate::authorize('file.read-content', $server);
        $entries = app(OperationStore::class)->all($server);
        $acknowledged = [];
        foreach ($entries as $entry) {
            if ($entry['status'] === 'completed' && ($entry['action'] ?? '') === 'restart-confirmed') {
                $acknowledged = array_merge($acknowledged, $entry['acknowledged_operations'] ?? []);
            }
        }
        return array_values(array_map([AuditTrail::class, 'display'], array_filter($entries, fn ($entry) =>
            $entry['status'] === 'completed' && !empty($entry['restart_required'])
            && !in_array($entry['id'], $acknowledged, true))));
    }

    public function confirm(Server $server): void
    {
        Gate::authorize('control.start', $server);
        app(OperationStore::class)->exclusive($server, function () use ($server) {
            $pending = $this->pending($server);
            if (!$pending) { return; }
            if ((app(DaemonServerRepository::class)->setServer($server)->getDetails()['state'] ?? '') !== 'running') {
                throw new RuntimeException('Start the server after your changes before confirming.');
            }
            app(OperationStore::class)->save($server, [
                'id' => bin2hex(random_bytes(12)), 'action' => 'restart-confirmed', 'name' => 'Server start confirmed',
                'status' => 'completed', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
                'started_us' => microtime(true), 'actor' => (string) (auth()->id() ?? 'system'),
                'message' => 'User confirmed the server was started after the pending changes.',
                'acknowledged_operations' => array_column($pending, 'id'), 'moves' => [],
            ]);
        });
    }
}
