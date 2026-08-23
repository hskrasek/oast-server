<?php

declare(strict_types=1);

namespace App\Reviews;

use RuntimeException;

final class ActiveReviewLimitExceeded extends RuntimeException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct('Active review limit exceeded.');
    }
}
