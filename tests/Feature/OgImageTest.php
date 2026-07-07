<?php

declare(strict_types=1);

use App\Site\Og\OgImageRenderer;
use App\Site\PublicationRepository;
use Tests\Support\FakeOgImageRenderer;
use Tests\Support\ThrowingOgImageRenderer;

beforeEach(function (): void {
    app()->bind(
        PublicationRepository::class,
        fn(): PublicationRepository => new PublicationRepository(base_path('tests/fixtures/publications')),
    );
});

it('renders a review slug as a png with an immutable cache header', function (): void {
    app()->instance(OgImageRenderer::class, new FakeOgImageRenderer());

    $response = $this->get('/og/train-travel-domain-modeling-deadbeef.png')->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('image/png')
        ->and($response->headers->get('Cache-Control'))->toContain('max-age=31536000')
        ->and($response->headers->get('Cache-Control'))->toContain('immutable')
        ->and($response->headers->get('Set-Cookie'))->toBeNull();
});

it('renders the home slug as a png', function (): void {
    app()->instance(OgImageRenderer::class, new FakeOgImageRenderer());

    $this->get('/og/home-deadbeef.png')
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png');
});

it('404s an unknown review slug', function (): void {
    app()->instance(OgImageRenderer::class, new FakeOgImageRenderer());

    $this->get('/og/nope-deadbeef.png')->assertNotFound();
});

it('404s a path with no hash suffix', function (): void {
    $this->get('/og/train-travel-domain-modeling.png')->assertNotFound();
});

it('serves the fallback image with a short ttl when rendering throws', function (): void {
    app()->instance(OgImageRenderer::class, new ThrowingOgImageRenderer());

    $response = $this->get('/og/train-travel-domain-modeling-deadbeef.png')->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('image/png')
        ->and($response->headers->get('Cache-Control'))->toContain('max-age=300');
});
