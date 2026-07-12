<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\CreateInvitationRequest;
use App\Models\OrganizationInvitation;
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
        $result = $service->createOrReplace($organization, $context->membership()->user()->firstOrFail(), $request->string('email')->value());

        return back()->with('invitation_url', $result['url']);
    }

    public function destroy(OrganizationInvitation $invitation, InvitationService $service): RedirectResponse
    {
        Gate::authorize('delete', $invitation);
        $service->revoke($invitation);

        return back();
    }
}
