<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Installation\BootstrapInstallation;
use App\Http\Requests\BootstrapInstallationRequest;
use App\Identity\RegistrationData;
use App\Models\Installation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class SetupController
{
    public function show(Request $request): View
    {
        if (Installation::query()->findOrFail(1)->bootstrapped_at !== null) {
            throw new NotFoundHttpException;
        }

        if ($request->session()->get('oast.setup.authorized') === true) {
            return view('setup.create');
        }

        return view('setup.authorize');
    }

    public function store(BootstrapInstallationRequest $request, BootstrapInstallation $bootstrap): RedirectResponse
    {
        $user = $bootstrap(new RegistrationData(
            $request->string('name')->value(),
            $request->string('email')->value(),
            $request->string('password')->value(),
        ), $request->string('organization_name')->value());
        $request->session()->forget('oast.setup.authorized');
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('app.home');
    }
}
