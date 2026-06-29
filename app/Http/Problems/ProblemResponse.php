<?php

declare(strict_types=1);

namespace App\Http\Problems;

use Crell\ApiProblem\ApiProblem;
use Illuminate\Http\Response;

final class ProblemResponse
{
    public static function from(ApiProblem $problem, int $status): Response
    {
        $problem->setStatus($status);

        return new Response(
            $problem->asJson(),
            $status,
            ['Content-Type' => 'application/problem+json'],
        );
    }
}
