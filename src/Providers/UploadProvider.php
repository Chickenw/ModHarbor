<?php

namespace GameNest\GameNestModManager\Providers;

use GameNest\GameNestModManager\Contracts\ConfiguredDownloadProvider;
use GameNest\GameNestModManager\Contracts\ModProvider;
use GameNest\GameNestModManager\Services\SourceContext;
use GameNest\GameNestModManager\Services\UploadPackageStore;

class UploadProvider implements ModProvider, ConfiguredDownloadProvider
{
    public function __construct(
        protected UploadPackageStore $store
    ) {}

    public function key(): string
    {
        return 'upload';
    }

    public function name(): string
    {
        return 'File Upload';
    }

    public function supportsSearch(): bool
    {
        return false;
    }

    public function search(
        string $query,
        array $options = [],
        ?SourceContext $source = null
    ): array {
        return [];
    }

    public function get(
        string|int $id,
        ?SourceContext $source = null
    ): ?array
    {
        $package = $this->store->get((string) $id);

        if ($package === null) {
            return null;
        }

        return [
            'id' => $package['id'],
            'name' => $package['name'],
            'summary' => 'Private/local uploaded package',
            'version' => (string) ($package['version_label'] ?? 'Local'),
            'latest_file' => [
                'id' => $package['id'],
                'name' => $package['filename'],
                'filename' => $package['filename'],
                'version' => (string) ($package['version_label'] ?? 'Local'),
                'size' => $package['size'],
            ],
        ];
    }
}
