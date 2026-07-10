<x-layouts.app title="Subscription Confirmed - oast">
<x-site.page class="py-24 flex flex-col gap-6">
    <h1 class="o-headline">Subscription confirmed</h1>
    <div class="o-confirm-box max-w-[460px]">
        <span class="font-semibold">→</span>
        <span>Subscription confirmed for {{ $email }}. See you at launch.</span>
    </div>
    <p><a href="{{ route('reviews.index') }}" class="font-mono text-mono-ui text-muted">read the published reviews →</a></p>
</x-site.page>
</x-layouts.app>
