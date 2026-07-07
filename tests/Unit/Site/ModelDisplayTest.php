<?php

declare(strict_types=1);

use App\Site\ModelDisplay;

it('shortens model slugs to display names', function (string $slug, string $expected): void {
    expect(ModelDisplay::short($slug))->toBe($expected);
})->with([
    'latest-pinned anthropic' => ['~anthropic/claude-sonnet-latest', 'claude-sonnet'],
    'openai' => ['openai/gpt-5.5', 'gpt-5.5'],
    'z-ai' => ['z-ai/glm-5.2', 'glm-5.2'],
    'versioned judge' => ['anthropic/claude-opus-4.8', 'claude-opus-4.8'],
    'bare name passes through' => ['glm-5.2', 'glm-5.2'],
]);
