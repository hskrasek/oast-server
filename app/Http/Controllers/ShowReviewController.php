<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\ReviewResource;
use App\Reviews\ScopedReviewResolver;
use Illuminate\Support\Facades\Gate;

final class ShowReviewController
{
    public function __invoke(string $review, ScopedReviewResolver $resolver): ReviewResource
    {
        $model = $resolver->findOrFail($review);
        Gate::authorize('view', $model);

        return new ReviewResource($model);
    }
}
