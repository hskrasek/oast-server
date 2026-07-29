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
