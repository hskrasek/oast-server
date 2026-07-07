<?php

declare(strict_types=1);

namespace App\Site\Og;

interface OgImageRenderer
{
    /**
     * Render the given HTML to a PNG at the given pixel size, returning raw bytes.
     */
    public function screenshot(string $html, int $width = 1200, int $height = 630): string;
}
