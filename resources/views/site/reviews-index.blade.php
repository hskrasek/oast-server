@extends('site.layout')

@section('title', 'Reviews - oast')

@section('content')
<div class="mx-auto max-w-[880px] px-6 py-16 flex flex-col gap-8">
    <header class="flex flex-col gap-3">
        <h1 class="o-headline">Published Reviews</h1>
        <p class="o-mono-small">real Council output · real specs · real costs</p>
    </header>

    <div class="grid gap-4 md:grid-cols-2">
        @foreach ($publications as $publication)
        @php($counts = $publication->findingCounts())
        <a href="{{ route('reviews.show', $publication->slug) }}" class="o-card">
            <span class="o-card-kicker">
                <span>{{ $publication->specName }} · {{ $publication->dimension }}</span>
                <span class="normal-case tracking-normal">{{ $publication->reviewedAt->format('M d, Y') }}</span>
            </span>
            <span class="o-card-headline">{{ $publication->headline }}</span>
            <span class="o-card-foot">
                <span>{{ $counts['blocker'] }} blocker · {{ $counts['should-fix'] }} should-fix · {{ $counts['consider'] }} consider</span>
                @if ($cost = $publication->totalCostUsd())
                <span class="o-card-cost">${{ number_format($cost, 2) }}</span>
                @endif
            </span>
        </a>
        @endforeach
    </div>
</div>
@endsection
