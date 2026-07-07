<?php

declare(strict_types=1);

it('produces a stable 8-char hex og hash', function (): void {
    $publication = ogPublicationFixture();

    expect($publication->ogHash())->toMatch('/^[a-f0-9]{8}$/')
        ->and($publication->ogHash())->toBe($publication->ogHash());
});

it('changes the og hash when the headline changes', function (): void {
    expect(ogPublicationFixture(['headline' => 'One'])->ogHash())
        ->not->toBe(ogPublicationFixture(['headline' => 'Two'])->ogHash());
});

it('builds the og image url from slug and hash', function (): void {
    $publication = ogPublicationFixture();

    expect($publication->ogImageUrl())
        ->toBe("/og/train-travel-domain-modeling-{$publication->ogHash()}.png");
});
