<?php

declare(strict_types=1);

use App\Models\Review;
use App\Models\ReviewEvent;

it('appends ordered events with array data', function (): void {
    $review = Review::factory()->create();

    $first = $review->appendEvent('panel.model.start', ['model' => 'openai/gpt-5.5']);
    $second = $review->appendEvent('panel.model.done', ['model' => 'openai/gpt-5.5', 'ms' => 42]);

    expect($second->id)->toBeGreaterThan($first->id)
        ->and($first->data)->toBe(['model' => 'openai/gpt-5.5'])
        ->and($review->events)->toHaveCount(2);
});

it('has relationship to review', function (): void {
    $review = Review::factory()->create();
    $event = $review->appendEvent('test.event', ['data' => 'value']);

    $loaded = ReviewEvent::query()->firstOrFail();

    expect($loaded->review)->toBeInstanceOf(Review::class)
        ->and($loaded->review->id)->toBe($review->id);
});
