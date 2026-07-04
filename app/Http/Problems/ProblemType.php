<?php

declare(strict_types=1);

namespace App\Http\Problems;

enum ProblemType: string
{
    case InvalidJudgeOutput = 'https://oast.sh/problems/judge-output-invalid';

    case NotFound = 'https://oast.sh/problems/not-found';

    case QuorumNotMet = 'https://oast.sh/problems/quorum-not-met';

    case Validation = 'https://oast.sh/problems/validation';
}
