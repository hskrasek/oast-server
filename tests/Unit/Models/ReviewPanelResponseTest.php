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
    $review->panelResponses()->create([
        'model' => 'z-ai/glm-5.2',
        'ok' => true,
        'content' => 'critique',
        'ms' => 166306,
    ]);

    $loaded = ReviewPanelResponse::query()->firstOrFail();

    expect($loaded->review)->toBeInstanceOf(Review::class)
        ->and($loaded->review->id)->toBe($review->id);
});

it('casts ok as boolean', function (): void {
    $review = Review::factory()->create();

    $response = $review->panelResponses()->create([
        'model' => 'test-model',
        'ok' => false,
        'late' => true,
    ]);

    expect($response->ok)->toBeFalse()
        ->and($response->late)->toBeTrue();
});

it('casts usage and cost_usd properly', function (): void {
    $review = Review::factory()->create();

    $response = $review->panelResponses()->create([
        'model' => 'test-model',
        'ok' => true,
        'usage' => ['completion_tokens' => 99],
        'cost_usd' => 0.456,
    ]);

    expect($response->usage)->toBeArray()
        ->and($response->usage['completion_tokens'])->toBe(99)
        ->and($response->cost_usd)->toBe(0.456);
});

it('is fillable with all supported attributes', function (): void {
    $review = Review::factory()->create();

    $response = new ReviewPanelResponse([
        'model' => 'manual-model',
        'ok' => true,
        'content' => 'manual critique',
        'error' => null,
        'ms' => 1000,
        'usage' => ['input' => 5],
        'cost_usd' => 0.001,
        'late' => false,
    ]);
    $response->review()->associate($review);
    $response->save();

    expect($response->model)->toBe('manual-model')
        ->and($response->content)->toBe('manual critique')
        ->and($response->ms)->toBe(1000);
});
