<?php

declare(strict_types=1);

namespace App\Council;

use App\Ai\Agents\Judge;
use App\Council\Exceptions\JudgeException;
use App\Council\Prompts\JudgePrompt;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredAgentResponse;

final readonly class CouncilOrchestrator
{
    /**
     * @param  array{panelists: list<string>, judge: string, baseline: string|null, quorum: int, timeout: int}  $config
     */
    public function __construct(
        private FindingValidator $validator,
        private array            $config,
    ) {}

    /**
     * @param  list<array{model: string, content: string|null}>  $panelCritiques
     *
     * @return array{findings: array<array-key, mixed>, ms: int, usage: array<string, int>}
     */
    public function runJudge(string $spec, array $panelCritiques, Dimension $dimension = Dimension::DomainModeling): array
    {
        $base = JudgePrompt::userPrompt($spec, $panelCritiques);
        $lastErrors = [];

        for ($attempts = 0; $attempts < 2; $attempts++) {
            $prompt = $attempts === 0
                ? $base
                : $base . "\n\nYour previous response was invalid: " . json_encode($lastErrors)
                . ". Return findings that satisfy every rule (a split finding MUST include `disagreement`).";

            $start = microtime(true);

            $response = new Judge($dimension)->prompt(
                $prompt,
                provider: Lab::OpenRouter,
                model: $this->config['judge'],
                timeout: $this->config['timeout'],
            );

            $ms = (int) round((microtime(true) - $start) * 1000);

            try {
                $structured = $response instanceof StructuredAgentResponse ? $response->toArray() : [];
                $rawFindings = is_array($structured['findings'] ?? null) ? $structured['findings'] : [];

                $findings = $this->validator->validate($rawFindings);

                return ['findings' => $findings, 'ms' => $ms, 'usage' => $this->usageMetrics($response->usage)];
            } catch (JudgeException $exception) {
                $lastErrors[] = $exception->errors;
            }
        }

        throw JudgeException::invalidOutput($lastErrors);
    }

    /**
     * @return array<string, int>
     */
    private function usageMetrics(Usage $usage): array
    {
        return [
            'prompt_tokens' => $usage->promptTokens,
            'completion_tokens' => $usage->completionTokens,
            'cache_write_input_tokens' => $usage->cacheWriteInputTokens,
            'cache_read_input_tokens' => $usage->cacheReadInputTokens,
            'reasoning_tokens' => $usage->reasoningTokens,
        ];
    }
}
