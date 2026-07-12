<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('defines the supervised persistent database-backed contract', function (): void {
    $dockerfile = (string) file_get_contents(base_path('Dockerfile'));
    $supervisor = (string) file_get_contents(base_path('docker/supervisord.conf'));
    $entrypoint = (string) file_get_contents(base_path('docker/entrypoint.sh'));
    $readme = (string) file_get_contents(base_path('docker/README.md'));

    expect($dockerfile)
        ->toContain('supervisor', 'VOLUME ["/var/lib/oast"]', 'OAST_QUEUE_WORKERS=1', 'DB_QUEUE_RETRY_AFTER=960', 'HEALTHCHECK')
        ->and($supervisor)->toContain('frankenphp php-server', 'queue:listen --tries=3 --timeout=900', 'autorestart=unexpected')
        ->and($entrypoint)->toContain('APP_KEY_FILE', 'rtrim($value, "\\r\\n")', 'php artisan migrate --force', '/var/lib/oast/publications')
        ->and($readme)->toContain('proxy_buffering off', 'proxy_read_timeout 600s', 'OAST_QUEUE_WORKERS', 'Backup and restore', 'DB_QUEUE_RETRY_AFTER=960');
});

it('rejects missing malformed and explicit raw or base64 sentinel keys', function (string $key): void {
    $process = new Process([base_path('docker/validate-app-key')], base_path(), ['APP_KEY' => $key]);
    $process->run();

    expect($process->getExitCode())->toBe(64)
        ->and($process->getErrorOutput())->toContain('APP_KEY must be a non-placeholder 32-byte Laravel key.');
})->with([
    'missing' => '',
    'malformed raw' => 'short',
    'malformed base64' => 'base64:not-valid-***',
    'example raw' => '01234567890123456789012345678901',
    'zero raw' => '00000000000000000000000000000000',
    'example base64' => 'base64:MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTIzNDU2Nzg5MDE=',
    'zero base64' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
]);

it('accepts valid raw and base64 keys', function (string $key): void {
    $process = new Process([base_path('docker/validate-app-key')], base_path(), ['APP_KEY' => $key]);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
})->with([
    'raw' => 'a9!B2@cD3#eF4$gH5%iJ6^kL7&mN8*pQ',
    'base64' => 'base64:YWJjZGVmZ2hpamtsbW5vcHFyc3R1dnd4eXowMTIzNDU=',
]);
