<?php

declare(strict_types=1);

namespace App\Streaming;

final class StreamLease
{
    private bool $released = false;

    public function __construct(private readonly StreamLeaseManager $manager, private readonly string $principal, private readonly string $leaseId) {}

    public function id(): string
    {
        return $this->leaseId;
    }

    public function refresh(): void
    {
        if (! $this->released) {
            $this->manager->refresh($this->principal, $this->leaseId);
        }
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        }

        $this->released = true;
        $this->manager->release($this->principal, $this->leaseId);
    }
}
