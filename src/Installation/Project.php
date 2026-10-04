<?php

declare(strict_types=1);

namespace Isaachatilima\Laracanon\Installation;

use RuntimeException;

final class Project
{
    public static function validate(string $path): void
    {
        foreach (['composer.json', 'artisan', 'bootstrap/app.php'] as $file) {
            if (! is_file($path.'/'.$file)) {
                throw new RuntimeException("Target is not a Laravel application: missing {$file}.");
            }
        }

        $composer = json_decode(file_get_contents($path.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        if (! isset($composer['require']['laravel/framework'])) {
            throw new RuntimeException('Target is not a Laravel application: laravel/framework must be a runtime Composer requirement.');
        }
    }
}
