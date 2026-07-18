<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Models\Installation;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\FileDatabaseProcess;

beforeEach(fn() => Installation::query()->findOrFail(1)->update(['bootstrapped_at' => now()]));

it('lets owners rename and invite while members receive 403', function (): void {
    [$owner, $organization] = memberFixture(role: 'owner');
    $this->actingAs($owner)->patch(route('app.settings.organization.update'), ['name' => 'Renamed'])->assertRedirect();
    expect($organization->refresh()->name)->toBe('Renamed');
    $this->actingAs($owner)->post(route('app.settings.organization.invitations.store'), ['email' => 'new@example.test'])->assertRedirect();
    $member = User::factory()->create();
    OrganizationMembership::factory()->for($organization)->for($member)->create();
    $this->actingAs($member)->patch(route('app.settings.organization.update'), ['name' => 'No'])->assertForbidden();
});

it('requires password confirmation for removal and transfer', function (): void {
    [$owner, $organization] = memberFixture(role: 'owner');
    $target = OrganizationMembership::factory()->for($organization)->for(User::factory())->create();
    $this->actingAs($owner)->delete(route('app.settings.organization.members.destroy', $target))->assertRedirect(route('password.confirm'));
    $this->actingAs($owner)->post(route('app.settings.organization.ownership.transfer'), ['membership_id' => $target->id])->assertRedirect(route('password.confirm'));
});

it('renders the settings page for an owner', function (): void {
    [$owner, $organization] = memberFixture(role: 'owner');
    OrganizationMembership::factory()->for($organization)->for(User::factory())->create();
    App\Models\OrganizationInvitation::factory()->for($organization)->create();
    $this->actingAs($owner)->get(route('app.settings.organization.show'))
        ->assertOk()->assertSee('Organization settings');
});

it('removes a member once the password is confirmed', function (): void {
    [$owner, $organization] = memberFixture(role: 'owner');
    $target = OrganizationMembership::factory()->for($organization)->for(User::factory())->create();
    $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])
        ->delete(route('app.settings.organization.members.destroy', $target))->assertRedirect();
    expect($target->fresh())->toBeNull();
});

it('transfers ownership once the password is confirmed', function (): void {
    [$owner, $organization, $ownerMembership] = memberFixture(role: 'owner');
    $target = OrganizationMembership::factory()->for($organization)->for(User::factory())->create();
    $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('app.settings.organization.ownership.transfer'), ['membership_id' => $target->id])->assertRedirect();
    expect($target->refresh()->role)->toBe(OrganizationRole::Owner)
        ->and($ownerMembership->refresh()->role)->toBe(OrganizationRole::Member);
});

it('revokes an invitation', function (): void {
    [$owner, $organization] = memberFixture(role: 'owner');
    $invitation = App\Models\OrganizationInvitation::factory()->for($organization)->create();
    $this->actingAs($owner)->delete(route('app.settings.organization.invitations.destroy', $invitation))->assertRedirect();
    expect($invitation->refresh()->revoked_at)->not->toBeNull();
});

/**
 * Migrate a fresh file-backed SQLite database and seed an organization with two
 * owners, returning [organizationId, firstOwnerId, firstMembershipId,
 * secondOwnerId, secondMembershipId]. Data is written through a dedicated
 * `sqlite_race` connection so the default (:memory:) connection RefreshDatabase
 * manages is never repointed out from under the test.
 *
 * @return array{int, int, int, int, int}
 */
function seedRaceOwners(string $database): array
{
    $migrate = new Process([PHP_BINARY, 'artisan', 'migrate:fresh', '--force'], base_path(), ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database]);
    expect($migrate->run())->toBe(0, $migrate->getErrorOutput());

    config(['database.connections.sqlite_race' => ['driver' => 'sqlite', 'database' => $database, 'prefix' => '', 'foreign_key_constraints' => true]]);
    $conn = DB::connection('sqlite_race');
    $now = now();
    $orgId = $conn->table('organizations')->insertGetId(['name' => 'Race Org', 'created_at' => $now, 'updated_at' => $now]);
    $firstOwnerId = $conn->table('users')->insertGetId(['name' => 'First Owner', 'email' => 'first@example.test', 'password' => bcrypt('secret'), 'created_at' => $now, 'updated_at' => $now]);
    $firstMembershipId = $conn->table('organization_memberships')->insertGetId(['organization_id' => $orgId, 'user_id' => $firstOwnerId, 'role' => 'owner', 'created_at' => $now, 'updated_at' => $now]);
    $secondOwnerId = $conn->table('users')->insertGetId(['name' => 'Second Owner', 'email' => 'second@example.test', 'password' => bcrypt('secret'), 'created_at' => $now, 'updated_at' => $now]);
    $secondMembershipId = $conn->table('organization_memberships')->insertGetId(['organization_id' => $orgId, 'user_id' => $secondOwnerId, 'role' => 'owner', 'created_at' => $now, 'updated_at' => $now]);

    return [$orgId, $firstOwnerId, $firstMembershipId, $secondOwnerId, $secondMembershipId];
}

it('serializes concurrent owner demotion and removal without lock-error credit', function (): void {
    $database = tempnam(sys_get_temp_dir(), 'oast-owner-race-');
    expect($database)->toBeString();
    try {
        [$orgId, $firstOwnerId, $firstMembershipId, $secondOwnerId, $secondMembershipId] = seedRaceOwners($database);
        $demote = FileDatabaseProcess::start($database, ['demote', (string) $secondOwnerId, (string) $firstMembershipId]);
        $remove = FileDatabaseProcess::start($database, ['remove', (string) $firstOwnerId, (string) $secondMembershipId]);
        $demote->wait();
        $remove->wait();
        $codes = [$demote->getExitCode(), $remove->getExitCode()];
        sort($codes);
        expect($codes)->toBe([0, 42])->not->toContain(70);
        DB::purge('sqlite_race');
        $conn = DB::connection('sqlite_race');
        expect($conn->table('organization_memberships')->where('organization_id', $orgId)
            ->where('role', OrganizationRole::Owner->value)->count())->toBe(1);
    } finally {
        DB::purge('sqlite_race');
        if (is_file($database)) {
            unlink($database);
        }
    }
});
