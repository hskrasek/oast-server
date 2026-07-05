@extends('site.layout')

@section('title', $publication->specName . ' - oast')

@section('content')
<div class="max-w-6xl mx-auto px-4 py-12">
    <!-- Review Header -->
    <h1 class="text-4xl font-bold mb-2">{{ $publication->headline }}</h1>
    <p class="text-gray-600 mb-8">{{ $publication->specName }}</p>

    <!-- Commentary -->
    @if (filled($publication->commentaryMd))
    <section class="mb-8 prose prose-sm">
        {!! Str::markdown($publication->commentaryMd, ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}
    </section>
    @endif

    <!-- Meta Strip -->
    <div class="bg-gray-100 p-6 rounded mb-12">
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
            <div>
                <p class="text-gray-600">Spec</p>
                <p class="font-mono"><a href="{{ $publication->specSourceUrl }}" class="hover:underline">{{ $publication->specName }}</a></p>
            </div>
            <div>
                <p class="text-gray-600">License</p>
                <p>{{ $publication->specLicense }}</p>
            </div>
            <div>
                <p class="text-gray-600">Dimension</p>
                <p>{{ $publication->dimension }}</p>
            </div>
            @if ($cost = $publication->totalCostUsd())
            <div>
                <p class="text-gray-600">Cost</p>
                <p class="font-mono">${{ number_format($cost, 2) }}</p>
            </div>
            @endif
            <div>
                <p class="text-gray-600">Reviewed</p>
                <p>{{ $publication->reviewedAt->format('M d, Y') }}</p>
            </div>
            <div>
                <p class="text-gray-600">Panelists</p>
                <p class="font-mono text-xs">{{ implode(', ', $publication->panelists) }}</p>
            </div>
            <div>
                <p class="text-gray-600">Judge</p>
                <p class="font-mono text-xs">{{ $publication->judge }}</p>
            </div>
        </div>
    </div>

    <!-- Findings -->
    <section>
        <h2 class="text-2xl font-bold mb-8">Findings</h2>

        <div class="space-y-8">
            @foreach ($publication->findings as $finding)
            <article class="border-l-4 border-gray-300 pl-6">
                <header class="mb-4">
                    <div class="flex gap-3 mb-2">
                        <span class="font-mono text-sm px-2 py-1 bg-gray-200 rounded">{{ $finding['severity'] ?? '' }}</span>
                        <span class="font-mono text-sm px-2 py-1 bg-gray-200 rounded">{{ $finding['confidence'] ?? '' }}</span>
                    </div>
                    <h3 class="text-xl font-bold mb-2">{{ $finding['title'] ?? '' }}</h3>
                    <code class="font-mono text-sm text-gray-600">{{ $finding['location'] ?? '' }}</code>
                </header>

                <p class="mb-4">{{ $finding['finding'] ?? '' }}</p>

                @if ($finding['why_it_matters'] ?? null)
                <p class="italic text-gray-600 mb-4">{{ $finding['why_it_matters'] }}</p>
                @endif

                @if (filled($finding['disagreement'] ?? null))
                @if (($finding['confidence'] ?? null) === 'split')
                <blockquote data-split class="bg-gray-50 border-l-4 border-gray-400 pl-4 py-2 mb-4 italic">
                    {{ $finding['disagreement'] }}
                </blockquote>
                @else
                <blockquote data-disagreement class="bg-gray-50 border-l-4 border-gray-400 pl-4 py-2 mb-4 italic">
                    {{ $finding['disagreement'] }}
                </blockquote>
                @endif
                @endif

                @if ($finding['suggested_change'] ?? null)
                <p class="text-gray-700">{{ $finding['suggested_change'] }}</p>
                @endif
            </article>
            @endforeach
        </div>
    </section>
</div>
@endsection
