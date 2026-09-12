<?php

namespace GameNest\GameNestModManager\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use GameNest\GameNestModManager\Contracts\ManagedFilesAdapter;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

class ConfigManagementService
{
    public function listing(
        Server $server,
        ?array $mods = null,
        ?array &$directorySnapshot = null
    ): array {
        return app(ManagedFileScanner::class)->scan(
            $server,
            true,
            $mods,
            $directorySnapshot
        );
    }

    public function read(Server $server, string $path): string
    {
        Gate::authorize('file.read-content', $server);
        FileTransaction::path($path);
        if (!in_array($path, array_column($this->listing($server), 'path'), true)) {
            throw new RuntimeException('Configuration is outside adapter-approved discovery rules.');
        }
        $repo = app(DaemonFileRepository::class)->setServer($server);
        $files = new FileTransaction($repo, '.gamenest/mod-manager/config-check', static function ($moves): void {});
        if (empty($files->stat($path)['file'])) { throw new RuntimeException('Configuration must be a regular file.'); }
        $contents = $repo->getContent($path, 1024 * 1024);
        if (strlen($contents) > 1024 * 1024 || str_contains($contents, "\0")) {
            throw new RuntimeException('Configuration must be text no larger than 1 MB.');
        }
        return $contents;
    }

    public function validate(Server $server, string $path, string $contents): void
    {
        if (strlen($contents) > 1024 * 1024 || str_contains($contents, "\0")) {
            throw new RuntimeException('Configuration must be text no larger than 1 MB.');
        }
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($extension === 'json') { json_decode($contents, true, 512, JSON_THROW_ON_ERROR); }
        if ($extension === 'ini' && @parse_ini_string($contents, true, INI_SCANNER_RAW) === false) {
            throw new RuntimeException('Invalid INI configuration.');
        }
        if (in_array($extension, ['yaml', 'yml'], true)) {
            if (class_exists(\Symfony\Component\Yaml\Yaml::class)) {
                \Symfony\Component\Yaml\Yaml::parse($contents);
            } elseif (function_exists('yaml_parse')) {
                if (@yaml_parse($contents) === false && trim($contents) !== 'false') { throw new RuntimeException('Invalid YAML.'); }
            } else { throw new RuntimeException('YAML editing requires Symfony YAML or the YAML extension.'); }
        }
        if ($extension === 'toml') {
            $validator = config('gamenest-mod-manager.config_validators.toml');
            if ($validator) { app($validator)->validate($contents); }
            elseif (class_exists(\Yosymfony\Toml\Toml::class)) { \Yosymfony\Toml\Toml::parse($contents); }
            else { throw new RuntimeException('TOML editing requires yosymfony/toml or a configured TOML validator.'); }
        }
        if ($extension === 'xml') {
            if (!class_exists(\DOMDocument::class)) { throw new RuntimeException('XML editing requires the DOM extension.'); }
            if (preg_match('/<!DOCTYPE|<!ENTITY/i', $contents)) { throw new RuntimeException('XML declarations with external entities are not allowed.'); }
            $previous = libxml_use_internal_errors(true);
            try {
                $document = new \DOMDocument;
                if (!$document->loadXML($contents, LIBXML_NONET)) { throw new RuntimeException('Invalid XML configuration.'); }
            } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        }
        $adapter = app(AdapterRegistry::class)->forServer($server);
        if ($adapter instanceof ManagedFilesAdapter) { $adapter->validateConfig($path, $contents); }
    }

    public function save(Server $server, string $path, string $contents, string $expectedHash, ?string $revision = null): array
    {
        return app(ManagedMutation::class)->run($server, $revision === null ? 'config-save' : 'config-restore',
            function ($files, &$journal) use ($server, $path, $contents, $expectedHash, $revision) {
                $current = $this->read($server, $path);
                if (!hash_equals(hash('sha256', $current), $expectedHash)) {
                    throw new RuntimeException('Configuration changed since opening. Reload before saving.');
                }
                if ($revision !== null) { $contents = app(ConfigRevisionStore::class)->contents($server, $path, $revision); }
                $this->validate($server, $path, $contents);
                $adapter = app(AdapterRegistry::class)->forServer($server);
                $journal['restart_required'] = $current !== $contents
                    && ($adapter?->metadata()['behavior']['restart_required'] ?? true);
                if ($current !== $contents) {
                    DiskSpaceGuard::server($server, strlen($contents));
                    app(ConfigRevisionStore::class)->backup($server, $path, $current);
                    if ($this->read($server, $path) !== $current) { throw new RuntimeException('Configuration changed during save.'); }
                    ManagedMutation::stopped($server);
                    ManagedMutation::replace($files, $path, $contents);
                }
                $journal['name'] = basename($path); $journal['affected_files'] = [$path];
                $journal['changes'] = [basename($path)];
            });
    }

    public function health(
        Server $server,
        ?array $mods = null,
        ?array &$directorySnapshot = null
    ): array {
        $rows = [];
        $repo = app(DaemonFileRepository::class)->setServer($server);

        foreach (
            array_slice(
                $this->listing(
                    $server,
                    $mods,
                    $directorySnapshot
                ),
                0,
                100
            ) as $row
        ) {
            try {
                $this->validate($server, $row['path'], $repo->getContent($row['path'], 1024 * 1024));
                $row['validation'] = 'valid';
            }
            catch (\Throwable) { $row['validation'] = 'Review format, content or validator availability'; }
            $rows[] = $row;
        }
        return $rows;
    }
}
