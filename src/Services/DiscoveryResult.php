<?php

namespace GameNest\GameNestModManager\Services;

final class DiscoveryResult
{
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
        public readonly int $lastPage,
        public readonly array $facets = [],
        public readonly array $meta = [],
    ) {
    }

    public static function page(
        array $items,
        int $total,
        int $page,
        int $perPage,
        ?int $lastPage = null,
        array $facets = [],
        array $meta = [],
    ): self {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $total = max(0, $total);
        $lastPage ??= max(1, (int) ceil($total / $perPage));

        return new self(
            array_values($items),
            $total,
            $page,
            $perPage,
            max(1, $lastPage),
            $facets,
            $meta,
        );
    }

    public function toArray(): array
    {
        return [
            'mods' => $this->items,
            'items' => $this->items,
            'total' => $this->total,
            'count' => count($this->items),
            'page' => $this->page,
            'per_page' => $this->perPage,
            'last_page' => $this->lastPage,
            'facets' => $this->facets,
            'meta' => $this->meta,
        ];
    }
}
