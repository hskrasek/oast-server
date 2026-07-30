<?php

declare(strict_types=1);

use App\Listeners\TouchPersonalAccessTokenLastUsed;
use App\Models\PersonalAccessToken;
use Laravel\Sanctum\Events\TokenAuthenticated;

it('touches at most once per minute', function (): void {
    $token = PersonalAccessToken::factory()->create(['last_used_at' => null]);
    $listener = new TouchPersonalAccessTokenLastUsed;
    $listener->handle(new TokenAuthenticated($token)); $first = $token->refresh()->last_used_at;
    $listener->handle(new TokenAuthenticated($token)); expect($token->refresh()->last_used_at->equalTo($first))->toBeTrue();
    $token->updateQuietly(['last_used_at' => now()->subSeconds(61)]);
    $this->travel(61)->seconds();
    $listener->handle(new TokenAuthenticated($token)); expect($token->refresh()->last_used_at->greaterThan($first))->toBeTrue();
    expect(config('sanctum.last_used_at'))->toBeFalse()->and(config('sanctum.guard'))->toBe([]);
});
