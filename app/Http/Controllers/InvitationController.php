<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Organizations\InvitationAcceptanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final readonly class InvitationController
{
    public function __construct(private InvitationAcceptanceService $acceptance) {}

    public function show(string $token): Response
    {
        $invitation = $this->acceptance->find($token);

        return response()->view($invitation?->available() ? 'invitations.show' : 'invitations.unavailable', ['token' => $token])
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function startLogin(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->acceptance->find($token);
        if (!$invitation instanceof \App\Models\OrganizationInvitation || ! $invitation->available()) {
            return redirect()->route('invitations.show', $token);
        }

        $request->session()->put('oast.invitation.token', $token);
        $request->session()->put('url.intended', route('invitations.show', $token));

        return redirect()->route('login');
    }

    public function startRegistration(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->acceptance->find($token);
        if (!$invitation instanceof \App\Models\OrganizationInvitation || ! $invitation->available()) {
            return redirect()->route('invitations.show', $token);
        }

        $request->session()->put('oast.invitation.token', $token);

        return redirect()->route('register');
    }
}
