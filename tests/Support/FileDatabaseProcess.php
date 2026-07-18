<?php

declare(strict_types=1);

namespace Tests\Support;

use Symfony\Component\Process\Process;

final class FileDatabaseProcess
{
    /** @param list<string> $arguments */
    public static function start(string $database, array $arguments): Process
    {
        $process = new Process([PHP_BINARY, base_path('tests/fixtures/m3a-race.php'), $database, ...$arguments], base_path());
        $process->setTimeout(30);
        $process->start();

        return $process;
    }
}
