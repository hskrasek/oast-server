<?php

declare(strict_types=1);

use App\Ai\Agents\Designer;
use App\Council\Prompts\DesignerPrompt;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;

it('loads the designer instructions with the output contract', function (): void {
    $instructions = mb_strtolower(new Designer()->instructions());

    expect($instructions)->toContain('complete spec')
        ->and($instructions)->toContain('openapi 3.1')
        ->and($instructions)->toContain('tbd: confirm with owner');
});

it('defines a spec and reply structured output schema', function (): void {
    $schema = new Designer()->schema(new JsonSchemaTypeFactory());

    expect($schema)->toHaveKeys(['spec', 'reply']);
});

it('has no tools', function (): void {
    expect(new Designer()->tools())->toBe([]);
});

it('builds a user prompt with history, current spec and message in order', function (): void {
    $prompt = DesignerPrompt::userPrompt(
        [['role' => 'user', 'content' => 'earlier ask'], ['role' => 'assistant', 'content' => 'earlier reply']],
        'openapi: 3.1.0',
        'add loans',
    );

    expect($prompt)->toContain('USER: earlier ask')
        ->and($prompt)->toContain('ASSISTANT: earlier reply')
        ->and($prompt)->toContain('openapi: 3.1.0')
        ->and($prompt)->toContain('add loans')
        ->and(mb_strpos($prompt, 'earlier ask'))->toBeLessThan(mb_strpos($prompt, 'openapi: 3.1.0'))
        ->and(mb_strpos($prompt, 'openapi: 3.1.0'))->toBeLessThan(mb_strpos($prompt, 'add loans'));
});

it('marks an empty spec as a fresh start', function (): void {
    expect(DesignerPrompt::userPrompt([], '', 'library API'))->toContain('(empty: this is a new spec)');
});
