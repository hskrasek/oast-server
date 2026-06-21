<?php

declare(strict_types=1);

namespace App\Council;

use App\Council\Exceptions\JudgeException;

final class FindingValidator
{
    /**
     * Validate the judge's raw (untrusted) findings, enforcing the one rule the
     * structured-output schema cannot: a split finding must carry a disagreement.
     *
     * @param  array<array-key, mixed>  $findings
     *
     * @return array<array-key, mixed>
     *
     * @throws JudgeException
     */
    public function validate(array $findings): array
    {
        foreach ($findings as $index => $finding) {
            if (! is_array($finding)) {
                continue;
            }

            if (($finding['confidence'] ?? null) === 'split' && blank($finding['disagreement'] ?? null)) {
                throw JudgeException::invalidOutput([
                    $index => ['disagreement' => 'A split finding must have a disagreement'],
                ]);
            }
        }

        return $findings;
    }
}
