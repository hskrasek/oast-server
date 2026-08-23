<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\CreatePersonalAccessTokenRequest;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Organizations\OrganizationContext;
use App\Tokens\PersonalAccessTokenService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class TokenSettingsController
{
    public function index(Request $request, OrganizationContext $context): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }

        $sealed = $request->session()->pull('oast.new_token');
        $response = response()->view('app.settings.tokens', [
            // Only tokens that can still authenticate; revoked or expired rows
            // would render a dead Revoke control and read as live credentials.
            'tokens' => $user->tokens()->where('organization_id', $context->organization()->id)
                ->whereNull('revoked_at')
                ->where(fn($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->latest()->get(),
            'plainToken' => is_string($sealed) ? Crypt::decryptString($sealed) : null,
        ]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    public function store(CreatePersonalAccessTokenRequest $request, OrganizationContext $context, PersonalAccessTokenService $service): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }

        $expires = $request->filled('expires_at') ? CarbonImmutable::parse($request->string('expires_at')->value()) : null;
        $created = $service->create($user, $context->organization(), $request->string('name')->value(), $expires);

        // Sessions are database-backed and unencrypted; never let the bearer
        // token sit in the sessions table in the clear.
        return redirect()->route('app.settings.tokens.index')->with('oast.new_token', Crypt::encryptString($created->plainTextToken));
    }

    public function destroy(Request $request, PersonalAccessToken $token, OrganizationContext $context, PersonalAccessTokenService $service): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }

        abort_unless($token->tokenable_id === $user->id && $token->organization_id === $context->organization()->id, 404);
        $service->revoke($token);

        return back();
    }
}
