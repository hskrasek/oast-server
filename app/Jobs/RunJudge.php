<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\ModelPricing;
use App\Council\CouncilOrchestrator;
use App\Council\Dimension;
use App\Council\Exceptions\JudgeException;
use App\Models\Review;
use App\Models\ReviewPanelResponse;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

final class RunJudge implements ShouldQueue
{
    use Batchable;
    use Queueable;

    public function __construct(
        public readonly int $reviewId,
        public readonly Dimension $dimension,
    ) {}

    public function handle(): void
    {
        $claimed = Review::query()
            ->whereKey($this->reviewId)
            ->where('status', 'running')
            ->update(['status' => 'judging']);

        if ($claimed === 0) {
            return; // another dispatch won the CAS
        }

        $orchestrator = app(CouncilOrchestrator::class);
        $review = Review::query()->findOrFail($this->reviewId);
        $panel = $review->panelResponses()->where('ok', true)->where('late', false)->get();
        $oastConfig = config('oast');
        $oastConfig = is_array($oastConfig) ? $oastConfig : [];

        $judgeModel = is_string($oastConfig['judge'] ?? null) ? $oastConfig['judge'] : '';
        if ($judgeModel === '') {
            throw new RuntimeException('Judge model configuration is missing or invalid.');
        }

        $review->appendEvent('judge.start', [
            'model' => $judgeModel,
            'panel_size' => $panel->count(),
        ]);

        $critiques = [
            ...$panel
                ->map(fn(ReviewPanelResponse $r): array => ['model' => $r->model, 'content' => $r->content])
                ->values()
                ->all(),
        ];

        try {
            $judge = $orchestrator->runJudge((string) $review->spec, $critiques, $this->dimension);
        } catch (JudgeException $judgeException) {
            $review->update(['status' => 'error']);
            $review->appendEvent('review.failed', ['stage' => 'judge', 'problem' => [
                'title' => 'Judge produced invalid output',
                'detail' => $judgeException->getMessage(),
            ]]);

            return;
        }

        $judgeCost = new ModelPricing()->costUsd($judgeModel, $judge['usage']);

        $metrics = $panel->map(fn(ReviewPanelResponse $r): array => [
            'model' => $r->model, 'ms' => $r->ms, 'usage' => $r->usage, 'cost_usd' => $r->cost_usd,
        ])->all();
        $metrics[] = ['model' => $judgeModel, 'ms' => $judge['ms'], 'usage' => $judge['usage'], 'cost_usd' => $judgeCost];

        $totalCost = collect($metrics)->sum(fn(array $m): float => (float) ($m['cost_usd'] ?? 0.0));

        $review->update([
            'status' => 'complete',
            'findings' => $judge['findings'],
            'panelists' => $panel->pluck('model')->all(),
            'panel_size' => $panel->count(),
            'metrics' => [...$metrics, ['total_cost_usd' => $totalCost]],
        ]);

        $review->appendEvent('judge.done', [
            'model' => $judgeModel,
            'ms' => $judge['ms'],
            'usage' => $judge['usage'],
            'cost_usd' => $judgeCost,
            'findings_count' => count($judge['findings']),
        ]);
        $review->appendEvent('review.completed', [
            'findings' => $judge['findings'],
            'total_cost_usd' => $totalCost,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $review = Review::query()->find($this->reviewId);

        if ($review === null || $review->status !== 'judging') {
            return;
        }

        $review->update(['status' => 'error']);
        $review->appendEvent('review.failed', ['stage' => 'judge', 'problem' => [
            'title' => 'Judge run failed',
            'detail' => $exception?->getMessage() ?? 'judge job failed',
        ]]);
    }
}
