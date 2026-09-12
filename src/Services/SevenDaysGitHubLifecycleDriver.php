<?php

namespace GameNest\GameNestModManager\Services;

class SevenDaysGitHubLifecycleDriver
    extends AbstractGitHubArchiveLifecycleDriver
{
    protected function deploymentRoots(): array
    {
        return [
            'Mods',
        ];
    }
}
