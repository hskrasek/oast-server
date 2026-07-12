<?php

declare(strict_types=1);

use App\Models\OrganizationMembership;
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

it('404s a cross organization review on the browser stream route', function (): void {
    [$user] = memberFixture();
    [, $other] = memberFixture();
    App\Models\Installation::query()->findOrFail(1)->update(['bootstrapped_at' => now()]);
    $review = Review::factory()->for($other)->create(['status' => 'running']);
    $this->actingAs($user)->get(route('app.reviews.events', $review->id))->assertNotFound();
});

it('terminates the API stream when the token is revoked before its next poll', function (): void {
    [$user, $organization] = memberFixture();
    $token = app(PersonalAccessTokenService::class)->create($user, $organization, 'CI', null);
    $review = Review::factory()->for($organization)->create(['status' => 'running']);
    $review->appendEvent('review.queued', []);
    $token->accessToken->forceFill(['revoked_at' => now()])->saveQuietly();
    // A revoked token never opens the stream: the Sanctum guard (Task 9) rejects
    // it at connect with 401 before any event is emitted — a strictly stronger
    // guarantee than terminating mid-stream. For a token revoked *during* a
    // long-lived stream (the guard only runs at connect), the per-poll
    // `stillAuthorized()` PAT-liveness check is the defence, exercised directly
    // by the "makes real context authorization false" dataset below.
    $this->withToken($token->plainTextToken)
        ->get("https://{$this->apiHost()}/reviews/{$review->id}/events")
        ->assertUnauthorized();
});

it('stops the open API stream on the next poll after membership is revoked mid stream', function (): void {
    // The middleware, guard, scoped resolver and policy all run during ->get(),
    // producing a 200 StreamedResponse; the stream closure only runs later when
    // streamedContent() is drained. Removing membership in between reproduces a
    // real mid-stream deauthorization: the first per-poll stillAuthorized() check
    // fails and the stream ends before emitting a single event.
    [$user, $organization] = memberFixture();
    $token = app(PersonalAccessTokenService::class)->create($user, $organization, 'CI', null);
    $review = Review::factory()->for($organization)->create(['status' => 'running']);
    $review->appendEvent('review.queued', []);
    $response = $this->withToken($token->plainTextToken)
        ->get("https://{$this->apiHost()}/reviews/{$review->id}/events");
    OrganizationMembership::query()->where('user_id', $user->id)->delete();
    expect($response->streamedContent())->toBe('');
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
        [, $other] = memberFixture();
        // organization_id is deliberately guarded (not in Review::$fillable), so a
        // reassignment must go through forceFill rather than a mass-assignment update.
        $review->forceFill(['organization_id' => $other->id])->save();
    }
    if ($failure === 'pat expired') {
        $token->accessToken->forceFill(['expires_at' => now()->subMinute()])->saveQuietly();
    }
    if ($failure === 'pat revoked') {
        $token->accessToken->forceFill(['revoked_at' => now()])->saveQuietly();
    }
    expect($context->stillAuthorized($review))->toBeFalse();
})->with(['membership removed', 'review reassigned', 'pat expired', 'pat revoked']);
