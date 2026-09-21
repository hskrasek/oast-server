<?php

declare(strict_types=1);

it('leaves the designer model unset by default so the action falls back to the judge', function (): void {
    expect(config('oast.designer'))->toBeNull()
        ->and(config('oast.judge'))->toBeString();
});
