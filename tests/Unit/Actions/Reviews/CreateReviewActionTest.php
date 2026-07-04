<?php

declare(strict_types=1);

use App\Actions\Reviews\CreateReviewAction;
use App\Council\ReviewMode;
use App\Jobs\RunPanelist;
use Illuminate\Support\Facades\Bus;

it('creates a running review and batches one job per panelist', function (): void {
    Bus::fake();

    $review = app(CreateReviewAction::class)('openapi: 3.1.0', ReviewMode::Council, 'spec.yaml');

    expect($review->status)->toBe('running')
        ->and($review->spec)->toBe('openapi: 3.1.0')
        ->and($review->mode)->toBe('council');

    Bus::assertBatched(fn($batch): bool => $batch->jobs->count() === count(config('oast.panelists'))
        && $batch->jobs->every(fn($job): bool => $job instanceof RunPanelist));
});

it('batches a single job for baseline mode', function (): void {
    Bus::fake();

    app(CreateReviewAction::class)('openapi: 3.1.0', ReviewMode::Baseline, 'spec.yaml');

    Bus::assertBatched(fn($batch): bool => $batch->jobs->count() === 1);
});

it('runs end-to-end on the sync queue', function (): void {
    fakeCouncil(); // Panelist + Judge fakes from tests/Pest.php
    Illuminate\Support\Facades\Http::fake(['openrouter.ai/api/v1/models' => Illuminate\Support\Facades\Http::response(['data' => []])]);

    $review = app(CreateReviewAction::class)('openapi: 3.1.0', ReviewMode::Council, 'spec.yaml');

    expect($review->refresh()->status)->toBe('complete')
        ->and($review->findings)->not->toBeEmpty()
        ->and($review->events()->pluck('event'))->toContain('review.completed');
});
