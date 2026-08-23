<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Reviews\DeleteReviewAction;
use App\Reviews\ScopedReviewResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

final class DeleteReviewController
{
    public function __invoke(
        string $review,
        ScopedReviewResolver $resolver,
        DeleteReviewAction $delete,
    ): RedirectResponse {
        $model = $resolver->findOrFail($review);
        Gate::authorize('delete', $model);
        $delete($model);

        return to_route('app.reviews.index')->with('status', 'Review deleted.');
    }
}
