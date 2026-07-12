<?php

declare(strict_types=1);

namespace App\Streaming;

use RuntimeException;

final class StreamLimitExceeded extends RuntimeException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct('Concurrent stream limit exceeded.');
    }
}
