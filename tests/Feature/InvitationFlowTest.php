<?php

declare(strict_types=1);

use App\Models\Installation;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Organizations\InvitationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Process\Process;
use Tests\Support\FileDatabaseProcess;

beforeEach(fn() => Installation::query()->findOrFail(1)->update(['bootstrapped_at' => now()]));

it('redirects invitation continuations to setup before bootstrap', function (): void {
    Installation::query()->findOrFail(1)->update(['bootstrapped_at' => null]);
    $token = str_repeat('a', 64);
    $this->get(route('invitations.show', $token))->assertRedirect(route('setup.show'));
    $this->post(route('invitations.start-registration', $token))->assertRedirect(route('setup.show'));
    $this->post(route('invitations.start-login', $token))->assertRedirect(route('setup.show'));
    $this->post(route('invitations.accept', $token))->assertRedirect(route('setup.show'));
});

it('creates a canonical hashed invitation and keeps a copyable URL when mail fails', function (): void {
    Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('mail down'));
    [$owner, $organization] = memberFixture(role: 'owner');
    $result = app(InvitationService::class)->createOrReplace($organization, $owner, ' NEW@EXAMPLE.TEST ');
    $plain = basename($result['url']);
    expect($plain)->toMatch('/^[a-f0-9]{64}$/')
        ->and($result['invitation']->email)->toBe('new@example.test')
        ->and($result['invitation']->token_hash)->toBe(hash('sha256', $plain))
        ->and($result['invitation']->getRawOriginal('token_hash'))->not->toContain($plain);
});

it('preserves an invitation through matching-email login and acceptance', function (): void {
    [$owner, $organization] = memberFixture(role: 'owner');
    $user = User::factory()->create(['email' => 'new@example.test']);
    $plain = basename(app(InvitationService::class)->createOrReplace($organization, $owner, $user->email)['url']);
    $this->post(route('invitations.start-login', $plain))->assertRedirect(route('login'));
    expect(session('oast.invitation.token'))->toBe($plain)
        ->and(session('url.intended'))->toBe(route('invitations.show', $plain));
    $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('invitations.show', $plain));
    $this->post(route('invitations.accept', $plain))->assertRedirect(route('app.home'));
    expect($user->memberships()->where('organization_id', $organization->id)->exists())->toBeTrue();
});

it('returns to the invitation after login but rejects a mismatched email', function (): void {
    [$owner, $organization] = memberFixture(role: 'owner');
    $other = User::factory()->create(['email' => 'other@example.test']);
    $plain = basename(app(InvitationService::class)->createOrReplace($organization, $owner, 'new@example.test')['url']);
    $this->post(route('invitations.start-login', $plain));
    $this->post(route('login'), ['email' => $other->email, 'password' => 'password'])
        ->assertRedirect(route('invitations.show', $plain));
    $this->post(route('invitations.accept', $plain))->assertSessionHasErrors('invitation');
    expect($other->memberships()->count())->toBe(0);
});

it('posts an invitation token into encrypted session before register', function (): void {
    [$owner, $organization] = memberFixture(role: 'owner');
    $result = app(InvitationService::class)->createOrReplace($organization, $owner, 'new@example.test');
    $plain = basename($result['url']);
    // Establish a previous URL via a real GET; Laravel only records _previous.url
    // on GET requests, so a lone POST would leave it null (nothing to assert on).
    $this->get(route('invitations.show', $plain))->assertOk();
    $this->post(route('invitations.start-registration', $plain))->assertRedirect(route('register'));
    expect(session('oast.invitation.token'))->toBe($plain);
    expect(session()->get('_previous.url'))->not->toContain($plain . '?');
});

it('registers and consumes an invitation atomically', function (): void {
    [$owner, $organization] = memberFixture(role: 'owner');
    $plain = basename(app(InvitationService::class)->createOrReplace($organization, $owner, 'new@example.test')['url']);
    $this->post(route('invitations.start-registration', $plain));
    $this->post('/register', [
        'name' => 'New Member', 'email' => ' NEW@EXAMPLE.TEST ',
        'password' => 'correct horse battery staple', 'password_confirmation' => 'correct horse battery staple',
        'invitation_token' => $plain,
    ])->assertRedirect('/');
    $user = User::query()->where('email', 'new@example.test')->sole();
    expect(OrganizationMembership::query()->where('user_id', $user->id)->value('organization_id'))->toBe($organization->id)
        ->and(OrganizationInvitation::query()->where('email', 'new@example.test')->value('accepted_at'))->not->toBeNull();
});

