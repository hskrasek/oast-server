<?php

declare(strict_types=1);

use App\Http\Problems\ProblemType;
use App\Models\Installation;
use App\Models\User;
use App\Tokens\PersonalAccessTokenService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Events\TokenAuthenticated;

it('rejects anonymous and session-only API requests with problem details', function (): void {
    $this->postJson("https://{$this->apiHost()}/reviews", ['spec' => 'openapi: 3.1.0'])
        ->assertUnauthorized()->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', ProblemType::Unauthenticated->value);
    [$user] = memberFixture();
    $this->actingAs($user)->postJson("https://{$this->apiHost()}/reviews", ['spec' => 'openapi: 3.1.0'])->assertUnauthorized();
});

it('accepts only a valid organization token with the required ability', function (): void {
    Bus::fake();
    [$user, $organization] = memberFixture();
    $created = app(PersonalAccessTokenService::class)->create($user, $organization, 'CI', null);
    $this->withToken($created->plainTextToken)->postJson("https://{$this->apiHost()}/reviews", ['spec' => 'openapi: 3.1.0'])->assertAccepted();
});

it('returns problem details when the token lacks the required ability', function (): void {
    [$user, $organization] = memberFixture();
    $created = app(PersonalAccessTokenService::class)->create($user, $organization, 'read-only', null);
    $created->accessToken->forceFill(['abilities' => ['review:read']])->saveQuietly();
    $this->withToken($created->plainTextToken)->postJson("https://{$this->apiHost()}/reviews", ['spec' => 'openapi: 3.1.0'])
        ->assertForbidden()->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', ProblemType::Forbidden->value);
});

it('rejects revoked expired and membership-orphaned tokens', function (string $state): void {
    [$user, $organization, $membership] = memberFixture();
    $created = app(PersonalAccessTokenService::class)->create($user, $organization, 'CI', null);
    $token = $created->accessToken;
    if ($state === 'revoked') {
        $token->updateQuietly(['revoked_at' => now()]);
    }
    if ($state === 'expired') {
        $token->updateQuietly(['expires_at' => now()->subMinute()]);
    }
    if ($state === 'orphaned') {
        $membership->delete();
    }
    $this->withToken($created->plainTextToken)->getJson("https://{$this->apiHost()}/reviews/999")->assertUnauthorized();
})->with(['revoked', 'expired', 'orphaned']);

it('renders a membership-deleted race as problem details instead of a 500', function (): void {
    Bus::fake();
    [$user, $organization, $membership] = memberFixture();
    $created = app(PersonalAccessTokenService::class)->create($user, $organization, 'CI', null);
    // Reproduces the actual race instead of mocking the final
    // OrganizationContext: TokenAuthenticated fires once Sanctum's own
    // authenticateAccessTokensUsing membership check has already passed
    // but before the controller re-resolves OrganizationContext, so
    // deleting the membership here lands in that exact window.
    Event::listen(TokenAuthenticated::class, fn() => $membership->delete());

    $this->withToken($created->plainTextToken)->postJson("https://{$this->apiHost()}/reviews", ['spec' => 'openapi: 3.1.0'])
        ->assertForbidden()->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', ProblemType::Forbidden->value);
});

it('never accepts a session-cookie-authenticated request on the api domain', function (): void {
    // Pins two independent config guards against regression: an empty
    // sanctum.guard means Sanctum\Guard::__invoke's session-guard loop
    // never runs, and excluding the api domain from sanctum.stateful means
    // EnsureFrontendRequestsAreStateful never treats it as a frontend
    // request. Either regressing alone would let a browser session ride
    // along on API-domain requests.
    expect(config('sanctum.guard'))->toBe([])
        ->and(config('sanctum.stateful'))->not->toContain(config()->string('oast.api_domain'));

    Installation::query()->findOrFail(1)->update(['bootstrapped_at' => now()]);
    $user = User::factory()->create(['password' => 'correct horse battery staple']);
    $this->post('/login', ['email' => $user->email, 'password' => 'correct horse battery staple'])
        ->assertRedirect('/');
    $this->assertAuthenticatedAs($user);

    $this->getJson("https://{$this->apiHost()}/reviews/1", ['Referer' => (string) config('app.url')])
        ->assertUnauthorized()->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', ProblemType::Unauthenticated->value);
});
