<?php

declare(strict_types=1);

use App\Council\PanelResponse;
use App\Council\ReviewMode;
use App\Council\ReviewResult;

it('builds review mode from string', function (): void {
    expect(ReviewMode::from('council'))->toBe(ReviewMode::Council)
        ->and(ReviewMode::from('baseline'))->toBe(ReviewMode::Baseline);
});

it('constructs ok and failed panel responses', function (): void {
    $ok = PanelResponse::success('openai/gpt', 'critique text', 1200);
    expect($ok->ok)->toBeTrue()
        ->and($ok->content)->toBe('critique text')
        ->and($ok->ms)->toBe(1200);

    $failed = PanelResponse::failure('google/gemini', 'timeout');
    expect($failed->ok)->toBeFalse()
        ->and($failed->content)->toBeNull()
        ->and($failed->error)->toBe('timeout');
});

it('serializes a review result to a snake_case array', function (): void {
    $result = new ReviewResult(
        mode: ReviewMode::Council,
        dimension: 'domain-modeling',
        panelists: ['a', 'b'],
        panelSize: 2,
        rawPanelistResponses: [['model' => 'a', 'ok' => true, 'content' => 'x', 'error' => null]],
        findings: [['title' => 'f']],
        metrics: [['model' => 'a', 'ms' => 10]],
        status: 'complete',
    );

    expect($result->toArray())->toMatchArray([
        'mode' => 'council',
        'dimension' => 'domain-modeling',
        'panelists' => ['a', 'b'],
        'panel_size' => 2,
        'status' => 'complete',
    ]);
});
