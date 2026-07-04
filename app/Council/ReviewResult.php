<?php

declare(strict_types=1);

namespace App\Council;

final readonly class ReviewResult
{
    /**
     * @param array<string> $panelists
     * @param array<array-key, non-empty-array<array-key, mixed>> $rawPanelistResponses
     * @param array<array-key, mixed> $findings
     * @param array<array-key, mixed> $metrics
     */
    public function __construct(
        public ReviewMode $mode,
        public string     $dimension,
        public array      $panelists,
        public int        $panelSize,
        public array      $rawPanelistResponses,
        public array      $findings,
        public array      $metrics,
        public string     $status,
    ) {}

    /**
     * @return array<string, array<array-key, mixed>|int|string>
     */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode->value,
            'dimension' => $this->dimension,
            'panelists' => $this->panelists,
            'panel_size' => $this->panelSize,
            'findings' => $this->findings,
            'metrics' => $this->metrics,
            'status' => $this->status,
        ];
    }
}
