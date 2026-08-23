<?php

declare(strict_types=1);

use App\Actions\Reviews\CreateReviewAction;
use App\Council\ReviewMode;
use App\Jobs\RunPanelist;
use Illuminate\Support\Facades\Bus;

it('creates a running review and batches one job per panelist', function (): void {
    Bus::fake();
    [$user, $organization] = memberFixture();

    $review = app(CreateReviewAction::class)('openapi: 3.1.0', ReviewMode::Council, $organization, $user, 'spec.yaml');

    expect($review->status)->toBe('running')
        ->and($review->spec)->toBe('openapi: 3.1.0')
        ->and($review->mode)->toBe('council');

    Bus::assertBatched(fn($batch): bool => $batch->jobs->count() === count(config('oast.panelists'))
        && $batch->jobs->every(fn($job): bool => $job instanceof RunPanelist));
});

it('batches a single job for baseline mode', function (): void {
    Bus::fake();
    [$user, $organization] = memberFixture();

    app(CreateReviewAction::class)('openapi: 3.1.0', ReviewMode::Baseline, $organization, $user, 'spec.yaml');

    Bus::assertBatched(fn($batch): bool => $batch->jobs->count() === 1);
});

it('runs end-to-end on the sync queue', function (): void {
    fakeCouncil(); // Panelist + Judge fakes from tests/Pest.php
    Illuminate\Support\Facades\Http::fake(['openrouter.ai/api/v1/models' => Illuminate\Support\Facades\Http::response(['data' => []])]);
    [$user, $organization] = memberFixture();

    $review = app(CreateReviewAction::class)('openapi: 3.1.0', ReviewMode::Council, $organization, $user, 'spec.yaml');

    expect($review->refresh()->status)->toBe('complete')
        ->and($review->findings)->not->toBeEmpty()
        ->and($review->events()->pluck('event'))->toContain('review.completed');
});

it('dispatches the unchanged batch only after the ownership transaction commits', function (): void {
    Bus::fake();
    [$user, $organization] = memberFixture();
    $review = app(CreateReviewAction::class)('openapi: 3.1.0', ReviewMode::Council, $organization, $user, 'spec.yaml');
    expect($review->organization_id)->toBe($organization->id)->and($review->created_by_user_id)->toBe($user->id);
    Bus::assertBatched(fn($batch): bool => $batch->jobs->count() === count(config('oast.panelists'))
        && $batch->jobs->every(fn($job): bool => $job instanceof RunPanelist));
});
