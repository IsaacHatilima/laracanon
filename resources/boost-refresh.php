<?php

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;
use Isaachatilima\Laracanon\Boost\BoostProjectRefresher;

// Invoked in a separate PHP process so newly Composer-installed providers and
// packages are visible. Stdout uses a marker because applications may emit text.
try {
    $project = $argv[1] ?? throw new RuntimeException('A project path is required.');
    chdir($project);
    $composer = json_decode((string) file_get_contents($project.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $vendorDirectory = $composer['config']['vendor-dir'] ?? 'vendor';
    $vendorPath = str_starts_with($vendorDirectory, '/') || preg_match('/^[a-z]:[\\\\\/]/i', $vendorDirectory)
        ? $vendorDirectory
        : $project.'/'.$vendorDirectory;
    require $vendorPath.'/autoload.php';
    $app = require $project.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    require_once __DIR__.'/../src/Boost/ManagedBoostFiles.php';
    require_once __DIR__.'/../src/Boost/NativeResourceRenderer.php';
    require_once __DIR__.'/../src/Boost/BoostProjectRefresher.php';

    $selected = json_decode($argv[2] ?? '[]', true, flags: JSON_THROW_ON_ERROR);
    $report = (new BoostProjectRefresher)->refresh($selected);
} catch (Throwable $exception) {
    $report = ['resources' => [], 'failures' => ['Boost refresh failed: '.$exception->getMessage()], 'notes' => [], 'conflicts' => []];
}

echo "\nLARACANON_BOOST_RESULT:".json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
exit($report['failures'] === [] && $report['conflicts'] === [] ? 0 : 1);
