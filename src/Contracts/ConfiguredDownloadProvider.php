<?php

namespace GameNest\GameNestModManager\Contracts;

/**
 * Marker contract for providers whose get() result exposes a downloadable
 * package through the normal ModHarbor file schema:
 *
 * [
 *     'latest_file' => [
 *         'id' => ...,
 *         'filename' => ...,
 *         'download_url' => ...,
 *     ],
 * ]
 *
 * Providers implementing this contract can use ConfiguredLifecycleDriver
 * for any Game Builder definition using archive/copy deployment.
 *
 * Provider-managed deployments remain exempt.
 */
interface ConfiguredDownloadProvider extends ModProvider
{
}
