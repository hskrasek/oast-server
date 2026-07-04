<?php

declare(strict_types=1);

use App\Ai\ModelPricing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Cache::flush();
    Http::fake([
        'openrouter.ai/api/v1/models' => Http::response(['data' => [
            'not-a-model-row',
            ['id' => 'openai/gpt-5.5', 'pricing' => ['prompt' => '0.000002', 'completion' => '0.00001']],
            ['id' => 123, 'pricing' => ['prompt' => '0.000001', 'completion' => '0.000005']],
            ['pricing' => ['prompt' => '0.000001', 'completion' => '0.000005']],
            ['id' => 'openai/no-pricing', 'pricing' => 'invalid'],
            ['id' => 'openai/bad-rates', 'pricing' => ['prompt' => 'invalid', 'completion' => 'bad']],
        ]]),
    ]);
});

it('prices a known model from prompt, completion, and reasoning tokens', function (): void {
    $cost = new ModelPricing()->costUsd('openai/gpt-5.5', [
        'prompt_tokens' => 1000, 'completion_tokens' => 500, 'reasoning_tokens' => 100,
    ]);

    // 1000*0.000002 + (500+100)*0.00001 = 0.002 + 0.006
    expect($cost)->toBe(0.008);
});

it('returns null for an unknown slug (e.g. a ~latest alias)', function (): void {
    expect(new ModelPricing()->costUsd('~anthropic/claude-sonnet-latest', ['prompt_tokens' => 10]))->toBeNull();
});

it('caches the price list', function (): void {
    $pricing = new ModelPricing();
    $pricing->costUsd('openai/gpt-5.5', []);
    $pricing->costUsd('openai/gpt-5.5', []);

    Http::assertSentCount(1);
});

it('handles models without pricing array in response', function (): void {
    expect(new ModelPricing()->costUsd('openai/no-pricing', []))->toBeNull();
});

it('converts non-numeric pricing values to 0.0', function (): void {
    $cost = new ModelPricing()->costUsd('openai/bad-rates', ['prompt_tokens' => 1000, 'completion_tokens' => 500]);

    expect($cost)->toBe(0.0);
});

it('defaults missing token counts to zero', function (): void {
    $cost = new ModelPricing()->costUsd('openai/gpt-5.5', []);

    expect($cost)->toBe(0.0);
});
