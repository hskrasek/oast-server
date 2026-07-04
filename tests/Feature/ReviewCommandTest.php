<?php

declare(strict_types=1);

use App\Models\Review;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

// fakeCouncil() comes from tests/Pest.php.

beforeEach(function (): void {
    Http::fake(['openrouter.ai/api/v1/models' => Http::response(['data' => []])]);
});

it('fails when the spec file is missing', function (): void {
    $this->artisan('oast:review', ['spec' => '/no/such/file.yaml'])
        ->assertFailed();
});

it('fails on an unknown dimension without convening the panel', function (): void {
    $path = sys_get_temp_dir() . '/oast-spec-' . uniqid() . '.yaml';
    file_put_contents($path, 'openapi: 3.1.0');

    $this->artisan('oast:review', ['spec' => $path, '--dimension' => 'vibes'])
        ->assertFailed();

    expect(Review::query()->count())->toBe(0);

    unlink($path);
});

it('runs a council review end-to-end on the sync queue and prints findings', function (): void {
    fakeCouncil();
    $spec = tempnam(sys_get_temp_dir(), 'spec');
    file_put_contents((string) $spec, 'openapi: 3.1.0');

    $this->artisan('oast:review', ['spec' => $spec])
        ->expectsOutputToContain('review.completed')
        ->expectsOutputToContain('Order exposes DB join table')
        ->assertExitCode(0);

    unlink((string) $spec);
});

it('exits non-zero when the review fails', function (): void {
    // Drive the failure through an unreachable quorum rather than a throwing
    // Panelist fake: on the sync queue, a job that actually throws re-throws
    // past Bus::batch()->dispatch() (SyncQueue::handleException() calls fail()
    // then rethrows) instead of being isolated the way a real queue worker
    // would — a known sync-only quirk, not something to route around the
    // command for. An unreachable quorum fails the review the same way
    // (PanelFinalizer marks it 'error') without any job ever throwing.
    fakeCouncil();
    config(['oast.quorum' => 5]);
    $spec = tempnam(sys_get_temp_dir(), 'spec');
    file_put_contents((string) $spec, 'openapi: 3.1.0');

    $this->artisan('oast:review', ['spec' => $spec])->assertExitCode(1);

    unlink((string) $spec);
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

it('runs a review for the workflows dimension and persists it', function (): void {
    fakeCouncil();
    $path = sys_get_temp_dir() . '/oast-spec-' . uniqid() . '.yaml';
    file_put_contents($path, 'openapi: 3.1.0');

    $this->artisan('oast:review', ['spec' => $path, '--dimension' => 'workflows'])
        ->assertSuccessful();

    expect(Review::query()->where('dimension', 'workflows')->where('status', 'complete')->count())->toBe(1);

    unlink($path);
});

it('times out waiting for the review to finish', function (): void {
    // Bus::fake() means the batch is created but no job ever runs, so the
    // review stays 'running' forever — the only way out of the polling loop
    // is the --timeout deadline. Sleep is real here (not faked): the loop
    // needs actual wall-clock time to pass so `now()->greaterThan($deadline)`
    // eventually trips, which also means this test genuinely exercises the
    // Sleep::for(500)->milliseconds() poll step, not just the timeout branch.
    Bus::fake();
    $path = sys_get_temp_dir() . '/oast-spec-' . uniqid() . '.yaml';
    file_put_contents($path, 'openapi: 3.1.0');

    $this->artisan('oast:review', ['spec' => $path, '--timeout' => 1])
        ->expectsOutputToContain('Timed out waiting for the review to finish.')
        ->assertExitCode(1);

    unlink($path);
})->group('slow');
