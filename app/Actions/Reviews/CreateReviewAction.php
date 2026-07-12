<?php

declare(strict_types=1);

namespace App\Actions\Reviews;

use App\Council\Dimension;
use App\Council\PanelFinalizer;
use App\Council\ReviewMode;
use App\Jobs\RunPanelist;
use App\Models\Organization;
use App\Models\Review;
use App\Models\User;
use App\Reviews\ActiveReviewLimitExceeded;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

final readonly class CreateReviewAction
{
    public function __invoke(
        string $spec,
        ReviewMode $mode,
        Organization $organization,
        ?User $creator,
        ?string $specRef = null,
        Dimension $dimension = Dimension::DomainModeling,
    ): Review {
        $panelists = $mode === ReviewMode::Baseline ? [$this->baselineModel()] : $this->panelistRoster();

        return DB::transaction(function () use ($spec, $mode, $organization, $creator, $specRef, $dimension, $panelists): Review {
            Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $active = Review::query()->where('organization_id', $organization->id)
                ->whereIn('status', ['queued', 'running', 'judging'])->count();
            if ($active >= config()->integer('oast.max_active_reviews')) {
                throw new ActiveReviewLimitExceeded(60);
            }

            $review = new Review([
                'spec_ref' => $specRef, 'spec_hash' => hash('sha256', $spec), 'spec' => $spec,
                'mode' => $mode->value, 'dimension' => $dimension->value, 'panelists' => $panelists,
                'panel_size' => 0, 'status' => 'running',
            ]);
            $review->organization()->associate($organization);
            $review->creator()->associate($creator);
            $review->save();
            $review->appendEvent('review.queued', ['mode' => $mode->value, 'dimension' => $dimension->value, 'panelists' => $panelists]);
            DB::afterCommit(fn() => $this->dispatchPanel($review->id, $panelists, $dimension));

            return $review;
        }, 3);
    }

    /** @param list<string> $panelists */
    private function dispatchPanel(int $reviewId, array $panelists, Dimension $dimension): void
    {
        Bus::batch(
            collect($panelists)->map(fn(string $model): RunPanelist => new RunPanelist($reviewId, $model, $dimension))->all(),
        )->name('review:' . $reviewId)->allowFailures()
            ->finally(fn(Batch $batch) => new PanelFinalizer()->finalize($reviewId, $dimension))->dispatch();
    }

    /** @return list<string> */
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
