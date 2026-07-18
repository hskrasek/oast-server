<?php

declare(strict_types=1);

use App\Reviews\ValidSpec;
use Illuminate\Support\Facades\Validator;

function specErrors(mixed $spec): array
{
    return Validator::make(['spec' => $spec], ['spec' => [new ValidSpec]])->errors()->get('spec');
}

it('accepts a yaml mapping and a json object', function (): void {
    expect(specErrors("openapi: 3.1.0\ninfo:\n  title: Pets\n"))->toBeEmpty()
        ->and(specErrors('{"openapi": "3.1.0", "paths": {}}'))->toBeEmpty();
});

it('rejects a spec above the configured byte limit', function (): void {
    config()->set('oast.max_spec_bytes', 16);

    expect(specErrors("openapi: 3.1.0\ninfo: {}\n"))->not->toBeEmpty();
});

it('rejects bytes that are not valid utf-8', function (): void {
    expect(specErrors("openapi: \xC3\x28"))->not->toBeEmpty();
});

it('rejects content that does not parse to a yaml or json document', function (): void {
    expect(specErrors('just some prose about an api'))->not->toBeEmpty()
        ->and(specErrors("key: [unclosed\n  broken"))->not->toBeEmpty();
});

it('rejects non-string values', function (): void {
    expect(specErrors(['not' => 'a string']))->not->toBeEmpty();
});
