<?php

namespace GameNest\GameNestModManager\Services;

class SevenDaysUploadLifecycleDriver
    extends AbstractUploadArchiveLifecycleDriver
{
    protected function deploymentRoots(): array
    {
        return [
            'Mods',
        ];
    }
}
