<?php

declare(strict_types=1);

use App\Council\Exceptions\JudgeException;
use App\Council\Exceptions\PanelException;
use App\Http\Problems\ProblemResponse;
use App\Http\Problems\ProblemType;
use App\Reviews\ActiveReviewLimitExceeded;
use App\Streaming\StreamLimitExceeded;
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
        apiPrefix: '',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // cloudflared on localhost is the only ingress path in prod, so
        // trusting all proxies is correct. Deliberately NOT trusting
        // X-Forwarded-Host: Cloudflare's edge doesn't overwrite it, so a
        // client can set it to an arbitrary value, which would otherwise
        // leak into signedRoute()-generated URLs (e.g. the SES confirm
        // link) and enable phishing via our own mail. Only For/Proto/Port
        // are needed to resolve the real client IP and https scheme.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_PORT,
        );

        $middleware->append(App\Http\Middleware\CanonicalizeEmailInput::class);

        $middleware->alias([
            'installation' => App\Http\Middleware\EnsureInstallationBootstrapped::class,
            'verified.configured' => App\Http\Middleware\EnsureEmailIsVerifiedWhenConfigured::class,
            'organization' => App\Http\Middleware\EnsureOrganizationMembership::class,
            // Laravel 13 and Sanctum 4.3 do not register these aliases automatically.
            'ability' => Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class,
            'abilities' => Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
        ]);

        // Fortify's auth-protected entry points (email/verify, user/confirm-password)
        // also carry EnsureInstallationBootstrapped via fortify.middleware. It must
        // outrank Authenticate so a pre-bootstrap request lands on /setup rather than
        // /login. The priority list slots Authenticate via the AuthenticatesRequests
        // contract it implements, so prepend ours just ahead of that contract.
        $middleware->prependToPriorityList(
            before: Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            prepend: App\Http\Middleware\EnsureInstallationBootstrapped::class,
        );
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

        $exceptions->render(function (Illuminate\Auth\AuthenticationException $e, Request $request) use ($onApi): ?Response {
            if (! $onApi($request)) {
                return null;
            }

            return ProblemResponse::from(new ApiProblem('Unauthenticated', ProblemType::Unauthenticated->value)->setDetail('A valid bearer token is required.'), 401);
        });

        $exceptions->render(function (Illuminate\Auth\Access\AuthorizationException|Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException $e, Request $request) use ($onApi): ?Response {
            if (! $onApi($request)) {
                return null;
            }

            return ProblemResponse::from(new ApiProblem('Forbidden', ProblemType::Forbidden->value)->setDetail('The credential cannot perform this action.'), 403);
        });

        $exceptions->render(function (Illuminate\Http\Exceptions\ThrottleRequestsException $e, Request $request) use ($onApi): ?Response {
            if (! $onApi($request)) {
                return null;
            }

            $retryAfter = $e->getHeaders()['Retry-After'] ?? '60';
            $retry = is_scalar($retryAfter) ? (string) $retryAfter : '60';

            return ProblemResponse::from(new ApiProblem('Rate limited', ProblemType::RateLimited->value)->setDetail('Too many requests.'), 429, ['Retry-After' => $retry]);
        });

        $exceptions->render(function (ActiveReviewLimitExceeded $e, Request $request) use ($onApi): Response {
            $headers = ['Retry-After' => (string) $e->retryAfter];

            if (! $onApi($request)) {
                return response('Too many active reviews.', 429, $headers);
            }

            return ProblemResponse::from(
                new ApiProblem('Rate limited', ProblemType::RateLimited->value)->setDetail('Too many active reviews.'),
                429,
                $headers,
            );
        });

        $exceptions->render(function (StreamLimitExceeded $e, Request $request) use ($onApi): Response {
            $headers = ['Retry-After' => (string) $e->retryAfter];

            if (! $onApi($request)) {
                return response('', 429, $headers);
            }

            return ProblemResponse::from(
                new ApiProblem('Rate limited', ProblemType::RateLimited->value)->setDetail('Too many concurrent event streams.'),
                429,
                $headers,
            );
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
