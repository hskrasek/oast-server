<?php

declare(strict_types=1);

namespace App\Providers;

use App\Council\CouncilOrchestrator;
use App\Council\FindingValidator;
use App\Organizations\OrganizationContext;
use App\Site\Newsletter\NewsletterContacts;
use App\Site\Newsletter\SesNewsletterContacts;
use App\Site\Og\CloudflareOgImageRenderer;
use App\Site\Og\OgImageRenderer;
use Aws\SesV2\SesV2Client;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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

        $this->app->singleton(
            OgImageRenderer::class,
            fn(): OgImageRenderer => new CloudflareOgImageRenderer(
                config()->string('services.cloudflare.account_id'),
                config()->string('services.cloudflare.browser_token'),
            ),
        );

        $this->app->scoped(OrganizationContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // CF-Connecting-IP is set by Cloudflare's edge and can't be spoofed by
        // the client (Cloudflare overwrites it); X-Forwarded-For's left-most
        // entry is client-suppliable and rotatable, so it's only a fallback
        // for non-Cloudflare-fronted environments (e.g. local dev).
        RateLimiter::for(
            'subscribe',
            fn(Request $request): Limit => Limit::perMinute(5)
                ->by('subscribe:' . ($request->header('CF-Connecting-IP') ?? $request->ip())),
        );
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
