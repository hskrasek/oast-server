<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Identity\CanonicalEmail;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class CanonicalizeEmailInput
{
    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => CanonicalEmail::from($request->input('email'))]);
        }

        return $next($request);
    }
}
