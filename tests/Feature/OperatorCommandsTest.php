<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('resets a canonical user password with explicit matching confirmation', function (): void {
    $user = User::factory()->create(['email' => 'owner@example.test']);
    $this->artisan('oast:user:password', ['email' => ' OWNER@EXAMPLE.TEST '])
        ->expectsQuestion('New password', 'correct horse battery staple')
        ->expectsQuestion('Confirm password', 'correct horse battery staple')
        ->assertSuccessful();
    expect(Hash::check('correct horse battery staple', $user->refresh()->password))->toBeTrue();
});

it('rejects mismatched command passwords before base validation', function (): void {
    User::factory()->create(['email' => 'owner@example.test']);
    $this->artisan('oast:user:password', ['email' => 'owner@example.test'])
        ->expectsQuestion('New password', 'correct horse battery staple')
        ->expectsQuestion('Confirm password', 'different password')
        ->expectsOutputToContain('Passwords do not match.')->assertFailed();
});

it('reports unknown users generically when resetting a password', function (): void {
    $this->artisan('oast:user:password', ['email' => 'missing@example.test'])
        ->expectsOutput('User not found.')->assertFailed();
});

it('rejects a matching password that fails base validation', function (): void {
    User::factory()->create(['email' => 'owner@example.test']);
    $this->artisan('oast:user:password', ['email' => 'owner@example.test'])
        ->expectsQuestion('New password', 'short')
        ->expectsQuestion('Confirm password', 'short')
        ->assertFailed();
});

it('verifies canonical email idempotently and reports unknown users generically', function (): void {
    $user = User::factory()->unverified()->create(['email' => 'owner@example.test']);
    $this->artisan('oast:user:verify', ['email' => ' OWNER@EXAMPLE.TEST '])->assertSuccessful();
    expect($user->refresh()->hasVerifiedEmail())->toBeTrue();
    $this->artisan('oast:user:verify', ['email' => 'missing@example.test'])->expectsOutput('User not found.')->assertFailed();
});
