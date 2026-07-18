<?php

declare(strict_types=1);

use App\Models\Review;
use App\Models\User;
use App\Policies\ReviewPolicy;

it('mirrors view authorization for the follow ability', function (): void {
    [$user, $organization] = memberFixture();
    $review = Review::factory()->for($organization)->create();
    $outsider = User::factory()->create();
    $policy = new ReviewPolicy();

    expect($policy->follow($user, $review))->toBeTrue()
        ->and($policy->follow($outsider, $review))->toBeFalse();
});
