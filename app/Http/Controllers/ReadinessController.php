<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ReadinessController
{
    public function __invoke(Migrator $migrator): JsonResponse
    {
        try {
            DB::connection()->getPdo();
            if (! $migrator->repositoryExists()) {
                return response()->json(['status' => 'not ready'], 503);
            }

            $files = $migrator->getMigrationFiles(database_path('migrations'));
            $ran = $migrator->getRepository()->getRan();
            if (array_diff(array_keys($files), $ran) !== []) {
                return response()->json(['status' => 'not ready'], 503);
            }

            return response()->json(['status' => 'ready']);
        } catch (Throwable $throwable) {
            report($throwable);

            return response()->json(['status' => 'not ready'], 503);
        }
    }
}
