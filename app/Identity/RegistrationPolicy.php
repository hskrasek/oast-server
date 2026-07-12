<?php

declare(strict_types=1);

namespace App\Identity;

use App\Models\OrganizationInvitation;
use App\Models\User;

interface RegistrationPolicy
{
    public function register(RegistrationData $data, OrganizationInvitation $invitation): User;
}
