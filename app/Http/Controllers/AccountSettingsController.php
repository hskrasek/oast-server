<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Identity\UpdateUserProfileInformation;
use App\Http\Requests\UpdateProfileRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class AccountSettingsController
{
    public function show(Request $request): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }

        return view('app.settings.account', ['user' => $user]);
    }

    public function update(UpdateProfileRequest $request, UpdateUserProfileInformation $action): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }
        // Deviation from the task-8 brief (necessary for PHPStan level max):
        // UpdateUserProfileInformation::update() declares `@param array<string, string> $input`
        // (matching the sibling ResetUserPassword/CreateNewUser actions), but
        // $request->validated() is typed array<string, mixed>. Extracting typed strings here
        // keeps the action's signature accurate instead of widening it to mixed.
        $action->update($user, [
            'name' => $request->string('name')->value(),
            'email' => $request->string('email')->value(),
        ]);

        return back()->with('status', 'Account updated.');
    }
}
