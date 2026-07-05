<?php

declare(strict_types=1);

namespace App\Providers;

use App\Council\CouncilOrchestrator;
use App\Council\FindingValidator;
use App\Site\Newsletter\NewsletterContacts;
use App\Site\Newsletter\SesNewsletterContacts;
use Aws\SesV2\SesV2Client;
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

        $this->app->singleton(
            NewsletterContacts::class,
            fn(): NewsletterContacts => new SesNewsletterContacts(
                new SesV2Client(['version' => 'latest', 'region' => config()->string('services.ses_contacts.region')]),
                config()->string('services.ses_contacts.list'),
            ),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \Illuminate\Support\Facades\RateLimiter::for('subscribe', fn(\Illuminate\Http\Request $request): \Illuminate\Cache\RateLimiting\Limit => \Illuminate\Cache\RateLimiting\Limit::perMinute(5)->by('subscribe:' . $request->ip()));
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
