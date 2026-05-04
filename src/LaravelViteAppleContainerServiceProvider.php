<?php

declare(strict_types=1);

namespace Boralp\LaravelViteAppleContainer;

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
        $this->publishes([
            __DIR__.'/../config/lvac.php' => config_path('lvac.php'),
        ], 'lvac-config');
    }
}
