<?php

declare(strict_types=1);

namespace App\Council;

use App\Ai\Agents\Panelist;
use App\Council\Prompts\PanelistPrompt;
use Laravel\Ai\Enums\Lab;
use Throwable;

final readonly class CouncilOrchestrator
{
    public function __construct(
        private FindingValidator $validator,
        private array            $config,
    ) {}

    /**
     * @return PanelResponse[]
     */
    public function deliberateOn(string $spec): array
    {
        $userPrompt = PanelistPrompt::userPrompt($spec);

        $responses = [];

        foreach ($this->config['panelists'] as $panelist) {
            $responses[] = $this->promptPanelist($userPrompt, $panelist)
                ?? $this->promptPanelist($userPrompt, $panelist)
                ?? PanelResponse::failure(model: $panelist, error: 'panel call failed after retry');
        }

        return $responses;
    }

    private function promptPanelist(string $userPrompt, string $model): ?PanelResponse
    {
        $start = microtime(true);

        try {
            $response = new Panelist()->prompt(
                $userPrompt,
                provider: Lab::OpenRouter,
                model: $model,
                timeout: $this->config['timeout'],
            );
        } catch (Throwable) {
            return null;
        }

        $ms = (int) round((microtime(true) - $start) * 1000);

        return PanelResponse::success(model: $model, content: '', ms: $ms);
    }
}
