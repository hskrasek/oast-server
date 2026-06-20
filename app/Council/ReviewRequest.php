<?php

declare(strict_types=1);

namespace App\Council;

final readonly class ReviewRequest
{
    public function __construct(
        public ReviewMode $mode,
        public string $dimension = 'domain-modeling',
    ) {}
}
