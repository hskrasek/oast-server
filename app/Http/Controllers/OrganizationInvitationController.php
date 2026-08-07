<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\CreateInvitationRequest;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Organizations\InvitationService;
use App\Organizations\OrganizationContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

final class OrganizationInvitationController
{
    public function store(CreateInvitationRequest $request, OrganizationContext $context, InvitationService $service): RedirectResponse
    {
        $organization = $context->organization();
        Gate::authorize('create', [OrganizationInvitation::class, $organization]);
        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }

        $result = $service->createOrReplace($organization, $user, $request->string('email')->value());

        return back()->with('invitation_url', $result['url']);
    }

    public function destroy(string $invitation, OrganizationContext $context, InvitationService $service): RedirectResponse
    {
        // Scoped through the current org (matching ScopedReviewResolver's
        // pattern) instead of an unscoped route-model binding: an
        // unscoped binding lets a cross-organization id resolve and then
        // fail authorization with 403, distinguishing it from a genuinely
        // unknown id's 404 — an existence oracle. Scoping the query means
        // a cross-organization id 404s identically to an unknown one.
        $model = OrganizationInvitation::query()
            ->where('organization_id', $context->organization()->id)
            ->findOrFail($invitation);
        Gate::authorize('delete', $model);
        $service->revoke($model);

        return back();
    }
}
