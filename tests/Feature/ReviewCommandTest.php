<?php

declare(strict_types=1);

use App\Models\Review;

// fakeCouncil() comes from tests/Pest.php.

it('runs a baseline review from a spec file and persists it', function (): void {
    fakeCouncil();
    $path = sys_get_temp_dir() . '/oast-spec-' . uniqid() . '.yaml';
    file_put_contents($path, 'openapi: 3.1.0');

    $this->artisan('oast:review', ['spec' => $path, '--baseline' => true])
        ->assertSuccessful();

    expect(Review::query()->where('mode', 'baseline')->where('status', 'complete')->count())->toBe(1);

    unlink($path);
});

it('fails when the spec file is missing', function (): void {
    $this->artisan('oast:review', ['spec' => '/no/such/file.yaml'])
        ->assertFailed();
});
