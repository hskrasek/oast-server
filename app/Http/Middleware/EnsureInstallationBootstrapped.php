<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Installation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureInstallationBootstrapped
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (Installation::query()->findOrFail(1)->bootstrapped_at === null) {
            return redirect()->route('setup.show');
        }

        return $next($request);
    }
}
