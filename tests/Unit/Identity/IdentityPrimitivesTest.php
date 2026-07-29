<?php

declare(strict_types=1);

use App\Identity\CanonicalEmail;
use App\Identity\PasswordRules;
use Illuminate\Validation\Rules\Password;

it('canonicalizes email', function (): void {
    expect(CanonicalEmail::from('  OWNER@Example.TEST '))->toBe('owner@example.test');
});

it('separates base and confirmed password rules', function (): void {
    expect(PasswordRules::base())->toHaveCount(3)
        ->and(PasswordRules::base()[0])->toBe('required')
        ->and(PasswordRules::base()[1])->toBe('string')
        ->and(PasswordRules::base()[2])->toBeInstanceOf(Password::class)
        ->and(PasswordRules::confirmed())->toHaveCount(4)
        ->and(PasswordRules::confirmed()[3])->toBe('confirmed');
});
