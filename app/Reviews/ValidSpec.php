<?php

declare(strict_types=1);

namespace App\Reviews;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class ValidSpec implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be a string.');

            return;
        }

        $max = config()->integer('oast.max_spec_bytes');
        if (mb_strlen($value, '8bit') > $max) {
            $fail(sprintf('The :attribute may not be larger than %d bytes.', $max));

            return;
        }

        if (! mb_check_encoding($value, 'UTF-8')) {
            $fail('The :attribute must be valid UTF-8.');

            return;
        }

        try {
            $document = Yaml::parse($value);
        } catch (ParseException) {
            $document = null;
        }

        // ponytail: parseability only — no OpenAPI-version gate; the council
        // reviews the raw text, so anything mapping-shaped is worth accepting.
        if (! is_array($document)) {
            $fail('The :attribute must be a YAML or JSON document.');
        }
    }
}
