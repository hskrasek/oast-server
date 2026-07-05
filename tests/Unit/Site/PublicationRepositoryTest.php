<?php

declare(strict_types=1);

use App\Site\Publication;
use App\Site\PublicationRepository;

function fixtureRepo(): PublicationRepository
{
    return new PublicationRepository(base_path('tests/fixtures/publications'));
}

it('loads publications sorted by published_at desc and skips malformed files', function (): void {
    $all = fixtureRepo()->all();

    // Should have 3: train-travel-domain-modeling, no-cost-review, mixed-types
    // Skipped: malformed.json, missing-date.json, non-array.json
    expect($all)->toHaveCount(3)
        ->and($all[0]->slug)->toBe('train-travel-domain-modeling')
        ->and($all[0]->findingCounts())->toBe(['blocker' => 1, 'should-fix' => 0, 'consider' => 1])
        ->and($all[0]->totalCostUsd())->toBe(0.62)
        ->and($all[1]->slug)->toBe('no-cost-review')
        ->and($all[1]->totalCostUsd())->toBeNull();
});

it('finds by slug and returns null for unknown slugs', function (): void {
    expect(fixtureRepo()->find('train-travel-domain-modeling'))->not->toBeNull()
        ->and(fixtureRepo()->find('mixed-types'))->not->toBeNull()
        ->and(fixtureRepo()->find('nope'))->toBeNull();
});

it('handles mixed types gracefully', function (): void {
    $pub = fixtureRepo()->find('mixed-types');
    expect($pub)->not->toBeNull();
    // Verify that mixed types are converted to strings
    expect($pub->headline)->toBeString()->toBe('123');
    expect($pub->commentaryMd)->toBeString()->toBe('1');
    expect($pub->specName)->toBeString()->toBe('45.67');
    expect($pub->specSourceUrl)->toBeString()->toBe('');
});

it('memoizes the directory scan per instance', function (): void {
    $repo = fixtureRepo();
    expect($repo->all())->toBe($repo->all()); // same array instance contents; second call must not rescan
});

it('returns an empty list when the directory does not exist', function (): void {
    expect(new PublicationRepository(base_path('nope'))->all())->toBe([]);
});

it('constructs publication from array with various types', function (): void {
    $pub = Publication::fromArray([
        'slug' => 'test',
        'headline' => 'headline',
        'commentary_md' => 'comment',
        'spec_name' => 'spec',
        'spec_source_url' => 'https://example.com',
        'spec_license' => 'MIT',
        'dimension' => 'test-dim',
        'panelists' => ['model1', 'model2'],
        'judge' => 'judge-model',
        'findings' => [],
        'metrics' => [],
        'reviewed_at' => '2026-01-01T00:00:00Z',
        'published_at' => '2026-01-02T00:00:00Z',
    ]);

    expect($pub->slug)->toBe('test')
        ->and($pub->headline)->toBe('headline');
});

it('handles asString type coercion for all scalar types', function (): void {
    // Test string (already string)
    expect(Publication::asString('hello'))->toBe('hello');
    // Test null
    expect(Publication::asString(null))->toBe('');
    // Test array
    expect(Publication::asString([]))->toBe('');
    // Test boolean true
    expect(Publication::asString(true))->toBe('1');
    // Test boolean false
    expect(Publication::asString(false))->toBe('');
    // Test integer
    expect(Publication::asString(42))->toBe('42');
    // Test float
    expect(Publication::asString(3.14))->toBe('3.14');
});

it('counts findings by severity and ignores unknown severities', function (): void {
    $pub = Publication::fromArray([
        'slug' => 'test',
        'headline' => 'test',
        'spec_name' => 'test',
        'dimension' => 'test',
        'panelists' => [],
        'judge' => 'test',
        'findings' => [
            ['severity' => 'blocker'],
            ['severity' => 'should-fix'],
            ['severity' => 'consider'],
            ['severity' => 'blocker'],
            ['severity' => 'unknown-severity'],
            ['no-severity-key' => 'value'],
            'not-an-array',
        ],
        'metrics' => [],
        'reviewed_at' => '2026-01-01T00:00:00Z',
        'published_at' => '2026-01-02T00:00:00Z',
    ]);

    expect($pub->findingCounts())->toBe(['blocker' => 2, 'should-fix' => 1, 'consider' => 1]);
});

it('throws when reviewed_at is missing', function (): void {
    expect(fn() => Publication::fromArray([
        'slug' => 'test',
        'headline' => 'test',
        'spec_name' => 'test',
        'dimension' => 'test',
        'panelists' => [],
        'judge' => 'test',
        'findings' => [],
        'metrics' => [],
        'published_at' => '2026-01-02T00:00:00Z',
    ]))->toThrow(InvalidArgumentException::class, 'reviewed_at');
});

it('throws when published_at is missing', function (): void {
    expect(fn() => Publication::fromArray([
        'slug' => 'test',
        'headline' => 'test',
        'spec_name' => 'test',
        'dimension' => 'test',
        'panelists' => [],
        'judge' => 'test',
        'findings' => [],
        'metrics' => [],
        'reviewed_at' => '2026-01-01T00:00:00Z',
    ]))->toThrow(InvalidArgumentException::class, 'published_at');
});
