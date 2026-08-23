<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\OrganizationMembership;
use App\Models\User;
use App\Organizations\MembershipService;
use App\Organizations\OrganizationContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class MembershipController
{
    public function destroy(Request $request, string $membership, OrganizationContext $context, MembershipService $service): RedirectResponse
    {
        // Scoped through the current org (matching ScopedReviewResolver's
        // pattern) instead of an unscoped route-model binding: an
        // unscoped binding lets a cross-organization id resolve and then
        // fail authorization with 403, distinguishing it from a genuinely
        // unknown id's 404 — an existence oracle. Scoping the query means
        // a cross-organization id 404s identically to an unknown one.
        $model = OrganizationMembership::query()
            ->where('organization_id', $context->organization()->id)
            ->findOrFail($membership);
        Gate::authorize('delete', $model);
        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }

        $service->remove($user, $model);

        return back();
    }
}
