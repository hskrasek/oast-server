<?php

declare(strict_types=1);

namespace App\Council\Exceptions;

use App\Http\Problems\ProblemType;
use Crell\ApiProblem\ApiProblem;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Response;
use RuntimeException;
use Throwable;

final class PanelException extends RuntimeException implements Responsable
{
    /**
     * @param  array<array-key, mixed>  $errors
     */
    private function __construct(
        string $message,
        int $code,
        ?Throwable $previous,
        public readonly array $errors = [],
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * @param  list<string>  $failedModels
     */
    public static function quorumNotMet(array $failedModels, int $succeeded, int $required): self
    {
        return new self(
            sprintf('Quorum not met: %d panelist(s) succeeded, %d required. Failed: ', $succeeded, $required) . implode(', ', $failedModels),
            503,
            null,
            $failedModels,
        );
    }

    public function toResponse($request): Response
    {
        $problem = new ApiProblem(
            'Council quorum not met',
            ProblemType::QuorumNotMet->value,
        );
        $problem->setDetail($this->getMessage());
        $problem['failed_models'] = $this->errors;

        return response(
            $problem->asJson(),
            $this->getCode(),
            ['Content-Type' => 'application/problem+json'],
        );
    }
}
