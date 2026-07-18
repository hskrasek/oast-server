<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\OrganizationMembership;
use App\Organizations\MembershipService;
use App\Organizations\OrganizationContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

final class MembershipController
{
    public function destroy(OrganizationMembership $membership, OrganizationContext $context, MembershipService $service): RedirectResponse
    {
        Gate::authorize('delete', $membership);
        $service->remove($context->membership()->user()->firstOrFail(), $membership);

        return back();
    }
}
