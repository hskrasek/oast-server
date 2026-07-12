<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Reviews\DeleteReviewAction;
use App\Reviews\ScopedReviewResolver;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

final class DeleteReviewController
{
    public function __invoke(string $review, ScopedReviewResolver $resolver, DeleteReviewAction $delete): Response
    {
        $model = $resolver->findOrFail($review);
        Gate::authorize('delete', $model);
        $delete($model);

        return response()->noContent();
    }
}
