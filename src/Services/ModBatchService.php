<?php

namespace GameNest\GameNestModManager\Services;

use App\Models\Server;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Throwable;

/** Each package and its required dependencies is atomic; batches stop on the first failure. */
class ModBatchService
{
    public function run(Server $server, string $action, array $keys): array
    {
        foreach (['file.read', 'file.read-content', 'file.create', 'file.update', 'file.delete'] as $permission) {
            Gate::authorize($permission, $server);
        }
        if (!in_array($action, ['update', 'reinstall', 'enable', 'disable', 'remove'], true)
            || count($keys) > 100 || !$keys || count(array_filter($keys, 'is_string')) !== count($keys)) {
            throw new RuntimeException('Select 1–100 installed mods and a supported action.');
        }
        $keys = array_values(array_unique($keys));
        $mods = app(ManifestService::class)->mods($server);
        if (array_diff($keys, array_keys($mods))) { throw new RuntimeException('Selection contains a mod that is no longer installed. Refresh the list.'); }
        // Parents before dependencies for removal/disable; reverse for enable.
        $ordered = []; $visiting = [];
        $visit = function ($key) use (&$visit, &$ordered, &$visiting, $keys, $mods): void {
            if (in_array($key, $ordered, true)) { return; }
            if (isset($visiting[$key])) { throw new RuntimeException('Installed dependency cycle requires review.'); }
            $visiting[$key] = true;
            foreach ($mods[$key]['required_by'] ?? [] as $parent) {
                if (in_array($parent, $keys, true)) { $visit($parent); }
            }
            unset($visiting[$key]); $ordered[] = $key;
        };
        foreach ($keys as $key) { $visit($key); }
        if ($action === 'enable') { $ordered = array_reverse($ordered); }
        $result = ['completed' => [], 'skipped' => [], 'failed' => null, 'remaining' => []];
        $lifecycle = app(ModLifecycleService::class);
        foreach ($ordered as $index => $key) {
            try {
                $current = app(ManifestService::class)->mods($server);
                // A prior parent removal may already have removed an orphan dependency.
                if (!isset($current[$key]) && $action === 'remove') { $result['skipped'][] = $key; continue; }
                if ($action === 'update') {
                    $updates = $lifecycle->updates($server);
                    if (($updates[$key]['status'] ?? '') === 'Check failed — try again') { throw new RuntimeException('Update check failed.'); }
                    if (empty($updates[$key]['available'])) { $result['skipped'][] = $key; continue; }
                }
                $lifecycle->run($server, $action, $key);
                $result['completed'][] = $key;
            } catch (Throwable) {
                $result['failed'] = $key;
                $result['remaining'] = array_slice($ordered, $index + 1);
                break;
            }
        }
        return $result;
    }
}
