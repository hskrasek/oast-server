<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateOrganizationRequest;
use App\Organizations\OrganizationContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

final class OrganizationSettingsController
{
    public function __invoke(OrganizationContext $context): View
    {
        return view('app.settings.organization', ['organization' => $context->organization()->load('memberships.user', 'invitations')]);
    }

    public function update(UpdateOrganizationRequest $request, OrganizationContext $context): RedirectResponse
    {
        $organization = $context->organization();
        Gate::authorize('update', $organization);
        $organization->update($request->validated());

        return back()->with('status', 'Organization updated.');
    }
}
