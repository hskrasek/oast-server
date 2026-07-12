<x-app-layout title="Review {{ $review->id }} — oast">
    <section class="o-app-page" x-data="reviewWorkspace({
        eventsUrl: @js(route('app.reviews.events', $review->id), JSON_UNESCAPED_SLASHES),
        status: @js($review->status),
        findings: @js($review->findings ?? [], JSON_UNESCAPED_SLASHES),
        spec: @js((string) $review->spec, JSON_UNESCAPED_SLASHES)
    })" x-init="init" @alpine:destroy.window="destroy">
        <header class="o-page-head">
            <div><p class="o-label">review #{{ $review->id }}</p><h1 class="o-headline">{{ $review->spec_ref ?: 'Pasted specification' }}</h1></div>
            <div class="o-page-actions">
                <span class="o-status" :class="`o-status-${status}`" x-text="({queued:'Queued',running:'Running',judging:'Judging',complete:'Complete',error:'Failed'})[status]"></span>
                @can('delete', $review)
                    <form method="POST" action="{{ route('app.reviews.destroy', $review->id) }}">@csrf @method('DELETE')
                        <button class="o-btn o-btn-danger" type="submit" data-confirm="Delete this review and its retained specification permanently?">Delete review</button>
                    </form>
                @endcan
            </div>
        </header>

        <div class="o-stream-banner" x-show="connection === 'loading'">Connecting to live progress…</div>
        <div class="o-stream-banner" x-show="connection === 'reconnecting'">Connection lost. Reconnecting and replaying missed events…</div>
        <div class="o-stream-banner o-stream-banner-error" x-show="connection === 'disconnected'">Live updates disconnected. Reload to recover from persisted state.</div>

        <section x-show="status === 'queued' || status === 'running' || status === 'judging'" class="o-progress-panel" aria-live="polite">
            <h2 class="o-title">Council progress</h2>
            <ol><template x-for="(event, index) in events" :key="index"><li><span x-text="event.name"></span><strong x-text="event.data.model || event.data.stage || ''"></strong></li></template></ol>
            <p x-show="events.length === 0">The review is queued; panel activity will appear here.</p>
        </section>

        <section x-show="status === 'error'" class="o-state-card o-stream-banner-error">
            <h2 class="o-title">Review failed</h2><p>{{ $review->error ?: 'The review could not complete. Start another review or inspect worker logs.' }}</p>
        </section>

        <section x-show="status === 'complete'" class="o-report-grid">
            <div class="o-findings-pane">
                <template x-for="group in [{key:'blocker',label:'Blockers'},{key:'should-fix',label:'Should fix'},{key:'consider',label:'Consider'}]" :key="group.key">
                    <section x-show="groupedFindings[group.key].length > 0" class="o-finding-group">
                        <h2 class="o-label" x-text="group.label"></h2>
                        <template x-for="finding in groupedFindings[group.key]" :key="`${finding.location}:${finding.title}`">
                            <article class="o-finding" @click="selectFinding(finding)">
                                <div class="o-finding-header"><span class="o-sev" :class="`o-sev-${finding.severity}`" x-text="finding.severity"></span><h3 class="o-title" x-text="finding.title"></h3><button type="button" class="o-loc" x-text="finding.location"></button></div>
                                <div class="o-finding-body"><p x-text="finding.finding"></p><p class="o-finding-why" x-text="finding.why_it_matters"></p><p class="o-suggest" x-text="finding.suggested_change"></p></div>
                            </article>
                        </template>
                    </section>
                </template>
                <div class="o-state-card" x-show="findings.length === 0">The Council completed without findings.</div>
            </div>
            <aside class="o-source-pane">
                <h2 class="o-label">Inline specification</h2>
                <p class="o-source-fallback" x-show="sourceMessage" x-text="sourceMessage"></p>
                <pre><template x-for="(line, index) in lines" :key="index"><span class="o-source-line" :class="{'is-highlighted': isHighlighted(index + 1)}"><i x-text="index + 1"></i><b x-text="line || ' '"></b></span></template></pre>
            </aside>
        </section>
    </section>
</x-app-layout>
