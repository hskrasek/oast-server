<?php

declare(strict_types=1);

use App\Ai\Agents\Judge;
use App\Council\Exceptions\JudgeException;

// orchestrator() comes from tests/Pest.php (Task 5, Step 1a); validFinding() from FindingValidatorTest is
// also globally available within the suite. If running this file in isolation, copy validFinding() locally.

it('runs the judge and returns validated findings', function () {
    Judge::fake([['findings' => [validFinding()]]]);

    $result = orchestrator()->runJudge('SPEC', [['model' => 'a/one', 'content' => 'crit']]);

    expect($result['findings'])->toHaveCount(1)
        ->and($result['findings'][0]['severity'])->toBe('blocker')
        ->and($result['ms'])->toBeInt();

    Judge::assertPrompted(fn($prompt) => str_contains($prompt->prompt, 'crit'));
});

it('re-prompts once when the first judge output is invalid, then succeeds', function () {
    Judge::fake([
        ['findings' => [validFinding(['confidence' => 'split'])]], // invalid: split w/o disagreement
        ['findings' => [validFinding()]],                          // valid
    ]);

    $result = orchestrator()->runJudge('SPEC', [['model' => 'a/one', 'content' => 'crit']]);

    expect($result['findings'])->toHaveCount(1);
});

it('throws when the judge is invalid twice', function () {
    Judge::fake([
        ['findings' => [validFinding(['confidence' => 'split'])]],
        ['findings' => [validFinding(['confidence' => 'split'])]],
    ]);

    orchestrator()->runJudge('SPEC', [['model' => 'a/one', 'content' => 'crit']]);
})->throws(JudgeException::class);
