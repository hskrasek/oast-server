<x-layouts.app title="Page expired - oast">
<x-site.page class="py-24 flex flex-col gap-6">
    <header class="flex flex-col gap-4 max-w-[65ch]">
        <p class="o-label">419</p>
        <h1 class="o-headline">That form went stale.</h1>
    </header>
    <p class="max-w-[46ch]">The page sat open long enough for its session to expire — nothing was submitted. Head back and try again; it'll work this time.</p>
    <p class="flex gap-6">
        <a href="{{ route('home') }}#notify" class="font-mono text-mono-ui text-muted underline">← back to the form</a>
    </p>
</x-site.page>
</x-layouts.app>
