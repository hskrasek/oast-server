<?php

declare(strict_types=1);

namespace App\Providers;

use App\Council\CouncilOrchestrator;
use App\Council\FindingValidator;
use Illuminate\Container\Container;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(
            CouncilOrchestrator::class,
            fn(Container $app): CouncilOrchestrator => new CouncilOrchestrator(
                $app->make(FindingValidator::class),
                $this->oastConfig(),
            ),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Read and normalise the council configuration into a typed shape.
     *
     * @return array{panelists: list<string>, judge: string, baseline: string|null, quorum: int, timeout: int}
     */
    private function oastConfig(): array
    {
        $config = config('oast');
        $config = is_array($config) ? $config : [];

        $panelists = is_array($config['panelists'] ?? null) ? $config['panelists'] : [];
        $baseline = $config['baseline'] ?? null;

        return [
            'panelists' => array_values(array_filter($panelists, is_string(...))),
            'judge' => is_string($config['judge'] ?? null) ? $config['judge'] : '',
            'baseline' => is_string($baseline) ? $baseline : null,
            'quorum' => is_int($config['quorum'] ?? null) ? $config['quorum'] : 2,
            'timeout' => is_int($config['timeout'] ?? null) ? $config['timeout'] : 120,
        ];
    }
}
