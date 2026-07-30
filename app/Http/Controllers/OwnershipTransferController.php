<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\TransferOwnershipRequest;
use App\Models\User;
use App\Organizations\MembershipService;
use App\Organizations\OrganizationContext;
use Illuminate\Http\RedirectResponse;

final class OwnershipTransferController
{
    public function __invoke(TransferOwnershipRequest $request, OrganizationContext $context, MembershipService $service): RedirectResponse
    {
        $target = $context->organization()->memberships()->findOrFail($request->integer('membership_id'));
        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }

        $service->transferOwnership($user, $target);

        return back();
    }
}
