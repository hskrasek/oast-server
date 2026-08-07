<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Reviews\CreateReviewAction;
use App\Http\Requests\StoreWebReviewRequest;
use App\Models\User;
use App\Organizations\OrganizationContext;
use Illuminate\Http\Response;

final class StoreReviewController
{
    public function __invoke(
        StoreWebReviewRequest $request,
        CreateReviewAction $create,
        OrganizationContext $context,
    ): Response {
        $creator = $request->user();
        assert($creator instanceof User);

        $review = $create(
            $request->spec(),
            $request->mode(),
            $context->organization(),
            $creator,
            $request->specRef(),
            $request->dimension(),
        );

        return response('', 202)->header('Location', route('app.reviews.show', $review->id));
    }
}
