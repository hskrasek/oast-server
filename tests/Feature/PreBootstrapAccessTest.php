<?php

declare(strict_types=1);

use App\Models\Installation;

it('redirects every enabled Fortify entry point to setup before bootstrap', function (string $method, string $uri): void {
    expect(Installation::query()->findOrFail(1)->bootstrapped_at)->toBeNull();
    $response = $method === 'get' ? $this->get($uri) : $this->post($uri);
    $response->assertRedirect(route('setup.show'));
})->with([
    ['get', '/login'],
    ['post', '/login'],
    ['get', '/forgot-password'],
    ['post', '/forgot-password'],
    ['get', '/reset-password/test-token'],
    ['get', '/email/verify'],
    ['get', '/user/confirm-password'],
]);

it('keeps setup health assets and publication pages reachable before bootstrap', function (): void {
    $this->get('/setup')->assertOk();
    $this->get('/up')->assertOk();
    $this->get('/')->assertOk();
    $this->get('/why')->assertOk();
    $this->get('/reviews')->assertOk();
    $this->get('/build/assets/app.css')->assertNotFound(); // no redirect to setup
});
