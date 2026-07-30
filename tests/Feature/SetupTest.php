<?php

declare(strict_types=1);

use App\Actions\Installation\BootstrapInstallation;
use App\Identity\RegistrationData;
use App\Models\Installation;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Review;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\FileDatabaseProcess;

beforeEach(fn() => config(['oast.bootstrap_secret' => 'test-bootstrap-secret']));

it('requires a posted secret and bootstraps once into an existing route', function (): void {
    Review::factory()->create(['organization_id' => null, 'created_by_user_id' => null]);
    $this->get('/setup?bootstrap_secret=test-bootstrap-secret')->assertOk()->assertSee('Bootstrap secret');
    expect(session('oast.setup.authorized'))->toBeNull();
    $this->post('/setup/authorize', ['bootstrap_secret' => 'wrong'])->assertSessionHasErrors('bootstrap_secret');
    $this->post('/setup/authorize', ['bootstrap_secret' => 'test-bootstrap-secret'])->assertRedirect(route('setup.show'));
    $this->get('/setup')->assertOk()->assertSee('Create installation');
    $this->post('/setup', [
        'name' => 'Operator', 'email' => ' OWNER@EXAMPLE.TEST ', 'organization_name' => 'Acme',
        'password' => 'correct horse battery staple', 'password_confirmation' => 'correct horse battery staple',
    ])->assertRedirect(route('app.home'));

    $user = User::query()->sole();
    expect($user->email)->toBe('owner@example.test')->and($user->hasVerifiedEmail())->toBeTrue()
        ->and(OrganizationMembership::query()->sole()->role->value)->toBe('owner')
        ->and(Review::query()->sole()->organization_id)->toBe(Organization::query()->sole()->id)
        ->and(Review::query()->sole()->created_by_user_id)->toBeNull()
        ->and(Installation::query()->findOrFail(1)->bootstrapped_at)->not->toBeNull();
    $this->get('/app')->assertOk()->assertSee('Installation ready');
    $this->get('/setup')->assertNotFound();
});

it('404s posting the bootstrap secret once already bootstrapped', function (): void {
    Installation::query()->findOrFail(1)->update(['bootstrapped_at' => now()]);
    $this->post('/setup/authorize', ['bootstrap_secret' => 'test-bootstrap-secret'])->assertNotFound();
});

it('refuses to bootstrap an already-bootstrapped installation', function (): void {
    Installation::query()->findOrFail(1)->update(['bootstrapped_at' => now()]);
    expect(fn() => app(BootstrapInstallation::class)(
        new RegistrationData('Operator', 'operator@example.test', 'correct horse battery staple'),
        'Acme',
    ))->toThrow(NotFoundHttpException::class);
});

it('serializes concurrent bootstrap against a file sqlite database', function (): void {
    // RefreshDatabase wraps every test in an open transaction on a single
    // process-wide cached in-memory PDO. Purging the connection below to
    // repoint it at a file abandons that transaction on the shared PDO
    // instead of closing it, corrupting every later test in this run
    // (sqlite "cannot VACUUM"/"already exists" failures). Roll it back first.
    DB::connection()->rollBack();

    $database = tempnam(sys_get_temp_dir(), 'oast-setup-');
    expect($database)->toBeString();
    $env = ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database];
    $migrate = new Symfony\Component\Process\Process([PHP_BINARY, 'artisan', 'migrate:fresh', '--force'], base_path(), $env);
    expect($migrate->run())->toBe(0, $migrate->getErrorOutput());

    $a = FileDatabaseProcess::start($database, ['setup', 'One', 'one@example.test', 'correct horse battery staple', 'One Org']);
    $b = FileDatabaseProcess::start($database, ['setup', 'Two', 'two@example.test', 'correct horse battery staple', 'Two Org']);
    $a->wait();
    $b->wait();
    expect([$a->getExitCode(), $b->getExitCode()])->toContain(0)->toContain(44);

    config(['database.connections.sqlite.database' => $database]);
    DB::purge('sqlite');
    expect(User::query()->count())->toBe(1)->and(Organization::query()->count())->toBe(1)
        ->and(OrganizationMembership::query()->count())->toBe(1);
    unlink($database);
});
