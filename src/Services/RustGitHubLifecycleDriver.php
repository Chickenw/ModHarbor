<?php

namespace GameNest\GameNestModManager\Services;

class RustGitHubLifecycleDriver
    extends AbstractGitHubArchiveLifecycleDriver
{
    protected function singleFileExtensions(): array
    {
        return [
            'cs',
        ];
    }

    protected function singleFileDeploymentRoot(
        FileTransaction $files,
        string $filename
    ): ?string {
        return app(
            RustRuntimeDetector::class
        )->pluginRoot($files);
    }

    protected function deploymentRoots(): array
    {
        return [
            'oxide/plugins',
            'carbon/plugins',
        ];
    }

    protected function archiveDeploymentMap(
        FileTransaction $files,
        ?SourceContext $source = null
    ): array {
        $activeRoot = app(
            RustRuntimeDetector::class
        )->pluginRoot($files);

        /*
         * Accept the two common Rust package layouts as archive INPUT,
         * but always deploy .cs plugins into the currently active
         * Carbon/Oxide runtime.
         */
        return [
            'carbon/plugins' => $activeRoot,
            'oxide/plugins' => $activeRoot,
        ];
    }
}
