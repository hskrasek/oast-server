<?php

declare(strict_types=1);

use App\Models\Review;
use App\Organizations\OrganizationContext;
use App\Tokens\PersonalAccessTokenService;

it('returns a browser 429 before streaming when the user ceiling is full', function (): void {
    config(['oast.max_concurrent_streams' => 1]);
    [$user, $organization] = memberFixture();
    App\Models\Installation::query()->findOrFail(1)->update(['bootstrapped_at' => now()]);
    $review = Review::factory()->for($organization)->create(['status' => 'running']);
    $lease = app(App\Streaming\StreamLeaseManager::class)->acquire('user:' . $user->id);
    try {
        $this->actingAs($user)->get(route('app.reviews.events', $review->id))
            ->assertTooManyRequests()->assertHeader('Retry-After', '60')->assertContent('');
    } finally {
        $lease->release();
    }
});

it('returns 404 before streaming a cross organization review', function (): void {
    [$user, $organization] = memberFixture();
    [, $other] = memberFixture();
    $token = app(PersonalAccessTokenService::class)->create($user, $organization, 'CI', null);
    $review = Review::factory()->for($other)->create();
    $this->withToken($token->plainTextToken)->get("https://{$this->apiHost()}/reviews/{$review->id}/events")->assertNotFound();
});

it('terminates the API stream when the token is revoked before its next poll', function (): void {
    [$user, $organization] = memberFixture();
    $token = app(PersonalAccessTokenService::class)->create($user, $organization, 'CI', null);
    $review = Review::factory()->for($organization)->create(['status' => 'running']);
    $review->appendEvent('review.queued', []);
    // Revoking before the request would reject it at auth:sanctum (Task 9's
    // Sanctum::authenticateAccessTokensUsing) before the controller ever
    // runs, so streamedContent() would never see a StreamedResponse. Revoke
    // via a TokenAuthenticated listener instead: auth succeeds for this
    // request (as it must, to reach the stream), then the token is revoked
    // before the stream loop's first per-poll stillAuthorized() check.
    Illuminate\Support\Facades\Event::listen(Laravel\Sanctum\Events\TokenAuthenticated::class, function () use ($token): void {
        $token->accessToken->forceFill(['revoked_at' => now()])->saveQuietly();
    });
    $body = $this->withToken($token->plainTextToken)
        ->get("https://{$this->apiHost()}/reviews/{$review->id}/events")->streamedContent();
    expect($body)->toBe('');
});

it('makes real context authorization false after membership removal review reassignment or token expiry', function (string $failure): void {
    [$user, $organization, $membership] = memberFixture();
    $token = app(PersonalAccessTokenService::class)->create($user, $organization, 'CI', null);
    $review = Review::factory()->for($organization)->create(['status' => 'running']);
    $this->withToken($token->plainTextToken);
    $request = Illuminate\Http\Request::create('/');
    $request->setUserResolver(fn() => $user->withAccessToken($token->accessToken));
    $context = new OrganizationContext($request);
    if ($failure === 'membership removed') {
        $membership->delete();
    }
    if ($failure === 'review reassigned') {
        // Review::$guarded protects organization_id from mass assignment
        // (deliberate, Task 10); forceFill bypasses that to simulate an
        // operator-level reassignment for this authorization check.
        [, $other] = memberFixture();
        $review->forceFill(['organization_id' => $other->id])->save();
    }
    if ($failure === 'pat expired') {
        $token->accessToken->forceFill(['expires_at' => now()->subMinute()])->saveQuietly();
    }
    expect($context->stillAuthorized($review))->toBeFalse();
})->with(['membership removed', 'review reassigned', 'pat expired']);
