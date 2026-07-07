{{-- 1200×630 OG raster template for review pages. Rendered by the local-only
     /og/{slug} route and captured to public/og/{slug}.png — never served in prod. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    @vite('resources/css/app.css')
</head>
<body>
<div class="relative flex h-[630px] w-[1200px] flex-col justify-between overflow-hidden bg-surface px-16 pt-14 pb-[52px] before:absolute before:inset-0 before:content-[''] before:[background:var(--glow-hero)] after:absolute after:inset-x-0 after:bottom-0 after:h-[2px] after:content-[''] after:[background:var(--glow-horizon-line)]">
    <div class="relative flex items-baseline justify-between">
        <span class="o-wordmark text-[26px]">oast<em>.sh</em></span>
        <span class="font-mono text-[15px] font-medium uppercase tracking-label text-muted">api design review · {{ $publication->dimension }}</span>
    </div>
    <div class="relative flex max-w-[980px] flex-col gap-[22px]">
        <div class="font-serif text-[64px] leading-[1.12] text-ink [text-wrap:balance]">{{ $publication->headline }}</div>
        <div class="font-mono text-[21px] text-muted">{{ $publication->specName }}</div>
    </div>
    <div class="relative flex items-center justify-between gap-8">
        <div class="flex items-center gap-9">
            @foreach ($tally as $class => $label)
            <span class="inline-flex items-center gap-3 font-mono text-[18px] font-semibold uppercase tracking-sev {{ $class }} before:size-[13px] before:rounded-[3px] before:bg-current before:content-['']">{{ $label }}</span>
            @endforeach
        </div>
        @if ($cost !== null)
        <span class="whitespace-nowrap font-mono text-[20px] font-semibold text-ember">${{ number_format($cost, 2) }}</span>
        @endif
    </div>
</div>
</body>
</html>
