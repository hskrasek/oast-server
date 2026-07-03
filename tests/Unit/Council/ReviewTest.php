<?php

declare(strict_types=1);

use App\Ai\Agents\Judge;
use App\Ai\Agents\Panelist;
use App\Council\CouncilOrchestrator;
use App\Council\Dimension;
use App\Council\Exceptions\PanelException;
use App\Council\ReviewMode;
use App\Council\ReviewRequest;

it('produces a complete council review', function () {
    Panelist::fake(['c1', 'c2', 'c3']);
    Judge::fake([['findings' => [validFinding()]]]);

    $result = orchestrator()->review('SPEC', new ReviewRequest(ReviewMode::Council));

    expect($result->status)->toBe('complete')
        ->and($result->mode)->toBe(ReviewMode::Council)
        ->and($result->panelSize)->toBe(3)
        ->and($result->findings)->toHaveCount(1)
        ->and($result->metrics)->toHaveCount(4); // 3 panel + 1 judge
});

it('fails the council review when quorum is not met', function () {
    Panelist::fake(fn() => throw new RuntimeException('down')); // all panelists fail both attempts
    Judge::fake();

    orchestrator()->review('SPEC', new ReviewRequest(ReviewMode::Council));
})->throws(PanelException::class);

it('produces a baseline review from a single model', function () {
    Panelist::fake(['only critique']);
    Judge::fake([['findings' => [validFinding()]]]);

    $result = orchestrator()->review('SPEC', new ReviewRequest(ReviewMode::Baseline));

    expect($result->mode)->toBe(ReviewMode::Baseline)
        ->and($result->panelSize)->toBe(1)
        ->and($result->panelists)->toBe(['a/one']) // first panelist as baseline
        ->and($result->findings)->toHaveCount(1);
});

it('completes a baseline review even when the single panelist fails', function () {
    Panelist::fake(fn() => throw new RuntimeException('down'));
    Judge::fake([['findings' => [validFinding()]]]);

    $result = orchestrator()->review('SPEC', new ReviewRequest(ReviewMode::Baseline));

    expect($result->status)->toBe('complete')
        ->and($result->mode)->toBe(ReviewMode::Baseline)
        ->and($result->panelSize)->toBe(0);
});

it('threads the requested dimension through to the result', function () {
    Panelist::fake(['c1', 'c2', 'c3']);
    Judge::fake([['findings' => [validFinding(['dimension' => 'workflows'])]]]);

    $result = orchestrator()->review('SPEC', new ReviewRequest(ReviewMode::Council, Dimension::Workflows));

    expect($result->dimension)->toBe('workflows')
        ->and($result->status)->toBe('complete');
});

it('resolves the orchestrator from the container', function () {
    expect(app(CouncilOrchestrator::class))->toBeInstanceOf(CouncilOrchestrator::class);
});
