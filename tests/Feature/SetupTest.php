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

it('serializes concurrent bootstrap against a file sqlite database', function (): void {
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

    // Read the file DB through a dedicated connection so the default (:memory:)
    // connection RefreshDatabase manages is never repointed/purged out from under
    // the next test in this process.
    config(['database.connections.sqlite_race' => ['driver' => 'sqlite', 'database' => $database, 'prefix' => '', 'foreign_key_constraints' => true]]);
    expect(User::on('sqlite_race')->count())->toBe(1)->and(Organization::on('sqlite_race')->count())->toBe(1)
        ->and(OrganizationMembership::on('sqlite_race')->count())->toBe(1);
    DB::purge('sqlite_race');
    unlink($database);
});

it('shows the create form once the secret has been authorized', function (): void {
    $this->withSession(['oast.setup.authorized' => true])->get('/setup')
        ->assertOk()->assertSee('Create installation');
});

it('404s setup routes once the installation is bootstrapped', function (): void {
    Installation::query()->whereKey(1)->update(['bootstrapped_at' => now()]);
    $this->get('/setup')->assertNotFound();
    $this->post('/setup/authorize', ['bootstrap_secret' => 'test-bootstrap-secret'])->assertNotFound();
});

it('rejects a missing bootstrap secret configuration', function (): void {
    config(['oast.bootstrap_secret' => null]);
    $this->post('/setup/authorize', ['bootstrap_secret' => 'anything'])->assertSessionHasErrors('bootstrap_secret');
});

it('refuses to bootstrap the installation twice at the action level', function (): void {
    $action = app(BootstrapInstallation::class);
    $action(new RegistrationData('One', 'one@example.test', 'correct horse battery staple'), 'One Org');

    expect(fn(): User => $action(new RegistrationData('Two', 'two@example.test', 'correct horse battery staple'), 'Two Org'))
        ->toThrow(NotFoundHttpException::class);
});
