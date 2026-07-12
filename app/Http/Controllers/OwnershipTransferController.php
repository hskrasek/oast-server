<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\TransferOwnershipRequest;
use App\Organizations\MembershipService;
use App\Organizations\OrganizationContext;
use Illuminate\Http\RedirectResponse;

final class OwnershipTransferController
{
    public function __invoke(TransferOwnershipRequest $request, OrganizationContext $context, MembershipService $service): RedirectResponse
    {
        $target = $context->organization()->memberships()->findOrFail($request->integer('membership_id'));
        $service->transferOwnership($context->membership()->user()->firstOrFail(), $target);

        return back();
    }
}
