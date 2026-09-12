<?php

namespace GameNest\GameNestModManager\Services;

use GameNest\GameNestModManager\Contracts\DiscoverableProvider;
use GameNest\GameNestModManager\Contracts\ModProvider;
use RuntimeException;

/**
 * Developer-facing validation and normalization for provider registrations.
 *
 * The panel can fail early with useful messages instead of discovering a bad
 * provider registration only after a user clicks Install.
 */
class ProviderSdk
{
    public function normalizeDefinition(string $key, array $definition): array
    {
        $key = strtolower(trim($key));

        if ($key === '' || preg_match('/^[a-z0-9][a-z0-9._-]*$/', $key) !== 1) {
            throw new RuntimeException('Invalid ModHarbor provider key: ' . $key . '.');
        }

        $class = trim((string) ($definition['class'] ?? ''));

        if ($class === '' || !class_exists($class)) {
            throw new RuntimeException('Provider ' . $key . ' references an unavailable class.');
        }

        if (!is_subclass_of($class, ModProvider::class)) {
            throw new RuntimeException($class . ' must implement the ModHarbor ModProvider contract.');
        }

        $capabilities = ProviderCapabilities::normalize((array) ($definition['capabilities'] ?? []));

        if (
            in_array(ProviderCapabilities::DISCOVER, $capabilities, true)
            && !is_subclass_of($class, DiscoverableProvider::class)
        ) {
            throw new RuntimeException(
                'Provider ' . $key . ' declares discover capability but does not implement DiscoverableProvider.'
            );
        }

        $metadataFields = [];

        foreach ((array) ($definition['metadata_fields'] ?? []) as $fieldKey => $field) {
            $fieldKey = strtolower(trim((string) $fieldKey));

            if (
                $fieldKey === ''
                || preg_match('/^[a-z0-9][a-z0-9_\-]*$/D', $fieldKey) !== 1
                || !is_array($field)
            ) {
                throw new RuntimeException(
                    'Provider ' . $key . ' contains an invalid metadata field.'
                );
            }

            $type = strtolower(trim((string) ($field['type'] ?? 'text')));

            if (!in_array($type, ['text', 'number', 'boolean', 'list'], true)) {
                throw new RuntimeException(
                    'Provider ' . $key . ' metadata field ' . $fieldKey
                    . ' uses an unsupported type.'
                );
            }

            $metadataFields[$fieldKey] = array_replace($field, [
                'label' => trim((string) ($field['label'] ?? '')) ?: $fieldKey,
                'type' => $type,
                'required' => (bool) ($field['required'] ?? false),
                'placeholder' => trim((string) ($field['placeholder'] ?? '')),
                'help' => trim((string) ($field['help'] ?? '')),
            ]);
        }

        $discovery = is_array($definition['discovery'] ?? null)
            ? $definition['discovery']
            : [];

        $sorts = [];
        foreach ((array) ($discovery['sorts'] ?? []) as $sortKey => $label) {
            $sortKey = strtolower(trim((string) $sortKey));
            $label = trim((string) $label);
            if ($sortKey !== '' && $label !== '') {
                $sorts[$sortKey] = $label;
            }
        }

        $pageSizes = array_values(array_unique(array_filter(array_map(
            static fn (mixed $size): int => max(0, (int) $size),
            (array) ($discovery['page_sizes'] ?? [24])
        ), static fn (int $size): bool => $size > 0 && $size <= 100)));

        if ($pageSizes === []) {
            $pageSizes = [24];
        }

        $defaultSort = strtolower(trim((string) ($discovery['default_sort'] ?? array_key_first($sorts) ?? '')));
        if ($defaultSort !== '' && !isset($sorts[$defaultSort])) {
            $defaultSort = (string) (array_key_first($sorts) ?? '');
        }

        return array_replace($definition, [
            'label' => trim((string) ($definition['label'] ?? '')) ?: $key,
            'browse_priority' => (int) ($definition['browse_priority'] ?? 1000),
            'capabilities' => $capabilities,
            'metadata_fields' => $metadataFields,
            'discovery' => array_replace($discovery, [
                'sorts' => $sorts,
                'default_sort' => $defaultSort,
                'page_sizes' => $pageSizes,
                'default_page_size' => in_array((int) ($discovery['default_page_size'] ?? 0), $pageSizes, true)
                    ? (int) $discovery['default_page_size']
                    : $pageSizes[0],
            ]),
        ]);
    }

    public function describe(string $key, array $definition): array
    {
        $definition = $this->normalizeDefinition($key, $definition);

        return [
            'key' => strtolower(trim($key)),
            'label' => $definition['label'],
            'capabilities' => $definition['capabilities'],
            'browse_priority' => $definition['browse_priority'],
            'metadata_fields' => $definition['metadata_fields'],
            'discovery' => $definition['discovery'],
        ];
    }
}
