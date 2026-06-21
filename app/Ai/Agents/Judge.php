<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\File;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;

final class Judge implements Agent, HasStructuredOutput, HasTools
{
    use Promptable;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return File::get(resource_path('prompts/judge.md'));
    }

    /**
     * Get the tools available to the agent.
     *
     * @return Tool[]
     */
    public function tools(): iterable
    {
        return [];
    }

    /**
     * Get the agent's structured output schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'findings' => $schema->array()->items(
                $schema->object(fn(JsonSchema $schema): array => [
                    'dimension' => $schema->string()->required(),
                    'title' => $schema->string()->required(),
                    'severity' => $schema->string()->enum(['blocker', 'should-fix', 'consider'])->required(),
                    'confidence' => $schema->string()->enum(['consensus', 'majority', 'split', 'lone-flag'])->required(),
                    'location' => $schema->string()->required(),
                    'finding' => $schema->string()->required(),
                    'why_it_matters' => $schema->string()->required(),
                    'disagreement' => $schema->string(),
                    'suggested_change' => $schema->string()->required(),
                ]),
            )->required(),
        ];
    }
}
