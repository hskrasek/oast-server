<?php

declare(strict_types=1);

namespace App\Reviews;

use App\Models\Review;
use App\Organizations\OrganizationContext;

final readonly class ScopedReviewResolver
{
    public function __construct(private OrganizationContext $context) {}

    public function findOrFail(int|string $id): Review
    {
        return Review::query()->where('organization_id', $this->context->organization()->id)->findOrFail($id);
    }
}
