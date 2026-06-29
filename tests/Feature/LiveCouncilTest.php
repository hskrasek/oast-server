<?php

declare(strict_types=1);

use App\Council\CouncilOrchestrator;
use App\Council\ReviewMode;
use App\Council\ReviewRequest;

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

    $result = app(CouncilOrchestrator::class)->review($spec, new ReviewRequest(ReviewMode::Council));

    expect($result->status)->toBe('complete')
        ->and($result->findings)->not->toBeEmpty();
})->group('live');
