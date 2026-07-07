# Dynamic OG Image Rendering Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace hand-captured, git-committed OG PNGs with on-demand images rendered by Cloudflare Browser Rendering, edge-cached, with no headless Chrome in the container.

**Architecture:** A public `GET /og/{slug}-{hash}.png` route renders a self-contained OG HTML string (scoped `<style>` + base64-embedded fonts) and POSTs it to Cloudflare's `browser-rendering/screenshot` REST endpoint, returning the PNG with an immutable cache header so Cloudflare's edge caches it. Content-hash URLs make invalidation automatic and stateless. A renderer interface (real Cloudflare impl + test fake) keeps live Chrome out of the test suite.

**Tech Stack:** PHP 8.5, Laravel 13, Pest 4, Cloudflare Browser Rendering REST API, OpenTofu (AWS ECS/Secrets Manager + Cloudflare provider), Tailwind 4 / Bun (assets only — the OG template is deliberately Tailwind-free).

## Global Constraints

- **PHP 8.5**, Laravel 13, `declare(strict_types=1)` in every PHP file.
- **Tests are Pest 4** functional style (`it(...)`, `expect(...)`), not raw PHPUnit.
- **`composer test` is the gate**: type-coverage `--min=100` → unit+feature at **100% line coverage** → Pint `--test` + Rector `--dry-run` + `vp fmt --check` → PHPStan level max. Every task ends green.
- Format with `composer lint` (Rector + Pint + `vp fmt`) before committing.
- **No `@php` blocks in page/template views** — the OG Blade receives all data from `OgTemplate`; no logic in the view.
- **No inline `style=` attributes** on elements. A `<style>` *element* in the OG template is allowed and necessary (self-contained render payload) — this is not the banned pattern.
- Test doubles live in `tests/Support/` (mirror `FakeNewsletterContacts`). Bind them with `app()->instance(...)` in tests (mirror `SubscribeFlowTest`).
- New service classes: `final readonly` where they hold only injected deps (mirror `SesNewsletterContacts`).
- Run one test file with `vendor/bin/pest <path>`; filter with `vendor/bin/pest --filter='name'`.

---

### Task 1: `OgImageRenderer` interface + Cloudflare implementation

**Files:**
- Create: `app/Site/Og/OgImageRenderer.php`
- Create: `app/Site/Og/CloudflareOgImageRenderer.php`
- Modify: `config/services.php` (add `cloudflare` block)
- Modify: `app/Providers/AppServiceProvider.php:register()` (bind the interface)
- Test: `tests/Unit/Site/Og/CloudflareOgImageRendererTest.php`

**Interfaces:**
- Produces: `App\Site\Og\OgImageRenderer::screenshot(string $html, int $width = 1200, int $height = 630): string` (returns raw PNG bytes; throws `RuntimeException` on failure).
- Produces: `App\Site\Og\CloudflareOgImageRenderer` — constructor `(string $accountId, string $token)`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Site\Og\CloudflareOgImageRenderer;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('posts html to the cloudflare screenshot endpoint and returns png bytes', function (): void {
    Http::fake([
        'api.cloudflare.com/*' => Http::response('PNGBYTES', 200, ['Content-Type' => 'image/png']),
    ]);

    $renderer = new CloudflareOgImageRenderer('acct-123', 'token-abc');

    $png = $renderer->screenshot('<h1>hi</h1>', 1200, 630);

    expect($png)->toBe('PNGBYTES');

    Http::assertSent(fn (Request $request): bool =>
        $request->url() === 'https://api.cloudflare.com/client/v4/accounts/acct-123/browser-rendering/screenshot'
        && $request->hasHeader('Authorization', 'Bearer token-abc')
        && $request['html'] === '<h1>hi</h1>'
        && $request['viewport'] === ['width' => 1200, 'height' => 630]
        && $request['screenshotOptions'] === ['type' => 'png']);
});

it('throws when cloudflare returns a non-image response', function (): void {
    Http::fake([
        'api.cloudflare.com/*' => Http::response(['success' => false], 403, ['Content-Type' => 'application/json']),
    ]);

    $renderer = new CloudflareOgImageRenderer('acct-123', 'token-abc');

    expect(fn (): string => $renderer->screenshot('<h1>hi</h1>'))
        ->toThrow(RuntimeException::class);
});

it('container resolves OgImageRenderer to the Cloudflare implementation', function (): void {
    expect(app(App\Site\Og\OgImageRenderer::class))
        ->toBeInstanceOf(CloudflareOgImageRenderer::class);
});
```

> This third test executes the `AppServiceProvider` binding closure (Step 6) — required
> for the 100% coverage gate, mirroring `SesNewsletterContactsTest`'s "container
> resolves" test.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Site/Og/CloudflareOgImageRendererTest.php`
Expected: FAIL — `Class "App\Site\Og\CloudflareOgImageRenderer" not found`.

- [ ] **Step 3: Write the interface**

`app/Site/Og/OgImageRenderer.php`:

```php
<?php

declare(strict_types=1);

namespace App\Site\Og;

interface OgImageRenderer
{
    /**
     * Render the given HTML to a PNG at the given pixel size, returning raw bytes.
     */
    public function screenshot(string $html, int $width = 1200, int $height = 630): string;
}
```

