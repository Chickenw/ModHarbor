<?php

namespace GameNest\GameNestModManager\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonServerRepository;
use RuntimeException;

class DiskSpaceGuard
{
    /** Pelican quotas are MiB; Wings reports current usage in bytes. Unlimited quotas need node monitoring. */
    public static function server(Server $server, int $additionalBytes): void
    {
        if ($additionalBytes < 0) { throw new RuntimeException('Invalid staging size.'); }
        $quota = (int) ($server->disk ?? 0) * 1024 * 1024;
        if ($quota <= 0) { return; }
        $details = app(DaemonServerRepository::class)->setServer($server)->getDetails();
        $usage = $details['resources']['disk_bytes'] ?? $details['utilization']['disk_bytes'] ?? null;
        if (!is_numeric($usage) || $usage < 0) { throw new RuntimeException('Cannot verify server disk usage. Retry after Wings reports resources.'); }
        if ($quota - (int) $usage < $additionalBytes + 2 * 1024 * 1024) {
            throw new RuntimeException('Insufficient server quota for staging and the operation journal. Free space before retrying.');
        }
    }

    public static function local(string $directory, int $additionalBytes): void
    {
        $free = disk_free_space($directory);
        if ($free === false || $free < $additionalBytes + 2 * 1024 * 1024) {
            throw new RuntimeException('Insufficient panel temporary disk space for package inspection.');
        }
    }
}
