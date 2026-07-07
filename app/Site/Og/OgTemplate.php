<?php

declare(strict_types=1);

namespace App\Site\Og;

use App\Site\Publication;
use Illuminate\Contracts\View\View;

final readonly class OgTemplate
{
    private const string HOME_VERSION = 'v1';

    public function __construct(private OgAssets $assets) {}

    public static function homeImageUrl(): string
    {
        return '/og/home-' . mb_substr(sha1('home-' . self::HOME_VERSION), 0, 8) . '.png';
    }

    public function review(Publication $publication): View
    {
        $counts = $publication->findingCounts();

        return view('site.og', [
            'fonts' => $this->assets->fontFaceCss(),
            'kicker' => 'api design review · ' . $publication->dimension,
            'headline' => $publication->headline,
            'specName' => $publication->specName,
            'cost' => $publication->totalCostUsd(),
            'tally' => array_filter([
                'sev-blocker' => $counts['blocker'] !== 0 ? $counts['blocker'] . ' blocker' . ($counts['blocker'] > 1 ? 's' : '') : null,
                'sev-should-fix' => $counts['should-fix'] !== 0 ? $counts['should-fix'] . ' should-fix' : null,
                'sev-consider' => $counts['consider'] !== 0 ? $counts['consider'] . ' consider' : null,
            ]),
        ]);
    }

    public function home(): View
    {
        return view('site.og-home', [
            'fonts' => $this->assets->fontFaceCss(),
        ]);
    }
}
