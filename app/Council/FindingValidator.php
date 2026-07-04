<?php

declare(strict_types=1);

namespace App\Council;

use App\Council\Exceptions\JudgeException;

final class FindingValidator
{
    /**
     * Validate the judge's raw (untrusted) findings, enforcing the rules the
     * structured-output schema cannot: a split finding must carry a disagreement,
     * and a location must be a JSON Pointer fragment (providers apply `pattern`
     * inconsistently, so it is enforced here where failure triggers a re-prompt).
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

            $location = $finding['location'] ?? null;

            if (! is_string($location) || ! str_starts_with($location, '#/')) {
                throw JudgeException::invalidOutput([
                    $index => ['location' => 'location must be a JSON Pointer fragment starting with #/ (e.g. #/paths/~1orders~1{id}/get)'],
                ]);
            }
        }

        return $findings;
    }
}
