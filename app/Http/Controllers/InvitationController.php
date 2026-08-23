<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\OrganizationInvitation;
use App\Organizations\InvitationAcceptanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;

final readonly class InvitationController
{
    public function __construct(private InvitationAcceptanceService $acceptance) {}

    public static function unseal(mixed $sealed): ?string
    {
        return is_string($sealed) ? rescue(fn(): string => Crypt::decryptString($sealed), null, false) : null;
    }

    public function show(string $token): Response
    {
        $invitation = $this->acceptance->find($token);
        return response()->view($invitation?->available() ? 'invitations.show' : 'invitations.unavailable', ['token' => $token])
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function startLogin(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->acceptance->find($token);
        if (!$invitation instanceof OrganizationInvitation || ! $invitation->available()) {
            return redirect()->route('invitations.show', $token);
        }

        // Sessions are database-backed and unencrypted: only the sealed token
        // may touch the sessions table, and url.intended goes through the
        // token-less resume route instead of embedding the bearer URL.
        $request->session()->put('oast.invitation.token', Crypt::encryptString($token));
        $request->session()->put('url.intended', route('invitations.resume'));
        return redirect()->route('login');
    }

    public function startRegistration(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->acceptance->find($token);
        if (!$invitation instanceof OrganizationInvitation || ! $invitation->available()) {
            return redirect()->route('invitations.show', $token);
        }

        $request->session()->put('oast.invitation.token', Crypt::encryptString($token));
        return redirect()->route('register');
    }

    public function resume(Request $request): RedirectResponse
    {
        $token = self::unseal($request->session()->get('oast.invitation.token'));

        return $token === null ? redirect()->route('home') : redirect()->route('invitations.show', $token);
    }
}
