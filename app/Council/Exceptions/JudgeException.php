<?php

declare(strict_types=1);

namespace App\Council\Exceptions;

use App\Http\Problems\ProblemType;
use Crell\ApiProblem\ApiProblem;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Response;
use RuntimeException;
use Throwable;

use function response;

final class JudgeException extends RuntimeException implements Responsable
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
     * @param  array<array-key, mixed>  $errors
     */
    public static function invalidOutput(array $errors): self
    {
        return new self('The judge produced output that failed validation', 502, null, $errors);
    }

    public function toResponse($request): Response
    {
        $problem = new ApiProblem(
            $this->getMessage(),
            ProblemType::InvalidJudgeOutput->value,
        );
        $problem->setStatus($this->getCode());

        $problem['errors'] = $this->errors;

        return response(
            $problem->asJson(),
            $this->getCode(),
            ['Content-Type' => 'application/problem+json'],
        );
    }
}
