<?php

namespace GameNest\GameNestModManager\Services;

use App\Models\Server;
use GameNest\GameNestModManager\Contracts\GameAdapter;
use RuntimeException;

class ProviderContext
{
    protected ServerRuntimeMetadata $runtimeMetadata;

    public function __construct(
        protected AdapterRegistry $adapters,
        ?ServerRuntimeMetadata $runtimeMetadata = null,
    ) {
        $this->runtimeMetadata =
            $runtimeMetadata
            ?? new ServerRuntimeMetadata();
    }

    public function adapter(Server $server): GameAdapter
    {
        $adapter = $this->adapters->forServer($server);

        if (!$adapter) {
            throw new RuntimeException(
                'No ModHarbor game adapter is available for this server.'
            );
        }

        return $adapter;
    }

    public function forServer(
        Server $server,
        string $source
    ): SourceContext {
        $adapter = $this->adapter($server);

        return $this->fromAdapter(
            $adapter,
            $source,
            $this->runtimeMetadata->forSource(
                $server,
                $adapter->definition(),
                $source
            )
        );
    }

    public function forGame(
        string $gameKey,
        string $source
    ): SourceContext {
        $adapter = $this->adapters->byKey($gameKey);

        if (!$adapter) {
            throw new RuntimeException(
                'Unknown ModHarbor game adapter: ' .
                $gameKey .
                '.'
            );
        }

        return $this->fromAdapter(
            $adapter,
            $source
        );
    }

    protected function fromAdapter(
        GameAdapter $adapter,
        string $source,
        array $runtime = []
    ): SourceContext {
        $source = strtolower(trim($source));

        if ($source !== 'existing' && !$adapter->hasSource($source)) {
            throw new RuntimeException(
                $adapter->name() .
                ' does not provide the ' .
                $source .
                ' source.'
            );
        }

        return new SourceContext(
            $adapter->key(),
            $adapter->name(),
            $source,
            array_replace(
                $runtime,
                $adapter->sourceConfig($source)
            )
        );
    }

    public function gameKey(Server $server): string
    {
        return $this->adapter($server)->key();
    }

    public function gameName(Server $server): string
    {
        return $this->adapter($server)->name();
    }

    public function hasSource(
        Server $server,
        string $source
    ): bool {
        return $this->adapter($server)
            ->hasSource($source);
    }

    public function source(
        Server $server,
        string $source
    ): array {
        return $this->forServer(
            $server,
            $source
        )->config();
    }

    public function value(
        Server $server,
        string $source,
        string $key,
        mixed $default = null
    ): mixed {
        return $this->forServer(
            $server,
            $source
        )->value(
            $key,
            $default
        );
    }

    public function requireValue(
        Server $server,
        string $source,
        string $key
    ): mixed {
        return $this->forServer(
            $server,
            $source
        )->require($key);
    }
}
