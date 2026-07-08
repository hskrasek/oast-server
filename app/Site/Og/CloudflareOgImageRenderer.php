<?php

declare(strict_types=1);

namespace App\Site\Og;

use Illuminate\Support\Facades\Http;
use RuntimeException;

final readonly class CloudflareOgImageRenderer implements OgImageRenderer
{
    public function __construct(
        private string $accountId,
        private string $token,
    ) {}

    public function screenshot(string $html, int $width = 1200, int $height = 630): string
    {
        $response = Http::withToken($this->token)
            ->timeout(20)
            ->post(sprintf('https://api.cloudflare.com/client/v4/accounts/%s/browser-rendering/screenshot', $this->accountId), [
                'html' => $html,
                'viewport' => ['width' => $width, 'height' => $height],
                'screenshotOptions' => ['type' => 'png'],
            ]);

        $contentType = (string) $response->header('Content-Type');

        if ($response->failed() || ! str_contains($contentType, 'image/')) {
            throw new RuntimeException(
                sprintf('Cloudflare screenshot failed (%d): ', $response->status()) . $response->body(),
            );
        }

        return $response->body();
    }
}
