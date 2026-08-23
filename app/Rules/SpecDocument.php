<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use JsonException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Gates spec submissions (pasted or uploaded) before any paid model call:
 * byte size, valid UTF-8, and YAML/JSON mapping parseability.
 */
final class SpecDocument implements ValidationRule
{
    private const int MAX_BYTES = 5 * 1024 * 1024;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $spec = $value instanceof UploadedFile ? $value->getContent() : $value;
        if (! is_string($spec) || $spec === '') {
            $fail('The :attribute must be a specification document.');
            return;
        }

        // '8bit' counts bytes, not characters — a multibyte document must not
        // slip past the 5 MiB cap. (Pint's mb_str_functions rewrites a bare
        // strlen() here, so the byte semantics must be explicit.)
        if (mb_strlen($spec, '8bit') > self::MAX_BYTES) {
            $fail('The :attribute may not be larger than 5 MiB.');
            return;
        }

        if (! mb_check_encoding($spec, 'UTF-8')) {
            $fail('The :attribute must be valid UTF-8 text.');
            return;
        }

        $parsed = $this->parse($spec);
        // A top-level sequence ([] / "- value") parses but cannot be an
        // OpenAPI document; only mappings may reach the paid panel.
        if (! is_array($parsed) || array_is_list($parsed)) {
            $fail('The :attribute must be a parseable YAML or JSON mapping.');
        }
    }

    private function parse(string $spec): mixed
    {
        try {
            return json_decode($spec, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            try {
                return Yaml::parse($spec);
            } catch (ParseException) {
                return null;
            }
        }
    }
}
