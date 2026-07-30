<?php

declare(strict_types=1);

namespace App\Actions\Reviews;

use App\Models\Review;

final class DeleteReviewAction
{
    public function __invoke(Review $review): void
    {
        $review->delete();
    }
}
