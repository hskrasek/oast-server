<?php

declare(strict_types=1);

use App\Council\Exceptions\JudgeException;
use App\Council\Exceptions\PanelException;
use App\Http\Problems\ProblemResponse;
use App\Http\Problems\ProblemType;
use Crell\ApiProblem\ApiProblem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
        // Map domain/validation failures to RFC 9457 problem+json on the API host.
        $onApi = fn(Request $request): bool => $request->getHost() === config('oast.api_domain');

        $exceptions->render(function (ValidationException $e, Request $request) use ($onApi): ?Response {
            if (! $onApi($request)) {
                return null;
            }

            $problem = new ApiProblem('Validation failed', ProblemType::Validation->value)
                ->setDetail($e->getMessage());
            $problem['errors'] = $e->errors();

            return ProblemResponse::from($problem, 422);
        });

        $exceptions->render(function (PanelException $e, Request $request) use ($onApi): ?Response {
            if (! $onApi($request)) {
                return null;
            }

            $problem = new ApiProblem('Council quorum not met', ProblemType::QuorumNotMet->value)
                ->setDetail($e->getMessage());
            $problem['failed_models'] = $e->failedModels;

            return ProblemResponse::from($problem, 503);
        });

        $exceptions->render(function (JudgeException $e, Request $request) use ($onApi): ?Response {
            if (! $onApi($request)) {
                return null;
            }

            $problem = new ApiProblem('Judge produced invalid output', ProblemType::InvalidJudgeOutput->value)
                ->setDetail($e->getMessage());
            $problem['errors'] = $e->errors;

            return ProblemResponse::from($problem, 502);
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) use ($onApi): ?Response {
            if (! $onApi($request)) {
                return null;
            }

            // Deliberately generic: the underlying exception message (e.g. a
            // ModelNotFoundException's "No query results for model [...] 999")
            // is an implementation detail, not something to leak to API callers.
            $problem = new ApiProblem('Not Found', ProblemType::NotFound->value)
                ->setDetail('The requested resource could not be found.');

            return ProblemResponse::from($problem, 404);
        });

        $exceptions->shouldRenderJsonWhen(
            fn(Request $request): bool => $request->getHost() === config()->string('oast.api_domain'),
        );
    })->create();
