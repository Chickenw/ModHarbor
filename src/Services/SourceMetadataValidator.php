<?php

namespace GameNest\GameNestModManager\Services;

use RuntimeException;

/** Provider schemas validate inert game metadata before activating a definition. */
class SourceMetadataValidator
{
    public function validate(array $game): void
    {
        $providers = (array) config('gamenest-mod-manager.providers', []);
        foreach ($game['sources'] as $provider => $source) {
            $schema = $providers[$provider] ?? [];
            $metadata = $source['metadata'];
            foreach ($schema['metadata_fields'] ?? [] as $key => $field) {
                $value = $metadata[$key] ?? null;
                if ($value === null || $value === '' || $value === []) {
                    if (!empty($field['required'])) { throw new RuntimeException($provider . ' requires source metadata: ' . $key . '.'); }
                    continue;
                }
                $valid = match ($field['type'] ?? 'text') {
                    'number' => is_int($value) && $value >= ($field['min'] ?? 0),
                    'boolean' => is_bool($value),
                    'list' => is_array($value) && array_is_list($value)
                        && count(array_filter($value, fn ($v) => is_string($v) || is_int($v))) === count($value),
                    default => is_string($value),
                };
                if (!$valid) { throw new RuntimeException('Invalid ' . $provider . ' source metadata type: ' . $key . '.'); }
                if (($field['format'] ?? '') === 'relative-path') { GameDefinition::path($value); }
            }
            $any = $schema['metadata_require_any'] ?? [];
            if ($any && !array_filter(array_intersect_key($metadata, array_flip($any)), fn ($v) => $v !== '' && $v !== null && $v !== [])) {
                throw new RuntimeException($provider . ' requires at least one game identity field.');
            }
        }
    }
}
