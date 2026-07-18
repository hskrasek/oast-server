<?php

declare(strict_types=1);

use App\Models\Installation;
use App\Models\Review;
use App\Models\ReviewPanelResponse;

beforeEach(fn() => Installation::query()->findOrFail(1)->update(['bootstrapped_at' => now()]));

it('allows creator or owner deletion and restricts creatorless reviews to owner', function (): void {
    [$owner, $organization] = memberFixture(role: 'owner');
    $member = App\Models\User::factory()->create();
    App\Models\OrganizationMembership::factory()->for($organization)->for($member)->create();
    $own = Review::factory()->for($organization)->create(['created_by_user_id' => $member->id, 'status' => 'complete']);
    $legacy = Review::factory()->for($organization)->create(['created_by_user_id' => null, 'status' => 'complete']);
    $this->actingAs($member)->delete(route('app.reviews.destroy', $own))->assertRedirect(route('app.reviews.index'));
    $this->actingAs($member)->delete(route('app.reviews.destroy', $legacy))->assertForbidden();
    $this->actingAs($owner)->delete(route('app.reviews.destroy', $legacy))->assertRedirect(route('app.reviews.index'));
});

it('forbids deleting a review that is still queued running or judging', function (string $status): void {
    [$owner, $organization] = memberFixture(role: 'owner');
    $review = Review::factory()->for($organization)->create(['created_by_user_id' => $owner->id, 'status' => $status]);

    $this->actingAs($owner)->delete(route('app.reviews.destroy', $review))->assertForbidden();
    expect(Review::query()->whereKey($review->id)->exists())->toBeTrue();
})->with(['queued', 'running', 'judging']);

it('cascades events and panel responses', function (): void {
    [$owner, $organization] = memberFixture(role: 'owner');
    $review = Review::factory()->for($organization)->create(['status' => 'complete']);
    $review->appendEvent('review.queued', []);
    $review->panelResponses()->create(['model' => 'a/one', 'ok' => true, 'ms' => 1, 'late' => false]);
    $this->actingAs($owner)->delete(route('app.reviews.destroy', $review))->assertRedirect(route('app.reviews.index'));
    expect(App\Models\ReviewEvent::query()->count())->toBe(0)->and(ReviewPanelResponse::query()->count())->toBe(0);
});
