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

it('rejects a consumed invitation before inserting the invited user', function (): void {
    [$owner, $organization] = memberFixture(role: 'owner');
    $plain = basename(app(InvitationService::class)->createOrReplace($organization, $owner, 'new@example.test')['url']);
    $service = app(App\Organizations\InvitationAcceptanceService::class);
    $invitation = $service->find($plain);
    assert($invitation instanceof OrganizationInvitation);
    $data = new App\Identity\RegistrationData('New Member', 'new@example.test', 'correct horse battery staple');
    $service->registerAndAccept($invitation, $data);
    $registered = User::query()->count();

    // A racing second registration must get the unavailable-invitation error,
    // not a users.email unique-constraint exception from inserting first.
    expect(fn() => $service->registerAndAccept($invitation, $data))
        ->toThrow(Illuminate\Validation\ValidationException::class)
        ->and(User::query()->count())->toBe($registered);
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
    $this->post(route('invitations.start-registration', $plain))->assertRedirect(route('register'));
    expect(session('oast.invitation.token'))->toBe($plain);
    // '_previous.url' is only ever populated by a prior GET (StartSession
    // only stores it for GET requests); this POST-only flow leaves it unset.
    // Cast to string so the guard (the token must never leak into it) still
    // holds without Pest's toContain() rejecting a null actual.
    expect((string) session()->get('_previous.url'))->not->toContain($plain . '?');
});

it('registers and consumes an invitation atomically', function (): void {
    [$owner, $organization] = memberFixture(role: 'owner');
    $plain = basename(app(InvitationService::class)->createOrReplace($organization, $owner, 'new@example.test')['url']);
    $this->post(route('invitations.start-registration', $plain));
    $this->post('/register', [
        'name' => 'New Member', 'email' => ' NEW@EXAMPLE.TEST ',
        'password' => 'correct horse battery staple', 'password_confirmation' => 'correct horse battery staple',
        'invitation_token' => $plain,
    ])->assertRedirect('/app/reviews');
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

it('redirects start-login and start-registration to the unavailable view for a bad token', function (): void {
    $token = str_repeat('a', 64);
    $this->post(route('invitations.start-login', $token))->assertRedirect(route('invitations.show', $token));
    $this->post(route('invitations.start-registration', $token))->assertRedirect(route('invitations.show', $token));
});

it('rejects accepting an unknown invitation token', function (): void {
    [$owner] = memberFixture(role: 'owner');
    $this->actingAs($owner)->post(route('invitations.accept', str_repeat('a', 64)))->assertSessionHasErrors('invitation');
});

it('rejects registration when the invitation token is unavailable', function (): void {
    $this->post('/register', [
        'name' => 'New Member', 'email' => 'new@example.test',
        'password' => 'correct horse battery staple', 'password_confirmation' => 'correct horse battery staple',
        'invitation_token' => str_repeat('a', 64),
    ])->assertSessionHasErrors('invitation');
    expect(User::query()->where('email', 'new@example.test')->exists())->toBeFalse();
});

it('rejects registration whose email does not match the invitation', function (): void {
    [$owner, $organization] = memberFixture(role: 'owner');
    $plain = basename(app(InvitationService::class)->createOrReplace($organization, $owner, 'invited@example.test')['url']);
    $this->post('/register', [
        'name' => 'New Member', 'email' => 'someone-else@example.test',
        'password' => 'correct horse battery staple', 'password_confirmation' => 'correct horse battery staple',
        'invitation_token' => $plain,
    ])->assertSessionHasErrors('invitation');
    expect(User::query()->where('email', 'someone-else@example.test')->exists())->toBeFalse();
});

it('rejects mismatched existing users and members without partial writes', function (): void {
    [$owner, $organization] = memberFixture(role: 'owner');
    $plain = basename(app(InvitationService::class)->createOrReplace($organization, $owner, 'new@example.test')['url']);
    $other = User::factory()->create(['email' => 'other@example.test']);
    $this->actingAs($other)->post(route('invitations.accept', $plain))->assertSessionHasErrors('invitation');
    expect($other->memberships()->count())->toBe(0)->and(OrganizationInvitation::query()->sole()->accepted_at)->toBeNull();
});

it('allows exactly one concurrent invitation acceptance', function (): void {
    // RefreshDatabase wraps every test in an open transaction on a single
    // process-wide cached in-memory PDO; purging it below to repoint at a
    // file would abandon (not close) that transaction on the shared PDO and
    // corrupt later tests. Roll it back first (see SetupTest's file-backed
    // race test for the same fix).
    DB::connection()->rollBack();
    $database = tempnam(sys_get_temp_dir(), 'oast-invite-');
    expect($database)->toBeString();
    try {
        $migrate = new Process([PHP_BINARY, 'artisan', 'migrate:fresh', '--force'], base_path(), ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database]);
        expect($migrate->run())->toBe(0, $migrate->getErrorOutput());
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $database]);
        DB::purge('sqlite');
        [$owner, $organization] = memberFixture(role: 'owner');
        $user = User::factory()->create(['email' => 'new@example.test']);
        $invitation = OrganizationInvitation::factory()->for($organization)->for($owner, 'inviter')->create(['email' => $user->email]);
        $a = FileDatabaseProcess::start($database, ['accept', (string) $invitation->id, (string) $user->id]);
        $b = FileDatabaseProcess::start($database, ['accept', (string) $invitation->id, (string) $user->id]);
        $a->wait();
        $b->wait();
        $codes = [$a->getExitCode(), $b->getExitCode()];
        sort($codes);
        expect($codes)->toBe([0, 42])->not->toContain(70);
        DB::purge('sqlite');
        expect(OrganizationMembership::query()->where('user_id', $user->id)->count())->toBe(1)
            ->and(OrganizationInvitation::query()->findOrFail($invitation->id)->accepted_at)->not->toBeNull();
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

it('serializes concurrent invitation acceptance and revocation', function (): void {
    // Same shared-PDO hazard as the acceptance race above — roll back the
    // RefreshDatabase transaction before repointing the connection at a file.
    DB::connection()->rollBack();
    $database = tempnam(sys_get_temp_dir(), 'oast-invite-revoke-');
    expect($database)->toBeString();
    try {
        $migrate = new Process([PHP_BINARY, 'artisan', 'migrate:fresh', '--force'], base_path(), ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database]);
        expect($migrate->run())->toBe(0, $migrate->getErrorOutput());
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $database]);
        DB::purge('sqlite');
        [$owner, $organization] = memberFixture(role: 'owner');
        $user = User::factory()->create(['email' => 'new@example.test']);
        $invitation = OrganizationInvitation::factory()->for($organization)->for($owner, 'inviter')->create(['email' => $user->email]);
        $accept = FileDatabaseProcess::start($database, ['accept', (string) $invitation->id, (string) $user->id]);
        $revoke = FileDatabaseProcess::start($database, ['revoke', (string) $invitation->id]);
        $accept->wait();
        $revoke->wait();
        $codes = [$accept->getExitCode(), $revoke->getExitCode()];
        sort($codes);
        expect($codes)->toBe([0, 42])->not->toContain(70);
        DB::purge('sqlite');
        $row = OrganizationInvitation::query()->findOrFail($invitation->id);
        expect(($row->accepted_at !== null) xor ($row->revoked_at !== null))->toBeTrue()
            ->and(OrganizationMembership::query()->where('user_id', $user->id)->exists())->toBe($row->accepted_at !== null);
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
