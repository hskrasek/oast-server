<?php

declare(strict_types=1);

namespace App\Streaming;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class StreamLeaseManager
{
    private const int TTL_SECONDS = 900;

    public function acquire(string $principal): StreamLease
    {
        $id = (string) Str::uuid();
        $this->locked($principal, function (array $leases) use ($id): array {
            $leases = $this->live($leases);
            if (count($leases) >= config()->integer('oast.max_concurrent_streams')) {
                throw new StreamLimitExceeded(60);
            }

            $leases[$id] = now()->addSeconds(self::TTL_SECONDS)->getTimestamp();

            return $leases;
        });

        return new StreamLease($this, $principal, $id);
    }

    public function refresh(string $principal, string $id): void
    {
        $this->locked($principal, function (array $leases) use ($id): array {
            $leases = $this->live($leases);
            if (array_key_exists($id, $leases)) {
                $leases[$id] = now()->addSeconds(self::TTL_SECONDS)->getTimestamp();
            }

            return $leases;
        });
    }

    public function release(string $principal, string $id): void
    {
        $this->locked($principal, function (array $leases) use ($id): array {
            $leases = $this->live($leases);
            unset($leases[$id]);

            return $leases;
        });
    }

    /**
     * @param  callable(array<string,int>): array<string,int>  $change
     */
    private function locked(string $principal, callable $change): void
    {
        $key = 'oast:sse:' . hash('sha256', $principal);
        Cache::lock($key . ':lock', 5)->block(2, function () use ($key, $change): void {
            $stored = Cache::get($key, []);
            $leases = [];
            if (is_array($stored)) {
                foreach ($stored as $leaseId => $expiry) {
                    if (is_string($leaseId) && is_int($expiry)) {
                        $leases[$leaseId] = $expiry;
                    }
                }
            }

            $leases = $change($leases);
            if ($leases === []) {
                Cache::forget($key);
            } else {
                Cache::put($key, $leases, now()->addSeconds(self::TTL_SECONDS * 2));
            }
        });
    }

    /**
     * @param  array<string,int>  $leases
     * @return array<string,int>
     */
    private function live(array $leases): array
    {
        return array_filter($leases, fn(int $expiry): bool => $expiry > now()->getTimestamp());
    }
}
