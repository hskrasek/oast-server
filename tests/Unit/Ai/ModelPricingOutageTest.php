<?php

declare(strict_types=1);

use App\Ai\ModelPricing;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Cache::flush();
});

it('degrades to null cost on a transport failure without caching the outage', function (): void {
    $calls = 0;
    Http::fake([
        'openrouter.ai/api/v1/models' => function () use (&$calls) {
            $calls++;

            if ($calls === 1) {
                throw new ConnectionException('connection refused');
            }

            return Http::response(['data' => [
                ['id' => 'openai/gpt-5.5', 'pricing' => ['prompt' => '0.000002', 'completion' => '0.00001']],
            ]]);
        },
    ]);

    expect(new ModelPricing()->costUsd('openai/gpt-5.5', ['prompt_tokens' => 10]))->toBeNull();

    expect(new ModelPricing()->costUsd('openai/gpt-5.5', ['prompt_tokens' => 10]))->not->toBeNull();
});

it('degrades to null cost on a failed HTTP response without caching the outage', function (): void {
    $calls = 0;
    Http::fake([
        'openrouter.ai/api/v1/models' => function () use (&$calls) {
            $calls++;

            if ($calls === 1) {
                return Http::response(status: 500);
            }

            return Http::response(['data' => [
                ['id' => 'openai/gpt-5.5', 'pricing' => ['prompt' => '0.000002', 'completion' => '0.00001']],
            ]]);
        },
    ]);

    expect(new ModelPricing()->costUsd('openai/gpt-5.5', ['prompt_tokens' => 10]))->toBeNull();

    expect(new ModelPricing()->costUsd('openai/gpt-5.5', ['prompt_tokens' => 10]))->not->toBeNull();
});
