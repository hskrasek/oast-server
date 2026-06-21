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

    public function review(string $spec, ReviewRequest $request): ReviewResult
    {
        $panel = $request->mode === ReviewMode::Baseline
            ? $this->baselinePanel($spec)
            : $this->deliberateOn($spec);

        $ok = array_values(array_filter($panel, fn(PanelResponse $r): bool => $r->ok));

        if ($request->mode === ReviewMode::Council && count($ok) < $this->config['quorum']) {
            $dead = array_filter($panel, fn(PanelResponse $r): bool => !$r->ok)
                    |> (fn($x): array => array_map(fn(PanelResponse $r): string => $r->model, $x, ))
                    |> array_values(...);

            throw PanelException::quorumNotMet($dead, count($ok), $this->config['quorum']);
        }

        $critiques = array_map(fn(PanelResponse $r): array => ['model' => $r->model, 'content' => $r->content], $ok);
        $judge = $this->runJudge($spec, $critiques);

        $metrics = array_map(fn(PanelResponse $r): array => ['model' => $r->model, 'ms' => $r->ms], $panel);
        $metrics[] = ['model' => $this->config['judge'], 'ms' => $judge['ms']];

        return new ReviewResult(
            mode: $request->mode,
            dimension: $request->dimension,
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

    private function baselinePanel(string $spec): array
    {
        $model = $this->config['baseline'] ?? $this->config['panelists'][0];
        $userPrompt = PanelistPrompt::userPrompt($spec);

        return [
            $this->promptPanelist($userPrompt, $model)
            ?? $this->promptPanelist($userPrompt, $model)
                ?? PanelResponse::failure($model, 'baseline call failed after retry'),
        ];
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