- [ ] **Step 4: Write the Cloudflare implementation**

`app/Site/Og/CloudflareOgImageRenderer.php`:

```php
<?php

declare(strict_types=1);

namespace App\Site\Og;

use Illuminate\Support\Facades\Http;
use RuntimeException;

final readonly class CloudflareOgImageRenderer implements OgImageRenderer
{
    public function __construct(
        private string $accountId,
        private string $token,
    ) {}

    public function screenshot(string $html, int $width = 1200, int $height = 630): string
    {
        $response = Http::withToken($this->token)
            ->timeout(20)
            ->post("https://api.cloudflare.com/client/v4/accounts/{$this->accountId}/browser-rendering/screenshot", [
                'html' => $html,
                'viewport' => ['width' => $width, 'height' => $height],
                'screenshotOptions' => ['type' => 'png'],
            ]);

        $contentType = (string) $response->header('Content-Type');

        if ($response->failed() || ! str_contains($contentType, 'image/')) {
            throw new RuntimeException(
                "Cloudflare screenshot failed ({$response->status()}): " . $response->body(),
            );
        }

        return $response->body();
    }
}
```

- [ ] **Step 5: Add the config block**

In `config/services.php`, add after the `ses_contacts` block (before the closing `];`):

```php
    'cloudflare' => [
        'account_id' => env('CLOUDFLARE_ACCOUNT_ID', ''),
        'browser_token' => env('CLOUDFLARE_BROWSER_TOKEN', ''),
    ],
```

> The `''` defaults (mirroring `ses_contacts`) keep `config()->string()` from throwing
> when the env vars are absent (tests, local dev). Prod gets real values from tofu; if
> they were ever empty, the screenshot call fails and the controller's fallback kicks in.

- [ ] **Step 6: Bind the interface**

In `app/Providers/AppServiceProvider.php`, add imports:

```php
use App\Site\Og\CloudflareOgImageRenderer;
use App\Site\Og\OgImageRenderer;
```

Add inside `register()`, after the `NewsletterContacts` binding:

```php
        $this->app->singleton(
            OgImageRenderer::class,
            fn(): OgImageRenderer => new CloudflareOgImageRenderer(
                config()->string('services.cloudflare.account_id'),
                config()->string('services.cloudflare.browser_token'),
            ),
        );
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Unit/Site/Og/CloudflareOgImageRendererTest.php`
Expected: PASS (3 tests).

- [ ] **Step 8: Commit**

```bash
composer lint
git add app/Site/Og config/services.php app/Providers/AppServiceProvider.php tests/Unit/Site/Og
git commit -m "feat: OgImageRenderer interface + Cloudflare Browser Rendering impl"
```

---

### Task 2: `Publication` OG hash + image URL

**Files:**
- Modify: `app/Site/Publication.php` (add `ogHash()` and `ogImageUrl()`)
- Test: `tests/Unit/Site/PublicationOgTest.php`

**Interfaces:**
- Consumes: `App\Site\Publication::fromArray(array): self`, `->findingCounts()`, `->totalCostUsd()`, public `$headline`, `$dimension`, `$slug`.
- Produces: `App\Site\Publication::ogHash(): string` (8 lowercase hex chars), `App\Site\Publication::ogImageUrl(): string` (`"/og/{slug}-{hash}.png"`).

- [ ] **Step 1: Add a shared fixture helper to `tests/Pest.php`**

So both this task and Task 4 can build a `Publication` without duplicating the array
or a fragile cross-file `require_once`, add this function to `tests/Pest.php` (after
the existing `uses(...)` lines):

```php
use App\Site\Publication;

function ogPublicationFixture(array $overrides = []): Publication
{
    return Publication::fromArray(array_merge([
        'slug' => 'train-travel-domain-modeling',
        'headline' => 'The Council vs. a well-designed spec',
        'commentary_md' => '',
        'spec_name' => 'Train Travel API',
        'spec_source_url' => 'https://example.test/spec',
        'spec_license' => 'CC-BY',
        'dimension' => 'domain-modeling',
        'panelists' => ['openai/gpt-5.5'],
        'judge' => 'anthropic/claude-opus-4.8',
        'findings' => [
            ['severity' => 'blocker'],
            ['severity' => 'should-fix'],
        ],
        'metrics' => [['total_cost_usd' => 0.62]],
        'reviewed_at' => '2026-07-05T00:00:00Z',
        'published_at' => '2026-07-05T00:00:00Z',
    ], $overrides));
}
```

- [ ] **Step 2: Write the failing test**

`tests/Unit/Site/PublicationOgTest.php`:

```php
<?php

declare(strict_types=1);

it('produces a stable 8-char hex og hash', function (): void {
    $publication = ogPublicationFixture();

    expect($publication->ogHash())->toMatch('/^[a-f0-9]{8}$/')
        ->and($publication->ogHash())->toBe($publication->ogHash());
});

it('changes the og hash when the headline changes', function (): void {
    expect(ogPublicationFixture(['headline' => 'One'])->ogHash())
        ->not->toBe(ogPublicationFixture(['headline' => 'Two'])->ogHash());
});

it('builds the og image url from slug and hash', function (): void {
    $publication = ogPublicationFixture();

    expect($publication->ogImageUrl())
        ->toBe("/og/train-travel-domain-modeling-{$publication->ogHash()}.png");
});
```

