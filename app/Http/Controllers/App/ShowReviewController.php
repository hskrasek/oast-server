<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Reviews\ScopedReviewResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

final class ShowReviewController
{
    public function __invoke(string $review, ScopedReviewResolver $resolver): View
    {
        $model = $resolver->findOrFail($review);
        Gate::authorize('view', $model);

        return view('app.reviews.show', ['review' => $model]);
    }
}
