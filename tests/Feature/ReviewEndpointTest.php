<?php

declare(strict_types=1);

use App\Ai\Agents\Panelist;
use App\Models\Review;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config(['oast.api_domain' => 'api.oast.test']);
    Http::fake(['openrouter.ai/api/v1/models' => Http::response(['data' => []])]);
});

it('runs a council review over http and persists it', function () {
    fakeCouncil();

    // QUEUE_CONNECTION=sync in the test environment runs the panel batch and
    // judge job inline, so the DB row reaches 'complete' by the time this
    // request returns — but the response reflects the review as it stood the
    // moment CreateReviewAction dispatched the batch (async 202-shaped
    // response arrives in Task 6).
    $response = $this->postJson('http://api.oast.test/reviews', [
        'spec' => 'openapi: 3.1.0',
        'mode' => 'council',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.status', 'running')
        ->assertJsonPath('data.mode', 'council');

    expect(Review::where('status', 'complete')->count())->toBe(1);
});

it('returns a problem+json validation error when spec is missing', function () {
    $this->postJson('http://api.oast.test/reviews', ['mode' => 'council'])
        ->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', App\Http\Problems\ProblemType::Validation)
        ->assertJsonPath('status', 422)
        ->assertJsonPath('errors.spec.0', fn($msg) => filled($msg));
});

it('accepts a workflows dimension and persists it', function () {
    fakeCouncil();

    $this->postJson('http://api.oast.test/reviews', [
        'spec' => 'openapi: 3.1.0',
        'dimension' => 'workflows',
    ])
        ->assertCreated()
        ->assertJsonPath('data.dimension', 'workflows');

    expect(Review::where('dimension', 'workflows')->count())->toBe(1);
});

it('rejects an unknown dimension with a problem+json validation error', function () {
    $this->postJson('http://api.oast.test/reviews', [
        'spec' => 'openapi: 3.1.0',
        'dimension' => 'vibes',
    ])
        ->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('errors.dimension.0', fn($msg) => filled($msg));
});

it('persists an error row when the panel cannot reach quorum', function () {
    // The batch/finalizer pipeline owns quorum failure now: it marks the
    // review row 'error' directly rather than throwing back to the HTTP
    // layer, so the endpoint still responds 201 with the review as queued.
    // The 503 problem+json surface returns in Task 6.
    //
    // Real queue connection (not sync): on the sync driver a failing job's
    // exception re-propagates through Bus::batch()->dispatch() and crashes
    // the request — that only happens because sync short-circuits the queue
    // worker's isolation. A real deployment always queues, so exercise the
    // failure path the way it actually runs.
    config(['queue.default' => 'database']);
    Panelist::fake(fn() => throw new RuntimeException('down'));

    $this->postJson('http://api.oast.test/reviews', ['spec' => 'openapi: 3.1.0', 'mode' => 'council'])
        ->assertCreated()
        ->assertJsonPath('data.status', 'running');

    $this->artisan('queue:work', ['--queue' => 'default', '--stop-when-empty' => true]);

    expect(Review::where('status', 'error')->count())->toBe(1);
});