- [ ] **Step 3: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Site/PublicationOgTest.php`
Expected: FAIL — `Call to undefined method App\Site\Publication::ogHash()`.

- [ ] **Step 4: Add the methods**

In `app/Site/Publication.php`, add these public methods (near `totalCostUsd()`):

```php
    public function ogHash(): string
    {
        $counts = $this->findingCounts();

        $key = implode('|', [
            $this->headline,
            (string) $counts['blocker'],
            (string) $counts['should-fix'],
            (string) $counts['consider'],
            (string) ($this->totalCostUsd() ?? ''),
            $this->dimension,
        ]);

        return substr(sha1($key), 0, 8);
    }

    public function ogImageUrl(): string
    {
        return "/og/{$this->slug}-{$this->ogHash()}.png";
    }
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/Site/PublicationOgTest.php`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
composer lint
git add app/Site/Publication.php tests/Pest.php tests/Unit/Site/PublicationOgTest.php
git commit -m "feat: Publication OG content-hash and image URL"
```

---

### Task 3: Vendor OG fonts + `OgAssets` font embedder

**Files:**
- Create (binary, copied): `resources/fonts/og/newsreader-opsz-normal.woff2`, `resources/fonts/og/ibm-plex-mono-500.woff2`, `resources/fonts/og/ibm-plex-mono-600.woff2`
- Create: `app/Site/Og/OgAssets.php`
- Test: `tests/Unit/Site/Og/OgAssetsTest.php`

**Interfaces:**
- Produces: `App\Site\Og\OgAssets::fontFaceCss(): string` — a string of three `@font-face` rules, each with a `src: url(data:font/woff2;base64,…)`. Memoized.

- [ ] **Step 1: Vendor the three woff2 files**

The Fontsource packages are already installed (see `resources/css/app.css` imports). Copy the exact weights the card uses:

```bash
mkdir -p resources/fonts/og
cp node_modules/@fontsource-variable/newsreader/files/newsreader-latin-opsz-normal.woff2 resources/fonts/og/newsreader-opsz-normal.woff2
cp node_modules/@fontsource/ibm-plex-mono/files/ibm-plex-mono-latin-500-normal.woff2 resources/fonts/og/ibm-plex-mono-500.woff2
cp node_modules/@fontsource/ibm-plex-mono/files/ibm-plex-mono-latin-600-normal.woff2 resources/fonts/og/ibm-plex-mono-600.woff2
ls -la resources/fonts/og
```

Expected: three `.woff2` files present. If the `files/` path differs, find them: `find node_modules/@fontsource* -name '*plex-mono-latin-600-normal.woff2' -o -name '*newsreader-latin-opsz-normal.woff2'`.

- [ ] **Step 2: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Site\Og\OgAssets;

it('embeds each og font as a base64 woff2 data uri', function (): void {
    $css = new OgAssets()->fontFaceCss();

    expect($css)->toContain('@font-face')
        ->and($css)->toContain("font-family:'Newsreader'")
        ->and($css)->toContain("font-family:'IBM Plex Mono'")
        ->and($css)->toContain('data:font/woff2;base64,')
        ->and(substr_count($css, '@font-face'))->toBe(3);
});

it('memoizes the font css', function (): void {
    $assets = new OgAssets();

    expect($assets->fontFaceCss())->toBe($assets->fontFaceCss());
});
```

- [ ] **Step 3: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Site/Og/OgAssetsTest.php`
Expected: FAIL — `Class "App\Site\Og\OgAssets" not found`.

- [ ] **Step 4: Write `OgAssets`**

`app/Site/Og/OgAssets.php`:

