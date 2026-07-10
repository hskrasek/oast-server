<x-layouts.app title="Why - oast">
<x-site.page class="py-16 flex flex-col gap-10">
    <header class="flex flex-col gap-4 max-w-[65ch]">
        <p class="o-label">Why oast exists</p>
        <h1 class="o-headline">I didn't want to build an AI product.</h1>
    </header>

    <div class="flex flex-col gap-5 max-w-[65ch]">
        <p>I'm an LLM skeptic. Enough of one that it's a running joke at work, where I somehow became
        the AI guy anyway. So when I tell you a panel of language models found real design problems in
        an API I've worked on for years, understand that nobody is less comfortable with that sentence
        than I am.</p>

        <p>Here's the itch this scratches. Linters are great at what they do — Spectral will tell you an
        operationId is missing every single time. But the expensive API mistakes aren't lint. A resource
        model that leaks your database schema. A payment flow that can't be retried safely. Two pagination
        styles that reveal your API evolved instead of being designed. Those get caught in design review,
        by senior engineers with taste and long memories — if you can get three of them in a room, which
        you mostly can't.</p>

        <p>So I ran an experiment. Take a spec I know cold (years of my own regrets, encoded in YAML), hand
        it to three frontier models independently — no shared rubric, no groupthink — then have a fourth
        model do nothing but organize their critiques into findings. Never add its own. Would the panel be
        sharper than asking one model once?</p>

        <p>Do I think a panel of LLMs replaces design review? No. Did it find real problems in a spec I'd
        stared at for years? Yes. But the part that actually convinced me was the disagreement. When the
        panel splits on a finding, the split lands almost exactly where two reasonable engineers would land
        — and oast shows you both positions, unaveraged. The judge doesn't break the tie. You do. I went in
        expecting confident nonsense; I came out with a review I'd forward to a team.</p>

        <p>Which is why everything on this site is receipts. The published reviews are real Council output
        on real, openly licensed specs, with real costs down to the cent. If the reviews aren't sharp,
        you'll be able to tell — that's the point of publishing them.</p>

        <p>About the name: an oast is a kiln that dries raw hops into something you can actually brew with.
        Raw spec in, refined spec out. (It also happens to contain "OAS", which was too good to pass up.)</p>

        <p>The plan is open-core. The server will be AGPL and self-hostable — bring your own OpenRouter
        key, pay the per-review cost you see on every published page, and nothing else. A hosted version
        comes later, for people who'd rather not run infrastructure to get a design review. The CLI
        (<code class="font-mono text-mono-ui text-muted">oast roast ./openapi.yaml</code>) will be MIT,
        because a CI tool you can't freely embed is a CI tool you uninstall.</p>

        <p>If you spot something wrong — in a review, in the approach, in this page — tell me. Being
        corrected in public is roughly half of how I've learned everything I know. And if you just want to
        know whether the Council is any good, don't take my word for it:
        <a href="{{ route('reviews.index') }}" class="underline">read the reviews</a>. They're the honest
        pitch.</p>
    </div>
</x-site.page>
</x-layouts.app>
