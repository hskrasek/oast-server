<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\OrganizationMembership;
use App\Models\User;
use App\Organizations\MembershipService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class MembershipController
{
    public function destroy(Request $request, OrganizationMembership $membership, MembershipService $service): RedirectResponse
    {
        Gate::authorize('delete', $membership);
        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }
        $service->remove($user, $membership);

        return back();
    }
}
