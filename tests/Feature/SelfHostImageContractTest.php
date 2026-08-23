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

it('keeps queue retry_after synchronized across sources and strictly greater than the worker timeout', function (): void {
    $queueConfig = (string) file_get_contents(base_path('config/queue.php'));
    $envExample = (string) file_get_contents(base_path('.env.example'));
    $dockerfile = (string) file_get_contents(base_path('Dockerfile'));
    $supervisor = (string) file_get_contents(base_path('docker/supervisord.conf'));

    preg_match("/'retry_after' => \(int\) env\('DB_QUEUE_RETRY_AFTER', (\d+)\)/", $queueConfig, $configMatch);
    preg_match('/DB_QUEUE_RETRY_AFTER=(\d+)/', $envExample, $envMatch);
    preg_match('/DB_QUEUE_RETRY_AFTER=(\d+)/', $dockerfile, $dockerfileMatch);
    preg_match('/queue:listen --tries=\d+ --timeout=(\d+)/', $supervisor, $timeoutMatch);

    expect($configMatch)->toHaveCount(2)
        ->and($envMatch)->toHaveCount(2)
        ->and($dockerfileMatch)->toHaveCount(2)
        ->and($timeoutMatch)->toHaveCount(2);

    $retryAfterValues = [(int) $configMatch[1], (int) $envMatch[1], (int) $dockerfileMatch[1]];
    $timeout = (int) $timeoutMatch[1];

    expect(array_unique($retryAfterValues))->toHaveCount(1)
        ->and($retryAfterValues[0])->toBeGreaterThan($timeout);
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

it('rejects the placeholder and too-short bootstrap secrets', function (string $secret, string $message): void {
    $process = new Process([base_path('docker/validate-bootstrap-secret')], base_path(), ['OAST_BOOTSTRAP_SECRET' => $secret]);
    $process->run();

    expect($process->getExitCode())->toBe(64)
        ->and($process->getErrorOutput())->toContain($message);
})->with([
    'documented placeholder' => ['replace-with-a-high-entropy-secret', 'must not be the documented placeholder'],
    'too short' => ['tiny-secret', 'must be at least 16 characters'],
]);

it('accepts a strong or absent bootstrap secret and discards mail by default', function (): void {
    $strong = new Process([base_path('docker/validate-bootstrap-secret')], base_path(), ['OAST_BOOTSTRAP_SECRET' => 'a-genuinely-long-random-secret']);
    $strong->run();
    $absent = new Process([base_path('docker/validate-bootstrap-secret')], base_path(), ['OAST_BOOTSTRAP_SECRET' => '']);
    $absent->run();

    expect($strong->getExitCode())->toBe(0, $strong->getErrorOutput())
        ->and($absent->getExitCode())->toBe(0, $absent->getErrorOutput())
        ->and((string) file_get_contents(base_path('docker/entrypoint.sh')))->toContain('validate-bootstrap-secret')
        // Mail defaults to the discarding array transport so invitation and
        // reset bearer links never land in container logs.
        ->and((string) file_get_contents(base_path('Dockerfile')))->toContain('validate-bootstrap-secret', 'MAIL_MAILER=array')
        // Reset links are built from the forwarded Host, so the proxy example
        // must pin it rather than reflect the client's request header.
        ->and((string) file_get_contents(base_path('docker/README.md')))->toContain('proxy_set_header Host $server_name;');
});

it('accepts valid raw and base64 keys', function (string $key): void {
    $process = new Process([base_path('docker/validate-app-key')], base_path(), ['APP_KEY' => $key]);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
})->with([
    'raw' => 'a9!B2@cD3#eF4$gH5%iJ6^kL7&mN8*pQ',
    'base64' => 'base64:YWJjZGVmZ2hpamtsbW5vcHFyc3R1dnd4eXowMTIzNDU=',
]);
