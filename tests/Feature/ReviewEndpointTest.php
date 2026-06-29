<?php

declare(strict_types=1);

use App\Ai\Agents\Panelist;
use App\Models\Review;

beforeEach(fn() => config(['oast.api_domain' => 'api.oast.test']));

it('runs a council review over http and persists it', function () {
    fakeCouncil();

    $response = $this->postJson('http://api.oast.test/reviews', [
        'spec' => 'openapi: 3.1.0',
        'mode' => 'council',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.status', 'complete')
        ->assertJsonPath('data.mode', 'council')
        ->assertJsonCount(1, 'data.findings');

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

it('persists an error row and returns a 503 problem+json when quorum is not met', function () {
    Panelist::fake(fn() => throw new RuntimeException('down'));

    $this->postJson('http://api.oast.test/reviews', ['spec' => 'openapi: 3.1.0', 'mode' => 'council'])
        ->assertStatus(503)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', App\Http\Problems\ProblemType::QuorumNotMet)
        ->assertJsonPath('status', 503);

    expect(Review::where('status', 'error')->count())->toBe(1);
});
