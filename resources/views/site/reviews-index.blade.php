@extends('site.layout')

@section('title', 'Reviews - oast')

@section('content')
<div class="max-w-6xl mx-auto px-4 py-12">
    <h1 class="text-4xl font-bold mb-8">Published Reviews</h1>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        @foreach ($publications as $publication)
        <a href="{{ route('reviews.show', $publication->slug) }}" class="border border-gray-300 p-6 rounded hover:shadow-lg transition">
            <h2 class="text-2xl font-bold mb-2">{{ $publication->specName }}</h2>
            <p class="text-gray-600 mb-4">{{ $publication->headline }}</p>

            <div class="space-y-2 text-sm text-gray-500">
                <p><strong>Dimension:</strong> {{ $publication->dimension }}</p>
                @php
                    $counts = $publication->findingCounts();
                @endphp
                <p><strong>Findings:</strong> {{ $counts['blocker'] }} blocker, {{ $counts['should-fix'] }} should-fix, {{ $counts['consider'] }} consider</p>
                @if ($cost = $publication->totalCostUsd())
                <p><strong>Cost:</strong> ${{ number_format($cost, 2) }}</p>
                @endif
                <p><strong>Reviewed:</strong> {{ $publication->reviewedAt->format('M d, Y') }}</p>
            </div>
        </a>
        @endforeach
    </div>
</div>
@endsection
