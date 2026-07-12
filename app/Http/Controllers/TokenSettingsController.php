<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\CreatePersonalAccessTokenRequest;
use App\Models\PersonalAccessToken;
use App\Organizations\OrganizationContext;
use App\Tokens\PersonalAccessTokenService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class TokenSettingsController
{
    public function index(Request $request, OrganizationContext $context): Response
    {
        $user = $context->membership()->user()->firstOrFail();
        $plain = $request->session()->pull('oast.new_token');
        $response = response()->view('app.settings.tokens', [
            'tokens' => $user->tokens()->where('organization_id', $context->organization()->id)->latest()->get(),
            'plainToken' => $plain,
        ]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    public function store(CreatePersonalAccessTokenRequest $request, OrganizationContext $context, PersonalAccessTokenService $service): RedirectResponse
    {
        $user = $context->membership()->user()->firstOrFail();
        $expires = $request->filled('expires_at') ? CarbonImmutable::parse($request->string('expires_at')->value()) : null;
        $created = $service->create($user, $context->organization(), $request->string('name')->value(), $expires);

        return redirect()->route('app.settings.tokens.index')->with('oast.new_token', $created->plainTextToken);
    }

    public function destroy(PersonalAccessToken $token, OrganizationContext $context, PersonalAccessTokenService $service): RedirectResponse
    {
        $user = $context->membership()->user()->firstOrFail();
        abort_unless($token->tokenable_id === $user->id && $token->organization_id === $context->organization()->id, 404);
        $service->revoke($token);

        return back();
    }
}
