<?php

namespace Filament\Pages { class Page {} }
namespace Filament\Notifications {
    class Notification {
        public static array $messages = [];
        public static function make(): self { return new self; }
        public function __call($name, $args): self { self::$messages[] = [$name, $args]; return $this; }
    }
}
namespace Illuminate\Support\Facades {
    class Crypt {
        public static function encryptString($s) { return base64_encode('encrypted:' . $s); }
        public static function decryptString($s) { return substr(base64_decode($s), 10); }
    }
}
namespace {
    use GameNest\GameNestModManager\Services\{ProviderSettingsStore, ProviderConnectionService, ProviderHttpClient, SourceRegistry, AdapterRegistry, ProviderContext};
    use GameNest\GameNestModManager\Pages\ProviderSettings;
    spl_autoload_register(function ($class) {
        $prefix = 'GameNest\\GameNestModManager\\';
        if (str_starts_with($class, $prefix)) { require __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php'; }
    });
    function env($key, $default = null) { return $default; }
    $settings = require __DIR__ . '/../config/gamenest-mod-manager.php';
    $directory = sys_get_temp_dir() . '/modharbor-settings-' . bin2hex(random_bytes(8));
    $admin = true;
    function storage_path($path) { return $GLOBALS['directory'] . '/' . $path; }
    function config($key, $default = null) {
        $value = $GLOBALS['settings'];
        foreach (explode('.', substr($key, strlen('gamenest-mod-manager.'))) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) { return $default; }
            $value = $value[$part];
        }
        return $value;
    }
    function auth() { return new class { public function user() { return new class { public function isRootAdmin() { return $GLOBALS['admin']; } }; } }; }
    function abort_unless($allowed, $code) { if (!$allowed) { throw new RuntimeException((string) $code); } }
    function abort($code) { throw new RuntimeException((string) $code); }
    class ProbeHttp extends ProviderHttpClient {
        public array $requests = [];
        public int $status = 200;
        public bool $fail = false;
        public function send(string $method, string $url, array $parameters = [], array $headers = []): array {
            $this->requests[] = compact('method', 'url', 'parameters', 'headers');
            if ($this->fail) { throw new RuntimeException('PRIVATE-UPSTREAM-SECRET'); }
            return ['status' => $this->status, 'body' => '{"key":"PRIVATE-UPSTREAM-SECRET"}'];
        }
    }
    $store = new ProviderSettingsStore;
    $http = new ProbeHttp;
    $connection = new ProviderConnectionService($store, $http);
    $adapters = new AdapterRegistry;
    $registry = new SourceRegistry($adapters, new ProviderContext($adapters));
    function app($class) { return match ($class) {
        ProviderSettingsStore::class => $GLOBALS['store'], ProviderConnectionService::class => $GLOBALS['connection'],
        SourceRegistry::class => $GLOBALS['registry'], default => new $class,
    }; }
    $count = 0;
    function verify($value, $message) { $GLOBALS['count']++; if (!$value) { throw new RuntimeException($message); } }
    $page = new ProviderSettings;
    $page->mount();
    $expectedProviders = array_keys(array_filter($registry->definitions(), fn ($d) => !empty($d['credential_fields'])));
    verify(array_keys($page->providers) === $expectedProviders, 'Only providers with global settings should be visible');
    verify($page->status['nexus'] === 'Not configured' && $page->status['github'] === 'Configured', 'Required and optional auth statuses');
    $page->values['modio'] = ['api_path' => 'https://api.mod.io/v1', 'api_key' => 'PRIVATE-SAVED-KEY'];
    $page->saveProvider('modio');
    verify($store->get('modio', 'api_key') === 'PRIVATE-SAVED-KEY', 'Save failed');
    verify($page->values['modio']['api_key'] === '', 'Saved secret leaked to browser state');
    $page->saveProvider('modio');
    verify($store->get('modio', 'api_key') === 'PRIVATE-SAVED-KEY', 'Blank secret failed to retain credential');
    $page->revealSecret('modio', 'api_key');
    verify($page->values['modio']['api_key'] === 'PRIVATE-SAVED-KEY', 'Reveal failed');
    $page->hideSecret('modio', 'api_key');
    verify($page->values['modio']['api_key'] === '' && !$page->revealed['modio']['api_key'], 'Hide failed');
    verify(!str_contains(file_get_contents(storage_path('app/gamenest-mod-manager/providers/modio.json')), 'PRIVATE-SAVED-KEY'), 'Secret stored in plain text');
    try { $store->assertPortable(['name' => 'PRIVATE-SAVED-KEY']); throw new LogicException('Saved credential exported'); }
    catch (RuntimeException $e) { verify(!str_contains($e->getMessage(), 'PRIVATE-SAVED-KEY'), 'Credential leaked in export error'); }
    verify($store->configurationStatus('modio', $registry->definition('modio')['credential_fields']) === true, 'Saved credential presence missing');
    foreach (['modio', 'nexus', 'curseforge', 'github', 'modrinth', 'steam-workshop', 'umod', '7daystodiemods'] as $provider) {
        $draft = ['api_path' => 'https://api.mod.io/v1', 'api_key' => 'PRIVATE-DRAFT', 'token' => 'PRIVATE-DRAFT'];
        $result = $connection->test($provider, $registry->definition($provider), $draft);
        verify($result['status'] === 'Connected' && !str_contains(json_encode($result), 'PRIVATE'), 'Probe failed/leaked: ' . $provider);
    }
    $http->fail = true;
    $page->testProvider('modio');
    verify($page->status['modio'] === 'Failed', 'Failure status not surfaced');
    verify(!str_contains(json_encode(\Filament\Notifications\Notification::$messages), 'PRIVATE'), 'Notification leaked upstream body or credentials');
    $http->fail = false; $http->status = 401;
    verify($connection->test('modio', $registry->definition('modio'))['status'] === 'Failed', '401 accepted');
    $before = count($http->requests);
    $connection->test('modio', $registry->definition('modio'), ['api_path' => 'https://attacker.invalid/v1']);
    verify(count($http->requests) === $before, 'Credential forwarded to untrusted host');
    foreach (['https://api.mod.io/v1?key=x', 'https://api.mod.io@attacker.invalid/v1', 'http://api.mod.io/v1', 'https://127.0.0.1/v1'] as $url) {
        try { ProviderConnectionService::modioBase($url); throw new LogicException('Unsafe URL accepted'); }
        catch (RuntimeException) { verify(true, 'URL rejected'); }
    }
    $admin = false;
    foreach (['mount' => [], 'saveProvider' => ['modio'], 'testProvider' => ['modio'], 'revealSecret' => ['modio', 'api_key'], 'hideSecret' => ['modio', 'api_key']] as $method => $args) {
        try { $page->$method(...$args); throw new LogicException('Non-root action permitted: ' . $method); }
        catch (RuntimeException $e) { verify($e->getMessage() === '403', 'Root authorization failed'); }
    }
    echo "$count provider settings and connection assertions passed.\n";
}
