<?php

/** Active source only: historical *.bak files must never cause guard failures. */
$root = dirname(__DIR__);
$shared = ['ManagedFileScanner', 'ManagedMutation', 'ExistingModService', 'ExistingModDriver',
    'ConfigManagementService', 'DependencyGraph', 'AuditTrail'];
$checks = 0;
foreach ($shared as $service) {
    $source = file_get_contents($root . '/src/Services/' . $service . '.php');
    if (preg_match('/\b(?:rust|eco|modio|umod|carbon|oxide)\b/i', $source)) {
        throw new RuntimeException('Game/provider coupling found in shared service: ' . $service);
    }
    $checks++;
}
$page = file_get_contents($root . '/src/Pages/ModManager.php');
foreach (['ManagedFileScanner::class', 'ExistingModService::class', 'ConfigManagementService::class', 'dependencyPreview('] as $hook) {
    if (!str_contains($page, $hook)) { throw new RuntimeException('Page bypasses shared service: ' . $hook); }
    $checks++;
}
$pageConfig = substr($page, strpos($page, 'public function refreshConfigs()'));
if (str_contains($pageConfig, '->putContent(') || str_contains($pageConfig, '->backup(')) {
    throw new RuntimeException('Config page bypasses transactional config service.');
}
$checks++;
$manifest = json_decode(file_get_contents($root . '/plugin.json'), true, 512, JSON_THROW_ON_ERROR);
if (($manifest['id'] ?? '') !== 'gamenest-mod-manager' || ($manifest['version'] ?? '') !== '1.0.0-rc.3'
    || ($manifest['namespace'] ?? '') !== 'GameNest\\GameNestModManager' || ($manifest['class'] ?? '') !== 'GameNestModManagerPlugin') {
    throw new RuntimeException('Invalid plugin identity or version.');
}
$checks++;
$contracts = file_get_contents($root . '/src/Contracts/ManagedFilesAdapter.php');
foreach (['scanRules', 'configRules', 'validateConfig'] as $method) {
    if (!str_contains($contracts, 'function ' . $method)) { throw new RuntimeException('Missing adapter extension.'); }
    $checks++;
}
echo "$checks architecture/metadata guards passed.\n";
