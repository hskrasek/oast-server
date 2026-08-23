<?php

declare(strict_types=1);

namespace App\Streaming;

final class StreamLease
{
    // A third of the 900s lease TTL: the SSE loop calls refresh() every
    // 500ms, and persisting each call would hammer the database cache store
    // that queue workers share on self-host SQLite.
    private const int REFRESH_INTERVAL_SECONDS = 300;

    private bool $released = false;

    private int $refreshedAt;

    public function __construct(private readonly StreamLeaseManager $manager, private readonly string $principal, private readonly string $leaseId)
    {
        $this->refreshedAt = now()->getTimestamp();
    }

    public function id(): string
    {
        return $this->leaseId;
    }

    public function refresh(): void
    {
        if ($this->released || now()->getTimestamp() - $this->refreshedAt < self::REFRESH_INTERVAL_SECONDS) {
            return;
        }

        $this->refreshedAt = now()->getTimestamp();
        $this->manager->refresh($this->principal, $this->leaseId);
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        } $this->released = true;
        $this->manager->release($this->principal, $this->leaseId);
    }
}
