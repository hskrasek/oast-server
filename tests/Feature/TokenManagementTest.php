<?php

declare(strict_types=1);

use App\Models\Installation;
use App\Models\PersonalAccessToken;

beforeEach(fn () => Installation::query()->findOrFail(1)->update(['bootstrapped_at' => now()]));

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
