<?php

declare(strict_types=1);

namespace App\Council\Exceptions;

use RuntimeException;

final class DesignerException extends RuntimeException
{
    /**
     * @param  array<int, string>  $errors
     */
    private function __construct(string $message, public readonly array $errors)
    {
        parent::__construct($message);
    }

    /**
     * @param  array<int, string>  $errors
     */
    public static function invalidOutput(array $errors): self
    {
        return new self('The designer produced a spec that does not parse', $errors);
    }
}
