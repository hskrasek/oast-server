<?php

declare(strict_types=1);

use App\Streaming\StreamLeaseManager;
use App\Streaming\StreamLimitExceeded;
use Illuminate\Support\Carbon;

it('counts live unique lease ids and releases idempotently', function (): void {
    config(['oast.max_concurrent_streams' => 2]);
    $manager = app(StreamLeaseManager::class);
    $one = $manager->acquire('token:1');
    $two = $manager->acquire('token:1');
    expect($one->id())->not->toBe($two->id());
    expect(fn() => $manager->acquire('token:1'))->toThrow(StreamLimitExceeded::class);
    $one->release();
    $one->release();
    expect($manager->acquire('token:1'))->toBeInstanceOf(App\Streaming\StreamLease::class);
});

it('purges each abandoned lease by its own expiry even when another lease refreshes', function (): void {
    Carbon::setTestNow('2026-07-11 00:00:00');
    config(['oast.max_concurrent_streams' => 2]);
    $manager = app(StreamLeaseManager::class);
    $abandoned = $manager->acquire('user:1');
    Carbon::setTestNow(now()->addMinutes(10));
    $active = $manager->acquire('user:1');
    $active->refresh();
    Carbon::setTestNow(now()->addMinutes(6));
    $active->refresh();
    expect($manager->acquire('user:1')->id())->not->toBe($abandoned->id());
    Carbon::setTestNow();
});

it('skips persisting a refresh until the refresh interval has elapsed', function (): void {
    Carbon::setTestNow('2026-07-11 00:00:00');
    config(['oast.max_concurrent_streams' => 1]);
    $manager = app(StreamLeaseManager::class);
    $lease = $manager->acquire('user:9');

    Carbon::setTestNow(now()->addSeconds(299));
    $lease->refresh();
    // Not persisted: expiry still dates from acquisition, so the lease dies at +900s.
    Carbon::setTestNow('2026-07-11 00:15:01');
    expect($manager->acquire('user:9'))->toBeInstanceOf(App\Streaming\StreamLease::class);

    Carbon::setTestNow('2026-07-11 00:00:00');
    $renewed = $manager->acquire('user:8');
    Carbon::setTestNow(now()->addSeconds(300));
    $renewed->refresh();
    // Persisted: the lease now expires 900s after the refresh, not acquisition.
    Carbon::setTestNow('2026-07-11 00:15:01');
    expect(fn() => $manager->acquire('user:8'))->toThrow(StreamLimitExceeded::class);
    Carbon::setTestNow();
});

it('keeps token and browser principals separate', function (): void {
    config(['oast.max_concurrent_streams' => 1]);
    $manager = app(StreamLeaseManager::class);
    $manager->acquire('token:7');
    expect($manager->acquire('user:7'))->toBeInstanceOf(App\Streaming\StreamLease::class);
});
