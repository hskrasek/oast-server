<?php

declare(strict_types=1);

namespace App\Streaming;

final class StreamLease
{
    private bool $released = false;

    private int $refreshedAt;

    public function __construct(private readonly StreamLeaseManager $manager, private readonly string $principal, private readonly string $leaseId, private readonly int $refreshAfterSeconds)
    {
        $this->refreshedAt = now()->getTimestamp();
    }

    public function id(): string
    {
        return $this->leaseId;
    }

    /**
     * Callers may invoke this on a tight loop (the SSE tick); the write is
     * skipped until refreshAfterSeconds has elapsed so a lease costs a
     * couple of cache writes per TTL, not two per second.
     */
    public function refresh(): void
    {
        if ($this->released || now()->getTimestamp() - $this->refreshedAt < $this->refreshAfterSeconds) {
            return;
        }

        $this->refreshedAt = now()->getTimestamp();
        $this->manager->refresh($this->principal, $this->leaseId);
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
