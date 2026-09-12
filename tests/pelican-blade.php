<?php

/** Mandatory deployment validation, using Pelican's real component registry and Blade compiler. */
$panel = realpath($argv[1] ?? dirname(__DIR__, 3));
if (!$panel || !is_file($panel . '/bootstrap/app.php') || !is_file($panel . '/vendor/autoload.php')) {
    throw new RuntimeException('A real Pelican application is required for Blade validation.');
}
require $panel . '/vendor/autoload.php';
$app = require $panel . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
// Load page classes against the actual installed Filament/Livewire contracts as well.
foreach ([\GameNest\GameNestModManager\Pages\GameSetup::class, \GameNest\GameNestModManager\Pages\ModManager::class] as $page) {
    if (!class_exists($page) || !is_subclass_of($page, \Filament\Pages\Page::class)) {
        throw new RuntimeException('Plugin page registration/autoload is unavailable: ' . $page);
    }
}
$compiler = $app->make('blade.compiler');
$count = 0;
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/resources/views', FilesystemIterator::SKIP_DOTS)) as $file) {
    if (!$file->isFile() || !str_ends_with($file->getFilename(), '.blade.php')) { continue; }
    $compiler->compile($file->getPathname());
    $compiled = $compiler->getCompiledPath($file->getPathname());
    $status = 0;
    passthru(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($compiled), $status);
    if ($status !== 0) { throw new RuntimeException('Compiled Pelican Blade PHP is invalid.'); }
    $count++;
}
if ($count === 0) { throw new RuntimeException('No plugin Blade templates were found.'); }
echo "$count plugin Blade template(s) compiled with the actual Pelican component registry and passed PHP lint.\n";
