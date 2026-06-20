<?php

declare(strict_types=1);

namespace App\Council\Prompts;

final class PanelistPrompt
{
    public static function userPrompt(string $spec): string
    {
        return 'Here is the OpenAPI specification to review:

        ' . $spec;
    }
}
