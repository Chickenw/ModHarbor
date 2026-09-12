<?php

$root = dirname(__DIR__);
$files = ['Services/GameDefinition', 'Services/GameDefinitionStore', 'Adapters/ConfiguredGameAdapter',
    'Services/ConfiguredDriverResolver', 'Services/ConfiguredPackagePlan', 'Services/ConfiguredLifecycleDriver', 'Pages/GameSetup'];
foreach ($files as $file) {
    $source = file_get_contents($root . '/src/' . $file . '.php');
    foreach (token_get_all($source) as $token) {
        if (is_array($token) && $token[0] === T_EVAL) { throw new RuntimeException('Executable definitions are forbidden.'); }
    }
    if (preg_match('/\b(?:shell_exec|exec|system|passthru|unserialize|create_function)\s*\(/', $source)) { throw new RuntimeException('Executable definition hook detected.'); }
    if (preg_match('/\b(?:251570|7daystodie|minecraft|thunderstore|curseforge|modrinth)\b/i', $source)) { throw new RuntimeException('Game or future provider hardcoding in builder: ' . $file); }
}
$adapter = file_get_contents($root . '/src/Adapters/ConfiguredGameAdapter.php');
if (!str_contains($adapter, 'extends AbstractGameAdapter') || !str_contains($adapter, 'GameDefinition $definition')) { throw new RuntimeException('Configured adapter bypasses validated contract.'); }
$page = file_get_contents($root . '/src/Pages/GameSetup.php');
if (!str_contains($page, 'isRootAdmin') || str_contains($page, 'putContent(')) { throw new RuntimeException('Builder page bypasses authorization/storage boundaries.'); }
$resolver = file_get_contents($root . '/src/Services/ConfiguredDriverResolver.php');
if (!str_contains($resolver, 'ConfiguredDriverFactory::class') || !str_contains($resolver, "['provider-managed']" ) && !str_contains($resolver, "!== 'provider-managed'")) { throw new RuntimeException('Missing trusted integration gate.'); }
foreach (['AdapterRegistry' => 'GameDefinitionStore::class', 'ModLifecycleService' => 'ConfiguredDriverResolver', 'SourceRegistry' => 'ConfiguredDriverResolver'] as $service => $hook) {
    if (!str_contains(file_get_contents($root . '/src/Services/' . $service . '.php'), $hook)) { throw new RuntimeException('Missing shared integration: ' . $service); }
}
echo "Game Builder architecture guards passed.\n";
