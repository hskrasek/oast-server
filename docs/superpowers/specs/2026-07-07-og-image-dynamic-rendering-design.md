# Dynamic OpenGraph Image Rendering (Design)

> Scope: replace the manually-captured, git-committed OG PNGs with dynamic
> generation, without adding headless Chrome to the runtime container. Render
> on demand via **Cloudflare Browser Rendering**, cache at the Cloudflare edge,
> keep the ECS task slim and stateless.

## Problem

Today's OG images are static PNGs in `public/og/`, captured by hand with a
browser and committed to git. Every new published review needs a manual
re-capture — the step this design removes. A future need for OG images on
content that isn't baked into the image (per-finding cards, the M3 web client,
arbitrary user specs) also argues for on-demand generation.

The constraint that shapes the solution: the prod container is **slim and
stateless** (0.25 vCPU / 512 MB Fargate task, no writable DB, no local browser).
Running Chrome — in-container or as a sidecar — breaks that. So rendering is
offloaded to Cloudflare Browser Rendering (the account already fronts every
oast.sh request), and the container only ever makes an HTTP call.

## Non-goals

Per-finding share cards, query-param-driven custom cards, the M3 live web
client's OG needs — all *enabled* by this design but not built here. A
Cloudflare Cache Rule for `/og/*` (default `.png` + `immutable` caching is
relied on until misses are observed). App-level (Laravel) response caching —
the edge cache makes it unnecessary at this volume.

## Approach (chosen)

Cloudflare Browser Rendering via its REST `screenshot` endpoint. Rejected
alternatives, for the record:

- **ogkit.dev / hosted OG SaaS** — laziest, but a third party renders and
  serves a brand-critical, launch-facing asset from its own domain; ongoing
  external dependency and vendor coupling on an otherwise dependency-free
  marketing site. Cuts against the "own it / receipts" grain.
- **Spatie `laravel-open-graph-image` + self-hosted Chrome** — a Chrome sidecar
  blows the task budget; even pointing Browsershot at a *remote* Chrome still
  needs Node + puppeteer-core *in the container*. Strictly less slim than a
  plain HTTP call for the same result.

Cloudflare Browser Rendering is essentially Spatie's model with Cloudflare
owning and patching the Chrome, reached over a REST call — dynamic generation
while the container stays as slim as the static approach.

## Architecture

### Routes

| Route | Visibility | Purpose |
|---|---|---|
| `GET /og/{slug}-{hash}.png` | public | The `og:image` target crawlers hit. The only path that calls Cloudflare. Outside session/cookie middleware. |
| `GET /og/{slug}` (HTML) | local only | Dev preview of the card for visual iteration. Never serves prod traffic. Reuses the same Blade the controller renders. |

`{slug}` also covers the homepage/default card (`home`).

**Route pattern:** slugs are themselves hyphenated (`slack-web-api-domain-modeling`),
so the `-{hash}.png` suffix can't be split on hyphens naively. Match a single
segment and destructure with a trailing-hash regex — hash is exactly the last 8
hex chars before `.png`: `^(?<slug>.+)-(?<hash>[a-f0-9]{8})$`. The slug then
resolves against the `PublicationRepository` (or `home`).

### Cache-miss request flow

The only time origin does real work:

1. Crawler requests `…/og/slack-web-api-domain-modeling-a1b2c3d4.png`.
2. Cloudflare edge cache miss → origin (via the tunnel).
3. Controller resolves the `Publication` (404 if unknown), renders a
   **self-contained** OG HTML string.
4. `POST /accounts/{account_id}/browser-rendering/screenshot` with
   `{ html, viewport: { width: 1200, height: 630 }, screenshotOptions: { type: "png" } }`
   → PNG bytes.
5. Return PNG with `Cache-Control: public, max-age=31536000, immutable`.
6. Cloudflare edge caches it; subsequent crawls never reach origin.

### Self-contained HTML (not `url` mode)

