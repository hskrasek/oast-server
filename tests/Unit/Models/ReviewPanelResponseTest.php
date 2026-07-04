<?php

declare(strict_types=1);

use App\Models\Review;
use App\Models\ReviewPanelResponse;

it('stores per-panelist rows with usage and late flag', function (): void {
    $review = Review::factory()->create();

    $row = $review->panelResponses()->create([
        'model' => 'z-ai/glm-5.2',
        'ok' => true,
        'content' => 'critique',
        'ms' => 166306,
        'usage' => ['prompt_tokens' => 10149],
        'cost_usd' => 0.0123,
        'late' => true,
    ]);

    expect($row->usage)->toBe(['prompt_tokens' => 10149])
        ->and($row->late)->toBeTrue()
        ->and($review->panelResponses()->count())->toBe(1);
});

it('has relationship to review', function (): void {
    $review = Review::factory()->create();
    $response = $review->panelResponses()->create([
        'model' => 'z-ai/glm-5.2',
        'ok' => true,
        'content' => 'critique',
        'ms' => 166306,
    ]);

    $loaded = ReviewPanelResponse::query()->firstOrFail();

    expect($loaded->review)->toBeInstanceOf(Review::class)
        ->and($loaded->review->id)->toBe($review->id);
});
