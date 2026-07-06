@props(['publication', 'showDate' => false])

@php
    $counts = $publication->findingCounts();
    $countParts = array_filter([
        $counts['blocker'] ? $counts['blocker'] . ' blocker' . ($counts['blocker'] > 1 ? 's' : '') : null,
        $counts['should-fix'] ? $counts['should-fix'] . ' should-fix' : null,
        $counts['consider'] ? $counts['consider'] . ' consider' : null,
    ]);
    $cost = $publication->totalCostUsd();
@endphp

<a href="{{ route('reviews.show', $publication->slug) }}" {{ $attributes->merge(['class' => 'o-card']) }}>
    <span class="o-card-kicker">
        <span>{{ $publication->specName }} · {{ $publication->dimension }}</span>
        @if ($showDate)
        <span class="normal-case tracking-normal">{{ $publication->reviewedAt->format('M d, Y') }}</span>
        @endif
    </span>
    <span class="o-card-headline">{{ $publication->headline }}</span>
    <span class="o-card-foot">
        <span>{{ implode(' · ', $countParts) }}</span>
        @if ($cost !== null)
        <span class="o-card-cost">${{ number_format($cost, 2) }}</span>
        @endif
    </span>
</a>
