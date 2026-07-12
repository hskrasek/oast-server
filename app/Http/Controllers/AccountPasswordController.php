<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Identity\UpdateUserPassword;
use App\Http\Requests\UpdateAccountPasswordRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

final class AccountPasswordController
{
    public function __invoke(UpdateAccountPasswordRequest $request, UpdateUserPassword $action): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw ValidationException::withMessages(['user' => 'Unable to resolve the authenticated user.']);
        }

        // UpdateUserPassword re-validates internally (it also serves Fortify's
        // password-reset flow), including the "confirmed" rule against
        // password_confirmation — a field with no rule of its own, so it never
        // survives $request->validated(). Pass it through explicitly, typed
        // as strings to match the action's Fortify-shared signature.
        $action->update($user, [
            'current_password' => $request->string('current_password')->value(),
            'password' => $request->string('password')->value(),
            'password_confirmation' => $request->string('password_confirmation')->value(),
        ]);

        return back()->with('status', 'Password updated.');
    }
}
