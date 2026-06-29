<?php

declare(strict_types=1);

namespace App\Council\Exceptions;

use RuntimeException;

final class JudgeException extends RuntimeException
{
    /**
     * @param  array<array-key, mixed>  $errors
     */
    private function __construct(string $message, public readonly array $errors)
    {
        parent::__construct($message);
    }

    /**
     * @param  array<array-key, mixed>  $errors
     */
    public static function invalidOutput(array $errors): self
    {
        return new self('The judge produced output that failed validation', $errors);
    }
}
