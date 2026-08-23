<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Models\Installation;
use App\Models\OrganizationInvitation;
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

it('completes member removal once password confirmed', function (): void {
    [$owner, $organization] = memberFixture(role: 'owner');
    $target = OrganizationMembership::factory()->for($organization)->for(User::factory())->create();
    $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])
        ->delete(route('app.settings.organization.members.destroy', $target))->assertRedirect();
    expect(OrganizationMembership::query()->find($target->id))->toBeNull();
});

it('completes ownership transfer once password confirmed', function (): void {
    [$owner, $organization] = memberFixture(role: 'owner');
    $target = OrganizationMembership::factory()->for($organization)->for(User::factory())->create();
    $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('app.settings.organization.ownership.transfer'), ['membership_id' => $target->id])->assertRedirect();
    expect($target->refresh()->role)->toBe(OrganizationRole::Owner);
});

it('returns the identical 404 for unknown and cross-organization membership ids on transfer', function (): void {
    [$owner] = memberFixture(role: 'owner');
    [, $otherOrganization] = memberFixture(role: 'owner');
    $foreign = OrganizationMembership::factory()->for($otherOrganization)->for(User::factory())->create();

    $confirmed = fn() => $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()]);
    $confirmed()->post(route('app.settings.organization.ownership.transfer'), ['membership_id' => 999999])->assertNotFound();
    $confirmed()->post(route('app.settings.organization.ownership.transfer'), ['membership_id' => $foreign->id])->assertNotFound();
});

it('lets owners revoke a pending invitation', function (): void {
    [$owner, $organization] = memberFixture(role: 'owner');
    $invitation = OrganizationInvitation::factory()->for($organization)->for($owner, 'inviter')->create();
    $this->actingAs($owner)->delete(route('app.settings.organization.invitations.destroy', $invitation))->assertRedirect();
    expect($invitation->refresh()->revoked_at)->not->toBeNull();
});

it('returns the identical 404 for unknown and cross-organization invitation ids', function (): void {
    [$owner] = memberFixture(role: 'owner');
    [$otherOwner, $otherOrganization] = memberFixture(role: 'owner');
    $cross = OrganizationInvitation::factory()->for($otherOrganization)->for($otherOwner, 'inviter')->create();
    $a = $this->actingAs($owner)->delete(route('app.settings.organization.invitations.destroy', $cross));
    $b = $this->actingAs($owner)->delete(route('app.settings.organization.invitations.destroy', 999999));
    $a->assertNotFound();
    $b->assertNotFound();
    expect($cross->refresh()->revoked_at)->toBeNull();
});

it('returns the identical 404 for unknown and cross-organization membership ids', function (): void {
    [$owner] = memberFixture(role: 'owner');
    [, $otherOrganization] = memberFixture(role: 'owner');
    $cross = OrganizationMembership::factory()->for($otherOrganization)->for(User::factory())->create();
    $a = $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])
        ->delete(route('app.settings.organization.members.destroy', $cross));
    $b = $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])
        ->delete(route('app.settings.organization.members.destroy', 999999));
    $a->assertNotFound();
    $b->assertNotFound();
    expect(OrganizationMembership::query()->find($cross->id))->not->toBeNull();
});

it('refuses to revoke an already-resolved invitation', function (): void {
    [$owner, $organization] = memberFixture(role: 'owner');
    $invitation = OrganizationInvitation::factory()->for($organization)->for($owner, 'inviter')->create(['revoked_at' => now()]);
    expect(fn() => app(App\Organizations\InvitationService::class)->revoke($invitation))
        ->toThrow(Illuminate\Validation\ValidationException::class);
});

it('serializes concurrent owner demotion and removal without lock-error credit', function (): void {
    // RefreshDatabase wraps every test in an open transaction on a single
    // process-wide cached in-memory PDO; purging it below to repoint at a
    // file would abandon (not close) that transaction on the shared PDO and
    // corrupt later tests. Roll it back first (see InvitationFlowTest's
    // file-backed race tests for the same fix).
    DB::connection()->rollBack();
    $database = tempnam(sys_get_temp_dir(), 'oast-owner-race-');
    expect($database)->toBeString();
    try {
        $migrate = new Process([PHP_BINARY, 'artisan', 'migrate:fresh', '--force'], base_path(), ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database]);
        expect($migrate->run())->toBe(0, $migrate->getErrorOutput());
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $database]);
        DB::purge('sqlite');
        [$firstOwner, $organization, $firstMembership] = memberFixture(role: 'owner');
        $secondOwner = User::factory()->create();
        $secondMembership = OrganizationMembership::factory()->for($organization)->for($secondOwner)
            ->create(['role' => OrganizationRole::Owner]);
        $demote = FileDatabaseProcess::start($database, ['demote', (string) $secondOwner->id, (string) $firstMembership->id]);
        $remove = FileDatabaseProcess::start($database, ['remove', (string) $firstOwner->id, (string) $secondMembership->id]);
        $demote->wait();
        $remove->wait();
        $codes = [$demote->getExitCode(), $remove->getExitCode()];
        sort($codes);
        expect($codes)->toBe([0, 42])->not->toContain(70);
        DB::purge('sqlite');
        expect(OrganizationMembership::query()->where('organization_id', $organization->id)
            ->where('role', OrganizationRole::Owner)->count())->toBe(1);
    } finally {
        // Deliberately no DB::purge() here: purging drops the cached
        // connection, so tearDown's RefreshDatabase rollback callback would
        // re-resolve a fresh connection against $database — which the
        // unlink() below has just removed — and crash with "does not
        // exist". Leaving the (already-fresh) connection cached lets
        // tearDown reuse its still-open handle instead.
        if (is_file($database)) {
            unlink($database);
        }
    }
});
