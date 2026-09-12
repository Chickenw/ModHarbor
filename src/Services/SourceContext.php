<?php

namespace GameNest\GameNestModManager\Services;

use RuntimeException;

final class SourceContext
{
    public function __construct(
        protected string $gameKey,
        protected string $gameName,
        protected string $sourceKey,
        protected array $config = [],
    ) {
        $this->gameKey = strtolower(trim($this->gameKey));
        $this->gameName = trim($this->gameName);
        $this->sourceKey = strtolower(trim($this->sourceKey));

        if (
            $this->gameKey === ''
            || $this->gameName === ''
            || $this->sourceKey === ''
        ) {
            throw new RuntimeException(
                'Invalid ModHarbor source context.'
            );
        }
    }

    public function gameKey(): string
    {
        return $this->gameKey;
    }

    public function gameName(): string
    {
        return $this->gameName;
    }

    public function key(): string
    {
        return $this->sourceKey;
    }

    public function config(): array
    {
        return $this->config;
    }

    public function value(
        string $key,
        mixed $default = null
    ): mixed {
        return $this->config[$key] ?? $default;
    }

    public function require(
        string $key
    ): mixed {
        $value = $this->value($key);

        if (
            $value === null
            || $value === ''
            || $value === []
        ) {
            throw new RuntimeException(
                'The ' .
                $this->sourceKey .
                ' source for ' .
                $this->gameName .
                ' is missing required configuration: ' .
                $key .
                '.'
            );
        }

        return $value;
    }

    public function cacheKey(
        string $suffix = ''
    ): string {
        $key =
            'modharbor:source:' .
            $this->gameKey .
            ':' .
            $this->sourceKey . ':' . hash('sha256', json_encode($this->config, JSON_THROW_ON_ERROR));

        if ($suffix !== '') {
            $key .= ':' . trim($suffix, ':');
        }

        return $key;
    }
}
