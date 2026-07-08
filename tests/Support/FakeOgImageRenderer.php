<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Site\Og\OgImageRenderer;

final class FakeOgImageRenderer implements OgImageRenderer
{
    /** @var list<array{html: string, width: int, height: int}> */
    public array $calls = [];

    public function screenshot(string $html, int $width = 1200, int $height = 630): string
    {
        $this->calls[] = ['html' => $html, 'width' => $width, 'height' => $height];

        // A real 1×1 transparent PNG.
        return (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
            true,
        );
    }
}
