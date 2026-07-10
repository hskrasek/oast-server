<x-layouts.app
    :title="$publication->specName . ' - oast'"
    :og-title="$publication->headline"
    :meta-description="$publication->specName . ' · ' . $publication->dimension . ' — reviewed by the Council: ' . count($publication->findings) . ' findings with severity, confidence, and disagreements.'"
    :og-image="url($publication->ogImageUrl())"
>
<x-site.page class="py-16 flex flex-col gap-10">
    <!-- Review Header -->
    <header class="flex flex-col gap-4">
        <p class="o-label">{{ $publication->specName }} · {{ $publication->dimension }}</p>
        <h1 class="o-headline">{{ $publication->headline }}</h1>
    </header>

    <!-- Commentary -->
    @if (filled($publication->commentaryMd))
    <section class="[&_em]:italic [&_a]:underline">
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
            <span class="o-meta-value"><x-site.panelists :models="$publication->panelists" /></span>
        </div>
        <div class="o-meta-cell">
            <span class="o-meta-label">Judge</span>
            <span class="o-meta-value" title="{{ $publication->judge }}">{{ \App\Site\ModelDisplay::short($publication->judge) }}</span>
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
            @foreach ($publication->findings as $finding)
            <a href="#finding-{{ $loop->iteration }}" class="o-table-row" data-severity="{{ $finding['severity'] ?? '' }}">
                <x-site.severity :severity="$finding['severity'] ?? 'consider'" />
                <span class="o-finding-title">{{ $finding['title'] ?? '' }}</span>
                <span class="o-loc" title="{{ $finding['location'] ?? '' }}">{{ $finding['location'] ?? '' }}</span>
                <span class="flex justify-end">
                    <x-site.confidence :confidence="$finding['confidence'] ?? 'lone-flag'" :severity="$finding['severity'] ?? 'consider'" />
                </span>
            </a>
            @endforeach
        </div>
    </section>

    <!-- Finding detail -->
    <section class="flex flex-col gap-6">
        @foreach ($publication->findings as $finding)
        <article id="finding-{{ $loop->iteration }}" class="o-finding">
            <header class="o-finding-header">
                <div class="flex items-center gap-3">
                    <x-site.severity :severity="$finding['severity'] ?? 'consider'" />
                    <x-site.confidence :confidence="$finding['confidence'] ?? 'lone-flag'" :severity="$finding['severity'] ?? 'consider'" />
                </div>
                <h3 class="o-title">{{ $finding['title'] ?? '' }}</h3>
                <code class="o-loc whitespace-normal">{{ $finding['location'] ?? '' }}</code>
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
</x-site.page>
</x-layouts.app>
