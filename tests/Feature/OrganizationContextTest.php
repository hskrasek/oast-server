<?php

declare(strict_types=1);

use App\Models\Installation;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Organizations\OrganizationContext;

// /app is gated behind EnsureInstallationBootstrapped (Task 4); these flows
// only exist once the installation is bootstrapped.
beforeEach(fn() => Installation::query()->whereKey(1)->update(['bootstrapped_at' => now()]));

it('resolves the sole browser membership', function (): void {
    [$user, $organization] = memberFixture();
    $this->actingAs($user)->get('/app')->assertOk();
    expect(app(OrganizationContext::class)->organization()->is($organization))->toBeTrue();
});

it('resolves a token organization only through a matching membership', function (): void {
    [$user, $organization] = memberFixture();
    $token = PersonalAccessToken::factory()->for($user, 'tokenable')->for($organization)->create();
    $user->withAccessToken($token);
    request()->setUserResolver(fn() => $user);
    app()->forgetScopedInstances();
    expect(app(OrganizationContext::class)->organization()->is($organization))->toBeTrue();
});

it('renders a holding page for a zero-membership user', function (): void {
    $this->actingAs(User::factory()->create())->get('/app')->assertOk()
        ->assertSee('You are not a member of any organization');
});
