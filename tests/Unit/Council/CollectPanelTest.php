<?php

declare(strict_types=1);

use App\Ai\Agents\Panelist;
use App\Council\PanelResponse;

it('collects all three panelists on the happy path', function () {
    Panelist::fake(['critique one', 'critique two', 'critique three']);

    $responses = orchestrator()->deliberateOn('SPEC');

    expect($responses)->toHaveCount(3)
        ->and(collect($responses)->every(fn(PanelResponse $r) => $r->ok))->toBeTrue();
});

it('retries a failed panelist once and succeeds on retry', function () {
    $calls = 0;
    Panelist::fake(function () use (&$calls) {
        $calls++;
        if ($calls === 1) {
            throw new RuntimeException('transient');
        }

        return 'critique';
    });

    $responses = orchestrator()->deliberateOn('SPEC');

    expect(collect($responses)->every(fn(PanelResponse $r) => $r->ok))->toBeTrue()
        ->and($calls)->toBe(4); // 1 fail + 1 retry + 2 more panelists
});

it('marks a panelist failed when both attempts fail', function () {
    $calls = 0;
    Panelist::fake(function () use (&$calls) {
        $calls++;
        // first panelist (calls 1 & 2) always fails; later panelists succeed
        if ($calls <= 2) {
            throw new RuntimeException('down');
        }

        return 'critique';
    });

    $responses = collect(orchestrator()->deliberateOn('SPEC'));

    expect($responses->first()->ok)->toBeFalse()
        ->and($responses->skip(1)->every(fn(PanelResponse $r) => $r->ok))->toBeTrue();
});
