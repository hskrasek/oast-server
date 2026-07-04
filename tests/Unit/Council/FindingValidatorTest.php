<?php

declare(strict_types=1);

use App\Council\Exceptions\JudgeException;
use App\Council\FindingValidator;

it('returns valid findings unchanged', function (): void {
    $findings = [validFinding()];
    expect((new FindingValidator)->validate($findings))->toBe($findings);
});

it('allows an empty findings list (a clean spec)', function (): void {
    expect((new FindingValidator)->validate([]))->toBe([]);
});

it('requires disagreement when confidence is split', function (): void {
    (new FindingValidator)->validate([validFinding(['confidence' => 'split'])]);
})->throws(JudgeException::class);

it('accepts a split finding that includes disagreement', function (): void {
    $findings = [validFinding(['confidence' => 'split', 'disagreement' => 'Model A says X; Model B disagrees.'])];
    expect((new FindingValidator)->validate($findings))->toBe($findings);
});

it('exposes validation errors on the exception', function (): void {
    try {
        (new FindingValidator)->validate([validFinding(['confidence' => 'split'])]);
        $this->fail('expected exception');
    } catch (JudgeException $judgeException) {
        expect($judgeException->errors)->toBeArray()->not->toBeEmpty();
    }
});

it('rejects a location that is not a JSON Pointer fragment', function (string|int|null $location): void {
    (new FindingValidator)->validate([validFinding(['location' => $location])]);
})->with([
    'bare path' => '/paths/~1orders',
    'plain anchor' => 'tags',
    'missing' => null,
    'non-string' => 7,
])->throws(JudgeException::class);

it('skips non-array findings', function (): void {
    $findings = ['not-an-array', validFinding()];

    expect((new FindingValidator)->validate($findings))->toBe($findings);
});
