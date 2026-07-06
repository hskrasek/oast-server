@extends('site.layout')

@section('title', $publication->specName . ' - oast')

@section('content')
<div class="mx-auto max-w-[880px] px-6 py-16 flex flex-col gap-10">
    <!-- Review Header -->
    <header class="flex flex-col gap-4">
        <p class="o-label">{{ $publication->specName }} · {{ $publication->dimension }}</p>
        <h1 class="o-headline">{{ $publication->headline }}</h1>
    </header>

    <!-- Commentary -->
    @if (filled($publication->commentaryMd))
    <section class="o-body-serif [&_em]:italic [&_a]:underline">
        {!! Str::markdown($publication->commentaryMd, ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}
    </section>
    @endif

    <!-- Meta Strip -->
    <div class="o-meta">
        <div class="o-meta-cell">
            <span class="o-meta-label">Spec</span>
            <span class="o-meta-value"><a href="{{ $publication->specSourceUrl }}" class="hover:underline">{{ $publication->specName }}</a></span>
        </div>
        <div class="o-meta-cell">
            <span class="o-meta-label">License</span>
            <span class="o-meta-value">{{ $publication->specLicense }}</span>
        </div>
        <div class="o-meta-cell">
            <span class="o-meta-label">Dimension</span>
            <span class="o-meta-value">{{ $publication->dimension }}</span>
        </div>
        @if ($cost = $publication->totalCostUsd())
        <div class="o-meta-cell">
            <span class="o-meta-label">Cost</span>
            <span class="o-meta-value is-accent">${{ number_format($cost, 2) }}</span>
        </div>
        @endif
        <div class="o-meta-cell">
            <span class="o-meta-label">Reviewed</span>
            <span class="o-meta-value">{{ $publication->reviewedAt->format('M d, Y') }}</span>
        </div>
        <div class="o-meta-cell">
            <span class="o-meta-label">Panelists</span>
            <span class="o-meta-value">{{ implode(' · ', $publication->panelists) }}</span>
        </div>
        <div class="o-meta-cell">
            <span class="o-meta-label">Judge</span>
            <span class="o-meta-value">{{ $publication->judge }}</span>
        </div>
    </div>

    <!-- Findings index table -->
    <section class="flex flex-col gap-5">
        <h2 class="o-label">Findings</h2>
        <div class="o-table">
            <div class="o-table-head">
                <div>severity</div>
                <div>finding</div>
                <div>location</div>
                <div class="text-right">confidence</div>
            </div>
            @foreach ($publication->findings as $i => $finding)
            <a href="#finding-{{ $i + 1 }}" class="o-table-row" data-severity="{{ $finding['severity'] ?? '' }}">
                <span class="o-sev o-sev-{{ $finding['severity'] ?? 'consider' }}">{{ $finding['severity'] ?? '' }}</span>
                <span class="o-finding-title">{{ $finding['title'] ?? '' }}</span>
                <span class="o-loc" title="{{ $finding['location'] ?? '' }}">{{ $finding['location'] ?? '' }}</span>
                <span class="flex justify-end">
                    @php($conf = $finding['confidence'] ?? 'lone-flag')
                    <span class="o-conf o-conf-{{ $conf }} o-sev-{{ $finding['severity'] ?? 'consider' }}"><span class="o-conf-text">{{ $conf }}</span></span>
                </span>
            </a>
            @endforeach
        </div>
    </section>

    <!-- Finding detail -->
    <section class="flex flex-col gap-6">
        @foreach ($publication->findings as $i => $finding)
        <article id="finding-{{ $i + 1 }}" class="o-finding">
            <header class="o-finding-header">
                <div class="flex items-center gap-3">
                    <span class="o-sev o-sev-{{ $finding['severity'] ?? 'consider' }}">{{ $finding['severity'] ?? '' }}</span>
                    @php($conf = $finding['confidence'] ?? 'lone-flag')
                    @if ($conf === 'split')
                    <span class="o-split-badge">split</span>
                    @else
                    <span class="o-conf o-conf-{{ $conf }} o-sev-{{ $finding['severity'] ?? 'consider' }}"><span class="o-conf-text">{{ $conf }}</span></span>
                    @endif
                </div>
                <h3 class="o-title">{{ $finding['title'] ?? '' }}</h3>
                <code class="o-loc !whitespace-normal">{{ $finding['location'] ?? '' }}</code>
            </header>

            <div class="o-finding-body">
                <p>{{ $finding['finding'] ?? '' }}</p>

                @if ($finding['why_it_matters'] ?? null)
                <p class="o-finding-why">{{ $finding['why_it_matters'] }}</p>
                @endif

                @if (filled($finding['disagreement'] ?? null))
                @if (($finding['confidence'] ?? null) === 'split')
                <blockquote data-split class="o-disagreement">
                    {{ $finding['disagreement'] }}
                </blockquote>
                <p class="o-split-foot">→ this is a judgment call. The judge doesn't break the tie — you do.</p>
                @else
                <blockquote data-disagreement class="o-disagreement">
                    {{ $finding['disagreement'] }}
                </blockquote>
                @endif
                @endif

                @if ($finding['suggested_change'] ?? null)
                <p class="o-suggest">→ {{ $finding['suggested_change'] }}</p>
                @endif
            </div>
        </article>
        @endforeach
    </section>
</div>
@endsection
