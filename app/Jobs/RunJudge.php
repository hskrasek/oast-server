<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Council\Dimension;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class RunJudge implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $reviewId,
        public readonly Dimension $dimension,
    ) {}

    public function handle(): void
    {
        // TODO: Task 4 implementation
    }
}
