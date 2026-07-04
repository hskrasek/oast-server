<?php

declare(strict_types=1);

namespace App\Actions\Reviews;

use App\Council\Dimension;
use App\Council\PanelFinalizer;
use App\Council\ReviewMode;
use App\Jobs\RunPanelist;
use App\Models\Review;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;

final readonly class CreateReviewAction
{
    public function __invoke(
        string $spec,
        ReviewMode $mode,
        ?string $specRef = null,
        Dimension $dimension = Dimension::DomainModeling,
    ): Review {
        $panelists = $mode === ReviewMode::Baseline
            ? [$this->baselineModel()]
            : $this->panelistRoster();

        $review = Review::query()->create([
            'spec_ref' => $specRef,
            'spec_hash' => hash('sha256', $spec),
            'spec' => $spec,
            'mode' => $mode->value,
            'dimension' => $dimension->value,
            'panelists' => $panelists,
            'panel_size' => 0,
            'status' => 'running',
        ]);

        $review->appendEvent('review.queued', [
            'mode' => $mode->value,
            'dimension' => $dimension->value,
            'panelists' => $panelists,
        ]);

        $reviewId = $review->id;

        Bus::batch(
            collect($panelists)
                ->map(fn(string $model): RunPanelist => new RunPanelist($reviewId, $model, $dimension))
                ->all(),
        )
            ->name('review:' . $reviewId)
            ->allowFailures()
            ->finally(fn(Batch $batch) => new PanelFinalizer()->finalize($reviewId, $dimension))
            ->dispatch();

        return $review;
    }

    /**
     * @return list<string>
     */
    private function panelistRoster(): array
    {
        $panelists = config('oast.panelists');

        return is_array($panelists) ? array_values(array_filter($panelists, is_string(...))) : [];
    }

    private function baselineModel(): string
    {
        $baseline = config('oast.baseline');

        return is_string($baseline) ? $baseline : ($this->panelistRoster()[0] ?? '');
    }
}
