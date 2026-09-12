<?php

namespace GameNest\GameNestModManager\Services;

use App\Models\Server;
use GameNest\GameNestModManager\Adapters\ConfiguredGameAdapter;
use GameNest\GameNestModManager\Contracts\{
    ConfiguredDownloadProvider,
    ConfiguredDriverFactory,
    LifecycleDriver
};
use RuntimeException;

class ConfiguredDriverResolver
{
    public function driverClass(
        ConfiguredGameAdapter $adapter,
        string $source
    ): ?string {
        if (
            !$adapter->enabled()
            || !$adapter->hasSource($source)
        ) {
            return null;
        }

        $capabilityClass =
            app(
                GameCapabilityRegistry::class
            )->driverClass(
                $adapter->capability(),
                $source
            );

        if ($capabilityClass !== null) {
            return $capabilityClass;
        }

        $definition =
            config(
                'gamenest-mod-manager.providers',
                []
            )[$source]
            ?? null;

        if (!is_array($definition)) {
            return null;
        }

        (new ProviderSdk)
            ->normalizeDefinition(
                $source,
                $definition
            );

        $factory =
            $definition[
                'configured_driver_factory'
            ]
            ?? null;

        if (
            is_string($factory)
            && is_subclass_of(
                $factory,
                ConfiguredDriverFactory::class
            )
        ) {
            return $factory;
        }

        $providerClass =
            trim(
                (string) (
                    $definition['class']
                    ?? ''
                )
            );

        if (
            $adapter->definition()['deployment']['strategy']
                !== 'provider-managed'
            && $providerClass !== ''
            && is_subclass_of(
                $providerClass,
                ConfiguredDownloadProvider::class
            )
        ) {
            return
                ConfiguredLifecycleDriver::class;
        }

        return null;
    }

    public function create(
        Server $server,
        ConfiguredGameAdapter $adapter,
        string $source
    ): LifecycleDriver {
        $class =
            $this->driverClass(
                $adapter,
                $source
            );

        if ($class === null) {
            throw new RuntimeException(
                'This provider has no compatible configured-game deployment integration.'
            );
        }

        if (
            $class ===
            ConfiguredLifecycleDriver::class
        ) {
            $driver =
                new ConfiguredLifecycleDriver(
                    $server,
                    $adapter,
                    app(
                        SourceRegistry::class
                    )->provider($source)
                );
        } elseif (
            is_subclass_of(
                $class,
                ConfiguredDriverFactory::class
            )
        ) {
            $driver =
                app($class)->create(
                    $server,
                    $adapter,
                    $source
                );
        } else {
            $driver = app($class);
        }

        if (
            !$driver instanceof LifecycleDriver
            || $driver->provider()->key()
                !== $source
        ) {
            throw new RuntimeException(
                'Invalid configured lifecycle driver.'
            );
        }

        return $driver;
    }
}
