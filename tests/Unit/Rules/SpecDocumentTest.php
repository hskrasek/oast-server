<?php

declare(strict_types=1);

use App\Rules\SpecDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;

function specDocumentPasses(mixed $value): bool
{
    return Validator::make(['spec' => $value], ['spec' => [new SpecDocument]])->passes();
}

it('accepts YAML and JSON mappings', function (): void {
    expect(specDocumentPasses("openapi: 3.1.0\ninfo:\n  title: Yes\n"))->toBeTrue()
        ->and(specDocumentPasses('{"openapi": "3.1.0"}'))->toBeTrue();
});

it('rejects invalid UTF-8 bytes', function (): void {
    expect(specDocumentPasses("openapi: 3.1.0\n\xC3\x28"))->toBeFalse();
});

it('rejects documents that do not parse to a mapping', function (): void {
    expect(specDocumentPasses('just some prose'))->toBeFalse()
        ->and(specDocumentPasses("k: v\n\tbroken"))->toBeFalse()
        ->and(specDocumentPasses('"a bare json string"'))->toBeFalse()
        ->and(specDocumentPasses('[]'))->toBeFalse()
        ->and(specDocumentPasses("- openapi\n- 3.1.0\n"))->toBeFalse();
});

it('rejects oversized and non-string values', function (): void {
    expect(specDocumentPasses(str_repeat('a', 5 * 1024 * 1024 + 1)))->toBeFalse()
        // Multibyte characters: byte count exceeds the cap even though the
        // character count stays under it.
        ->and(specDocumentPasses(str_repeat('é', 3 * 1024 * 1024)))->toBeFalse()
        ->and(specDocumentPasses(42))->toBeFalse();
});

it('validates uploaded file contents including empty uploads', function (): void {
    expect(specDocumentPasses(UploadedFile::fake()->createWithContent('a.yaml', 'openapi: 3.1.0')))->toBeTrue()
        ->and(specDocumentPasses(UploadedFile::fake()->createWithContent('a.bin', "\xFF\xFE\x00\x01")))->toBeFalse()
        ->and(specDocumentPasses(UploadedFile::fake()->createWithContent('empty.yaml', '')))->toBeFalse();
});
