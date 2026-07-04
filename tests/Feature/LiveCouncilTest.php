<?php

declare(strict_types=1);

use App\Actions\Reviews\CreateReviewAction;
use App\Council\ReviewMode;

it('runs a real council review against OpenRouter', function (): void {
    if (blank(config('ai.providers.openrouter.key'))) {
        $this->markTestSkipped('OPENROUTER_API_KEY not set.');
    }

    $spec = <<<'YAML'
    openapi: 3.1.0
    info: { title: Demo, version: 1.0.0 }
    paths:
      /order_line_items:
        get: { responses: { '200': { description: ok } } }
    YAML;

    // QUEUE_CONNECTION=sync in the test environment runs the panel batch and
    // judge job inline, so the review is already terminal once this returns.
    $review = app(CreateReviewAction::class)($spec, ReviewMode::Council);

    expect($review->refresh()->status)->toBe('complete')
        ->and($review->findings)->not->toBeEmpty();
})->group('live');
