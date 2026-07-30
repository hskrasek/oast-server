<?php

declare(strict_types=1);

use App\Ai\Agents\Panelist;
use App\Jobs\RunPanelist;
use App\Models\Review;
use App\Tokens\PersonalAccessTokenService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config(['oast.api_domain' => 'api.oast.test']);
    Http::fake(['openrouter.ai/api/v1/models' => Http::response(['data' => []])]);
});

it('returns 202 with a Location header and dispatches the batch', function () {
    Bus::fake();
    [$user, $organization] = memberFixture();
    $token = app(PersonalAccessTokenService::class)->create($user, $organization, 'CI', null);

    $response = $this->withToken($token->plainTextToken)->postJson("https://{$this->apiHost()}/reviews", [
        'spec' => 'openapi: 3.1.0',
        'mode' => 'council',
    ]);

    $response->assertAccepted()
        ->assertJsonPath('data.status', 'running')
        ->assertJsonPath('data.mode', 'council');

    $reviewId = $response->json('data.id');
    $response->assertHeader('Location', "https://{$this->apiHost()}/reviews/{$reviewId}");

    Bus::assertBatched(fn($batch): bool => $batch->jobs->count() === count(config('oast.panelists'))
        && $batch->jobs->every(fn($job): bool => $job instanceof RunPanelist));
});

it('returns a problem+json validation error when spec is missing', function () {
    [$user, $organization] = memberFixture();
    $token = app(PersonalAccessTokenService::class)->create($user, $organization, 'CI', null);

    $this->withToken($token->plainTextToken)->postJson("https://{$this->apiHost()}/reviews", ['mode' => 'council'])
        ->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', App\Http\Problems\ProblemType::Validation)
        ->assertJsonPath('status', 422)
        ->assertJsonPath('errors.spec.0', fn($msg) => filled($msg));
});

it('accepts a workflows dimension and persists it', function () {
    fakeCouncil();
    [$user, $organization] = memberFixture();
    $token = app(PersonalAccessTokenService::class)->create($user, $organization, 'CI', null);

    $this->withToken($token->plainTextToken)->postJson("https://{$this->apiHost()}/reviews", [
        'spec' => 'openapi: 3.1.0',
        'dimension' => 'workflows',
    ])
        ->assertAccepted()
        ->assertJsonPath('data.dimension', 'workflows');

    expect(Review::where('dimension', 'workflows')->count())->toBe(1);
});

it('rejects an unknown dimension with a problem+json validation error', function () {
    [$user, $organization] = memberFixture();
    $token = app(PersonalAccessTokenService::class)->create($user, $organization, 'CI', null);

    $this->withToken($token->plainTextToken)->postJson("https://{$this->apiHost()}/reviews", [
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
    // layer, so the endpoint still responds 202 with the review as queued.
    //
    // Real queue connection (not sync): on the sync driver a failing job's
    // exception re-propagates through Bus::batch()->dispatch() and crashes
    // the request — that only happens because sync short-circuits the queue
    // worker's isolation. A real deployment always queues, so exercise the
    // failure path the way it actually runs.
    config(['queue.default' => 'database']);
    Panelist::fake(fn() => throw new RuntimeException('down'));
    [$user, $organization] = memberFixture();
    $token = app(PersonalAccessTokenService::class)->create($user, $organization, 'CI', null);

    $this->withToken($token->plainTextToken)->postJson("https://{$this->apiHost()}/reviews", ['spec' => 'openapi: 3.1.0', 'mode' => 'council'])
        ->assertAccepted()
        ->assertJsonPath('data.status', 'running');

    $this->artisan('queue:work', ['--queue' => 'default', '--stop-when-empty' => true]);

    expect(Review::where('status', 'error')->count())->toBe(1);
});

it('shows a review by id', function () {
    [$user, $organization] = memberFixture();
    $token = app(PersonalAccessTokenService::class)->create($user, $organization, 'CI', null);
    $review = Review::factory()->for($organization)->create(['status' => 'complete', 'mode' => 'council']);

    $this->withToken($token->plainTextToken)->getJson("https://{$this->apiHost()}/reviews/{$review->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $review->id)
        ->assertJsonPath('data.status', 'complete');
});

it('404s an unknown review id as problem+json', function () {
    [$user, $organization] = memberFixture();
    $token = app(PersonalAccessTokenService::class)->create($user, $organization, 'CI', null);

    $this->withToken($token->plainTextToken)->getJson("https://{$this->apiHost()}/reviews/999")
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', App\Http\Problems\ProblemType::NotFound)
        ->assertJsonMissingPath('exception')
        ->assertJsonMissingPath('trace');
});
