<?php

declare(strict_types=1);

use App\Ai\Agents\Judge;
use App\Ai\Agents\Panelist;
use App\Models\Review;
use Illuminate\Support\Facades\Http;

// fakeCouncil() comes from tests/Pest.php.

beforeEach(function (): void {
    Http::fake(['openrouter.ai/api/v1/models' => Http::response(['data' => []])]);
});

it('runs a baseline review from a spec file and persists it', function (): void {
    fakeCouncil();
    $path = sys_get_temp_dir() . '/oast-spec-' . uniqid() . '.yaml';
    file_put_contents($path, 'openapi: 3.1.0');

    $this->artisan('oast:review', ['spec' => $path, '--baseline' => true])
        ->assertSuccessful();

    expect(Review::query()->where('mode', 'baseline')->where('status', 'complete')->count())->toBe(1);

    unlink($path);
});

it('fails when the spec file is missing', function (): void {
    $this->artisan('oast:review', ['spec' => '/no/such/file.yaml'])
        ->assertFailed();
});

it('runs a review for the workflows dimension and persists it', function (): void {
    fakeCouncil();
    $path = sys_get_temp_dir() . '/oast-spec-' . uniqid() . '.yaml';
    file_put_contents($path, 'openapi: 3.1.0');

    $this->artisan('oast:review', ['spec' => $path, '--dimension' => 'workflows'])
        ->assertSuccessful();

    expect(Review::query()->where('dimension', 'workflows')->where('status', 'complete')->count())->toBe(1);

    unlink($path);
});

it('fails on an unknown dimension without convening the panel', function (): void {
    $path = sys_get_temp_dir() . '/oast-spec-' . uniqid() . '.yaml';
    file_put_contents($path, 'openapi: 3.1.0');

    $this->artisan('oast:review', ['spec' => $path, '--dimension' => 'vibes'])
        ->assertFailed();

    expect(Review::query()->count())->toBe(0);

    unlink($path);
});

it('persists an error row when the review cannot reach quorum', function (): void {
    // The command now just dispatches the batch (via CreateReviewAction) and
    // returns — quorum failure is owned by the finalizer/DB row, not a thrown
    // exception, so the command itself still succeeds. Real 202/async command
    // semantics land in Task 7.
    //
    // Real queue connection (not sync): on the sync driver a failing job's
    // exception re-propagates through Bus::batch()->dispatch() and crashes
    // the command — that only happens because sync short-circuits the queue
    // worker's isolation. A real deployment always queues, so exercise the
    // failure path the way it actually runs.
    config(['queue.default' => 'database']);
    Panelist::fake(fn() => throw new RuntimeException('down'));
    Judge::fake();
    $path = sys_get_temp_dir() . '/oast-spec-' . uniqid() . '.yaml';
    file_put_contents($path, 'openapi: 3.1.0');

    $this->artisan('oast:review', ['spec' => $path])
        ->assertSuccessful();

    $this->artisan('queue:work', ['--queue' => 'default', '--stop-when-empty' => true]);

    expect(Review::query()->where('status', 'error')->count())->toBe(1);

    unlink($path);
});
