<?php

declare(strict_types=1);

namespace App\Providers;

use App\Council\CouncilOrchestrator;
use Illuminate\Container\Container;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CouncilOrchestrator::class, fn(Container $app): \App\Council\CouncilOrchestrator => new CouncilOrchestrator(
            $app->make(\App\Council\FindingValidator::class),
            $app['config']->get('oast'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
