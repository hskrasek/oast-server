<?php

declare(strict_types=1);

use App\Models\Installation;
use App\Models\Review;

beforeEach(function (): void {
    Installation::query()->findOrFail(1)->update(['bootstrapped_at' => now()]);
});

it('renders retained source and the same-origin session event route', function (): void {
    [$user, $organization] = memberFixture();
    $review = Review::factory()->for($organization)->create([
        'status' => 'running',
        'spec' => "openapi: 3.1.0\n# exact\n",
    ]);

    $this->actingAs($user)->get(route('app.reviews.show', $review->id))->assertOk()
        ->assertSee(route('app.reviews.events', $review->id))
        ->assertSee('Council progress')
        ->assertSee('Inline specification')
        ->assertDontSee('api.oast.test');
});

it('returns 404 for a review in another organization', function (): void {
    [$user] = memberFixture();
    [, $otherOrganization] = memberFixture();
    $review = Review::factory()->for($otherOrganization)->create();

    $this->actingAs($user)->get(route('app.reviews.show', $review->id))->assertNotFound();
});

it('renders a complete report and a terminal failure', function (): void {
    [$user, $organization] = memberFixture();
    $complete = Review::factory()->for($organization)->create([
        'status' => 'complete',
        'findings' => [validFinding(), validFinding([
            'severity' => 'consider',
            'title' => 'Consider pagination',
            'location' => '#/paths',
        ])],
    ]);
    $failed = Review::factory()->for($organization)->create([
        'status' => 'error',
        'error' => 'Panel quorum not met',
    ]);

    $this->actingAs($user)->get(route('app.reviews.show', $complete->id))->assertOk()
        ->assertSee('Blockers')->assertSee('Consider')->assertSee('#/paths');
    $this->actingAs($user)->get(route('app.reviews.show', $failed->id))->assertOk()
        ->assertSee('Review failed')->assertSee('Panel quorum not met');
});
