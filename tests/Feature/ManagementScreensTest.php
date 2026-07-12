<?php

declare(strict_types=1);

use App\Models\Installation;
use App\Models\OrganizationInvitation;
use App\Models\PersonalAccessToken;

beforeEach(fn() => Installation::query()->findOrFail(1)->update(['bootstrapped_at' => now()]));

it('renders exact settings forms without tenant or secret inputs', function (): void {
    [$owner] = memberFixture(role: 'owner');
    $this->actingAs($owner)->get(route('app.settings.account.show'))->assertOk()->assertSee('name="email"', false)->assertDontSee('organization_id');
    $this->get(route('app.settings.organization.show'))->assertOk()->assertSee('name="email"', false)->assertDontSee('token_hash');
    $this->get(route('app.settings.tokens.index'))->assertOk()->assertSee('name="name"', false)->assertDontSee('organization_id');
});

it('never leaks another organizations members invitations or tokens', function (): void {
    [$owner] = memberFixture(role: 'owner');
    [$other, $otherOrganization] = memberFixture(role: 'owner');
    OrganizationInvitation::factory()->for($otherOrganization)->for($other, 'inviter')->create(['email' => 'secret-other@example.test']);
    PersonalAccessToken::factory()->for($other, 'tokenable')->for($otherOrganization)->create(['name' => 'other-secret-token']);
    $this->actingAs($owner)->get(route('app.settings.organization.show'))->assertDontSee('secret-other@example.test');
    $this->get(route('app.settings.tokens.index'))->assertDontSee('other-secret-token');
});

it('keeps public publication pages public before and after bootstrap', function (): void {
    $this->get('/')->assertOk();
    $this->get('/why')->assertOk();
    $this->get('/reviews')->assertOk();
});

it('has no unrestricted registration link and uses post logout', function (): void {
    $this->get('/login')->assertOk()->assertDontSee('Create account')->assertDontSee('href="/logout"', false);
});
