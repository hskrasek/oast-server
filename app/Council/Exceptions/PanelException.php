<?php

declare(strict_types=1);

namespace App\Council\Exceptions;

use App\Http\Problems\ProblemType;
use Crell\ApiProblem\ApiProblem;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Response;
use RuntimeException;

final class PanelException extends RuntimeException implements Responsable
{
    private function __construct(
        protected $message,
        protected $code = 0,
        $previous = null,
        public readonly array $errors = [],
    ) {
        parent::__construct($message, $code, $previous);
    }

    public static function quorumNotMet(array $failedModels, int $succeeded, int $required): self
    {
        return new self(
            sprintf('Quorum not met: %d panelist(s) succeeded, %d required. Failed: ', $succeeded, $required) . implode(', ', $failedModels),
            503,
            $failedModels,
        );
    }

    public function toResponse($request): Response
    {
        $problem = new ApiProblem(
            'Council quorum not met',
            ProblemType::QuorumNotMet->value,
        );
        $problem->setDetail($this->message);
        $problem['failed_models'] = $this->errors;

        return response(
            $problem->asJson(),
            $this->code,
            ['Content-Type' => 'application/problem+json'],
        );
    }
}
