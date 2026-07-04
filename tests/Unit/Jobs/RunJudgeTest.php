<?php

declare(strict_types=1);

use App\Ai\Agents\Judge;
use App\Council\Dimension;
use App\Jobs\RunJudge;
use App\Models\Review;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::fake(['openrouter.ai/api/v1/models' => Http::response(['data' => []])]);
});

function reviewWithPanel(string $status = 'running'): Review
{
    $review = Review::factory()->create(['status' => $status, 'spec' => 'openapi: 3.1.0']);
    $review->panelResponses()->create(['model' => 'a', 'ok' => true, 'content' => 'crit-a', 'ms' => 10, 'usage' => ['prompt_tokens' => 5]]);
    $review->panelResponses()->create(['model' => 'b', 'ok' => true, 'content' => 'crit-b', 'ms' => 20, 'usage' => ['prompt_tokens' => 6]]);
    $review->panelResponses()->create(['model' => 'c', 'ok' => true, 'content' => 'late', 'ms' => 99, 'late' => true]);

    return $review;
}

it('judges non-late critiques and completes the review', function (): void {
    Judge::fake([['findings' => [validFinding()]]]);
    $review = reviewWithPanel();

    new RunJudge($review->id, Dimension::DomainModeling)->handle();

    $review->refresh();
    expect($review->status)->toBe('complete')
        ->and($review->findings)->toHaveCount(1)
        ->and($review->panel_size)->toBe(2) // late panelist excluded
        ->and($review->events()->pluck('event')->all())
        ->toBe(['judge.start', 'judge.done', 'review.completed']);
});

it('no-ops when another judge won the CAS', function (): void {
    Judge::fake([['findings' => [validFinding()]]]);
    $review = reviewWithPanel(status: 'judging');

    new RunJudge($review->id, Dimension::DomainModeling)->handle();

    expect($review->refresh()->status)->toBe('judging')
        ->and($review->events()->count())->toBe(0);
});

it('fails the review when the judge output stays invalid', function (): void {
    Judge::fake([
        ['findings' => [validFinding(['confidence' => 'split'])]],
        ['findings' => [validFinding(['confidence' => 'split'])]],
    ]);
    $review = reviewWithPanel();

    new RunJudge($review->id, Dimension::DomainModeling)->handle();

    $review->refresh();
    expect($review->status)->toBe('error')
        ->and($review->events()->pluck('event')->all())->toBe(['judge.start', 'review.failed']);
});

it('throws when judge model configuration is missing', function (): void {
    Judge::fake([['findings' => [validFinding()]]]);
    $review = reviewWithPanel();
    config(['oast' => []]);

    expect(fn() => new RunJudge($review->id, Dimension::DomainModeling)->handle())
        ->toThrow(RuntimeException::class, 'Judge model configuration is missing or invalid.');
});

it('marks the review errored and records review.failed when the judge job fails', function (): void {
    $review = reviewWithPanel(status: 'judging');

    new RunJudge($review->id, Dimension::DomainModeling)->failed(new RuntimeException('boom'));

    $review->refresh();
    expect($review->status)->toBe('error')
        ->and($review->events()->pluck('event')->all())->toBe(['review.failed'])
        ->and($review->events()->sole()->data)->toBe([
            'stage' => 'judge',
            'problem' => ['title' => 'Judge run failed', 'detail' => 'boom'],
        ]);
});

it('leaves a completed review untouched when the judge job fails afterward', function (): void {
    $review = reviewWithPanel(status: 'complete');

    new RunJudge($review->id, Dimension::DomainModeling)->failed(new RuntimeException('boom'));

    $review->refresh();
    expect($review->status)->toBe('complete')
        ->and($review->events()->count())->toBe(0);
});

it('no-ops the failed hook when the review is missing', function (): void {
    new RunJudge(9999, Dimension::DomainModeling)->failed(new RuntimeException('boom'));

    expect(true)->toBeTrue();
});
