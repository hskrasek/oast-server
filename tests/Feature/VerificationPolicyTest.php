<?php

declare(strict_types=1);

use App\Models\Installation;

it('allows unverified members when enforcement is disabled', function (): void {
    Installation::query()->findOrFail(1)->update(['bootstrapped_at' => now()]);
    [$user] = memberFixture();
    $user->forceFill(['email_verified_at' => null])->save();
    config(['oast.enforce_email_verification' => false]);
    $this->actingAs($user)->get('/app')->assertOk();
});

it('redirects unverified members when enforcement is enabled without affecting public pages', function (): void {
    Installation::query()->findOrFail(1)->update(['bootstrapped_at' => now()]);
    [$user] = memberFixture();
    $user->forceFill(['email_verified_at' => null])->save();
    config(['oast.enforce_email_verification' => true]);
    $this->actingAs($user)->get('/app')->assertRedirect(route('verification.notice'));
    $this->get('/')->assertOk();
});
