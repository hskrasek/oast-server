<?php

declare(strict_types=1);

use App\Actions\Identity\UpdateUserProfileInformation;
use App\Http\Controllers\AccountSettingsController;
use App\Http\Requests\UpdateProfileRequest;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

it('throws when show is reached without a resolved user', function (): void {
    (new AccountSettingsController)->show(new Request);
})->throws(ValidationException::class);

it('throws when update is reached without a resolved user', function (): void {
    // Constructed directly (not via the container) so Laravel's FormRequest
    // "resolving" hook — which copies in the current bound request and
    // auto-validates — never fires; this exercises the controller's own
    // defensive null-user check in isolation.
    (new AccountSettingsController)->update(new UpdateProfileRequest, new UpdateUserProfileInformation);
})->throws(ValidationException::class);
