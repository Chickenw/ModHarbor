<?php

namespace App\Models { class Server { public string $uuid = 'server-one'; } }
namespace Filament\Facades {
    class Filament {
        public static mixed $tenant;
        public static function getTenant() { return self::$tenant; }
    }
}
namespace Illuminate\Support\Facades {
    class Gate {
        public static bool $allowed = true;
        public static array $checks = [];
        public static function authorize($ability, $server): void {
            self::$checks[] = [$ability, $server->uuid];
            if (!self::$allowed) { throw new \RuntimeException('Denied'); }
        }
    }
}
namespace {
    require __DIR__ . '/game-builder-page.php';
    $page = new \GameNest\GameNestModManager\Pages\ModManager;
    $page->server = new \App\Models\Server;
    \Filament\Facades\Filament::$tenant = $page->server;
    $page->hydrate();
    checkPage(count(\Illuminate\Support\Facades\Gate::$checks) === 2, 'Hydration did not authorize both reads');
    $GLOBALS['pageServices'][\GameNest\GameNestModManager\Services\SupportBundle::class] = new class extends \GameNest\GameNestModManager\Services\SupportBundle {
        public function json(\App\Models\Server $server): string { return '{"schema_version":1}'; }
    };
    $download = $page->downloadSupportBundle();
    checkPage($download['name'] === 'modharbor-support.json' && $download['contents'] === '{"schema_version":1}', 'Support download response incorrect');
    checkPage($download['headers']['Cache-Control'] === 'no-store', 'Support download may be cached');
    $other = new \App\Models\Server; $other->uuid = 'server-two';
    \Filament\Facades\Filament::$tenant = $other;
    try { $page->hydrate(); throw new LogicException('Cross-tenant action allowed'); }
    catch (RuntimeException $e) { checkPage($e->getMessage() === '403', 'Tenant mismatch not rejected'); }
    try { $page->downloadSupportBundle(); throw new LogicException('Cross-tenant support download allowed'); }
    catch (RuntimeException $e) { checkPage($e->getMessage() === '403', 'Support tenant mismatch not rejected'); }
    \Filament\Facades\Filament::$tenant = $page->server;
    \Illuminate\Support\Facades\Gate::$allowed = false;
    try { $page->hydrate(); throw new LogicException('Revoked access allowed'); }
    catch (RuntimeException $e) { checkPage($e->getMessage() === 'Denied', 'Revocation not checked'); }
    try { $page->downloadSupportBundle(); throw new LogicException('Revoked support download allowed'); }
    catch (RuntimeException $e) { checkPage($e->getMessage() === 'Denied', 'Support permission not checked'); }
    echo "Mod Manager hydration, tenant mismatch and revoked-permission checks passed.\n";
}
