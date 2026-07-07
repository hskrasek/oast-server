<?php

declare(strict_types=1);

use App\Site\Og\CloudflareOgImageRenderer;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('posts html to the cloudflare screenshot endpoint and returns png bytes', function (): void {
    Http::fake([
        'api.cloudflare.com/*' => Http::response('PNGBYTES', 200, ['Content-Type' => 'image/png']),
    ]);

    $renderer = new CloudflareOgImageRenderer('acct-123', 'token-abc');

    $png = $renderer->screenshot('<h1>hi</h1>', 1200, 630);

    expect($png)->toBe('PNGBYTES');

    Http::assertSent(fn(Request $request): bool
        => $request->url() === 'https://api.cloudflare.com/client/v4/accounts/acct-123/browser-rendering/screenshot'
        && $request->hasHeader('Authorization', 'Bearer token-abc')
        && $request['html'] === '<h1>hi</h1>'
        && $request['viewport'] === ['width' => 1200, 'height' => 630]
        && $request['screenshotOptions'] === ['type' => 'png']);
});

it('throws when cloudflare returns a non-image response', function (): void {
    Http::fake([
        'api.cloudflare.com/*' => Http::response(['success' => false], 403, ['Content-Type' => 'application/json']),
    ]);

    $renderer = new CloudflareOgImageRenderer('acct-123', 'token-abc');

    expect(fn(): string => $renderer->screenshot('<h1>hi</h1>'))
        ->toThrow(RuntimeException::class);
});

it('container resolves OgImageRenderer to the Cloudflare implementation', function (): void {
    expect(app(App\Site\Og\OgImageRenderer::class))
        ->toBeInstanceOf(CloudflareOgImageRenderer::class);
});
