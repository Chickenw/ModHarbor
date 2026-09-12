<?php

/** Portable offline release gate. Optional first argument is the real Pelican application path. */
$root = dirname(__DIR__);
$failed = 0;
foreach (['src', 'config', 'tests'] as $directory) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') { continue; }
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file->getPathname()) . ' 2>&1', $output, $status);
        if ($status) { $failed++; echo implode("\n", $output) . "\n"; }
        $output = [];
    }
}
foreach (['run', 'architecture', 'architecture-v070', 'architecture-providers', 'provider-http', 'steamcmd', 'json', 'game-builder-page', 'game-profile-catalog', 'provider-settings', 'mod-manager-page', 'game-artwork'] as $test) {
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/' . $test . '.php'), $status);
    if ($status) { $failed++; }
}
putenv('MODHARBOR_TEST_RUNTIME=1');
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/run.php'), $status);
if ($status) { $failed++; }
putenv('MODHARBOR_TEST_RUNTIME');
if (isset($argv[1])) {
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/pelican-blade.php') . ' ' . escapeshellarg($argv[1]), $status);
    if ($status) { $failed++; }
} else { echo "Real Pelican Blade validation not run: supply its application path.\n"; }
echo "Release gate: $failed failed checks.\n";
exit($failed ? 1 : 0);
