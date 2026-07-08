<?php

declare(strict_types=1);

namespace App\Site\Og;

final class OgAssets
{
    /**
     * @var list<array{family: string, weight: string, file: string}>
     */
    private const array FONTS = [
        ['family' => 'Newsreader', 'weight' => '400', 'file' => 'newsreader-opsz-normal.woff2'],
        ['family' => 'IBM Plex Mono', 'weight' => '500', 'file' => 'ibm-plex-mono-500.woff2'],
        ['family' => 'IBM Plex Mono', 'weight' => '600', 'file' => 'ibm-plex-mono-600.woff2'],
    ];

    private ?string $fontCss = null;

    public function fontFaceCss(): string
    {
        if ($this->fontCss !== null) {
            return $this->fontCss;
        }

        $css = '';

        foreach (self::FONTS as $font) {
            $bytes = (string) file_get_contents(resource_path('fonts/og/' . $font['file']));
            $base64 = base64_encode($bytes);

            $css .= sprintf(
                "@font-face{font-family:'%s';font-weight:%s;font-style:normal;font-display:block;src:url(data:font/woff2;base64,%s) format('woff2');}",
                $font['family'],
                $font['weight'],
                $base64,
            );
        }

        return $this->fontCss = $css;
    }
}
