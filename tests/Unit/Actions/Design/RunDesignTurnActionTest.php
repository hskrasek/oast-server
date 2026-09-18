<?php

declare(strict_types=1);

use App\Actions\Design\RunDesignTurnAction;
use App\Ai\Agents\Designer;
use App\Council\Exceptions\DesignerException;

const VALID_SPEC = "openapi: 3.1.0\ninfo:\n  title: Library\n  version: 0.1.0\n";

function designHistory(int $count): array
{
    return array_map(
        fn(int $i): array => ['role' => $i % 2 === 0 ? 'user' : 'assistant', 'content' => "m{$i}"],
        range(1, $count),
    );
}

it('returns the drafted spec and reply', function (): void {
    Designer::fake([['spec' => VALID_SPEC, 'reply' => 'Started the spec.']]);

    $turn = app(RunDesignTurnAction::class)('', 'library API', []);

    expect($turn)->toBe(['spec' => VALID_SPEC, 'reply' => 'Started the spec.']);
});

it('retries once with the parse error when the spec is not YAML', function (): void {
    Designer::fake([
        ['spec' => "openapi: 3.1.0\ninfo: [unclosed", 'reply' => 'oops'],
        ['spec' => VALID_SPEC, 'reply' => 'fixed'],
    ]);

    $turn = app(RunDesignTurnAction::class)(VALID_SPEC, 'add books', []);

    expect($turn['reply'])->toBe('fixed');
    Designer::assertPrompted(fn(object $prompt): bool => str_contains($prompt->prompt, 'Your previous spec did not parse'));
});

it('throws after two invalid specs', function (): void {
    Designer::fake([
        ['spec' => 'info: [unclosed', 'reply' => 'a'],
        ['spec' => "title: no openapi key\n", 'reply' => 'b'],
    ]);

    expect(fn() => app(RunDesignTurnAction::class)(VALID_SPEC, 'add books', []))
        ->toThrow(DesignerException::class, 'The designer produced a spec that does not parse');
});

it('rejects a parseable document without a top-level openapi key', function (): void {
    Designer::fake([
        ['spec' => "title: nope\n", 'reply' => 'a'],
        ['spec' => "title: nope\n", 'reply' => 'b'],
    ]);

    try {
        app(RunDesignTurnAction::class)(VALID_SPEC, 'x', []);
        $this->fail('expected DesignerException');
    } catch (DesignerException $e) {
        expect($e->errors)->toHaveCount(2)
            ->and($e->errors[0])->toContain('openapi');
    }
});

it('truncates history to the last 20 messages before prompting', function (): void {
    Designer::fake([['spec' => VALID_SPEC, 'reply' => 'ok']]);

    app(RunDesignTurnAction::class)(VALID_SPEC, 'x', designHistory(25));

    Designer::assertPrompted(fn(object $prompt): bool => ! str_contains($prompt->prompt, 'USER: m5') && str_contains($prompt->prompt, 'USER: m6'));
});

it('uses the judge model when no designer model is configured', function (): void {
    config(['oast.designer' => null, 'oast.judge' => 'judge/strong']);

    expect(app(RunDesignTurnAction::class)->model())->toBe('judge/strong');
});

it('prefers the configured designer model', function (): void {
    config(['oast.designer' => 'draft/fast']);

    expect(app(RunDesignTurnAction::class)->model())->toBe('draft/fast');
});
