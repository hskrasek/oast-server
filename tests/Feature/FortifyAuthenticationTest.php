<?php

declare(strict_types=1);

use App\Models\Installation;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

beforeEach(fn() => Installation::query()->findOrFail(1)->update(['bootstrapped_at' => now()]));

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

it('fires the Failed event and increments the login limiter on failure', function (): void {
    Illuminate\Support\Facades\Event::fake([Illuminate\Auth\Events\Failed::class]);
    User::factory()->create(['email' => 'owner@example.test', 'password' => 'correct horse battery staple']);

    $this->from('/login')->post('/login', ['email' => 'owner@example.test', 'password' => 'wrong'])
        ->assertSessionHasErrors('email');

    Illuminate\Support\Facades\Event::assertDispatched(Illuminate\Auth\Events\Failed::class);
    expect(app(Laravel\Fortify\LoginRateLimiter::class)->attempts(request()->merge(['email' => 'owner@example.test'])))->toBeGreaterThan(0);
});

it('rehashes an outdated password hash on successful login', function (): void {
    $user = User::factory()->create(['email' => 'owner@example.test']);
    // Seed a hash whose cost differs from the configured rounds; the hashed
    // cast refuses to store one directly, so write it past the cast.
    User::query()->whereKey($user->id)->update(['password' => password_hash('correct horse battery staple', PASSWORD_BCRYPT, ['cost' => 5])]);
    $stale = $user->refresh()->password;

    $this->post('/login', ['email' => 'owner@example.test', 'password' => 'correct horse battery staple']);

    $this->assertAuthenticatedAs($user);
    $fresh = $user->refresh()->password;
    expect($fresh)->not->toBe($stale)
        ->and(Hash::needsRehash($fresh))->toBeFalse()
        ->and(Hash::check('correct horse battery staple', $fresh))->toBeTrue();
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

it('gives an identical generic forgot-password response for known and unknown emails', function (): void {
    Notification::fake();
    User::factory()->create(['email' => 'owner@example.test']);

    $known = $this->post('/forgot-password', ['email' => 'owner@example.test']);
    $known->assertSessionHasNoErrors();
    $knownStatus = session('status');

    $unknown = $this->post('/forgot-password', ['email' => 'missing@example.test']);
    $unknown->assertSessionHasNoErrors();
    $unknownStatus = session('status');

    expect($knownStatus)->not->toBeNull()->and($unknownStatus)->toBe($knownStatus);
});

it('gives the same generic message as json for known and unknown forgot-password emails', function (): void {
    Notification::fake();
    User::factory()->create(['email' => 'owner@example.test']);

    $known = $this->postJson('/forgot-password', ['email' => 'owner@example.test'])->assertOk();
    $unknown = $this->postJson('/forgot-password', ['email' => 'missing@example.test'])->assertOk();

    expect($known->json('message'))->not->toBeNull()->and($unknown->json('message'))->toBe($known->json('message'));
});

it('throttles forgot-password requests after five attempts per email and ip', function (): void {
    Notification::fake();
    User::factory()->create(['email' => 'owner@example.test']);

    for ($i = 0; $i < 5; $i++) {
        $this->post('/forgot-password', ['email' => 'owner@example.test'])->assertSessionHasNoErrors();
    }
    $this->post('/forgot-password', ['email' => 'owner@example.test'])->assertTooManyRequests();
});
