<?php

declare(strict_types=1);

use App\Site\Og\OgAssets;

it('embeds each og font as a base64 woff2 data uri', function (): void {
    $css = new OgAssets()->fontFaceCss();

    expect($css)->toContain('@font-face')
        ->and($css)->toContain("font-family:'Newsreader'")
        ->and($css)->toContain("font-family:'IBM Plex Mono'")
        ->and($css)->toContain('data:font/woff2;base64,')
        ->and(mb_substr_count($css, '@font-face'))->toBe(3);
});

it('memoizes the font css', function (): void {
    $assets = new OgAssets();

    expect($assets->fontFaceCss())->toBe($assets->fontFaceCss());
});
