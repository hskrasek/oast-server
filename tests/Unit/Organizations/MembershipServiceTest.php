<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Models\OrganizationMembership;
use App\Models\PersonalAccessToken;
use App\Organizations\MembershipService;
use Illuminate\Validation\ValidationException;

it('rejects removing or demoting the final owner', function (): void {
    [$owner, $organization, $membership] = memberFixture(role: 'owner');
    $service = app(MembershipService::class);
    expect(fn() => $service->remove($owner, $membership))->toThrow(ValidationException::class)
        ->and(fn() => $service->changeRole($owner, $membership, OrganizationRole::Member))->toThrow(ValidationException::class);
    expect($organization->memberships()->where('role', OrganizationRole::Owner)->count())->toBe(1);
});

it('revokes organization tokens when removing a member', function (): void {
    [$owner, $organization] = memberFixture(role: 'owner');
    $member = App\Models\User::factory()->create();
    $membership = OrganizationMembership::factory()->for($organization)->for($member)->create();
    $token = PersonalAccessToken::factory()->for($member, 'tokenable')->for($organization)->create();
    app(MembershipService::class)->remove($owner, $membership);
    expect($token->refresh()->revoked_at)->not->toBeNull()->and($membership->fresh())->toBeNull();
});

it('rejects transferring ownership to the current owner', function (): void {
    [$owner, $organization, $ownerMembership] = memberFixture(role: 'owner');
    expect(fn() => app(MembershipService::class)->transferOwnership($owner, $ownerMembership))
        ->toThrow(ValidationException::class);
    expect($organization->memberships()->where('role', OrganizationRole::Owner)->count())->toBe(1);
});

it('transfers ownership in one transaction', function (): void {
    [$owner, $organization, $ownerMembership] = memberFixture(role: 'owner');
    $member = App\Models\User::factory()->create();
    $target = OrganizationMembership::factory()->for($organization)->for($member)->create();
    app(MembershipService::class)->transferOwnership($owner, $target);
    expect($ownerMembership->refresh()->role)->toBe(OrganizationRole::Member)
        ->and($target->refresh()->role)->toBe(OrganizationRole::Owner);
});

it('demotes a non-final owner', function (): void {
    [$owner, $organization] = memberFixture(role: 'owner');
    $second = App\Models\User::factory()->create();
    $secondMembership = OrganizationMembership::factory()->for($organization)->for($second)->create(['role' => OrganizationRole::Owner]);
    app(MembershipService::class)->changeRole($owner, $secondMembership, OrganizationRole::Member);
    expect($secondMembership->refresh()->role)->toBe(OrganizationRole::Member)
        ->and($organization->memberships()->where('role', OrganizationRole::Owner)->count())->toBe(1);
});

it('lets a non-final owner leave', function (): void {
    [$owner, $organization] = memberFixture(role: 'owner');
    $second = App\Models\User::factory()->create();
    OrganizationMembership::factory()->for($organization)->for($second)->create(['role' => OrganizationRole::Owner]);
    app(MembershipService::class)->leave($owner);
    expect($organization->memberships()->where('user_id', $owner->id)->exists())->toBeFalse()
        ->and($organization->memberships()->where('role', OrganizationRole::Owner)->count())->toBe(1);
});

it('forbids a non-owner from removing a member', function (): void {
    [, $organization] = memberFixture(role: 'owner');
    $member = App\Models\User::factory()->create();
    $membership = OrganizationMembership::factory()->for($organization)->for($member)->create();
    expect(fn() => app(MembershipService::class)->remove($member, $membership))
        ->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($membership->fresh())->not->toBeNull();
});
