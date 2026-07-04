<?php

declare(strict_types=1);

use App\Council\Dimension;
use App\Council\PanelFinalizer;
use App\Jobs\RunJudge;
use App\Models\Review;
use Illuminate\Support\Facades\Queue;

it('dispatches the judge immediately when quorum is met', function (): void {
    Queue::fake();
    $review = Review::factory()->create(['status' => 'running', 'mode' => 'council']);
    $review->panelResponses()->create(['model' => 'a', 'ok' => true, 'content' => 'x']);
    $review->panelResponses()->create(['model' => 'b', 'ok' => true, 'content' => 'y']);

    new PanelFinalizer()->finalize($review->id, Dimension::DomainModeling);

    Queue::assertPushed(RunJudge::class, fn(RunJudge $job): bool => $job->delay === null);
});

it('fails the review when quorum is missed', function (): void {
    Queue::fake();
    $review = Review::factory()->create(['status' => 'running', 'mode' => 'council']);
    $review->panelResponses()->create(['model' => 'a', 'ok' => true, 'content' => 'x']);
    $review->panelResponses()->create(['model' => 'b', 'ok' => false, 'error' => 'dead']);

    new PanelFinalizer()->finalize($review->id, Dimension::DomainModeling);

    $review->refresh();
    Queue::assertNothingPushed();
    expect($review->status)->toBe('error')
        ->and($review->events()->sole()->event)->toBe('review.failed');
});

it('noop when review is already in a terminal state', function (): void {
    Queue::fake();
    $review = Review::factory()->create(['status' => 'judging', 'mode' => 'council']);

    new PanelFinalizer()->finalize($review->id, Dimension::DomainModeling);

    Queue::assertNothingPushed();
    expect($review->events()->count())->toBe(0);
});
