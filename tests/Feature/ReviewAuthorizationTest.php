<?php

declare(strict_types=1);

use App\Models\Review;
use App\Tokens\PersonalAccessTokenService;
use Illuminate\Support\Facades\Bus;

it('derives organization and creator from the token and ignores hostile ids', function (): void {
    Bus::fake();
    [$user, $organization] = memberFixture();
    [, $other] = memberFixture();
    $token = app(PersonalAccessTokenService::class)->create($user, $organization, 'CI', null);
    $response = $this->withToken($token->plainTextToken)->postJson("https://{$this->apiHost()}/reviews", [
        'spec' => 'openapi: 3.1.0', 'organization_id' => $other->id, 'created_by_user_id' => 999,
    ])->assertAccepted();
    $review = Review::query()->findOrFail($response->json('data.id'));
    expect($review->organization_id)->toBe($organization->id)->and($review->created_by_user_id)->toBe($user->id);
});

it('returns the identical 404 for unknown and cross organization ids', function (): void {
    [$user, $organization] = memberFixture();
    [, $other] = memberFixture();
    $token = app(PersonalAccessTokenService::class)->create($user, $organization, 'CI', null);
    $cross = Review::factory()->for($other)->create();
    $a = $this->withToken($token->plainTextToken)->getJson("https://{$this->apiHost()}/reviews/{$cross->id}");
    $b = $this->withToken($token->plainTextToken)->getJson("https://{$this->apiHost()}/reviews/999999");
    $a->assertNotFound();
    $b->assertNotFound();
    expect($a->getContent())->toBe($b->getContent());
});
