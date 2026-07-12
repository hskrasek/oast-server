<?php

declare(strict_types=1);

use App\Models\Installation;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

// Fortify routes are gated behind EnsureInstallationBootstrapped (Task 4);
// these flows only exist once the installation is bootstrapped.
beforeEach(fn() => Installation::query()->whereKey(1)->update(['bootstrapped_at' => now()]));

it('logs in by canonical email and logs out only by post', function (): void {
    $user = User::factory()->create(['email' => 'owner@example.test', 'password' => 'correct horse battery staple']);

    $this->post('/login', ['email' => ' OWNER@EXAMPLE.TEST ', 'password' => 'correct horse battery staple'])
        ->assertRedirect('/');
    $this->assertAuthenticatedAs($user);
    $this->get('/logout')->assertMethodNotAllowed();
    $this->post('/logout')->assertRedirect('/');
    $this->assertGuest();
});

it('uses the same login failure for unknown email and wrong password', function (): void {
    User::factory()->create(['email' => 'owner@example.test', 'password' => 'correct horse battery staple']);
    $unknown = $this->from('/login')->post('/login', ['email' => 'missing@example.test', 'password' => 'wrong']);
    $wrong = $this->from('/login')->post('/login', ['email' => 'owner@example.test', 'password' => 'wrong']);

    $unknown->assertSessionHasErrors(['email' => __('auth.failed')]);
    $wrong->assertSessionHasErrors(['email' => __('auth.failed')]);
});

it('canonicalizes password reset lookup and resets with confirmed rules', function (): void {
    Notification::fake();
    $user = User::factory()->create(['email' => 'owner@example.test']);
    $this->post('/forgot-password', ['email' => ' OWNER@EXAMPLE.TEST '])->assertSessionHas('status');
    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $mail) use ($user): bool {
        $response = $this->post('/reset-password', [
            'token' => $mail->token, 'email' => ' OWNER@EXAMPLE.TEST ',
            'password' => 'new correct horse battery staple',
            'password_confirmation' => 'new correct horse battery staple',
        ]);
        $response->assertSessionHas('status');
        return true;
    });
    expect(Hash::check('new correct horse battery staple', $user->refresh()->password))->toBeTrue();
});

it('serves verification and password confirmation views', function (): void {
    $user = User::factory()->unverified()->create();
    $this->actingAs($user)->get('/email/verify')->assertOk()->assertSee('Verify email');
    $this->actingAs($user)->get('/user/confirm-password')->assertOk()->assertSee('Confirm password');
});
