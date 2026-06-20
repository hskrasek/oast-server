<?php

declare(strict_types=1);

namespace App\Council;

enum ReviewMode: string
{
    case Baseline = 'baseline';
    case Council = 'council';
}
