<?php

declare(strict_types=1);

namespace App\Council;

enum Dimension: string
{
    case DomainModeling = 'domain-modeling';
    case Workflows = 'workflows';
}
