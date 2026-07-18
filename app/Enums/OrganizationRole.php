<?php

declare(strict_types=1);

namespace App\Enums;

enum OrganizationRole: string
{
    case Owner = 'owner';
    case Member = 'member';
}
