<?php

declare(strict_types=1);

use App\Council\Exceptions\JudgeException;
use App\Council\FindingValidator;

function validFinding(array $overrides = []): array
{
    return array_merge([
        'dimension' => 'domain-modeling',
        'title' => 'Order exposes DB join table',
        'severity' => 'blocker',
        'confidence' => 'consensus',
        'location' => '#/paths/~1order_line_items',
        'finding' => 'A join table is exposed as a resource.',
        'why_it_matters' => 'Chains the public contract to the DB schema.',
        'suggested_change' => 'Model orders and line items as domain resources.',
    ], $overrides);
}

it('returns valid findings unchanged', function () {
    $findings = [validFinding()];
    expect((new FindingValidator)->validate($findings))->toBe($findings);
});

it('allows an empty findings list (a clean spec)', function () {
    expect((new FindingValidator)->validate([]))->toBe([]);
});

it('requires disagreement when confidence is split', function () {
    (new FindingValidator)->validate([validFinding(['confidence' => 'split'])]);
})->throws(JudgeException::class);

it('accepts a split finding that includes disagreement', function () {
    $findings = [validFinding(['confidence' => 'split', 'disagreement' => 'Model A says X; Model B disagrees.'])];
    expect((new FindingValidator)->validate($findings))->toBe($findings);
});

it('exposes validation errors on the exception', function () {
    try {
        (new FindingValidator)->validate([validFinding(['confidence' => 'split'])]);
        $this->fail('expected exception');
    } catch (JudgeException $e) {
        expect($e->errors)->toBeArray()->not->toBeEmpty();
    }
});
