<?php

spl_autoload_register(function ($class) {
    $prefix = 'GameNest\\GameNestModManager\\';
    if (str_starts_with($class, $prefix)) { require __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php'; }
});
function env($key, $default = null) { return $default; }
$config = require __DIR__ . '/../config/gamenest-mod-manager.php';
$sdk = new \GameNest\GameNestModManager\Services\ProviderSdk;
$count = 0;
foreach (['nexus', '7daystodiemods', 'steam-workshop', 'curseforge', 'modrinth'] as $key) {
    $definition = $sdk->normalizeDefinition($key, $config['providers'][$key]);
    if ($key === 'steam-workshop') {
        if (!is_subclass_of($definition['configured_driver_factory'], \GameNest\GameNestModManager\Contracts\ConfiguredDriverFactory::class)) { throw new RuntimeException('Workshop lacks a trusted factory'); }
    } elseif (!is_subclass_of($definition['class'], \GameNest\GameNestModManager\Contracts\ConfiguredDownloadProvider::class)) { throw new RuntimeException('Missing generic download opt-in'); }
    $count++;
    foreach (['src/Pages/GameSetup.php', 'src/Pages/ModManager.php', 'src/Services/ConfiguredDriverResolver.php'] as $path) {
        if (str_contains(file_get_contents(__DIR__ . '/../' . $path), "'" . $key . "'")) { throw new RuntimeException('Hard-coded expansion provider in shared flow'); }
        $count++;
    }
}
$workshopSchema = $sdk->normalizeDefinition('steam-workshop', $config['providers']['steam-workshop']);
$appIdSchema = $workshopSchema['metadata_fields']['app_id'] ?? [];
if (($appIdSchema['inherit_from'] ?? null) !== 'steam_app_id') {
    throw new RuntimeException('Provider metadata inheritance is not registry-driven');
}
$count++;

$view = file_get_contents(__DIR__ . '/../resources/views/pages/game-setup.blade.php');
if (!str_contains($view, 'metadata_example') || !str_contains($view, 'providerOptions()')) { throw new RuntimeException('Game Builder is not registry-driven'); }
$count++;
echo "$count provider architecture guards passed.\n";
