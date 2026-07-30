<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Identity\UpdateUserPassword;
use App\Http\Requests\UpdateAccountPasswordRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

final class AccountPasswordController
{
    public function __invoke(UpdateAccountPasswordRequest $request, UpdateUserPassword $action): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }

        // Deviation from the task-8 brief (necessary fix): UpdateAccountPasswordRequest::rules()
        // only declares current_password/password, so $request->validated() strips
        // password_confirmation before UpdateUserPassword re-validates the 'confirmed' rule,
        // always failing it (mirrors why Fortify's own PasswordController passes
        // $request->all() rather than validated() input). Typed extraction (rather than all(),
        // which is array<string, mixed>) keeps the action's `array<string, string>` signature
        // accurate for PHPStan level max.
        $action->update($user, [
            'current_password' => $request->string('current_password')->value(),
            'password' => $request->string('password')->value(),
            'password_confirmation' => $request->string('password_confirmation')->value(),
        ]);

        return back()->with('status', 'Password updated.');
    }
}
