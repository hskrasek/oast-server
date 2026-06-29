<?php

declare(strict_types=1);

use App\Http\Problems\ProblemType;
use Crell\ApiProblem\ApiProblem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
        apiPrefix: '',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (ValidationException $e, Request $request): Illuminate\Contracts\Routing\ResponseFactory|Illuminate\Http\Response|null {
            if ($request->getHost() !== config('oast.api_domain')) {
                return null;
            }

            $problem = new ApiProblem(
                'Validation failed',
                ProblemType::Validation->value,
            )->setStatus(422)->setDetail($e->getMessage());

            $problem['errors'] = $e->errors();

            return response(
                $problem->asJson(),
                422,
                ['Content-Type' => 'application/problem+json'],
            );
        });

        $exceptions->shouldRenderJsonWhen(
            fn(Request $request): bool => $request->getHost() === config()->string('oast.api_domain'),
        );
    })->create();
