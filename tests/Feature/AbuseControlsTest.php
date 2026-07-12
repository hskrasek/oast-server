<?php

declare(strict_types=1);

use App\Models\Review;
use App\Tokens\PersonalAccessTokenService;
use Illuminate\Support\Facades\Bus;

it('serializes and rejects the organization active-review ceiling for API and browser', function (): void {
    Bus::fake();
    config(['oast.max_active_reviews' => 1]);
    [$user, $organization] = memberFixture();
    App\Models\Installation::query()->findOrFail(1)->update(['bootstrapped_at' => now()]);
    Review::factory()->for($organization)->create(['status' => 'judging']);
    $token = app(PersonalAccessTokenService::class)->create($user, $organization, 'CI', null);
    $this->withToken($token->plainTextToken)->postJson("https://{$this->apiHost()}/reviews", ['spec' => 'openapi: 3.1.0'])
        ->assertTooManyRequests()->assertHeader('Retry-After', '60')
        ->assertHeader('Content-Type', 'application/problem+json');
    $this->actingAs($user)->post(route('app.reviews.store'), ['spec' => 'openapi: 3.1.0'])
        ->assertTooManyRequests()->assertHeader('Retry-After', '60')->assertSee('Too many active reviews.');
    expect(Review::query()->where('organization_id', $organization->id)->count())->toBe(1);
});
