<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Council\Dimension;
use App\Council\ReviewMode;
use Illuminate\Contracts\View\View;

final class CreateReviewController
{
    public function __invoke(): View
    {
        return view('app.reviews.create', [
            'modes' => ReviewMode::cases(),
            'dimensions' => Dimension::cases(),
        ]);
    }
}
