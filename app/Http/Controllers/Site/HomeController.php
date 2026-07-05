<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Site\PublicationRepository;
use Illuminate\Contracts\View\View;

final class HomeController
{
    public function __invoke(PublicationRepository $publications): View
    {
        return view('site.home', ['featured' => array_slice($publications->all(), 0, 3)]);
    }
}
