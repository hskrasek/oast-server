<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Identity\UpdateUserProfileInformation;
use App\Http\Requests\UpdateProfileRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class AccountSettingsController
{
    public function show(Request $request): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw ValidationException::withMessages(['user' => 'Unable to resolve the authenticated user.']);
        }

        return view('app.settings.account', ['user' => $user]);
    }

    public function update(UpdateProfileRequest $request, UpdateUserProfileInformation $action): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw ValidationException::withMessages(['user' => 'Unable to resolve the authenticated user.']);
        }

        // UpdateUserProfileInformation is typed to array<string, string> — it
        // also serves Fortify's own profile-update pipeline, which supplies
        // raw request data. $request->validated() is array<string, mixed>,
        // so pull the two validated fields out explicitly as strings.
        $action->update($user, [
            'name' => $request->string('name')->value(),
            'email' => $request->string('email')->value(),
        ]);

        return back()->with('status', 'Account updated.');
    }
}
