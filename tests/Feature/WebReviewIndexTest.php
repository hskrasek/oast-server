<?php

declare(strict_types=1);

use App\Models\Installation;
use App\Models\Organization;
use App\Models\Review;

beforeEach(function (): void {
    Installation::query()->findOrFail(1)->update(['bootstrapped_at' => now()]);
});

it('requires authentication for review history', function (): void {
    $this->get('/app/reviews')->assertRedirect('/login');
});

it('shows only current organization history and all real status labels', function (): void {
    [$user, $organization] = memberFixture(role: 'owner');
    $other = Organization::factory()->create();

    foreach (['queued', 'running', 'judging', 'complete', 'error'] as $status) {
        Review::factory()->for($organization)->create([
            'status' => $status,
            'spec_ref' => $status . '.yaml',
            'metrics' => $status === 'complete' ? [['total_cost_usd' => 0.125]] : null,
            'error' => $status === 'error' ? 'Panel quorum not met' : null,
        ]);
    }
    Review::factory()->for($other)->create(['spec_ref' => 'private.yaml']);

    $response = $this->actingAs($user)->get('/app/reviews')->assertOk();
    foreach (['Queued', 'Running', 'Judging', 'Complete', 'Failed'] as $label) {
        $response->assertSee($label);
    }
    $response->assertSee('$0.1250')->assertSee('Panel quorum not met')->assertDontSee('private.yaml');
});

it('renders an explicit empty state and create action', function (): void {
    [$user] = memberFixture(role: 'owner');

    $this->actingAs($user)->get('/app/reviews')->assertOk()
        ->assertSee('No reviews yet')
        ->assertSee('Start a review')
        ->assertSee(route('app.reviews.create'));
});
