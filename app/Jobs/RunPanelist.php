<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\Agents\Panelist;
use App\Ai\ModelPricing;
use App\Council\Dimension;
use App\Council\Prompts\PanelistPrompt;
use App\Models\Review;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Responses\Data\Usage;
use Throwable;

final class RunPanelist implements ShouldQueue
{
    use Batchable;
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public readonly int $reviewId,
        public readonly string $model,
        public readonly Dimension $dimension,
    ) {}

    public static function quorumFor(Review $review): int
    {
        return $review->mode === 'baseline' ? 1 : (int) config('oast.quorum');
    }

    public function handle(): void
    {
        $review = Review::query()->findOrFail($this->reviewId);
        $review->appendEvent('panel.model.start', ['model' => $this->model]);

        $start = microtime(true);

        $response = new Panelist($this->dimension)->prompt(
            PanelistPrompt::userPrompt((string) $review->spec),
            provider: Lab::OpenRouter,
            model: $this->model,
            timeout: (int) config('oast.timeout'),
        );

        $ms = (int) round((microtime(true) - $start) * 1000);
        $usage = $this->usageMetrics($response->usage);
        $late = in_array($review->refresh()->status, ['judging', 'complete', 'error'], true);

        $review->panelResponses()->create([
            'model' => $this->model,
            'ok' => true,
            'content' => $response->text,
            'ms' => $ms,
            'usage' => $usage,
            'cost_usd' => new ModelPricing()->costUsd($this->model, $usage),
            'late' => $late,
        ]);

        if ($late) {
            $review->appendEvent('panel.model.late', ['model' => $this->model, 'ms' => $ms]);

            return;
        }

        $review->appendEvent('panel.model.done', [
            'model' => $this->model,
            'ms' => $ms,
            'usage' => $usage,
            'cost_usd' => new ModelPricing()->costUsd($this->model, $usage),
        ]);

        $this->dispatchJudgeAtQuorum($review);
    }

    public function failed(?Throwable $exception): void
    {
        $review = Review::query()->find($this->reviewId);

        if ($review === null) {
            return;
        }

        $review->panelResponses()->create([
            'model' => $this->model,
            'ok' => false,
            'error' => $exception?->getMessage() ?? 'panel call failed',
        ]);

        $review->appendEvent('panel.model.failed', [
            'model' => $this->model,
            'error' => $exception?->getMessage() ?? 'panel call failed',
            'attempt' => $this->tries,
        ]);
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

    private function dispatchJudgeAtQuorum(Review $review): void
    {
        // ponytail: sync driver runs delayed jobs inline, which would always cut the
        // last panelist — quorum-early is a real-queue optimization only.
        if (config('queue.default') === 'sync') {
            return;
        }

        $successes = $review->panelResponses()->where('ok', true)->where('late', false)->count();

        if ($successes === self::quorumFor($review)) {
            RunJudge::dispatch($this->reviewId, $this->dimension)
                ->delay((int) config('oast.quorum_grace'));
        }
    }
}
