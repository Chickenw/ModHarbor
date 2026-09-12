<?php

namespace GameNest\GameNestModManager\Contracts;

use App\Models\Server;

interface GameAdapter
{
    /**
     * Internal stable identifier.
     */
    public function key(): string;

    /**
     * User-facing game name.
     */
    public function name(): string;

    /**
     * Developer-facing metadata for the adapter.
     *
     * Keep operational rules out of this array; it is intended for UI,
     * diagnostics, documentation, and third-party adapter inspection.
     */
    public function metadata(): array;

    /**
     * Shared feature flags exposed by the adapter.
     */
    public function features(): array;

    /**
     * Defaults merged into every source definition before per-source values.
     */
    public function sourceDefaults(): array;

    /**
     * Determine whether this adapter supports the server.
     */
    public function supports(Server $server): bool;

    /**
     * Sources/providers available to this game.
     *
     * Example:
     *
     * [
     *     'steam-workshop' => [
     *         'app_id' => 123456,
     *     ],
     *     'github' => [],
     *     'upload' => [],
     * ]
     */
    public function sources(): array;

    /**
     * Backward-compatible provider list.
     *
     * Existing ModHarbor code can continue using this while
     * the provider layer moves to source metadata.
     */
    public function providers(): array;

    /**
     * Configuration for one source/provider.
     */
    public function sourceConfig(string $source): array;

    /**
     * Whether this adapter exposes a source/provider.
     */
    public function hasSource(string $source): bool;

    /**
     * Common directories in which this game stores mods/plugins.
     */
    public function modDirectories(): array;

    /**
     * Common configuration directories.
     */
    public function configDirectories(): array;
}