The screenshot payload is fully inlined — a scoped `<style>` block (design
tokens + `og-*` classes, mirroring the design system's `surfaces/og-image.html`)
with the two needed font faces (Newsreader serif, IBM Plex Mono) embedded as
base64 `@font-face` sources. No `@vite`, no Tailwind bundle, no assets fetched
from origin.

Rationale — rejecting `url` mode (pointing Cloudflare at the live
`/og/{slug}` page): that fetch round-trips back through oast.sh's own edge →
tunnel → origin and is subject to the site's bot management, `robots`, and the
CF-Connecting-IP rate limiter — any of which could challenge or throttle the
renderer fetching its own origin. Inlined HTML sidesteps all of it: Cloudflare's
browser fetches nothing from origin, and rendering is deterministic and
origin-independent.

### Statelessness

Origin holds zero state; the cache is the Cloudflare edge, keyed by URL. The
image route sits **outside** session/cookie middleware — a `Set-Cookie` on the
response would make Cloudflare refuse to cache it. Bare route, no session.

## Cache invalidation — content-hash URLs

`{hash} = substr(sha1(headline | severity-counts | cost | dimension), 0, 8)`,
computed on the `Publication`. The page's `og:image` meta emits the current
hash. Publishing or editing a review changes an input → changes the hash →
changes the URL → Cloudflare renders a fresh object. The old URL stays cached,
harmlessly orphaned. No purge API, no invalidation bookkeeping, nothing
stateful. The controller ignores the hash when rendering (it always renders the
current publication); the hash exists solely to make the URL change when
content does.

## Components

### `OgImageRenderer` (interface + two impls)

```
OgImageRenderer            screenshot(string $html, int $width, int $height): string  // PNG bytes
├─ CloudflareOgImageRenderer   Http::withToken()->post(browser-rendering/screenshot …) → body bytes
└─ FakeOgImageRenderer         returns a fixed 1×1 PNG fixture
```

Bound in `AppServiceProvider` to the Cloudflare impl; the test suite binds the
fake — the same seam as `NewsletterContacts`. Keeps live Chrome out of the
test path while holding the 100% coverage gate.

### Image controller — `GET /og/{slug}-{hash}.png`

Resolves the publication (home = default card), renders the self-contained OG
HTML, calls the renderer, returns `image/png` with the immutable cache header.
On renderer failure → fallback (below). Unknown slug → 404.

### `Publication` hash accessor

A method returning the 8-char content hash, unit-tested for stability and for
changing when any input changes.

### Self-contained OG Blade

The scoped-`<style>` + embedded-font template. Two variants (review card, home
card) as today; the controller renders these to the HTML string. The existing
`resources/views/site/og.blade.php` / `og-home.blade.php` are refactored from
`@vite`+Tailwind utilities to this self-contained form.

### Meta tags

`layout.blade.php` `og:image` changes from `asset('og/{slug}.png')` (static) to
the hashed dynamic route, on both home and review pages.

## Failure fallback

One committed `public/og/fallback.png` (the home card) — the single static image
that survives. On renderer failure (CF API down/timeout), the controller serves
it with a **short** TTL (`max-age=300`, not immutable) so the next crawl retries
rather than caching the fallback permanently. Exactly one static image exists,
never regenerated per-review — the manual per-review step stays gone.

## Infrastructure — OpenTofu

- **Runtime Cloudflare token**, scoped to Browser Rendering only — distinct from
  the `oast-tofu` provisioning token (DNS + Tunnel write). **Tofu mints it** via a
  `cloudflare_api_token` resource (Browser Rendering permission on the account),
  writes the value straight into Secrets Manager, and wires it into the ECS task
  env + execution-role read grant (alongside `app-key` / tunnel-token). No manual
  token step — the whole chain is `tofu apply`.
  - **Prerequisite:** minting a token via tofu requires the *provisioning* token
    (`oast-tofu`) to carry the user-level **"API Tokens Write"** permission, which
    it currently lacks. One-time broadening of `oast-tofu` (re-mint with DNS Write
    + Tunnel Write + API Tokens Write). The minted runtime token's value lands in
    tofu state (S3, encrypted) — same handling as the tunnel secret.
- `config/services.php` — a `cloudflare` block: `account_id` (known),
  `browser_token` (from env).

**Ops gate (runbook):** confirm Browser Rendering is enabled on the account
(free allocation; may ride the Workers plan) — a one-time check, flagged like
SES production access.

## Migration

1. Ship the dynamic path; verify a live crawl (Slack/Twitter/`opengraph.dev`)
   renders the card.
2. Swap `layout.blade.php` `og:image` to the hashed dynamic route.
3. Delete the four committed per-review PNGs and the static home PNG; keep
   `fallback.png`.
4. Keep the local-only HTML render routes for visual iteration.

## Testing (100% line + type coverage gate)

- Image controller: known slug → `200 image/png` + immutable cache header (fake
  renderer); unknown slug → `404`; renderer throws → `fallback.png` served with
  short TTL.
- `CloudflareOgImageRenderer` via `Http::fake()` — asserts endpoint, viewport,
  `screenshotOptions`, token header; returns the body bytes.
- Hash accessor: stable for identical inputs; changes when any input changes.
- Meta-tag assertion: home + review pages emit the hashed `og:image` URL.
- No live network or Chrome in the suite.

## Decisions locked

1. **Renderer:** Cloudflare Browser Rendering REST `screenshot`, HTML mode,
   self-contained payload.
2. **Cache:** Cloudflare edge, content-hash URLs, `immutable`; no purge logic;
   no app-level cache.
3. **Statelessness:** image route outside session middleware; origin stateless.
4. **Seam:** `OgImageRenderer` interface, real + fake, bound per environment.
5. **Fallback:** single committed `fallback.png`, short TTL on failure.
6. **Token:** runtime Browser-Rendering-scoped CF token, **minted by tofu**
   (`cloudflare_api_token`) into Secrets Manager — separate from the tofu
   provisioning token, which must gain "API Tokens Write" to mint it.
