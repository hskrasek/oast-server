<?php

declare(strict_types=1);

use App\Site\Og\OgTemplate;

it('renders a self-contained review card with no vite asset links', function (): void {
    $html = app(OgTemplate::class)->review(ogPublicationFixture(['headline' => 'RPC habit']))->render();

    expect($html)->toContain('RPC habit')
        ->and($html)->toContain('Train Travel API')
        ->and($html)->toContain('data:font/woff2;base64,')
        ->and($html)->toContain('$0.62')
        ->and($html)->not->toContain('/build/');
});

it('renders the home card', function (): void {
    $html = app(OgTemplate::class)->home()->render();

    expect($html)->toContain('never gets tired')
        ->and($html)->toContain('data:font/woff2;base64,')
        ->and($html)->not->toContain('/build/');
});

it('builds a stable home image url', function (): void {
    expect(OgTemplate::homeImageUrl())->toMatch('#^/og/home-[a-f0-9]{8}\.png$#')
        ->and(OgTemplate::homeImageUrl())->toBe(OgTemplate::homeImageUrl());
});
