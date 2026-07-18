<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\AuthorizeSetupRequest;
use App\Models\Installation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class SetupAuthorizationController
{
    public function __invoke(AuthorizeSetupRequest $request): RedirectResponse
    {
        if (Installation::query()->findOrFail(1)->bootstrapped_at !== null) {
            throw new NotFoundHttpException;
        }

        $configured = config('oast.bootstrap_secret');
        if (! is_string($configured) || $configured === '' || ! hash_equals($configured, $request->string('bootstrap_secret')->value())) {
            throw ValidationException::withMessages(['bootstrap_secret' => 'The bootstrap secret is invalid.']);
        }

        $request->session()->regenerateToken();
        $request->session()->put('oast.setup.authorized', true);

        return redirect()->route('setup.show');
    }
}
