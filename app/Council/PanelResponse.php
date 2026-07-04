<?php

declare(strict_types=1);

namespace App\Council;

final readonly class PanelResponse
{
    /**
     * @param  array<string, int>|null  $usage
     */
    private function __construct(
        public string $model,
        public bool $ok,
        public ?string $content,
        public int $ms,
        public ?string $error,
        public ?array $usage,
    ) {}

    /**
     * @param  array<string, int>|null  $usage
     */
    public static function success(string $model, ?string $content, int $ms, ?array $usage = null): self
    {
        return new self($model, true, $content, $ms, null, $usage);
    }

    public static function failure(string $model, string $error): self
    {
        return new self($model, false, null, 0, $error, null);
    }
}
