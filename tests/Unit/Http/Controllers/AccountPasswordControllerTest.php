<?php

declare(strict_types=1);

use App\Actions\Identity\UpdateUserPassword;
use App\Http\Controllers\AccountPasswordController;
use App\Http\Requests\UpdateAccountPasswordRequest;
use Illuminate\Validation\ValidationException;

it('throws when invoked without a resolved user', function (): void {
    // Constructed directly (not via the container) so Laravel's FormRequest
    // "resolving" hook never fires — see AccountSettingsControllerTest.
    (new AccountPasswordController)(new UpdateAccountPasswordRequest, new UpdateUserPassword);
})->throws(ValidationException::class);
