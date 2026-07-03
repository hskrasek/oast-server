<?php

declare(strict_types=1);


use App\Ai\Agents\Judge;
use App\Ai\Agents\Panelist;
use App\Council\Prompts\JudgePrompt;
use App\Council\Prompts\PanelistPrompt;

it('panelist instructions carry critique guidance but no rubric severities', function (): void {
    $instructions = new Panelist()->instructions();

    expect(mb_strtolower($instructions))->toContain('domain')
        ->and(mb_strtolower($instructions))->not->toContain('blocker'); // rubric not leaked to panel
});

it('loads dimension-specific instructions for panelist and judge', function (): void {
    $panelist = new Panelist(App\Council\Dimension::Workflows)->instructions();
    $judge = new Judge(App\Council\Dimension::Workflows)->instructions();

    expect(mb_strtolower($panelist))->toContain('long-running')
        ->and(mb_strtolower($panelist))->not->toContain('blocker')
        ->and($judge)->toContain('**workflows**')
        ->and(new Judge()->instructions())->toContain('**domain-modeling**');
});

it('judge schema defines a findings array with the required keys', function (): void {
    $schema = new Judge()->schema(new Illuminate\JsonSchema\JsonSchemaTypeFactory());

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
