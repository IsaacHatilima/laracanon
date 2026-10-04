<?php

declare(strict_types=1);

namespace Isaachatilima\Laracanon;

use Illuminate\Support\ServiceProvider;
use Isaachatilima\Laracanon\Commands\InstallCommand;
use Isaachatilima\Laracanon\Commands\ListCommand;
use Isaachatilima\Laracanon\Items\ItemCatalog;
use Isaachatilima\Laracanon\Support\InstallationProgress;
use Isaachatilima\Laracanon\Support\ProcessRunner;
use Isaachatilima\Laracanon\Support\SymfonyProcessRunner;

final class LaracanonServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(InstallationProgress::class);
        $this->app->bind(ProcessRunner::class, SymfonyProcessRunner::class);
        $this->app->bind(ItemCatalog::class, fn ($app) => new ItemCatalog(
            $app['config']->get('laracanon.items_path') ?? dirname(__DIR__).'/resources/items'
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ListCommand::class, InstallCommand::class]);
        }
    }
}
