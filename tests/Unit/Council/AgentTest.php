<?php

declare(strict_types=1);


use App\Ai\Agents\Judge;
use App\Ai\Agents\Panelist;
use App\Council\Prompts\JudgePrompt;
use App\Council\Prompts\PanelistPrompt;
use Illuminate\JsonSchema\JsonSchema;

it('panelist instructions carry critique guidance but no rubric severities', function (): void {
    $instructions = new Panelist()->instructions();

    expect(strtolower($instructions))->toContain('domain')
        ->and(strtolower($instructions))->not->toContain('blocker'); // rubric not leaked to panel
});

it('judge schema defines a findings array with the required keys', function (): void {
    $schema = new Judge()->schema(new \Illuminate\JsonSchema\JsonSchemaTypeFactory());

    expect($schema)->toHaveKey('findings');
});

it('builds a panel user prompt embedding the raw spec', function (): void {
    expect(PanelistPrompt::userPrompt("openapi: 3.1.0"))->toContain('openapi: 3.1.0');
});

it('builds a judge user prompt embedding spec and labeled critiques', function (): void {
    $prompt = JudgePrompt::userPrompt('SPEC_BODY', [
        ['model' => 'a/one', 'content' => 'critique one'],
    ]);
    expect($prompt)->toContain('SPEC_BODY')
        ->and($prompt)->toContain('a/one')
        ->and($prompt)->toContain('critique one');
});
