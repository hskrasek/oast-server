<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Site\PublicationRepository;
use Illuminate\Contracts\View\View;

final class ReviewIndexController
{
    public function __invoke(PublicationRepository $publications): View
    {
        return view('site.reviews-index', ['publications' => $publications->all()]);
    }
}
