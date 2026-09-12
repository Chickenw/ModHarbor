<?php

namespace GameNest\GameNestModManager\Services;

use App\Models\Server;
use GameNest\GameNestModManager\Adapters\ConfiguredGameAdapter;
use GameNest\GameNestModManager\Contracts\ModProvider;
use RuntimeException;

/** Workshop files enter the same journaled lifecycle as archive packages. */
class SteamWorkshopLifecycleDriver extends ConfiguredLifecycleDriver
{
    public function __construct(private Server $workshopServer, private ConfiguredGameAdapter $workshopAdapter, ModProvider $provider)
    {
        parent::__construct($workshopServer, $workshopAdapter, $provider);
    }
    public function prepare(string|int $id, string|int|null $fileId, FileTransaction $files, ?SourceContext $source = null): array
    {
        if (!$source || $source->key() !== $this->provider()->key() || $source->gameKey() !== $this->workshopAdapter->key()) { throw new RuntimeException('Workshop source context mismatch.'); }
        $mod = $this->provider()->get($id, $source);
        if (!$mod || empty($mod['latest_file']['id'])) { throw new RuntimeException('Workshop item is unavailable.'); }
        if ($fileId !== null && (string) $fileId !== $mod['latest_file']['id']) { throw new RuntimeException('Steam Workshop cannot retrieve an older revision.'); }
        $contents = app(SteamCmdDownloader::class)->download((string) $source->require('app_id'), (string) $mod['id']);
        $latest = $this->provider()->get($id, $source);
        if (($latest['latest_file']['id'] ?? null) !== $mod['latest_file']['id']) { throw new RuntimeException('Workshop item changed during download. Retry.'); }
        $target = $this->workshopAdapter->definition()['deployment']['target'];
        $prefix = (string) $source->value('content_prefix', '');
        if ($prefix !== '') { GameDefinition::path($prefix); }
        $subdirectory = $source->value('item_subdirectory', true);
        DiskSpaceGuard::server($this->workshopServer, array_sum(array_map('strlen', $contents)));
        $stage = $files->root . '/staging/workshop/' . bin2hex(random_bytes(8)); $files->mkdir($stage);
        $staged = [];
        foreach ($contents as $relative => $body) {
            FileTransaction::path($relative);
            if ($prefix !== '') {
                if (!str_starts_with($relative, $prefix . '/')) { continue; }
                $relative = substr($relative, strlen($prefix) + 1);
            }
            $path = $target . '/' . ($subdirectory ? $mod['id'] . '/' : '') . $relative;
            $this->validatePath($path);
            $temporary = $stage . '/' . hash('sha256', $path);
            $files->repo->putContent($temporary, $body);
            if ($files->repo->getContent($temporary, max(1, strlen($body) + 1)) !== $body) { throw new RuntimeException('Workshop staging verification failed.'); }
            $staged[$path] = $temporary;
        }
        if (!$staged) { throw new RuntimeException('Workshop package does not match the configured content prefix.'); }
        return ['mod' => $mod, 'file' => $mod['latest_file'], 'files' => $staged, 'dependency_specs' => $mod['dependencies'] ?? []];
    }
}
