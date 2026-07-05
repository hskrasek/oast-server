<?php

declare(strict_types=1);

use App\Models\Review;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    File::deleteDirectory(base_path('database/publications-test'));
    config()->set('site.publications_path', base_path('database/publications-test'));
});

afterEach(function (): void {
    File::deleteDirectory(base_path('database/publications-test'));
});

it('exports a complete review to a publication json', function (): void {
    $review = Review::factory()->create([
        'status' => 'complete',
        'dimension' => 'domain-modeling',
        'panelists' => ['a-model', 'b-model'],
        'findings' => [validFinding()],
        'metrics' => [['model' => 'a-model', 'ms' => 5], ['total_cost_usd' => 0.5]],
    ]);
    $commentary = tempnam(sys_get_temp_dir(), 'md');
    file_put_contents((string) $commentary, 'Why this spec.');

    $this->artisan('site:publish', [
        'review' => $review->id,
        'slug' => 'test-slug',
        '--headline' => 'A headline',
        '--commentary' => $commentary,
        '--spec-name' => 'Test Spec',
        '--spec-url' => 'https://example.com/spec',
        '--spec-license' => 'CC0',
    ])->assertExitCode(0);

    $json = json_decode(File::get(base_path('database/publications-test/test-slug.json')), true);
    expect($json['slug'])->toBe('test-slug')
        ->and($json['headline'])->toBe('A headline')
        ->and($json['commentary_md'])->toBe('Why this spec.')
        ->and($json['judge'])->toBe(config('oast.judge'))
        ->and($json['findings'])->toHaveCount(1)
        ->and($json['published_at'])->not->toBeEmpty();
});

it('refuses an incomplete review', function (): void {
    $review = Review::factory()->create(['status' => 'error']);

    $this->artisan('site:publish', ['review' => $review->id, 'slug' => 'x'])
        ->assertExitCode(1);
});

it('refuses an existing slug', function (): void {
    File::ensureDirectoryExists(base_path('database/publications-test'));
    File::put(base_path('database/publications-test/taken.json'), '{}');
    $review = Review::factory()->create(['status' => 'complete']);

    $this->artisan('site:publish', ['review' => $review->id, 'slug' => 'taken'])
        ->assertExitCode(1);
});

it('publishes with minimal options', function (): void {
    $review = Review::factory()->create([
        'status' => 'complete',
        'dimension' => 'test',
        'panelists' => [],
        'findings' => [],
        'metrics' => [['total_cost_usd' => 0.1]],
    ]);

    $this->artisan('site:publish', [
        'review' => $review->id,
        'slug' => 'minimal',
    ])->assertExitCode(0);

    $json = json_decode(File::get(base_path('database/publications-test/minimal.json')), true);
    expect($json['slug'])->toBe('minimal')
        ->and($json['headline'])->toBe('minimal')
        ->and($json['commentary_md'])->toBe('');
});

it('refuses malicious slugs with path traversal', function (): void {
    $review = Review::factory()->create(['status' => 'complete']);

    $this->artisan('site:publish', ['review' => $review->id, 'slug' => '../evil'])
        ->assertExitCode(1);

    expect(File::exists(base_path('database/publications-test/../evil.json')))->toBeFalse();
});

it('refuses slugs with slashes', function (): void {
    $review = Review::factory()->create(['status' => 'complete']);

    $this->artisan('site:publish', ['review' => $review->id, 'slug' => 'has/slash'])
        ->assertExitCode(1);

    expect(File::exists(base_path('database/publications-test/has/slash.json')))->toBeFalse();
});

it('refuses null created_at date', function (): void {
    $review = Review::factory()->create([
        'status' => 'complete',
        'created_at' => null,
    ]);

    $this->artisan('site:publish', ['review' => $review->id, 'slug' => 'no-date'])
        ->assertExitCode(1);

    expect(File::exists(base_path('database/publications-test/no-date.json')))->toBeFalse();
});
