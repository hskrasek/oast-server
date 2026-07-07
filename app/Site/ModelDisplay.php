<?php

declare(strict_types=1);

namespace App\Site;

final readonly class ModelDisplay
{
    /**
     * Short display name for an OpenRouter model slug, per the design system:
     * strip the provider path, the routing `~` marker, and `-latest` pins.
     * `~anthropic/claude-sonnet-latest` → `claude-sonnet`.
     */
    public static function short(string $slug): string
    {
        $name = mb_ltrim($slug, '~');
        $slash = mb_strrpos($name, '/');

        if ($slash !== false) {
            $name = mb_substr($name, $slash + 1);
        }

        return str_ends_with($name, '-latest') ? mb_substr($name, 0, -7) : $name;
    }
}
