<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(
            UncompromisedVerifier::class,
            fn(): UncompromisedVerifier => new class implements UncompromisedVerifier {
                public function verify($data): bool
                {
                    return true;
                }
            },
        );
    }

    protected function apiHost(): string
    {
        return config()->string('oast.api_domain');
    }
}
