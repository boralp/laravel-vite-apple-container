<?php

declare(strict_types=1);

namespace Boralp\LaravelViteAppleContainer;

use Boralp\LaravelViteAppleContainer\Commands\BuildCommand;
use Illuminate\Support\ServiceProvider;

final class LaravelViteAppleContainerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/lvac.php',
            'lvac'
        );
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                BuildCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/lvac.php' => config_path('lvac.php'),
            ], 'lvac-config');
        }
    }
}
