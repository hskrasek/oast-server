<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Organizations\InvitationAcceptanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final readonly class InvitationAcceptanceController
{
    public function __construct(private InvitationAcceptanceService $acceptance) {}

    public function __invoke(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->acceptance->find($token);
        $user = $request->user();
        if (!$invitation instanceof \App\Models\OrganizationInvitation || ! $user instanceof User) {
            throw ValidationException::withMessages(['invitation' => 'This invitation is not available.']);
        }

        $this->acceptance->accept($invitation, $user);
        $request->session()->forget(['oast.invitation.token', 'url.intended']);

        return redirect()->route('app.home');
    }
}
