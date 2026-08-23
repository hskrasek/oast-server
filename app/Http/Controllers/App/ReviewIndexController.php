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
            // Never hydrate `spec` (up to 5 MiB per row) or findings here; the
            // index only renders metadata and what the delete policy reads.
            'reviews' => $context->organization()->reviews()->latest()
                ->select(['id', 'organization_id', 'created_by_user_id', 'spec_ref', 'status', 'error', 'metrics', 'created_at'])
                ->paginate(20),
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
