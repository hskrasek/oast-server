<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Site\PublicationRepository;
use Illuminate\Contracts\View\View;

final class ReviewShowController
{
    public function __invoke(PublicationRepository $publications, string $slug): View
    {
        $publication = $publications->find($slug);

        abort_if(!$publication instanceof \App\Site\Publication, 404);

        return view('site.review-show', ['publication' => $publication]);
    }
}
