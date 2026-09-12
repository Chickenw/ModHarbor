<?php

namespace GameNest\GameNestModManager\Services;

use App\Models\Server;

final class ServerRuntimeMetadata
{
    /**
     * Return the effective Pelican egg variables for a server.
     *
     * Server-specific values win. If Pelican has not persisted a value,
     * the egg default is used instead.
     *
     * @return array<string, string>
     */
    public function variables(Server $server): array
    {
        $values = [];

        foreach ($server->variables()->get() as $variable) {
            $key = trim((string) $variable->env_variable);

            if ($key === '') {
                continue;
            }

            $serverValue = $variable->server_value;

            $values[$key] = (string) (
                $serverValue !== null
                    ? $serverValue
                    : $variable->default_value
            );
        }

        return $values;
    }

    /**
     * Resolve provider metadata from a definition-controlled variable map.
     *
     * Example:
     *
     * runtime_metadata => [
     *     'curseforge' => [
     *         'game_version' => ['variable' => 'MINECRAFT_VERSION'],
     *         'mod_loader_type' => [
     *             'variable' => 'SERVER_TYPE',
     *             'map' => ['neoforge' => 6],
     *         ],
     *     ],
     * ]
     *
     * The resolver knows nothing about Minecraft, CurseForge loader names,
     * or any particular egg. Those details remain data in the definition.
     *
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    public function forSource(
        Server $server,
        array $definition,
        string $source
    ): array {
        $rules = $definition['runtime_metadata'][$source] ?? [];

        if (!is_array($rules) || $rules === []) {
            return [];
        }

        $variables = $this->variables($server);
        $resolved = [];

        foreach ($rules as $target => $rule) {
            if (!is_string($target) || $target === '') {
                continue;
            }

            if (is_string($rule)) {
                $rule = ['variable' => $rule];
            }

            if (!is_array($rule)) {
                continue;
            }

            $variable = trim((string) ($rule['variable'] ?? ''));

            if ($variable === '' || !array_key_exists($variable, $variables)) {
                continue;
            }

            $value = trim($variables[$variable]);

            if ($value === '') {
                continue;
            }

            if (isset($rule['map']) && is_array($rule['map'])) {
                $lookup = strtolower($value);

                if (!array_key_exists($lookup, $rule['map'])) {
                    continue;
                }

                $value = $rule['map'][$lookup];
            }

            if (
                isset($rule['ignore'])
                && is_array($rule['ignore'])
                && in_array(strtolower((string) $value), array_map(
                    static fn ($item): string => strtolower((string) $item),
                    $rule['ignore']
                ), true)
            ) {
                continue;
            }

            if (($rule['list'] ?? false) === true) {
                $value = [$value];
            }

            $resolved[$target] = $value;
        }

        return $resolved;
    }
}
