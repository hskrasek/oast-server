<?php

declare(strict_types=1);

namespace App\Providers;

use App\Council\CouncilOrchestrator;
use App\Council\FindingValidator;
use App\Identity\RegistrationPolicy;
use App\Identity\SelfHostedRegistrationPolicy;
use App\Listeners\TouchPersonalAccessTokenLastUsed;
use App\Models\OrganizationMembership;
use App\Models\PersonalAccessToken;
use App\Organizations\OrganizationContext;
use App\Site\Newsletter\NewsletterContacts;
use App\Site\Newsletter\SesNewsletterContacts;
use App\Site\Og\CloudflareOgImageRenderer;
use App\Site\Og\OgImageRenderer;
use Aws\SesV2\SesV2Client;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Events\TokenAuthenticated;
use Laravel\Sanctum\Sanctum;

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

        $this->app->bind(RegistrationPolicy::class, SelfHostedRegistrationPolicy::class);
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

        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
        Sanctum::authenticateAccessTokensUsing(fn(PersonalAccessToken $token, bool $valid): bool => $valid && $token->revoked_at === null
            && ($token->expires_at === null || $token->expires_at->isFuture())
            && OrganizationMembership::query()->where('user_id', $token->tokenable_id)
                ->where('organization_id', $token->organization_id)->exists());
        Event::listen(TokenAuthenticated::class, TouchPersonalAccessTokenLastUsed::class);
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
