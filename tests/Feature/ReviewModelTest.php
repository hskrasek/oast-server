<?php

declare(strict_types=1);

use App\Council\ReviewMode;
use App\Council\ReviewResult;
use App\Models\Review;

it('persists a review result and casts json columns', function () {
    $result = new ReviewResult(
        mode: ReviewMode::Council,
        dimension: 'domain-modeling',
        panelists: ['a/one', 'b/two'],
        panelSize: 2,
        rawPanelistResponses: [['model' => 'a/one', 'ok' => true, 'content' => 'c', 'error' => null]],
        findings: [['title' => 'finding one']],
        metrics: [['model' => 'a/one', 'ms' => 10]],
        status: 'complete',
    );

    $review = Review::fromResult($result, 'openapi.yaml', 'abc123');

    $fresh = Review::find($review->id);
    expect($fresh->spec_ref)->toBe('openapi.yaml')
        ->and($fresh->spec_hash)->toBe('abc123')
        ->and($fresh->mode)->toBe('council')
        ->and($fresh->panel_size)->toBe(2)
        ->and($fresh->findings)->toBe([['title' => 'finding one']])
        ->and($fresh->metrics[0]['model'])->toBe('a/one')
        ->and($fresh->status)->toBe('complete');
});
