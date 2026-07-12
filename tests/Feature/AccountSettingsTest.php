<?php

declare(strict_types=1);

use App\Models\Installation;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

beforeEach(fn() => Installation::query()->findOrFail(1)->update(['bootstrapped_at' => now()]));

it('shows the account settings page for the authenticated user, even with no organization', function (): void {
    $user = App\Models\User::factory()->create();
    $this->actingAs($user)->get(route('app.settings.account.show'))->assertOk()->assertSee($user->email);
});

it('requires recent confirmation and updates password through the app route', function (): void {
    [$user] = memberFixture();
    $payload = [
        'current_password' => 'password',
        'password' => 'new correct horse battery staple',
        'password_confirmation' => 'new correct horse battery staple',
    ];
    $this->actingAs($user)->put(route('app.settings.account.password.update'), $payload)
        ->assertRedirect(route('password.confirm'));
    $this->withSession(['auth.password_confirmed_at' => time()])
        ->put(route('app.settings.account.password.update'), $payload)->assertRedirect();
    expect(Hash::check($payload['password'], $user->refresh()->password))->toBeTrue();
});

it('resets verification and notifies only when enforcement is enabled', function (bool $enabled, int $sent): void {
    Notification::fake();
    [$user] = memberFixture();
    config(['oast.enforce_email_verification' => $enabled]);
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
        ->patch(route('app.settings.account.update'), ['name' => 'Changed', 'email' => ' NEW@EXAMPLE.TEST '])->assertRedirect();
    expect($user->refresh()->email)->toBe('new@example.test')->and($user->email_verified_at)->toBeNull();
    Notification::assertSentToTimes($user, VerifyEmail::class, $sent);
})->with([[true, 1], [false, 0]]);