```php
<?php

declare(strict_types=1);

namespace App\Site\Og;

final class OgAssets
{
    /**
     * @var list<array{family: string, weight: string, file: string}>
     */
    private const array FONTS = [
        ['family' => 'Newsreader', 'weight' => '400', 'file' => 'newsreader-opsz-normal.woff2'],
        ['family' => 'IBM Plex Mono', 'weight' => '500', 'file' => 'ibm-plex-mono-500.woff2'],
        ['family' => 'IBM Plex Mono', 'weight' => '600', 'file' => 'ibm-plex-mono-600.woff2'],
    ];

    private ?string $fontCss = null;

    public function fontFaceCss(): string
    {
        if ($this->fontCss !== null) {
            return $this->fontCss;
        }

        $css = '';

        foreach (self::FONTS as $font) {
            $bytes = (string) file_get_contents(resource_path("fonts/og/{$font['file']}"));
            $base64 = base64_encode($bytes);

            $css .= sprintf(
                "@font-face{font-family:'%s';font-weight:%s;font-style:normal;font-display:block;src:url(data:font/woff2;base64,%s) format('woff2');}",
                $font['family'],
                $font['weight'],
                $base64,
            );
        }

        return $this->fontCss = $css;
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/Site/Og/OgAssetsTest.php`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
composer lint
git add resources/fonts/og app/Site/Og/OgAssets.php tests/Unit/Site/Og/OgAssetsTest.php
git commit -m "feat: vendor OG fonts and OgAssets base64 embedder"
```

---

### Task 4: Self-contained OG Blade templates + `OgTemplate`

**Files:**
- Rewrite: `resources/views/site/og.blade.php` (self-contained review card)
- Rewrite: `resources/views/site/og-home.blade.php` (self-contained home card)
- Create: `app/Site/Og/OgTemplate.php`
- Test: `tests/Unit/Site/Og/OgTemplateTest.php`

**Interfaces:**
- Consumes: `OgAssets::fontFaceCss()`, `Publication` (`->dimension`, `->headline`, `->specName`, `->findingCounts()`, `->totalCostUsd()`).
- Produces:
  - `App\Site\Og\OgTemplate` — constructor `(OgAssets $assets)`.
  - `->review(App\Site\Publication $publication): Illuminate\Contracts\View\View`
  - `->home(): Illuminate\Contracts\View\View`
  - `OgTemplate::homeImageUrl(): string` (static) → `"/og/home-{hash}.png"`.

- [ ] **Step 1: Write the failing test**

`ogPublicationFixture()` is the global helper added to `tests/Pest.php` in Task 2.

```php
<?php

declare(strict_types=1);

use App\Site\Og\OgTemplate;

it('renders a self-contained review card with no vite asset links', function (): void {
    $html = app(OgTemplate::class)->review(ogPublicationFixture(['headline' => 'RPC habit']))->render();

    expect($html)->toContain('RPC habit')
        ->and($html)->toContain('Train Travel API')
        ->and($html)->toContain('data:font/woff2;base64,')
        ->and($html)->toContain('$0.62')
        ->and($html)->not->toContain('/build/');
});

it('renders the home card', function (): void {
    $html = app(OgTemplate::class)->home()->render();

    expect($html)->toContain('never gets tired')
        ->and($html)->toContain('data:font/woff2;base64,')
        ->and($html)->not->toContain('/build/');
});

