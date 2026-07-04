<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\ReviewResource;
use App\Models\Review;

final class ShowReviewController
{
    public function __invoke(Review $review): ReviewResource
    {
        return new ReviewResource($review);
    }
}
