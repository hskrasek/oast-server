<?php

declare(strict_types=1);

namespace App\Actions\Reviews;

use App\Council\CouncilOrchestrator;
use App\Council\Exceptions\JudgeException;
use App\Council\Exceptions\PanelException;
use App\Council\ReviewMode;
use App\Council\ReviewRequest;
use App\Models\Review;

final readonly class CreateReviewAction
{
    public function __construct(
        private CouncilOrchestrator $orchestrator,
    ) {}

    /**
     * Run a review and persist it. Transport-agnostic: returns the domain
     * Review on success and throws the domain exception on failure — callers
     * (HTTP responder, CLI command) decide how to present either outcome.
     */
    public function __invoke(string $spec, ReviewMode $mode, ?string $specRef = null): Review
    {
        $specHash = hash('sha256', $spec);

        try {
            $result = $this->orchestrator->review($spec, new ReviewRequest($mode));
        } catch (PanelException|JudgeException $exception) {
            $this->persistError($specHash, $mode, $exception->getMessage(), $specRef);

            throw $exception;
        }

        return Review::fromResult($result, $specRef, $specHash);
    }

    private function persistError(string $hash, ReviewMode $mode, string $message, ?string $specRef): void
    {
        Review::create([
            'spec_ref' => $specRef,
            'spec_hash' => $hash,
            'mode' => $mode->value,
            'dimension' => 'domain-modeling',
            'panel_size' => 0,
            'status' => 'error',
            'error' => $message,
        ]);
    }
}
