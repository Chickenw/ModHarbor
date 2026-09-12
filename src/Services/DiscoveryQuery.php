<?php

namespace GameNest\GameNestModManager\Services;

final class DiscoveryQuery
{
    public function __construct(
        public readonly string $search = '',
        public readonly string $sort = '',
        public readonly int $page = 1,
        public readonly int $perPage = 24,
        public readonly array $filters = [],
        public readonly array $tags = [],
    ) {
    }

    public static function fromArray(array $query): self
    {
        return new self(
            search: trim((string) ($query['search'] ?? $query['query'] ?? '')),
            sort: strtolower(trim((string) ($query['sort'] ?? ''))),
            page: max(1, (int) ($query['page'] ?? 1)),
            perPage: max(1, min(100, (int) ($query['per_page'] ?? $query['limit'] ?? 24))),
            filters: is_array($query['filters'] ?? null) ? $query['filters'] : [],
            tags: array_values(array_unique(array_filter(array_map(
                static fn (mixed $tag): string => trim((string) $tag),
                (array) ($query['tags'] ?? [])
            ), static fn (string $tag): bool => $tag !== ''))),
        );
    }

    public function filter(string $key, mixed $default = null): mixed
    {
        return $this->filters[$key] ?? $default;
    }

    public function withPage(int $page): self
    {
        return new self(
            $this->search,
            $this->sort,
            max(1, $page),
            $this->perPage,
            $this->filters,
            $this->tags,
        );
    }

    public function toArray(): array
    {
        return [
            'search' => $this->search,
            'sort' => $this->sort,
            'page' => $this->page,
            'per_page' => $this->perPage,
            'filters' => $this->filters,
            'tags' => $this->tags,
        ];
    }
}
