<x-layouts.app title="Not found - oast">
<x-site.page class="py-24 flex flex-col gap-6">
    <header class="flex flex-col gap-4 max-w-[65ch]">
        <p class="o-label">404</p>
        <h1 class="o-headline">This page didn't survive the roast.</h1>
    </header>
    <p class="max-w-[46ch]">Whatever was here either moved or never existed. The kiln keeps no secrets.</p>
    <p class="flex gap-6">
        <a href="{{ route('home') }}" class="font-mono text-mono-ui text-muted underline">← back home</a>
        <a href="{{ route('reviews.index') }}" class="font-mono text-mono-ui text-muted underline">read the published reviews →</a>
    </p>
</x-site.page>
</x-layouts.app>
