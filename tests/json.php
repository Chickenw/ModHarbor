<?php

// Validate active JSON artifacts; dependencies and historical files are not project input.
$root = dirname(__DIR__);
function env($key, $default = null) { return $default; }
$settings = require $root . '/config/gamenest-mod-manager.php';
function config($key, $default = null) {
    $value = $GLOBALS['settings'];
    foreach (explode('.', substr($key, strlen('gamenest-mod-manager.'))) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) { return $default; }
        $value = $value[$part];
    }
    return $value;
}
$count = 0;
foreach (['plugin.json', 'runtime/composer.json', 'runtime/composer.lock'] as $file) {
    json_decode(file_get_contents($root . '/' . $file), true, 512, JSON_THROW_ON_ERROR); $count++;
}
require $root . '/src/Services/GameDefinition.php';
require $root . '/src/Services/PortableDefinitionGuard.php';
foreach (glob($root . '/examples/*.modharbor.json') as $file) {
    \GameNest\GameNestModManager\Services\GameDefinition::fromJson(file_get_contents($file), array_keys($settings['providers'])); $count++;
}
echo "$count JSON artifacts validated, including portable definition schema.\n";
