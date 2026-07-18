<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Reviews\CreateReviewAction;
use App\Council\Dimension;
use App\Council\ReviewMode;
use App\Http\Requests\StoreReviewRequest;
use App\Http\Resources\ReviewResource;
use App\Organizations\OrganizationContext;
use Illuminate\Http\JsonResponse;

final class ReviewController extends Controller
{
    public function store(StoreReviewRequest $request, CreateReviewAction $action): JsonResponse
    {
        $review = $action(
            $request->string('spec')->value(),
            $request->enum('mode', ReviewMode::class, ReviewMode::Council),
            app(OrganizationContext::class)->organization(),
            $request->user(),
            dimension: $request->enum('dimension', Dimension::class, Dimension::DomainModeling),
        );

        return new ReviewResource($review)->response()
            ->setStatusCode(202)
            ->header('Location', route($request->getHost() === config('oast.api_domain') ? 'api.reviews.show' : 'app.reviews.show', $review));
    }
}
