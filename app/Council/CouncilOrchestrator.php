<?php

declare(strict_types=1);

namespace App\Council;

use App\Ai\Agents\Judge;
use App\Ai\Agents\Panelist;
use App\Council\Exceptions\JudgeException;
use App\Council\Prompts\JudgePrompt;
use App\Council\Prompts\PanelistPrompt;
use Laravel\Ai\Enums\Lab;
use Throwable;

final readonly class CouncilOrchestrator
{
    public function __construct(
        private FindingValidator $validator,
        private array            $config,
    ) {}

    /**
     * @return PanelResponse[]
     */
    public function deliberateOn(string $spec): array
    {
        $userPrompt = PanelistPrompt::userPrompt($spec);

        $responses = [];

        foreach ($this->config['panelists'] as $panelist) {
            $responses[] = $this->promptPanelist($userPrompt, $panelist)
                ?? $this->promptPanelist($userPrompt, $panelist)
                ?? PanelResponse::failure(model: $panelist, error: 'panel call failed after retry');
        }

        return $responses;
    }

    public function runJudge(string $spec, array $panelCritiques): array
    {
        $base = JudgePrompt::userPrompt($spec, $panelCritiques);
        $lastErrors = [];

        for ($attempts = 0; $attempts < 2; $attempts++) {
            $prompt = $attempts === 0
                ? $base
                : $base . "\n\nYour previous response was invalid: " . json_encode($lastErrors)
                . ". Return findings that satisfy every rule (a split finding MUST include `disagreement`).";

            $start = microtime(true);

            $response = new Judge()->prompt(
                $prompt,
                provider: Lab::OpenRouter,
                model: $this->config['judge'],
            );

            $ms = (int) round((microtime(true) - $start) * 1000);

            try {
                $findings = $this->validator->validate($response['findings'] ?? []);

                return ['findings' => $findings, 'ms' => $ms];
            } catch (JudgeException $exception) {
                $lastErrors[] = $exception->errors;
            }
        }

        throw JudgeException::invalidOutput($lastErrors);
    }

    private function promptPanelist(string $userPrompt, string $model): ?PanelResponse
    {
        $start = microtime(true);

        try {
            $response = new Panelist()->prompt(
                $userPrompt,
                provider: Lab::OpenRouter,
                model: $model,
                timeout: $this->config['timeout'],
            );
        } catch (Throwable) {
            return null;
        }

        $ms = (int) round((microtime(true) - $start) * 1000);

        return PanelResponse::success(model: $model, content: $response->text, ms: $ms);
    }
}
