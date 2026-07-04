<?php

declare(strict_types=1);

namespace App\Ai;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final class ModelPricing
{
    /**
     * @param  array<string, int>  $usage
     */
    public function costUsd(string $model, array $usage): ?float
    {
        $rates = $this->rates()[$model] ?? null;

        if ($rates === null) {
            return null;
        }

        $prompt = $usage['prompt_tokens'] ?? 0;
        $completion = ($usage['completion_tokens'] ?? 0) + ($usage['reasoning_tokens'] ?? 0);

        return round($prompt * $rates['prompt'] + $completion * $rates['completion'], 6);
    }

    /**
     * @return array<string, array{prompt: float, completion: float}>
     */
    private function rates(): array
    {
        return Cache::remember('oast.model-pricing', now()->addDay(), function (): array {
            $models = Http::get('https://openrouter.ai/api/v1/models')->json('data');
            $rates = [];

            foreach (is_array($models) ? $models : [] as $model) {
                if (! is_array($model)) {
                    continue;
                }
                if (! is_string($model['id'] ?? null)) {
                    continue;
                }
                if (! is_array($model['pricing'] ?? null)) {
                    continue;
                }
                $prompt = $model['pricing']['prompt'];
                $completion = $model['pricing']['completion'];

                $rates[$model['id']] = [
                    'prompt' => is_numeric($prompt) ? (float) $prompt : 0.0,
                    'completion' => is_numeric($completion) ? (float) $completion : 0.0,
                ];
            }

            return $rates;
        });
    }
}
