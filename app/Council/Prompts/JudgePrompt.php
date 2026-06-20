<?php

declare(strict_types=1);

namespace App\Council\Prompts;

final class JudgePrompt
{
    public static function userPrompt(string $spec, array $panelCritiques): string
    {
        $critiques = collect($panelCritiques)
            ->map(fn(array $c): string => sprintf('### Panelist: %s%s%s', $c['model'], PHP_EOL, $c['content']))
            ->join("\n\n");

        return "## Specification under review\n\n{$spec}\n\n## Panel critiques\n\n{$critiques}";
    }
}
