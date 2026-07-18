<?php

declare(strict_types=1);

use App\Models\Installation;
use App\Models\OrganizationMembership;
use App\Models\Review;
use App\Models\User;

beforeEach(function (): void {
    Installation::query()->findOrFail(1)->update(['bootstrapped_at' => now()]);
});

it('shows deletion to creator and owner but not an unrelated member', function (): void {
    [$owner, $organization] = memberFixture(role: 'owner');
    $creator = User::factory()->create();
    $member = User::factory()->create();
    OrganizationMembership::factory()->for($organization)->for($creator)->create();
    OrganizationMembership::factory()->for($organization)->for($member)->create();
    $review = Review::factory()->for($organization)->create(['created_by_user_id' => $creator->id, 'status' => 'complete']);
    // Matched against the form's action="..." attribute, not the bare URL: the
    // destroy route (`/app/reviews/{id}`) is a string-prefix of the events route
    // (`/app/reviews/{id}/events`), which is always present in the page's Alpine
    // data regardless of delete permission — a bare-URL assertDontSee() would be
    // a guaranteed false failure.
    $action = 'action="' . route('app.reviews.destroy', $review->id) . '"';

    $this->actingAs($creator)->get(route('app.reviews.show', $review->id))->assertSee($action, false)->assertSee('_method', false);
    $this->actingAs($owner)->get(route('app.reviews.show', $review->id))->assertSee($action, false);
    $this->actingAs($member)->get(route('app.reviews.show', $review->id))->assertDontSee($action, false);
});

it('deletes through the scoped M3A action and redirects to history', function (): void {
    [$owner, $organization] = memberFixture(role: 'owner');
    $review = Review::factory()->for($organization)->create(['status' => 'complete']);

    $this->actingAs($owner)->delete(route('app.reviews.destroy', $review->id))
        ->assertRedirect(route('app.reviews.index'))
        ->assertSessionHas('status', 'Review deleted.');
    $this->assertDatabaseMissing('reviews', ['id' => $review->id]);
});

it('returns 404 before deletion policy for another organization', function (): void {
    [$owner] = memberFixture(role: 'owner');
    [, $otherOrganization] = memberFixture(role: 'owner');
    $review = Review::factory()->for($otherOrganization)->create(['status' => 'complete']);

    $this->actingAs($owner)->delete(route('app.reviews.destroy', $review->id))->assertNotFound();
});
