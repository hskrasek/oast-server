<?php

declare(strict_types=1);

namespace App\Council\Exceptions;

use RuntimeException;

final class PanelException extends RuntimeException
{
    /**
     * @param  list<string>  $failedModels
     */
    private function __construct(string $message, public readonly array $failedModels)
    {
        parent::__construct($message);
    }

    /**
     * @param  list<string>  $failedModels
     */
    public static function quorumNotMet(array $failedModels, int $succeeded, int $required): self
    {
        return new self(
            sprintf('Quorum not met: %d panelist(s) succeeded, %d required. Failed: ', $succeeded, $required) . implode(', ', $failedModels),
            $failedModels,
        );
    }
}