it('builds a stable home image url', function (): void {
    expect(OgTemplate::homeImageUrl())->toMatch('#^/og/home-[a-f0-9]{8}\.png$#')
        ->and(OgTemplate::homeImageUrl())->toBe(OgTemplate::homeImageUrl());
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Site/Og/OgTemplateTest.php`
Expected: FAIL — `Class "App\Site\Og\OgTemplate" not found`.

- [ ] **Step 3: Rewrite `resources/views/site/og.blade.php`**

```blade
{{-- Self-contained 1200×630 review OG card. All data comes from OgTemplate;
     no @php, no @vite — the payload is sent verbatim to Cloudflare screenshot. --}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>
{!! $fonts !!}
:root{--surface:#171310;--ink:#ede4d8;--muted:#a89a89;--subtle:#8f7f6c;--ember:#f26430;--amber:#dda032;--serif:'Newsreader',Georgia,serif;--mono:'IBM Plex Mono',ui-monospace,monospace;}
*{margin:0;box-sizing:border-box;}
.og{position:relative;width:1200px;height:630px;overflow:hidden;background:var(--surface);display:flex;flex-direction:column;justify-content:space-between;padding:56px 64px 52px;}
.og::before{content:"";position:absolute;inset:0;background:radial-gradient(ellipse 70% 100% at 50% 100%,rgba(242,100,48,.13),transparent 65%);}
.og::after{content:"";position:absolute;left:0;right:0;bottom:0;height:2px;background:linear-gradient(90deg,transparent,rgba(242,100,48,.55),transparent);}
.og>*{position:relative;}
.top{display:flex;align-items:baseline;justify-content:space-between;}
.wordmark{font:600 26px/1 var(--mono);color:var(--ink);}
.wordmark em{font-style:normal;color:var(--ember);}
.kicker{font:500 15px/1 var(--mono);letter-spacing:.14em;text-transform:uppercase;color:var(--muted);}
.mid{display:flex;flex-direction:column;gap:22px;max-width:980px;}
.headline{font:400 64px/1.12 var(--serif);color:var(--ink);text-wrap:balance;}
.spec{font:500 21px/1 var(--mono);color:var(--muted);}
.bottom{display:flex;align-items:center;justify-content:space-between;gap:32px;}
.tally{display:flex;align-items:center;gap:36px;}
.sev{display:inline-flex;align-items:center;gap:12px;font:600 18px/1 var(--mono);text-transform:uppercase;letter-spacing:.02em;}
.sev::before{content:"";width:13px;height:13px;border-radius:3px;background:currentColor;}
.sev-blocker{color:var(--ember);}
.sev-should-fix{color:var(--amber);}
.sev-consider{color:var(--subtle);}
.cost{font:600 20px/1 var(--mono);color:var(--ember);white-space:nowrap;}
</style>
</head>
<body>
<div class="og">
  <div class="top">
    <span class="wordmark">oast<em>.sh</em></span>
    <span class="kicker">{{ $kicker }}</span>
  </div>
  <div class="mid">
    <div class="headline">{{ $headline }}</div>
    <div class="spec">{{ $specName }}</div>
  </div>
  <div class="bottom">
    <div class="tally">
      @foreach ($tally as $class => $label)
      <span class="sev {{ $class }}">{{ $label }}</span>
      @endforeach
    </div>
    @if ($cost !== null)
    <span class="cost">${{ number_format($cost, 2) }}</span>
    @endif
  </div>
</div>
</body>
</html>
```

- [ ] **Step 4: Rewrite `resources/views/site/og-home.blade.php`**

```blade
{{-- Self-contained 1200×630 home / default OG card. --}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>
{!! $fonts !!}
:root{--surface:#171310;--ink:#ede4d8;--muted:#a89a89;--subtle:#8f7f6c;--ember:#f26430;--serif:'Newsreader',Georgia,serif;--mono:'IBM Plex Mono',ui-monospace,monospace;}
*{margin:0;box-sizing:border-box;}
.og{position:relative;width:1200px;height:630px;overflow:hidden;background:var(--surface);display:flex;flex-direction:column;justify-content:space-between;padding:56px 64px 52px;}
.og::before{content:"";position:absolute;inset:0;background:radial-gradient(ellipse 70% 100% at 50% 100%,rgba(242,100,48,.13),transparent 65%);}
.og::after{content:"";position:absolute;left:0;right:0;bottom:0;height:2px;background:linear-gradient(90deg,transparent,rgba(242,100,48,.55),transparent);}
.og>*{position:relative;}
.top{display:flex;align-items:baseline;justify-content:space-between;}
.wordmark{font:600 26px/1 var(--mono);color:var(--ink);}
.wordmark em{font-style:normal;color:var(--ember);}
.kicker{font:500 15px/1 var(--mono);letter-spacing:.14em;text-transform:uppercase;color:var(--muted);}
.mid{display:flex;flex-direction:column;gap:26px;max-width:980px;}
.headline{font:400 64px/1.12 var(--serif);color:var(--ink);text-wrap:balance;}
.spec{font:500 21px/1 var(--mono);color:var(--muted);}
.bottom{display:flex;align-items:center;justify-content:space-between;gap:32px;}
.foot{font:400 18px/1 var(--mono);color:var(--subtle);}
.brand{font:600 20px/1 var(--mono);color:var(--ember);white-space:nowrap;}
</style>
</head>
<body>
<div class="og">
  <div class="top">
    <span class="wordmark">oast<em>.sh</em></span>
    <span class="kicker">api design review</span>
  </div>
  <div class="mid">
    <div class="headline">Your API design, argued over by a panel that never gets tired.</div>
    <div class="spec">$ oast roast ./openapi.yaml</div>
  </div>
  <div class="bottom">
    <span class="foot">openapi + arazzo · severity × confidence · real costs</span>
    <span class="brand">oast.sh</span>
  </div>
</div>
</body>
</html>
```

- [ ] **Step 5: Write `OgTemplate`**

`app/Site/Og/OgTemplate.php`:

```php
<?php

declare(strict_types=1);

namespace App\Site\Og;

use App\Site\Publication;
use Illuminate\Contracts\View\View;

final readonly class OgTemplate
{
    private const string HOME_VERSION = 'v1';

    public function __construct(private OgAssets $assets) {}

    public function review(Publication $publication): View
    {
        $counts = $publication->findingCounts();

        return view('site.og', [
            'fonts' => $this->assets->fontFaceCss(),
            'kicker' => 'api design review · ' . $publication->dimension,
            'headline' => $publication->headline,
            'specName' => $publication->specName,
            'cost' => $publication->totalCostUsd(),
            'tally' => array_filter([
                'sev-blocker' => $counts['blocker'] !== 0 ? $counts['blocker'] . ' blocker' . ($counts['blocker'] > 1 ? 's' : '') : null,
                'sev-should-fix' => $counts['should-fix'] !== 0 ? $counts['should-fix'] . ' should-fix' : null,
                'sev-consider' => $counts['consider'] !== 0 ? $counts['consider'] . ' consider' : null,
            ]),
        ]);
    }

    public function home(): View
    {
        return view('site.og-home', [
            'fonts' => $this->assets->fontFaceCss(),
        ]);
    }

    public static function homeImageUrl(): string
    {
        return '/og/home-' . substr(sha1('home-' . self::HOME_VERSION), 0, 8) . '.png';
    }
}
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Unit/Site/Og/OgTemplateTest.php`
Expected: PASS (3 tests).

- [ ] **Step 7: Commit**

```bash
composer lint
git add resources/views/site/og.blade.php resources/views/site/og-home.blade.php app/Site/Og/OgTemplate.php tests/Unit/Site/Og/OgTemplateTest.php
git commit -m "feat: self-contained OG Blade templates + OgTemplate builder"
```

---

### Task 5: Image controller, route, and fallback

**Files:**
- Create: `app/Http/Controllers/Site/OgImageController.php`
- Create (binary): `public/og/fallback.png` (rename the existing home capture)
- Modify: `routes/web.php` (public image route; replace the local preview routes)
- Create: `tests/Support/FakeOgImageRenderer.php`
- Create: `tests/Support/ThrowingOgImageRenderer.php`
- Test: `tests/Feature/OgImageTest.php`

**Interfaces:**
- Consumes: `OgImageRenderer::screenshot()`, `OgTemplate::review()/home()`, `PublicationRepository::find()`, `Publication::ogImageUrl()`.
- Produces: route named `og.image`; `OgImageController::__invoke(string $file, PublicationRepository $publications, OgImageRenderer $renderer, OgTemplate $template): Illuminate\Http\Response`.

- [ ] **Step 1: Create the test doubles**

`tests/Support/FakeOgImageRenderer.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Site\Og\OgImageRenderer;

final class FakeOgImageRenderer implements OgImageRenderer
{
    /** @var list<array{html: string, width: int, height: int}> */
    public array $calls = [];

    public function screenshot(string $html, int $width = 1200, int $height = 630): string
    {
        $this->calls[] = ['html' => $html, 'width' => $width, 'height' => $height];

        // A real 1×1 transparent PNG.
        return (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
            true,
        );
    }
}
```

`tests/Support/ThrowingOgImageRenderer.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Site\Og\OgImageRenderer;
use RuntimeException;

final class ThrowingOgImageRenderer implements OgImageRenderer
{
    public function screenshot(string $html, int $width = 1200, int $height = 630): string
    {
        throw new RuntimeException('render failed');
    }
}
```

- [ ] **Step 2: Write the failing feature test**

`tests/Feature/OgImageTest.php`:

```php
<?php

declare(strict_types=1);

use App\Site\Og\OgImageRenderer;
use App\Site\PublicationRepository;
use Tests\Support\FakeOgImageRenderer;
use Tests\Support\ThrowingOgImageRenderer;

beforeEach(function (): void {
    app()->bind(
        PublicationRepository::class,
        fn (): PublicationRepository => new PublicationRepository(base_path('tests/fixtures/publications')),
    );
});

it('renders a review slug as a png with an immutable cache header', function (): void {
    app()->instance(OgImageRenderer::class, new FakeOgImageRenderer());

    $response = $this->get('/og/train-travel-domain-modeling-deadbeef.png')->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('image/png')
        ->and($response->headers->get('Cache-Control'))->toContain('max-age=31536000')
        ->and($response->headers->get('Cache-Control'))->toContain('immutable')
        ->and($response->headers->get('Set-Cookie'))->toBeNull();
});

it('renders the home slug as a png', function (): void {
    app()->instance(OgImageRenderer::class, new FakeOgImageRenderer());

    $this->get('/og/home-deadbeef.png')
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png');
});

it('404s an unknown review slug', function (): void {
    app()->instance(OgImageRenderer::class, new FakeOgImageRenderer());

    $this->get('/og/nope-deadbeef.png')->assertNotFound();
});

it('404s a path with no hash suffix', function (): void {
    $this->get('/og/train-travel-domain-modeling.png')->assertNotFound();
});

it('serves the fallback image with a short ttl when rendering throws', function (): void {
    app()->instance(OgImageRenderer::class, new ThrowingOgImageRenderer());

    $response = $this->get('/og/train-travel-domain-modeling-deadbeef.png')->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('image/png')
        ->and($response->headers->get('Cache-Control'))->toContain('max-age=300');
});
```

- [ ] **Step 3: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/OgImageTest.php`
Expected: FAIL — route not defined (404 on the first assertOk, or missing controller class).

- [ ] **Step 4: Create the fallback image**

```bash
git mv public/og/home.png public/og/fallback.png
ls public/og
```

Expected: `fallback.png` plus the four per-review PNGs (removed in Task 6).

- [ ] **Step 5: Write the controller**

`app/Http/Controllers/Site/OgImageController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Site\Og\OgImageRenderer;
use App\Site\Og\OgTemplate;
use App\Site\PublicationRepository;
use Illuminate\Http\Response;
use Throwable;

final class OgImageController
{
    public function __invoke(
        string $file,
        PublicationRepository $publications,
        OgImageRenderer $renderer,
        OgTemplate $template,
    ): Response {
        if (preg_match('/^(?<slug>.+)-(?<hash>[a-f0-9]{8})$/', $file, $matches) !== 1) {
            abort(404);
        }

        $slug = $matches['slug'];

        // Resolve BEFORE the try so an unknown slug 404s instead of falling back.
        $view = $slug === 'home'
            ? $template->home()
            : $template->review($publications->find($slug) ?? abort(404));

        try {
            $png = $renderer->screenshot($view->render());

            return response($png, 200, [
                'Content-Type' => 'image/png',
                'Cache-Control' => 'public, max-age=31536000, immutable',
            ]);
        } catch (Throwable $throwable) {
            report($throwable);

            return response((string) file_get_contents(public_path('og/fallback.png')), 200, [
                'Content-Type' => 'image/png',
                'Cache-Control' => 'public, max-age=300',
            ]);
        }
    }
}
```

- [ ] **Step 6: Replace the OG routes**

In `routes/web.php`, add the import at the top:

```php
use App\Http\Controllers\Site\OgImageController;
use App\Site\Og\OgTemplate;
```

Replace the entire existing `if (app()->environment('local')) { … }` OG block with:

```php
// Public OG image endpoint — crawlers hit this; it calls Cloudflare Browser
// Rendering. Outside session middleware so no Set-Cookie defeats edge caching.
Route::get('/og/{file}.png', OgImageController::class)
    ->where('file', '[A-Za-z0-9-]+')
    ->withoutMiddleware([
        // Strip session/cookie middleware so no Set-Cookie is emitted (which would
        // make Cloudflare refuse to cache the PNG). ShareErrorsFromSession must go
        // too — it calls $request->session() and throws once StartSession is gone.
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
    ])
    ->name('og.image');

// Local-only HTML previews for iterating on the card design in a browser.
if (app()->environment('local')) {
    Route::get('/og/preview', fn (OgTemplate $template) => $template->home())->name('og.preview.home');
    Route::get('/og/preview/{slug}', function (string $slug, OgTemplate $template, App\Site\PublicationRepository $publications) {
        return $template->review($publications->find($slug) ?? abort(404));
    })->name('og.preview.review');
}
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/OgImageTest.php`
Expected: PASS (5 tests).

- [ ] **Step 8: Commit**

```bash
composer lint
git add app/Http/Controllers/Site/OgImageController.php routes/web.php tests/Support/FakeOgImageRenderer.php tests/Support/ThrowingOgImageRenderer.php tests/Feature/OgImageTest.php public/og/fallback.png
git commit -m "feat: dynamic OG image route via Cloudflare Browser Rendering with fallback"
```

---

### Task 6: Wire meta tags to the dynamic route + retire static PNGs

**Files:**
- Modify: `resources/views/site/layout.blade.php` (`og:image` default → home dynamic URL)
- Modify: `resources/views/site/review-show.blade.php` (`og_image` section → publication dynamic URL)
- Delete: `public/og/train-travel-domain-modeling.png`, `public/og/train-travel-workflows.png`, `public/og/train-travel-resource-relationships.png`, `public/og/slack-web-api-domain-modeling.png`
- Modify: `tests/Feature/SitePagesTest.php` (assert dynamic og:image)

**Interfaces:**
- Consumes: `OgTemplate::homeImageUrl()`, `Publication::ogImageUrl()`.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/SitePagesTest.php`:

```php
it('emits a dynamic hashed og:image on the homepage', function (): void {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toMatch('#property="og:image" content="[^"]*/og/home-[a-f0-9]{8}\.png"#');
});

it('emits a dynamic hashed og:image on a review page', function (): void {
    $html = $this->get('/reviews/train-travel-domain-modeling')->assertOk()->getContent();

    expect($html)->toMatch('#property="og:image" content="[^"]*/og/train-travel-domain-modeling-[a-f0-9]{8}\.png"#');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/SitePagesTest.php --filter='og:image'`
Expected: FAIL — current markup still points at `asset('og/…png')` static paths.

- [ ] **Step 3: Update the layout default `og:image`**

In `resources/views/site/layout.blade.php`, change the `og:image` meta line from:

```blade
    <meta property="og:image" content="@yield('og_image', asset('og/home.png'))">
```

to:

```blade
    <meta property="og:image" content="@yield('og_image', url(\App\Site\Og\OgTemplate::homeImageUrl()))">
```

- [ ] **Step 4: Update the review page `og_image`**

In `resources/views/site/review-show.blade.php`, change:

```blade
@section('og_image', asset('og/' . $publication->slug . '.png'))
```

to:

```blade
@section('og_image', url($publication->ogImageUrl()))
```

- [ ] **Step 5: Delete the static per-review PNGs**

```bash
git rm public/og/train-travel-domain-modeling.png public/og/train-travel-workflows.png public/og/train-travel-resource-relationships.png public/og/slack-web-api-domain-modeling.png
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/SitePagesTest.php`
Expected: PASS (all, including the two new og:image assertions).

- [ ] **Step 7: Commit**

```bash
composer lint
git add resources/views/site/layout.blade.php resources/views/site/review-show.blade.php tests/Feature/SitePagesTest.php public/og
git commit -m "feat: point og:image at dynamic route, retire static per-review PNGs"
```

---

### Task 7: Infrastructure — tofu-minted Browser Rendering token

**Files:**
- Modify: `infra/ses.tf` *(or a new `infra/browser-rendering.tf`)* — `cloudflare_api_token` + Secrets Manager secret/version
- Modify: `infra/ecs.tf` — app container `environment` (+`CLOUDFLARE_ACCOUNT_ID`) and `secrets` (+`CLOUDFLARE_BROWSER_TOKEN`)
- Modify: `infra/iam.tf` — add the new secret ARN to `task_execution_secrets`

**Interfaces:**
- Consumes: `var.cloudflare_account_id`, `aws_iam_role.task_execution` (existing), the app container definition in `infra/ecs.tf`.

> **Manual prerequisite (runbook, before `tofu apply`):** the `oast-tofu` provisioning
> token must gain the user-level **"API Tokens Write"** permission (re-mint with DNS
> Write + Cloudflare Tunnel Write + API Tokens Write). Without it, creating a
> `cloudflare_api_token` resource fails with an authorization error. Also confirm
> **Browser Rendering is enabled** on the account (free allocation; one-time check).

- [ ] **Step 1: Add the token + secret**

Create `infra/browser-rendering.tf`:

```hcl
# Runtime Cloudflare token, scoped to Browser Rendering on the account, minted by
# tofu and pushed straight into Secrets Manager. Distinct from the oast-tofu
# provisioning token. The token value lands in tofu state (S3, encrypted).
data "cloudflare_api_token_permission_groups_list" "all" {}

locals {
  browser_rendering_pg = [
    for pg in data.cloudflare_api_token_permission_groups_list.all.result :
    pg.id if can(regex("Browser Rendering", pg.name))
  ][0]
}

resource "cloudflare_api_token" "browser_render" {
  name = "oast-og-browser-rendering"

  policies = [{
    effect            = "allow"
    permission_groups = [{ id = local.browser_rendering_pg }]
    resources = {
      "com.cloudflare.api.account.${var.cloudflare_account_id}" = "*"
    }
  }]
}

resource "aws_secretsmanager_secret" "browser_token" {
  name = "oast/cf-browser-token"
}

resource "aws_secretsmanager_secret_version" "browser_token" {
  secret_id     = aws_secretsmanager_secret.browser_token.id
  secret_string = cloudflare_api_token.browser_render.value
}
```

- [ ] **Step 2: Wire the container env + secret**

In `infra/ecs.tf`, in the `app` container `environment` list, add after the `AWS_DEFAULT_REGION` line:

```hcl
        { name = "CLOUDFLARE_ACCOUNT_ID", value = var.cloudflare_account_id },
```

In the same container's `secrets` list (currently just `APP_KEY`), add:

```hcl
        { name = "CLOUDFLARE_BROWSER_TOKEN", valueFrom = aws_secretsmanager_secret.browser_token.arn },
```

- [ ] **Step 3: Grant the execution role read access**

In `infra/iam.tf`, in `aws_iam_role_policy.task_execution_secrets`, extend the `Resource` array (currently `[app_key.arn, tunnel_token.arn]`) to include the new secret:

```hcl
      Resource = [
        aws_secretsmanager_secret.app_key.arn,
        aws_secretsmanager_secret.tunnel_token.arn,
        aws_secretsmanager_secret.browser_token.arn,
      ]
```

- [ ] **Step 4: Validate + plan**

```bash
cd infra
tofu validate
CLOUDFLARE_API_TOKEN=<broadened-oast-tofu-token> tofu plan
```

Expected: `tofu validate` succeeds; `tofu plan` shows **to add**: `cloudflare_api_token.browser_render`, `aws_secretsmanager_secret.browser_token`, `aws_secretsmanager_secret_version.browser_token`, and **to change**: the ECS task definition + the execution-secrets IAM policy. No unexpected destroys.

- [ ] **Step 5: Commit** (apply happens through the deploy runbook, not here)

```bash
cd ..
git add infra/browser-rendering.tf infra/ecs.tf infra/iam.tf
git commit -m "feat: tofu-minted Cloudflare Browser Rendering token for OG images"
```

---

### Task 8: Full-suite gate + docs

**Files:**
- Modify: `docs/deploy.md` (add the two runbook prerequisites)

- [ ] **Step 1: Run the complete gate**

Run: `composer test`
Expected: type-coverage 100% → unit+feature 100% line coverage (all OG classes covered) → Pint/Rector/`vp fmt` clean → PHPStan max 0 errors.

If coverage flags an uncovered line, add the missing test case in the relevant task's test file (most likely the `OgImageController` home/review branches — ensure both are exercised, which the Task 5 tests already do).

- [ ] **Step 2: Document the runbook prerequisites**

In `docs/deploy.md`, add a step near the SES production-access note:

```markdown
- One-time: broaden the `oast-tofu` Cloudflare token to include **API Tokens Write**
  (alongside DNS Write + Tunnel Write) so `tofu apply` can mint the runtime Browser
  Rendering token. Confirm **Browser Rendering** is enabled on the account.
```

- [ ] **Step 3: Commit**

```bash
git add docs/deploy.md
git commit -m "docs: OG image runbook prerequisites"
```

---

## Post-implementation verification (manual, after deploy)

1. `tofu apply` with the broadened token; confirm the three new resources create.
2. Trigger a deploy (push to `main`); wait for the service to roll.
3. `curl -sI https://oast.sh/og/home-<hash>.png` → `200`, `content-type: image/png`, `cache-control: …immutable`, **no** `set-cookie`.
4. Paste a review URL into `https://opengraph.dev` (or Slack) → the kiln card renders with the headline, severity tally, and cost.
5. Confirm a second `curl` of the same URL is served from Cloudflare cache (`cf-cache-status: HIT`).

## Notes / deferred (YAGNI until observed)

- No Cloudflare Cache Rule for `/og/*` — default `.png` + `immutable` caching is relied on; add a rule only if `cf-cache-status` shows misses.
- No app-level (Laravel) response cache — the edge cache covers it at this volume.
- The home card hash is bumped by hand via `OgTemplate::HOME_VERSION` when the home card copy changes (rare); reviews self-bust via content hash.
