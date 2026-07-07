{{-- 1200×630 OG raster template for the homepage / default embeds. Rendered by
     the local-only /og/home route and captured to public/og/home.png. --}}
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
        <span class="font-mono text-[15px] font-medium uppercase tracking-label text-muted">api design review</span>
    </div>
    <div class="relative flex max-w-[980px] flex-col gap-[26px]">
        <div class="font-serif text-[64px] leading-[1.12] text-ink [text-wrap:balance]">Your API design, argued over by a panel that never gets tired.</div>
        <div class="font-mono text-[21px] text-muted">$ oast roast ./openapi.yaml</div>
    </div>
    <div class="relative flex items-center justify-between gap-8">
        <span class="font-mono text-[18px] text-subtle">openapi + arazzo · severity × confidence · real costs</span>
        <span class="whitespace-nowrap font-mono text-[20px] font-semibold text-ember">oast.sh</span>
    </div>
</div>
</body>
</html>
