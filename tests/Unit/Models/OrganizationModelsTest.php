<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Models\Installation;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\PersonalAccessToken;
use App\Models\Review;

it('exposes the shared owner fixture and typed relationships', function (): void {
    [$user, $organization, $membership] = memberFixture(role: 'owner');

    expect($membership->role)->toBe(OrganizationRole::Owner)
        ->and($user->memberships()->sole()->is($membership))->toBeTrue()
        ->and($user->organizations()->sole()->is($organization))->toBeTrue()
        ->and($organization->members()->sole()->is($user))->toBeTrue();
});

it('keeps personal access token organization immutable', function (): void {
    $token = PersonalAccessToken::factory()->create();
    $token->organization_id++;

    expect(fn() => $token->save())->toThrow(LogicException::class, 'Token organization is immutable.');
});

it('enforces one membership per user', function (): void {
    [$user] = memberFixture();

    expect(fn() => OrganizationMembership::factory()->for($user)->create())
        ->toThrow(Illuminate\Database\QueryException::class);
});

it('exposes organization has-many relations to memberships, invitations, and reviews', function (): void {
    [, $organization, $membership] = memberFixture();
    $invitation = OrganizationInvitation::factory()->for($organization)->create();
    $review = Review::factory()->for($organization)->create();

    expect($organization->memberships()->sole()->is($membership))->toBeTrue()
        ->and($organization->invitations()->sole()->is($invitation))->toBeTrue()
        ->and($organization->reviews()->sole()->is($review))->toBeTrue();
});

it('links a personal access token to its organization', function (): void {
    $organization = Organization::factory()->create();
    $token = PersonalAccessToken::factory()->for($organization)->create();

    expect($token->organization()->is($organization))->toBeTrue();
});

it('links a review to its organization and creator', function (): void {
    [$creator, $organization] = memberFixture();
    $review = Review::factory()->for($organization)->create(['created_by_user_id' => $creator->id]);

    expect($review->organization()->is($organization))->toBeTrue()
        ->and($review->creator()->is($creator))->toBeTrue();
});

it('computes invitation availability from accepted, revoked, and expiry state', function (): void {
    $available = OrganizationInvitation::factory()->create();
    $accepted = OrganizationInvitation::factory()->create(['accepted_at' => now()]);
    $revoked = OrganizationInvitation::factory()->create(['revoked_at' => now()]);
    $expired = OrganizationInvitation::factory()->create(['expires_at' => now()->subDay()]);

    expect($available->available())->toBeTrue()
        ->and($accepted->available())->toBeFalse()
        ->and($revoked->available())->toBeFalse()
        ->and($expired->available())->toBeFalse();
});

it('links an invitation to its organization and inviter', function (): void {
    [$inviter, $organization] = memberFixture();
    $invitation = OrganizationInvitation::factory()->for($organization)->create(['invited_by_user_id' => $inviter->id]);

    expect($invitation->organization()->is($organization))->toBeTrue()
        ->and($invitation->inviter()->is($inviter))->toBeTrue();
});

it('tracks the singleton installation row and its default organization', function (): void {
    $organization = Organization::factory()->create();

    $installation = Installation::query()->firstOrFail();
    $installation->update(['bootstrapped_at' => now(), 'default_organization_id' => $organization->id]);
    $installation->refresh();

    expect($installation->bootstrapped_at)->toBeInstanceOf(DateTimeInterface::class)
        ->and($installation->defaultOrganization()->is($organization))->toBeTrue();
});
