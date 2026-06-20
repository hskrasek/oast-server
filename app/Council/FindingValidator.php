<?php

declare(strict_types=1);

namespace App\Council;

use App\Council\Exceptions\JudgeException;

/**
 * @phpstan-type Finding array{
 *     confidence?: string,
 *     disagreement?: string,
 * }
 */
final class FindingValidator
{
    /**
     * @param array<array-key, Finding> $findings
     *
     * @return array<array-key, Finding>
     * @throws JudgeException
     */
    public function validate(array $findings): array
    {
        foreach ($findings as $index => $finding) {
            if (($finding['confidence'] ?? null) === 'split' && blank($finding['disagreement'] ?? null)) {
                throw JudgeException::invalidOutput([
                    $index => ['disagreement' => 'A split finding must have a disagreement'],
                ]);
            }
        }

        return $findings;
    }
}
