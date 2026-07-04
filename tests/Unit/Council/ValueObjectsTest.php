<?php

declare(strict_types=1);

use App\Council\ReviewMode;

it('builds review mode from string', function (): void {
    expect(ReviewMode::from('council'))->toBe(ReviewMode::Council)
        ->and(ReviewMode::from('baseline'))->toBe(ReviewMode::Baseline);
});
