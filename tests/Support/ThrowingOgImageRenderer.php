<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Site\Og\OgImageRenderer;
use RuntimeException;

final class ThrowingOgImageRenderer implements OgImageRenderer
{
    public function screenshot(string $html, int $width = 1200, int $height = 630): string
    {
        throw new RuntimeException('render failed');
    }
}
