<?php

declare(strict_types=1);

use App\Site\Og\OgImageRenderer;
use App\Site\Og\OgTemplate;
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

    $url = (app(PublicationRepository::class)->find('train-travel-domain-modeling') ?? throw new RuntimeException('fixture missing'))->ogImageUrl();

    $response = $this->get($url)->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('image/png')
        ->and($response->headers->get('Cache-Control'))->toContain('max-age=31536000')
        ->and($response->headers->get('Cache-Control'))->toContain('immutable')
        ->and($response->headers->get('Set-Cookie'))->toBeNull();
});

it('renders the home slug as a png', function (): void {
    app()->instance(OgImageRenderer::class, new FakeOgImageRenderer());

    $this->get(OgTemplate::homeImageUrl())
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

it('404s a valid slug with a well-formed but wrong hash', function (): void {
    app()->instance(OgImageRenderer::class, new FakeOgImageRenderer());

    $this->get('/og/train-travel-domain-modeling-00000000.png')->assertNotFound();
});

it('404s the home slug with a well-formed but wrong hash', function (): void {
    app()->instance(OgImageRenderer::class, new FakeOgImageRenderer());

    $this->get('/og/home-00000000.png')->assertNotFound();
});

it('serves the fallback image as non-cacheable when rendering throws', function (): void {
    app()->instance(OgImageRenderer::class, new ThrowingOgImageRenderer());

    $url = (app(PublicationRepository::class)->find('train-travel-domain-modeling') ?? throw new RuntimeException('fixture missing'))->ogImageUrl();

    $response = $this->get($url)->assertOk();

    // Fallback must not be cached (see controller note): a cached failure would
    // pin the wrong image for hours under Cloudflare's edge-TTL floor.
    expect($response->headers->get('Content-Type'))->toBe('image/png')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
});
