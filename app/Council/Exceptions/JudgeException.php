<?php

declare(strict_types=1);

namespace App\Council\Exceptions;

use App\Http\Problems\ProblemType;
use Crell\ApiProblem\ApiProblem;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Response;
use Exception;

use function response;

final class JudgeException extends Exception implements Responsable
{
    private function __construct(
        protected $message,
        protected $code = 0,
        $previous = null,
        public readonly array $errors = [],
    ) {
        parent::__construct($message, $code, $previous);
    }

    public static function invalidOutput(array $errors): self
    {
        return new self('The judge produced output that failed validation', 502, null, $errors);
    }

    public function toResponse($request): Response
    {
        $problem = new ApiProblem(
            $this->message,
            ProblemType::InvalidJudgeOutput->value,
        );

        $problem['errors'] = $this->errors;

        return response(
            $problem->asJson(),
            $this->code,
            ['Content-Type' => 'application/problem+json'],
        );
    }
}