it('uses one unavailable response for unknown expired revoked and consumed tokens', function (string $state): void {
    [$owner, $organization] = memberFixture(role: 'owner');
    $plain = basename(app(InvitationService::class)->createOrReplace($organization, $owner, 'new@example.test')['url']);
    $invitation = OrganizationInvitation::query()->where('email', 'new@example.test')->sole();
    if ($state === 'expired') {
        $invitation->update(['expires_at' => now()->subMinute()]);
    }
    if ($state === 'revoked') {
        $invitation->update(['revoked_at' => now()]);
    }
    if ($state === 'consumed') {
        $invitation->update(['accepted_at' => now()]);
    }
    if ($state === 'unknown') {
        $plain = str_repeat('a', 64);
    }
    $this->get(route('invitations.show', $plain))->assertOk()->assertSee('This invitation is not available')
        ->assertHeader('Referrer-Policy', 'no-referrer');
})->with(['unknown', 'expired', 'revoked', 'consumed']);

it('rejects mismatched existing users and members without partial writes', function (): void {
    [$owner, $organization] = memberFixture(role: 'owner');
    $plain = basename(app(InvitationService::class)->createOrReplace($organization, $owner, 'new@example.test')['url']);
    $other = User::factory()->create(['email' => 'other@example.test']);
    $this->actingAs($other)->post(route('invitations.accept', $plain))->assertSessionHasErrors('invitation');
    expect($other->memberships()->count())->toBe(0)->and(OrganizationInvitation::query()->sole()->accepted_at)->toBeNull();
});

it('shows the registration form with the invited token from session', function (): void {
    $this->withSession(['oast.invitation.token' => str_repeat('a', 64)])
        ->get('/register')->assertOk()->assertSee('Accept invitation');
});

it('rejects invited registration when the email does not match the invitation', function (): void {
    [$owner, $organization] = memberFixture(role: 'owner');
    $plain = basename(app(InvitationService::class)->createOrReplace($organization, $owner, 'new@example.test')['url']);
    $this->post(route('invitations.start-registration', $plain));
    $this->from('/register')->post('/register', [
        'name' => 'Mismatch', 'email' => 'different@example.test',
        'password' => 'correct horse battery staple', 'password_confirmation' => 'correct horse battery staple',
        'invitation_token' => $plain,
    ])->assertSessionHasErrors('invitation');
    expect(User::query()->where('email', 'different@example.test')->exists())->toBeFalse()
        ->and(OrganizationInvitation::query()->sole()->accepted_at)->toBeNull();
});

it('redirects login and registration starts back to the invitation when the token is unavailable', function (): void {
    $token = str_repeat('a', 64);
    $this->post(route('invitations.start-login', $token))->assertRedirect(route('invitations.show', $token));
    $this->post(route('invitations.start-registration', $token))->assertRedirect(route('invitations.show', $token));
    expect(session('oast.invitation.token'))->toBeNull();
});

it('rejects an authenticated acceptance of an unknown token', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)->post(route('invitations.accept', str_repeat('a', 64)))->assertSessionHasErrors('invitation');
    expect($user->memberships()->count())->toBe(0);
});

it('rejects registration when the invitation token is unknown', function (): void {
    $this->from('/register')->post('/register', [
        'name' => 'Nobody', 'email' => 'nobody@example.test',
        'password' => 'correct horse battery staple', 'password_confirmation' => 'correct horse battery staple',
        'invitation_token' => str_repeat('a', 64),
    ])->assertSessionHasErrors('invitation');
    expect(User::query()->where('email', 'nobody@example.test')->exists())->toBeFalse();
});

it('revokes an available invitation and rejects revoking an unavailable one', function (): void {
    [$owner, $organization] = memberFixture(role: 'owner');
    app(InvitationService::class)->createOrReplace($organization, $owner, 'new@example.test');
    $invitation = OrganizationInvitation::query()->sole();
    app(InvitationService::class)->revoke($invitation);
    expect($invitation->refresh()->revoked_at)->not->toBeNull();
    expect(fn() => app(InvitationService::class)->revoke($invitation))
        ->toThrow(Illuminate\Validation\ValidationException::class);
});

