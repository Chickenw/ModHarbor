<?php

use GameNest\GameNestModManager\Contracts\ConfiguredDownloadProvider;
use GameNest\GameNestModManager\Providers\DirectDownloadProvider;
use GameNest\GameNestModManager\Providers\GitHubProvider;
use GameNest\GameNestModManager\Providers\UploadProvider;

$tests[
    'generic downloadable providers opt into configured lifecycle'
] = function () {
    foreach (
        [
            GitHubProvider::class,
            DirectDownloadProvider::class,
            UploadProvider::class,
        ]
        as $provider
    ) {
        check(
            is_subclass_of(
                $provider,
                ConfiguredDownloadProvider::class
            ),
            $provider .
                ' does not opt into generic configured deployment'
        );
    }
};

$tests[
    'configured driver resolver has no hard-coded generic provider list'
] = function () {
    $source = file_get_contents(
        __DIR__ .
        '/../src/Services/ConfiguredDriverResolver.php'
    );

    check(
        str_contains(
            $source,
            'ConfiguredDownloadProvider::class'
        ),
        'ConfiguredDriverResolver does not use the generic download contract'
    );

    check(
        !str_contains(
            $source,
            "'upload',\n                    'direct',\n                    'github'"
        ),
        'ConfiguredDriverResolver still contains a provider-name allowlist'
    );
};
