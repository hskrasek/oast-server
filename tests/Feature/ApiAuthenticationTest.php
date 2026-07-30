<?php

declare(strict_types=1);

use App\Http\Problems\ProblemType;
use App\Tokens\PersonalAccessTokenService;
use Illuminate\Support\Facades\Bus;

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
