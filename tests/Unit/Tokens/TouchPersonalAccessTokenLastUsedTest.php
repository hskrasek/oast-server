<?php

declare(strict_types=1);

use App\Listeners\TouchPersonalAccessTokenLastUsed;
use App\Models\PersonalAccessToken;
use Laravel\Sanctum\Events\TokenAuthenticated;

it('ignores an event token that is not the app PersonalAccessToken model', function (): void {
    $listener = new TouchPersonalAccessTokenLastUsed;
    $listener->handle(new TokenAuthenticated(new Laravel\Sanctum\PersonalAccessToken));

    expect(true)->toBeTrue();
});

it('touches at most once per minute', function (): void {
    $token = PersonalAccessToken::factory()->create(['last_used_at' => null]);
    $listener = new TouchPersonalAccessTokenLastUsed;
    $listener->handle(new TokenAuthenticated($token));
    $first = $token->refresh()->last_used_at;
    $listener->handle(new TokenAuthenticated($token));
    expect($token->refresh()->last_used_at->equalTo($first))->toBeTrue();
    // Datetime columns store second-level precision, so asserting "greater
    // than" against real wall-clock time is flaky when the whole test runs
    // inside one second. Travel the app clock forward instead of forging a
    // stale column value directly, so the throttle window is exercised
    // deterministically.
    $this->travel(61)->seconds();
    $listener->handle(new TokenAuthenticated($token));
    expect($token->refresh()->last_used_at->greaterThan($first))->toBeTrue();
    expect(config('sanctum.last_used_at'))->toBeFalse()->and(config('sanctum.guard'))->toBe([]);
});
