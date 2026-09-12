<?php

namespace GameNest\GameNestModManager\Providers;

use GameNest\GameNestModManager\Services\AdapterRegistry;
use GameNest\GameNestModManager\Services\EcoModInstaller;
use GameNest\GameNestModManager\Services\ManifestService;
use GameNest\GameNestModManager\Services\ModManagerService;
use GameNest\GameNestModManager\Services\PackageRecipeRegistry;
use GameNest\GameNestModManager\Services\ProviderContext;
use GameNest\GameNestModManager\Services\ProviderSdk;
use GameNest\GameNestModManager\Services\SourceRegistry;
use Illuminate\Support\ServiceProvider;

class GameNestModManagerPluginProvider extends ServiceProvider
{
    public function register(): void
    {
        // Keep parser dependencies inside this plugin; do not alter the panel's Composer project.
        $runtime = __DIR__ . '/../../runtime/vendor/autoload.php';
        if (is_file($runtime)) {
            $loader = require $runtime;
            $loader->unregister();
            $loader->register(false); // Prefer any compatible parser already supplied by Pelican.
        }
        $this->mergeConfigFrom(
            __DIR__ .
            '/../../config/gamenest-mod-manager.php',
            'gamenest-mod-manager'
        );

        $this->app->singleton(
            AdapterRegistry::class
        );

        $this->app->singleton(
            ProviderContext::class
        );

        $this->app->singleton(
            ProviderSdk::class
        );

        $this->app->singleton(
            SourceRegistry::class
        );

        $this->app->singleton(
            ManifestService::class
        );

        $this->app->singleton(
            PackageRecipeRegistry::class
        );

        $this->app->singleton(
            ModManagerService::class
        );

        $this->app->singleton(
            ModIoProvider::class
        );

        $this->app->singleton(
            GitHubProvider::class
        );

        $this->app->singleton(
            EcoModInstaller::class
        );
    }

    public function boot(): void
    {
        $this->loadViewsFrom(
            __DIR__ .
            '/../../resources/views',
            'gamenest-mod-manager'
        );

        try {
            if (class_exists(\Illuminate\Support\Facades\Route::class) && app()->bound('router')) {
                \Illuminate\Support\Facades\Route::middleware(['web'])
                    ->get('/modharbor/branding/banner-v3.webp', function () {
                        $file = dirname(__DIR__, 2) . '/resources/artwork/modharbor-banner-v3.webp';
                        if (!is_file($file)) {
                            return response('', 404);
                        }

                        return response()->file($file, [
                            'Content-Type' => 'image/webp',
                            'Cache-Control' => 'public, max-age=3600',
                        ]);
                    });
                \Illuminate\Support\Facades\Route::middleware(['web', 'auth'])
                    ->get('/modharbor/server-avatar/{uuid}', function (string $uuid) {
                        if (!preg_match('/^[A-Za-z0-9-]{8,64}$/D', $uuid)) {
                            abort(404);
                        }
                        $user = auth()->user();
                        $server = \App\Models\Server::query()->where('uuid', $uuid)->first();
                        if (!$user || !$server) {
                            abort(404);
                        }
                        $allowed = false;
                        try {
                            $allowed = method_exists($user, 'can') && $user->can('view', $server);
                        } catch (\Throwable) {
                            $allowed = false;
                        }
                        if (
                            !$allowed
                            && !(method_exists($user, 'isRootAdmin') && $user->isRootAdmin())
                        ) {
                            abort(403);
                        }
                        $uri = app(
                            \GameNest\GameNestModManager\Services\ServerTenantArtwork::class
                        )->dataUri($server);
                        if (
                            !is_string($uri)
                            || !preg_match(
                                '#^data:(image/(?:jpeg|png|webp|svg\+xml));base64,([A-Za-z0-9+/]+=*)$#D',
                                $uri,
                                $match
                            )
                        ) {
                            abort(404);
                        }
                        $bytes = base64_decode($match[2], true);
                        if ($bytes === false || $bytes === '') {
                            abort(404);
                        }

                        return response($bytes, 200, [
                            'Content-Type' => $match[1],
                            'Cache-Control' => 'private, max-age=3600',
                        ]);
                    });
                \Illuminate\Support\Facades\Route::middleware(['web', 'auth'])
                    ->get('/modharbor/game-artwork/{key}', function (string $key) {
                        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/D', $key)) {
                            abort(404);
                        }
                        $user = auth()->user();
                        if (
                            !$user
                            || !(method_exists($user, 'isRootAdmin') && $user->isRootAdmin())
                        ) {
                            abort(403);
                        }
                        $catalog = app(
                            \GameNest\GameNestModManager\Services\GameDefinitionStore::class
                        )->snapshot();
                        $game = $catalog['definitions'][$key] ?? null;
                        if (!is_array($game)) {
                            abort(404);
                        }
                        $uri = app(
                            \GameNest\GameNestModManager\Services\GameArtworkService::class
                        )->resolve($game);
                        if (
                            !is_string($uri)
                            || !preg_match(
                                '#^data:(image/(?:jpeg|png|webp|svg\+xml));base64,([A-Za-z0-9+/]+=*)$#D',
                                $uri,
                                $match
                            )
                        ) {
                            abort(404);
                        }
                        $bytes = base64_decode($match[2], true);
                        if ($bytes === false || $bytes === '') {
                            abort(404);
                        }

                        return response($bytes, 200, [
                            'Content-Type' => $match[1],
                            'Cache-Control' => 'private, max-age=600',
                        ]);
                    });
            }
        } catch (\Throwable) {
            // Branding route must never take the panel down.
        }
    }
}
