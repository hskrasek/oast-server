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

it('keeps token and browser principals separate', function (): void {
    config(['oast.max_concurrent_streams' => 1]);
    $manager = app(StreamLeaseManager::class);
    $manager->acquire('token:7');
    expect($manager->acquire('user:7'))->toBeInstanceOf(App\Streaming\StreamLease::class);
});
