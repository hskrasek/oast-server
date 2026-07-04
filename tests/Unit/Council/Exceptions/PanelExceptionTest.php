<?php

declare(strict_types=1);

use App\Council\Exceptions\PanelException;

// PanelException is no longer thrown by the async panel/judge pipeline (Task 5)
// — quorum failure is now recorded on the review row by PanelFinalizer — but the
// class stays for the HTTP problem+json render bootstrap/app.php still registers
// for it, wired up again once Task 6 rebuilds the synchronous-facing HTTP surface.

it('builds a quorum-not-met exception carrying the failed models', function (): void {
    $exception = PanelException::quorumNotMet(['a/one', 'b/two'], succeeded: 1, required: 2);

    expect($exception)->toBeInstanceOf(RuntimeException::class)
        ->and($exception->failedModels)->toBe(['a/one', 'b/two'])
        ->and($exception->getMessage())->toContain('1 panelist(s) succeeded, 2 required')
        ->and($exception->getMessage())->toContain('a/one, b/two');
});
