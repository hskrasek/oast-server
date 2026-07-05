<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::fake(['openrouter.ai/api/v1/models' => Http::response(['data' => []])]);
});

it('404s api routes when the api is disabled', function (): void {
    config()->set('oast.api_enabled', false);

    $this->getJson('https://' . config()->string('oast.api_domain') . '/reviews/1')
        ->assertNotFound();
});

it('serves api routes when enabled', function (): void {
    config()->set('oast.api_enabled', true);

    // unknown id still 404s, but by model binding — assert the problem body shape exists either way
    $this->getJson('https://' . config()->string('oast.api_domain') . '/reviews/999999')
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/problem+json');
});

it('does not gate site routes', function (): void {
    config()->set('oast.api_enabled', false);

    $this->get('/')->assertOk();
});

it('allows api requests through the middleware when enabled', function (): void {
    Bus::fake();
    config()->set('oast.api_enabled', true);

    $this->postJson('https://' . config()->string('oast.api_domain') . '/reviews', [
        'spec' => 'openapi: 3.1.0',
        'mode' => 'council',
    ])->assertAccepted();
});
