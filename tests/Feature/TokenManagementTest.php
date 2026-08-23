<?php

declare(strict_types=1);

use App\Models\Installation;
use App\Models\PersonalAccessToken;

beforeEach(fn() => Installation::query()->findOrFail(1)->update(['bootstrapped_at' => now()]));

it('renders plaintext once on a private no-store get and never renders its hash', function (): void {
    [$owner] = memberFixture(role: 'owner');
    $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()]);
    $post = $this->post(route('app.settings.tokens.store'), ['name' => 'CI', 'expires_at' => '']);
    $post->assertRedirect(route('app.settings.tokens.index'));
    $token = PersonalAccessToken::query()->sole();
    $get = $this->get(route('app.settings.tokens.index'));
    $get->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    preg_match('/\d+\|[A-Za-z0-9]+/', $get->getContent(), $matches);
    expect($matches)->toHaveCount(1)->and(hash('sha256', explode('|', $matches[0], 2)[1]))->toBe($token->token);
    $this->get(route('app.settings.tokens.index'))->assertDontSee($matches[0])->assertDontSee($token->token);
});

it('never stores the plaintext token unencrypted in the session', function (): void {
    [$owner] = memberFixture(role: 'owner');
    $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()]);
    $this->post(route('app.settings.tokens.store'), ['name' => 'CI']);
    $token = PersonalAccessToken::query()->sole();

    $sealed = session('oast.new_token');
    expect($sealed)->toBeString()->not->toMatch('/\d+\|[A-Za-z0-9]+/');
    $plain = Illuminate\Support\Facades\Crypt::decryptString($sealed);
    expect(hash('sha256', explode('|', $plain, 2)[1]))->toBe($token->token);
});

it('lists only tokens that can still authenticate', function (): void {
    [$owner, $organization] = memberFixture(role: 'owner');
    $live = $owner->tokens()->create(['organization_id' => $organization->id, 'name' => 'live', 'token' => hash('sha256', 'a'), 'abilities' => []]);
    $owner->tokens()->create(['organization_id' => $organization->id, 'name' => 'revoked', 'token' => hash('sha256', 'b'), 'abilities' => [], 'revoked_at' => now()]);
    $owner->tokens()->create(['organization_id' => $organization->id, 'name' => 'expired', 'token' => hash('sha256', 'c'), 'abilities' => [], 'expires_at' => now()->subMinute()]);

    $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('app.settings.tokens.index'))
        ->assertOk()->assertSee('live')->assertDontSee('revoked')->assertDontSee('expired')
        ->assertSee(route('app.settings.tokens.destroy', $live));
});

it('requires password confirmation for token creation and revocation', function (): void {
    [$owner] = memberFixture(role: 'owner');
    $this->actingAs($owner)->post(route('app.settings.tokens.store'), ['name' => 'CI'])->assertRedirect(route('password.confirm'));
});

it('uses fixed abilities and immutable organization scope', function (): void {
    [$owner, $organization] = memberFixture(role: 'owner');
    $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('app.settings.tokens.store'), ['name' => 'CI']);
    $token = PersonalAccessToken::query()->sole();
    expect($token->organization_id)->toBe($organization->id)
        ->and($token->abilities)->toBe(['review:create', 'review:read', 'review:follow']);
});

it('revokes a token via the destroy route', function (): void {
    [$owner] = memberFixture(role: 'owner');
    $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('app.settings.tokens.store'), ['name' => 'CI']);
    $token = PersonalAccessToken::query()->sole();
    $this->delete(route('app.settings.tokens.destroy', $token))->assertRedirect();
    expect($token->refresh()->revoked_at)->not->toBeNull();
});
