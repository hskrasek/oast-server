<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Organizations\OrganizationContext;
use Illuminate\Contracts\View\View;

final class ReviewIndexController
{
    public function __invoke(OrganizationContext $context): View
    {
        return view('app.reviews.index', [
            'reviews' => $context->organization()->reviews()->latest()->paginate(20),
            'statusLabels' => [
                'queued' => 'Queued',
                'running' => 'Running',
                'judging' => 'Judging',
                'complete' => 'Complete',
                'error' => 'Failed',
            ],
        ]);
    }
}
