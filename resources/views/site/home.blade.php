@extends('site.layout')

@section('content')
<!-- Hero -->
<section class="o-hero">
    <div class="mx-auto max-w-[1160px] px-6 lg:px-12 py-20 lg:py-24 grid gap-14 lg:grid-cols-[1fr_540px] items-center">
        <div class="flex flex-col gap-7">
            <h1 class="o-display">Your API design, argued over by a panel that never gets tired.</h1>
            <p class="o-body-serif max-w-[46ch]">Spectral tells you an operationId is missing. Nobody tells you your resource model leaks the database, your payment flow can't be retried safely, or your 'REST' API is RPC in a trench coat — until clients depend on it.</p>
            <div class="flex items-center gap-4">
                <a href="#notify" class="o-btn">Notify me</a>
                <a href="{{ route('reviews.index') }}" class="o-btn o-btn-outline">read the reviews →</a>
            </div>
        </div>
        <div class="o-docket">
            <div class="o-docket-bar">
                <span class="o-docket-dot"></span><span class="o-docket-dot"></span><span class="o-docket-dot"></span>
                <span class="ml-3">$ oast roast ./slack-web-api.yaml</span>
                <span class="ml-auto text-[10.5px] text-text-4">oast v0.4</span>
            </div>
            <div class="o-docket-quote">
                <span class="o-docket-model text-ember">claude-sonnet</span>
                <span class="o-docket-text">&ldquo;The entire API is an RPC method catalog rather than a resource model.&rdquo;</span>
            </div>
            <div class="o-docket-quote">
                <span class="o-docket-model text-amber">gpt-5.5</span>
                <span class="o-docket-text">&ldquo;Payment modeled as a write-only singleton action — no retrieval, no history.&rdquo;</span>
            </div>
            <div class="o-docket-quote">
                <span class="o-docket-model text-copper">glm-5.2</span>
                <span class="o-docket-text">&ldquo;Two competing pagination styles reveal API evolution, not design.&rdquo;</span>
            </div>
            <div class="o-docket-foot">
                <span>judge: claude-opus → 16 findings · 3 consensus</span>
                <span class="text-text-1">$2.59 · 3m41s</span>
            </div>
        </div>
    </div>
</section>

<div class="mx-auto max-w-[880px] px-6 flex flex-col gap-20 py-20">
    <!-- How It Works -->
    <section class="flex flex-col gap-6">
        <h2 class="o-label">How it works</h2>
        <ol class="flex flex-col gap-5">
            <li class="flex gap-4 items-baseline">
                <span class="o-mono-ui text-ember">01</span>
                <p class="o-body-serif">Three frontier models critique your spec independently — no shared rubric, no groupthink.</p>
            </li>
            <li class="flex gap-4 items-baseline">
                <span class="o-mono-ui text-ember">02</span>
                <p class="o-body-serif">A judge model organizes their critiques into findings — it never adds its own.</p>
            </li>
            <li class="flex gap-4 items-baseline">
                <span class="o-mono-ui text-ember">03</span>
                <p class="o-body-serif">Every finding carries severity (blocker / should-fix / consider) and confidence (consensus / majority / split / lone-flag).</p>
            </li>
        </ol>
    </section>

    <!-- Split Explainer -->
    <section class="flex flex-col gap-6">
        <h2 class="o-label">When the panel disagrees</h2>
        <p class="o-body-serif">When the panel disagrees, you see both sides. A split on a blocker is the most valuable thing we can show you.</p>
        <div class="o-finding">
            <div class="o-finding-header">
                <div class="flex items-center gap-2.5">
                    <span class="o-split-badge">split</span>
                    <span class="o-mono-small text-text-4">the panel disagrees — both positions, unaveraged</span>
                </div>
                <div class="o-title">UI-surface concepts (views, dialogs) exposed as side-effecting GETs</div>
            </div>
            <div class="grid md:grid-cols-[1fr_1px_1fr]">
                <div class="flex flex-col gap-2.5 p-5">
                    <span class="flex items-center gap-2">
                        <span class="inline-block size-[7px] rounded-full bg-voice-a"></span>
                        <span class="o-docket-model text-text-1">claude-sonnet</span>
                    </span>
                    <span class="o-docket-text">&ldquo;A view has identity and a lifecycle — open, push, update, close. It's a resource being treated as a fire-and-forget GET.&rdquo;</span>
                </div>
                <div class="hidden md:block bg-border-1"></div>
                <div class="flex flex-col gap-2.5 p-5 bg-voice-b-wash">
                    <span class="flex items-center gap-2">
                        <span class="inline-block size-[7px] rounded-full bg-voice-b"></span>
                        <span class="o-docket-model text-text-1">glm-5.2</span>
                    </span>
                    <span class="o-docket-text">&ldquo;Modeling modal and workflow UI interactions as event-driven commands is not necessarily wrong for a platform API.&rdquo;</span>
                </div>
            </div>
            <div class="px-5 py-3.5 border-t border-border-1">
                <span class="o-split-foot">→ this is a judgment call. The judge doesn't break the tie — you do.</span>
            </div>
        </div>
    </section>

    <!-- Featured Reviews -->
    @if ($featured)
    <section class="flex flex-col gap-6">
        <h2 class="o-label">Published reviews</h2>
        <div class="grid gap-4 md:grid-cols-3">
            @foreach ($featured as $publication)
            <x-site.review-card :publication="$publication" />
            @endforeach
        </div>
    </section>
    @endif

    <!-- Roadmap -->
    <section id="roadmap" class="flex flex-col gap-6">
        <h2 class="o-label">What's coming</h2>
        <ul class="flex flex-col gap-4">
            <li class="flex gap-4 items-baseline">
                <span class="o-mono-ui text-text-1">CLI</span>
                <span class="o-body-serif"><code class="o-mono-ui">oast roast</code> in your terminal</span>
            </li>
            <li class="flex gap-4 items-baseline">
                <span class="o-mono-ui text-text-1">CI gate</span>
                <span class="o-body-serif">blockers fail the pipeline</span>
            </li>
            <li class="flex gap-4 items-baseline">
                <span class="o-mono-ui text-text-1">hosted</span>
                <span class="o-body-serif">managed keys, teams</span>
            </li>
        </ul>
    </section>

    <!-- Signup -->
    <section id="notify" class="flex flex-col gap-5 pb-8">
        <h2 class="o-label">Get the launch</h2>
        <form method="POST" action="{{ route('subscribe') }}" class="flex flex-col gap-2.5 max-w-[460px]">
            @csrf
            <div class="flex gap-2.5">
                <input type="email" name="email" required placeholder="you@company.dev" class="o-input flex-1 max-w-[280px]">
                <input type="text" name="website" tabindex="-1" autocomplete="off" class="hidden" aria-hidden="true">
                <button type="submit" class="o-btn">Notify me</button>
            </div>
            <p class="o-mono-small text-text-4">We're building in the open. Leave an email, get the launch.</p>
            @if (session('status'))
            <p role="status" class="o-confirm-box"><span class="font-semibold">→</span> {{ session('status') }}</p>
            @endif
            @error('email')
            <p role="alert" class="o-mono-small text-ember">{{ $message }}</p>
            @enderror
        </form>
    </section>
</div>
@endsection
