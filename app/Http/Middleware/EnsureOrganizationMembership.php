<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Organizations\MissingOrganizationMembership;
use App\Organizations\OrganizationContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureOrganizationMembership
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            app(OrganizationContext::class)->membership();
        } catch (MissingOrganizationMembership) {
            return response()->view('app.no-organization');
        }

        return $next($request);
    }
}
