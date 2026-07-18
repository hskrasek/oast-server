<?php

declare(strict_types=1);

use App\Models\Installation;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Event;
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

it('rehashes an outdated password hash on login', function (): void {
    // Any cost differing from the configured BCRYPT_ROUNDS (4 in phpunit.xml) needs rehash;
    // written via query builder because the hashed cast rejects off-configuration hashes.
    $stale = password_hash('correct horse battery staple', PASSWORD_BCRYPT, ['cost' => 5]);
    $user = User::factory()->create();
    User::query()->whereKey($user->id)->update(['password' => $stale]);

    $this->post('/login', ['email' => $user->email, 'password' => 'correct horse battery staple'])->assertRedirect('/');

    $fresh = $user->refresh()->password;
    expect($fresh)->not->toBe($stale)
        ->and(Hash::check('correct horse battery staple', $fresh))->toBeTrue();
});

it('fires the failed authentication event on a wrong password', function (): void {
    Event::fake([Failed::class]);
    User::factory()->create(['email' => 'owner@example.test', 'password' => 'correct horse battery staple']);

    $this->from('/login')->post('/login', ['email' => 'owner@example.test', 'password' => 'wrong']);

    Event::assertDispatched(Failed::class);
});

it('throttles the sixth login attempt for the same email and ip', function (): void {
    User::factory()->create(['email' => 'owner@example.test', 'password' => 'correct horse battery staple']);
    foreach (range(1, 5) as $i) {
        $this->from('/login')->post('/login', ['email' => 'owner@example.test', 'password' => 'wrong'])
            ->assertSessionHasErrors('email');
    }

    $this->from('/login')->post('/login', ['email' => 'owner@example.test', 'password' => 'wrong'])
        ->assertTooManyRequests();
});

it('serves verification and password confirmation views', function (): void {
    $user = User::factory()->unverified()->create();
    $this->actingAs($user)->get('/email/verify')->assertOk()->assertSee('Verify email');
    $this->actingAs($user)->get('/user/confirm-password')->assertOk()->assertSee('Confirm password');
});
