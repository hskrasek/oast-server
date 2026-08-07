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
    $review = Review::factory()->for($organization)->create(['created_by_user_id' => $creator->id]);
    // Deviation from the brief: `app.reviews.destroy` and `app.reviews.show` share the
    // same URI (`/app/reviews/{review}`, differing only by HTTP verb), and the page also
    // embeds `eventsUrl: '.../reviews/{id}/events'`. A bare-URL assertSee/assertDontSee
    // on $action is always a substring match against that eventsUrl, regardless of
    // whether the delete form actually renders. Asserting on the quoted form `action="..."`
    // attribute disambiguates the two.
    $formAction = 'action="' . route('app.reviews.destroy', $review->id) . '"';

    $this->actingAs($creator)->get(route('app.reviews.show', $review->id))->assertSee($formAction, false)->assertSee('_method', false);
    $this->actingAs($owner)->get(route('app.reviews.show', $review->id))->assertSee($formAction, false);
    $this->actingAs($member)->get(route('app.reviews.show', $review->id))->assertDontSee($formAction, false);
});

it('deletes through the scoped M3A action and redirects to history', function (): void {
    [$owner, $organization] = memberFixture(role: 'owner');
    $review = Review::factory()->for($organization)->create();

    $this->actingAs($owner)->delete(route('app.reviews.destroy', $review->id))
        ->assertRedirect(route('app.reviews.index'))
        ->assertSessionHas('status', 'Review deleted.');
    $this->assertDatabaseMissing('reviews', ['id' => $review->id]);
});

it('returns 404 before deletion policy for another organization', function (): void {
    [$owner] = memberFixture(role: 'owner');
    [, $otherOrganization] = memberFixture(role: 'owner');
    $review = Review::factory()->for($otherOrganization)->create();

    $this->actingAs($owner)->delete(route('app.reviews.destroy', $review->id))->assertNotFound();
});
