<?php

declare(strict_types=1);

use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Database\Migrations\MigrationRepositoryInterface;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

it('does not carry stateful session/cookie/CSRF middleware', function (): void {
    $route = Route::getRoutes()->getByName('up');
    $effective = app('router')->gatherRouteMiddleware($route);

    expect($effective)
        ->not->toContain(StartSession::class)
        ->not->toContain(AddQueuedCookiesToResponse::class)
        ->not->toContain(ShareErrorsFromSession::class)
        ->not->toContain(PreventRequestForgery::class);
});

it('is ready only when database and migrations are current', function (): void {
    $this->getJson('/up')->assertOk()->assertExactJson(['status' => 'ready']);
});

it('is not ready when the migration repository is absent', function (): void {
    $migrator = Mockery::mock(Migrator::class);
    $migrator->shouldReceive('repositoryExists')->once()->andReturnFalse();
    app()->instance(Migrator::class, $migrator);

    $this->getJson('/up')->assertServiceUnavailable()->assertExactJson(['status' => 'not ready']);
});

it('is not ready for pending migrations', function (): void {
    $repository = Mockery::mock(MigrationRepositoryInterface::class);
    $repository->shouldReceive('getRan')->once()->andReturn([]);
    $migrator = Mockery::mock(Migrator::class);
    $migrator->shouldReceive('repositoryExists')->once()->andReturnTrue();
    $migrator->shouldReceive('getMigrationFiles')->once()->andReturn([
        '2026_pending' => '/tmp/2026_pending.php',
    ]);
    $migrator->shouldReceive('getRepository')->once()->andReturn($repository);
    app()->instance(Migrator::class, $migrator);

    $this->getJson('/up')->assertServiceUnavailable()->assertExactJson(['status' => 'not ready']);
});

it('does not expose database exceptions', function (): void {
    $migrator = Mockery::mock(Migrator::class);
    $migrator->shouldReceive('repositoryExists')->once()->andThrow(new RuntimeException('database down'));
    app()->instance(Migrator::class, $migrator);

    $this->getJson('/up')->assertServiceUnavailable()->assertExactJson(['status' => 'not ready'])->assertDontSee('database down');
});
