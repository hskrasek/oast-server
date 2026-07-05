<?php

declare(strict_types=1);

use App\Ai\Agents\Panelist;
use App\Council\Dimension;
use App\Jobs\RunJudge;
use App\Jobs\RunPanelist;
use App\Models\Review;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Http::fake(['openrouter.ai/api/v1/models' => Http::response(['data' => []])]);
});

it('no-ops on queue redelivery when a successful row already exists', function (): void {
    Panelist::fake(['critique']);
    $review = Review::factory()->create(['status' => 'running', 'mode' => 'council']);
    $review->panelResponses()->create(['model' => 'openai/gpt-5.5', 'ok' => true, 'content' => 'first run']);

    new RunPanelist($review->id, 'openai/gpt-5.5', Dimension::DomainModeling)->handle();

    expect($review->panelResponses()->where('model', 'openai/gpt-5.5')->count())->toBe(1)
        ->and($review->events()->count())->toBe(0);
});

it('stores a successful response and emits start/done events', function (): void {
    Panelist::fake(['a sharp critique']);
    $review = Review::factory()->create(['status' => 'running', 'mode' => 'council']);

    new RunPanelist($review->id, 'openai/gpt-5.5', Dimension::DomainModeling)->handle();

    $row = $review->panelResponses()->sole();
    expect($row->ok)->toBeTrue()
        ->and($row->content)->toBe('a sharp critique')
        ->and($review->events()->pluck('event')->all())->toBe(['panel.model.start', 'panel.model.done']);
});

it('marks the response late and skips quorum when the judge already started', function (): void {
    Panelist::fake(['slow critique']);
    Queue::fake();
    $review = Review::factory()->create(['status' => 'judging', 'mode' => 'council']);

    new RunPanelist($review->id, 'z-ai/glm-5.2', Dimension::DomainModeling)->handle();

    expect($review->panelResponses()->sole()->late)->toBeTrue()
        ->and($review->events()->pluck('event')->all())->toBe(['panel.model.start', 'panel.model.late']);
    Queue::assertNotPushed(RunJudge::class);
});

it('dispatches a grace-delayed judge when quorum is reached off the sync driver', function (): void {
    config()->set('queue.default', 'database');
    config()->set('oast.quorum', 1);
    config()->set('oast.quorum_grace', 60);
    Panelist::fake(['critique']);
    Queue::fake();
    $review = Review::factory()->create(['status' => 'running', 'mode' => 'council']);

    new RunPanelist($review->id, 'openai/gpt-5.5', Dimension::DomainModeling)->handle();

    Queue::assertPushed(RunJudge::class, fn(RunJudge $job): bool => $job->delay === 60);
});

it('records the failure row and event via the failed hook', function (): void {
    $review = Review::factory()->create(['status' => 'running']);
    $job = new RunPanelist($review->id, 'openai/gpt-5.5', Dimension::DomainModeling);

    $job->failed(new RuntimeException('upstream 500'));

    $row = $review->panelResponses()->sole();
    expect($row->ok)->toBeFalse()
        ->and($row->error)->toBe('upstream 500')
        ->and($review->events()->pluck('event')->all())->toBe(['panel.model.failed']);
});

it('silently handles failure when the review is deleted', function (): void {
    $job = new RunPanelist(9999, 'openai/gpt-5.5', Dimension::DomainModeling);

    $job->failed(new RuntimeException('upstream 500'));

    // No exception thrown, no side effects
    expect(true)->toBeTrue();
});
