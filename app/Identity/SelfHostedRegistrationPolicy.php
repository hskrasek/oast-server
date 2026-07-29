<?php

declare(strict_types=1);

namespace App\Identity;

use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Organizations\InvitationAcceptanceService;

final readonly class SelfHostedRegistrationPolicy implements RegistrationPolicy
{
    public function __construct(private InvitationAcceptanceService $acceptance) {}

    public function register(RegistrationData $data, OrganizationInvitation $invitation): User
    {
        return $this->acceptance->registerAndAccept($invitation, $data);
    }
}
