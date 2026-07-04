<?php

declare(strict_types=1);

namespace App\Council;

use App\Jobs\RunJudge;
use App\Jobs\RunPanelist;
use App\Models\Review;

final class PanelFinalizer
{
    public function finalize(int $reviewId, Dimension $dimension): void
    {
        $review = Review::query()->findOrFail($reviewId);

        if (in_array($review->status, ['judging', 'complete', 'error'], true)) {
            return; // quorum-early judge already ran or review already terminal
        }

        $successes = $review->panelResponses()->where('ok', true)->where('late', false)->count();

        if ($successes >= RunPanelist::quorumFor($review)) {
            RunJudge::dispatch($reviewId, $dimension);

            return;
        }

        $failed = $review->panelResponses()->where('ok', false)->pluck('model')->all();
        $review->update(['status' => 'error']);
        $review->appendEvent('review.failed', ['stage' => 'panel', 'problem' => [
            'title' => 'Panel quorum not met',
            'detail' => sprintf('%d of %d required panelists succeeded.', $successes, RunPanelist::quorumFor($review)),
            'failed_models' => $failed,
        ]]);
    }
}