/**
 * Migrate a fresh file-backed SQLite database and seed an available invitation
 * for `new@example.test`, returning [invitationId, invitedUserId]. Data is
 * written through a dedicated `sqlite_race` connection so the default (:memory:)
 * connection RefreshDatabase manages is never repointed out from under the test.
 *
 * @return array{int, int}
 */
function seedRaceInvitation(string $database): array
{
    $migrate = new Process([PHP_BINARY, 'artisan', 'migrate:fresh', '--force'], base_path(), ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database]);
    expect($migrate->run())->toBe(0, $migrate->getErrorOutput());

    config(['database.connections.sqlite_race' => ['driver' => 'sqlite', 'database' => $database, 'prefix' => '', 'foreign_key_constraints' => true]]);
    $conn = DB::connection('sqlite_race');
    $now = now();
    $orgId = $conn->table('organizations')->insertGetId(['name' => 'Race Org', 'created_at' => $now, 'updated_at' => $now]);
    $ownerId = $conn->table('users')->insertGetId(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => bcrypt('secret'), 'created_at' => $now, 'updated_at' => $now]);
    $conn->table('organization_memberships')->insert(['organization_id' => $orgId, 'user_id' => $ownerId, 'role' => 'owner', 'created_at' => $now, 'updated_at' => $now]);
    $userId = $conn->table('users')->insertGetId(['name' => 'New Member', 'email' => 'new@example.test', 'password' => bcrypt('secret'), 'created_at' => $now, 'updated_at' => $now]);
    $invitationId = $conn->table('organization_invitations')->insertGetId([
        'organization_id' => $orgId, 'invited_by_user_id' => $ownerId, 'email' => 'new@example.test', 'role' => 'member',
        'token_hash' => hash('sha256', bin2hex(random_bytes(32))), 'expires_at' => $now->copy()->addDay(),
        'created_at' => $now, 'updated_at' => $now,
    ]);

    return [$invitationId, $userId];
}

it('allows exactly one concurrent invitation acceptance', function (): void {
    $database = tempnam(sys_get_temp_dir(), 'oast-invite-');
    expect($database)->toBeString();
    try {
        [$invitationId, $userId] = seedRaceInvitation($database);
        $a = FileDatabaseProcess::start($database, ['accept', (string) $invitationId, (string) $userId]);
        $b = FileDatabaseProcess::start($database, ['accept', (string) $invitationId, (string) $userId]);
        $a->wait();
        $b->wait();
        $codes = [$a->getExitCode(), $b->getExitCode()];
        sort($codes);
        expect($codes)->toBe([0, 42])->not->toContain(70);
        DB::purge('sqlite_race');
        $conn = DB::connection('sqlite_race');
        expect($conn->table('organization_memberships')->where('user_id', $userId)->count())->toBe(1)
            ->and($conn->table('organization_invitations')->where('id', $invitationId)->value('accepted_at'))->not->toBeNull();
    } finally {
        DB::purge('sqlite_race');
        if (is_file($database)) {
            unlink($database);
        }
    }
});

it('serializes concurrent invitation acceptance and revocation', function (): void {
    $database = tempnam(sys_get_temp_dir(), 'oast-invite-revoke-');
    expect($database)->toBeString();
    try {
        [$invitationId, $userId] = seedRaceInvitation($database);
        $accept = FileDatabaseProcess::start($database, ['accept', (string) $invitationId, (string) $userId]);
        $revoke = FileDatabaseProcess::start($database, ['revoke', (string) $invitationId]);
        $accept->wait();
        $revoke->wait();
        $codes = [$accept->getExitCode(), $revoke->getExitCode()];
        sort($codes);
        expect($codes)->toBe([0, 42])->not->toContain(70);
        DB::purge('sqlite_race');
        $conn = DB::connection('sqlite_race');
        $row = $conn->table('organization_invitations')->where('id', $invitationId)->sole();
        expect(($row->accepted_at !== null) xor ($row->revoked_at !== null))->toBeTrue()
            ->and($conn->table('organization_memberships')->where('user_id', $userId)->exists())->toBe($row->accepted_at !== null);
    } finally {
        DB::purge('sqlite_race');
        if (is_file($database)) {
            unlink($database);
        }
    }
});
