<?php

declare(strict_types=1);

namespace App\Council;

use App\Ai\Agents\Judge;
use App\Ai\Agents\Panelist;
use App\Council\Exceptions\JudgeException;
use App\Council\Exceptions\PanelException;
use App\Council\Prompts\JudgePrompt;
use App\Council\Prompts\PanelistPrompt;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

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
     * @return list<PanelResponse>
     */
    public function deliberateOn(string $spec, Dimension $dimension = Dimension::DomainModeling): array
    {
        $userPrompt = PanelistPrompt::userPrompt($spec);

        $responses = [];

        foreach ($this->config['panelists'] as $panelist) {
            $response = $this->promptPanelist($userPrompt, $panelist, $dimension)
                ?? $this->promptPanelist($userPrompt, $panelist, $dimension);

            $responses[] = $response ?? PanelResponse::failure(model: $panelist, error: 'panel call failed after retry');
        }

        return $responses;
    }

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

    public function review(string $spec, ReviewRequest $request): ReviewResult
    {
        $panel = $request->mode === ReviewMode::Baseline
            ? $this->baselinePanel($spec, $request->dimension)
            : $this->deliberateOn($spec, $request->dimension);

        $ok = array_values(array_filter($panel, fn(PanelResponse $r): bool => $r->ok));

        if ($request->mode === ReviewMode::Council && count($ok) < $this->config['quorum']) {
            $dead = array_filter($panel, fn(PanelResponse $r): bool => ! $r->ok)
                |> (fn(array $failed): array => array_map(fn(PanelResponse $r): string => $r->model, $failed))
                |> array_values(...);

            throw PanelException::quorumNotMet($dead, count($ok), $this->config['quorum']);
        }

        $critiques = array_map(fn(PanelResponse $r): array => ['model' => $r->model, 'content' => $r->content], $ok);
        $judge = $this->runJudge($spec, $critiques, $request->dimension);

        $metrics = array_map(fn(PanelResponse $r): array => ['model' => $r->model, 'ms' => $r->ms, 'usage' => $r->usage], $panel);
        $metrics[] = ['model' => $this->config['judge'], 'ms' => $judge['ms'], 'usage' => $judge['usage']];

        return new ReviewResult(
            mode: $request->mode,
            dimension: $request->dimension->value,
            panelists: array_map(fn(PanelResponse $r): string => $r->model, $ok),
            panelSize: count($ok),
            rawPanelistResponses: array_map(fn(PanelResponse $r): array => [
                'model' => $r->model,
                'ok' => $r->ok,
                'content' => $r->content,
                'error' => $r->error,
            ], $panel),
            findings: $judge['findings'],
            metrics: $metrics,
            status: 'complete',
        );
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

    /**
     * @return list<PanelResponse>
     */
    private function baselinePanel(string $spec, Dimension $dimension): array
    {
        $model = $this->config['baseline'] ?? $this->config['panelists'][0];
        $userPrompt = PanelistPrompt::userPrompt($spec);

        $response = $this->promptPanelist($userPrompt, $model, $dimension)
            ?? $this->promptPanelist($userPrompt, $model, $dimension);

        return [
            $response ?? PanelResponse::failure($model, 'baseline call failed after retry'),
        ];
    }

    private function promptPanelist(string $userPrompt, string $model, Dimension $dimension): ?PanelResponse
    {
        $start = microtime(true);

        try {
            $response = new Panelist($dimension)->prompt(
                $userPrompt,
                provider: Lab::OpenRouter,
                model: $model,
                timeout: $this->config['timeout'],
            );
        } catch (Throwable) {
            return null;
        }

        $ms = (int) round((microtime(true) - $start) * 1000);

        return PanelResponse::success(model: $model, content: $response->text, ms: $ms, usage: $this->usageMetrics($response->usage));
    }
}
