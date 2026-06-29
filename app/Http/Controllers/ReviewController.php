<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Reviews\CreateReviewAction;
use App\Council\ReviewMode;
use App\Http\Requests\StoreReviewRequest;
use App\Http\Resources\ReviewResource;

final class ReviewController extends Controller
{
    public function store(StoreReviewRequest $request, CreateReviewAction $action): ReviewResource
    {
        $review = $action(
            $request->string('spec')->value(),
            $request->enum('mode', ReviewMode::class, ReviewMode::Council),
        );

        return new ReviewResource($review);
    }
}
