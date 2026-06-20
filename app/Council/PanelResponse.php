<?php

declare(strict_types=1);

namespace App\Council;

final readonly class PanelResponse
{
    private function __construct(
        public string $model,
        public bool $ok,
        public ?string $content,
        public int $ms,
        public ?string $error,
    ) {}

    public static function success(string $model, ?string $content, int $ms): self
    {
        return new self($model, true, $content, $ms, null);
    }

    public static function failure(string $model, string $error): self
    {
        return new self($model, false, null, 0, $error);
    }
}
