<?php

namespace GameNest\GameNestModManager\Services;

use GameNest\GameNestModManager\Contracts\LifecycleDriver;
use GameNest\GameNestModManager\Contracts\ModProvider;
use GameNest\GameNestModManager\Providers\ModIoProvider;
use RuntimeException;

class EcoLifecycleDriver implements LifecycleDriver
{
    public function __construct(protected ModIoProvider $modio) {}

    public function provider(): ModProvider { return $this->modio; }

    public function dependencies(
        string|int $id,
        ?SourceContext $source = null
    ): array
    {
        return array_values(array_unique(array_map(fn ($entry) => (int) $entry['mod_id'], $this->modio->dependencies(
            $this->id($id),
            $source
        ))));
    }

    public function validatePath(string $path): void
    {
        FileTransaction::path($path);
        if (!preg_match('#^(Mods|UserCode|Configs)/.+#', $path)
            || preg_match('#^Mods/(__core__|Eco\.[^/]+)(/|$)#i', $path)) {
            throw new RuntimeException('Path is outside the supported Eco mod locations: ' . $path);
        }
    }

    public function preservePath(string $path): bool
    {
        // Runtime/user configuration is never moved or deleted. Templates remain managed.
        return (str_starts_with($path, 'Configs/') && !str_ends_with(strtolower($path), '.eco.template'))
            || (bool) preg_match('/\.(eco|json|cfg|ini|toml|ya?ml)$/i', $path);
    }

    protected function id(string|int $id): int
    {
        if (!ctype_digit((string) $id) || (int) $id < 1) {
            throw new RuntimeException('Invalid mod.io identifier.');
        }
        return (int) $id;
    }

    public function prepare(
        string|int $id,
        string|int|null $fileId,
        FileTransaction $files,
        ?SourceContext $source = null
    ): array
    {
        $id = $this->id($id);
        $mod = $this->modio->get($id, $source);
        if (!$mod || (int) ($mod['id'] ?? 0) !== $id) {
            throw new RuntimeException('Unable to retrieve the selected mod.');
        }
        $file = $fileId !== null ? $this->modio->file(
            $id,
            $this->id($fileId),
            $source
        ) : ($mod['latest_file'] ?? []);
        if ((int) ($file['id'] ?? 0) < 1 || !str_starts_with($file['download_url'] ?? '', 'https://')) {
            throw new RuntimeException('This mod has no downloadable release.');
        }
        $stage = $files->root . '/staging/' . $id;
        $files->mkdir($stage);
        $files->repo->pull($file['download_url'], '/' . $stage, ['filename' => 'archive.zip', 'foreground' => true]);
        $files->repo->decompressFile('/' . $stage, 'archive.zip');
        $files->repo->deleteFiles('/' . $stage, ['archive.zip']);

        $root = $this->deploymentRoot($files, $stage);
        $plan = [];
        foreach ($this->archiveDeploymentMap() as $sourceRoot => $targetRoot) {
            $entry = $files->stat($root . '/' . $sourceRoot);
            if ($entry !== null) {
                if (!empty($entry['file'])) {
                    throw new RuntimeException('Invalid Eco archive folder: ' . $sourceRoot);
                }
                $this->collect(
                    $files,
                    $root . '/' . $sourceRoot,
                    $targetRoot,
                    $plan
                );
            }
        }
        if (!$plan) {
            throw new RuntimeException('The archive contains no supported Eco mod files.');
        }
        // Create missing active configs from templates, without ever replacing existing configs.
        foreach ($plan as $target => $source) {
            if (str_starts_with($target, 'Configs/') && str_ends_with(strtolower($target), '.eco.template')) {
                $active = substr($target, 0, -9);
                if (!isset($plan[$active]) && $files->stat($active) === null) {
                    $generated = $stage . '/generated/' . hash('sha256', $active);
                    $files->mkdir(dirname($generated));
                    $files->repo->putContent($generated, $files->repo->getContent($source, 4 * 1024 * 1024));
                    $plan[$active] = $generated;
                }
            }
        }
        return ['mod' => $mod, 'file' => $file, 'files' => $plan];
    }

    protected function archiveDeploymentMap(): array
    {
        return [
            'Mods' => 'Mods',
            'UserCode' => 'Mods/UserCode',
            'Configs' => 'Configs',
        ];
    }

    protected function deploymentRoot(FileTransaction $files, string $stage): string
    {
        $candidates = [$stage];
        foreach ($files->listing($stage) as $entry) {
            if (!empty($entry['symlink'])) {
                throw new RuntimeException('Archive symbolic links are not supported.');
            }
            $name = (string) ($entry['name'] ?? '');
            FileTransaction::path($name);
            if (str_contains($name, '/')) {
                throw new RuntimeException('Invalid archive entry.');
            }
            if (empty($entry['file'])) {
                $candidates[] = $stage . '/' . $name;
            }
        }
        foreach ($candidates as $candidate) {
            foreach (['Mods', 'UserCode', 'Configs'] as $folder) {
                if ($files->stat($candidate . '/' . $folder) !== null) {
                    return $candidate;
                }
            }
        }
        throw new RuntimeException('Expected Mods/, UserCode/, Configs/, or one wrapper folder containing them.');
    }

    protected function collect(FileTransaction $files, string $source, string $target, array &$plan, int $depth = 0): void
    {
        if ($depth > 32 || count($plan) > 10000) {
            throw new RuntimeException('The mod archive exceeds supported file/depth limits.');
        }
        foreach ($files->listing($source) as $entry) {
            $name = (string) ($entry['name'] ?? '');
            FileTransaction::path($name);
            if (str_contains($name, '/') || !empty($entry['symlink'])) {
                throw new RuntimeException('Unsafe archive entry.');
            }
            $path = $target . '/' . $name;
            $this->validatePath($path);
            if (!empty($entry['file'])) {
                $plan[$path] = $source . '/' . $name;
            } else {
                $this->collect($files, $source . '/' . $name, $path, $plan, $depth + 1);
            }
        }
    }
}
