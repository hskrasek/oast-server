# Implementation Plan

## Goal

Deliver the authenticated, organization-scoped M3B review workspace—history, paste/upload submission, same-origin SSE progress, source-aware inline findings, authorized deletion, readiness, and a supervised persistent self-host image—on the exact M3A contracts without changing public publication routes.

> **For agentic workers:** REQUIRED SUB-SKILL: use `superpowers:subagent-driven-development` or `superpowers:executing-plans`. Execute one task at a time, keep every failing/passing gate, and commit at each task boundary.

**Architecture:** Blade renders the authenticated application pages. Alpine owns only submission and live/report interaction. Native browser `EventSource` connects to the same-origin session route. A focused `yaml` 2.9 mapper resolves URI-fragment JSON Pointers against CST-aware YAML nodes and preserves original source line ranges. M3A remains authoritative for `OrganizationContext`, `ScopedReviewResolver`, `CreateReviewAction`, `DeleteReviewAction`, `ReviewEventsController`, policies, and `memberFixture`. The self-host image runs migrations before `supervisord`, which supervises FrankenPHP and database queue listeners against `/var/lib/oast`.

**Tech stack:** PHP 8.5, Laravel 13, Pest 4, Blade, Alpine.js 3.15, native `EventSource`, Tailwind CSS 4, Bun, Vite Plus, Vitest, `yaml` 2.9, FrankenPHP, `supervisord`, SQLite WAL.

## Global Constraints

- Complete M3A first. This plan consumes these exact M3A interfaces:
    - `CreateReviewAction::__invoke(string $spec, ReviewMode $mode, Organization $organization, ?User $creator, ?string $specRef = null, Dimension $dimension = Dimension::DomainModeling): Review`.
    - `ScopedReviewResolver::findOrFail(int|string $id): Review`.
    - `DeleteReviewAction::__invoke(Review $review): void`.
    - `ReviewEventsController` with scoped string review IDs, per-poll authorization, stream leases, `Last-Event-ID` replay, and `X-Accel-Buffering: no`.
    - `memberFixture(string $role = 'member'): array{User, Organization, OrganizationMembership}`.
- Keep public `GET /reviews` and `GET /reviews/{slug}` unchanged and backed only by `PublicationRepository`.
- All browser review routes are same-origin session routes. Native `EventSource` sends the session cookie; do not add bearer headers, query-string tokens, custom cursors, CORS, or cross-subdomain session behavior.
- Preserve immutable `reviews.spec` bytes. Never parse and reserialize submitted JSON/YAML.
- Source mapping is in scope and implemented in Task 2; it is not deferred.
- The only review statuses are `queued`, `running`, `judging`, `complete`, and `error`, with labels Queued, Running, Judging, Complete, and Failed.
- Use Blade + Alpine + native `EventSource`; do not add Livewire, React, Vue, an SPA router, or a second design system.
- Use Bun. Because the repository's Vite Plus test alias is not a reliable runner, JavaScript tests run with `bunx vitest run`; `bun run test:js` invokes that exact command.
- Keep PHP line and type coverage at 100%, PHPStan max/bleeding-edge, Pint/Rector/Vite formatting, SDK fakes, and no live LLM HTTP in the default suite.
- Use `declare(strict_types=1)`, final PHP classes, static routes before `{review}`, and organization scope before policy authorization.

## Tasks

### Task 1: Install the focused browser dependencies and runnable test harness

**Files:**

- Modify: `package.json`, `bun.lock`, `vite.config.js`, `resources/js/app.js`
- Create: `resources/js/smoke.test.js`

- [ ] **Step 1: Add the failing dependency smoke test**

Create `resources/js/smoke.test.js`:

```js
import { describe, expect, it } from "vitest";
import { LineCounter, parseDocument } from "yaml";

describe("M3B browser dependencies", () => {
    it("retains source positions", () => {
        const lineCounter = new LineCounter();
        const document = parseDocument("openapi: 3.1.0\n", { lineCounter });

        expect(document.get("openapi")).toBe("3.1.0");
        expect(lineCounter.linePos(0)).toEqual({ line: 1, col: 1 });
    });
});
```

- [ ] **Step 2: Confirm the test fails before installation**

Run:

```bash
bunx vitest run resources/js/smoke.test.js
```

Expected: FAIL because `vitest` and/or `yaml` is not installed as a direct project dependency.

- [ ] **Step 3: Install exact runtime ranges and the test runner**

Run:

```bash
bun add alpinejs@^3.15.0 yaml@^2.9.0
bun add --dev vitest@latest
```

Expected: only `package.json` and `bun.lock` change; no npm or pnpm lockfile appears.

Replace the `scripts` object in `package.json` with:

```json
"scripts": {
    "build": "vp build",
    "dev": "vp dev",
    "lint": "NODE_OPTIONS='--experimental-strip-types' vp fmt resources/",
    "test:lint": "NODE_OPTIONS='--experimental-strip-types' vp fmt --check resources/",
    "test:js": "bunx vitest run"
}
```

Add this sibling of `plugins` and `server` inside `defineConfig()` in `vite.config.js`:

```js
test: {
    include: ["resources/js/**/*.test.js"],
    environment: "node",
},
```

Replace `resources/js/app.js` with this complete M3A-compatible Alpine bootstrap; later tasks add two imports and registrations before `Alpine.start()`:

```js
import Alpine from "alpinejs";

window.Alpine = Alpine;
Alpine.start();

document.addEventListener("click", async (event) => {
    const target = event.target;
    if (!(target instanceof Element)) return;

    const copy = target.closest("[data-copy]");
    if (copy instanceof HTMLElement) {
        await navigator.clipboard.writeText(copy.dataset.copy ?? "");
        copy.textContent = "Copied";
    }

    const confirm = target.closest("[data-confirm]");
    if (
        confirm instanceof HTMLElement &&
        !window.confirm(confirm.dataset.confirm ?? "Are you sure?")
    ) {
        event.preventDefault();
    }
});
```

- [ ] **Step 4: Run the browser dependency gate**

Run:

```bash
bunx vitest run resources/js/smoke.test.js
bun run build
bun run test:lint
```

Expected: one Vitest test passes, the production bundle builds, and formatting passes.

- [ ] **Step 5: Commit**

```bash
git add package.json bun.lock vite.config.js resources/js/app.js resources/js/smoke.test.js
git commit -m "build: add M3B browser dependencies"
```

**Acceptance:** `bun run test:js` and direct `bunx vitest run` are both runnable without `vp test`.

---

### Task 2: Implement exact JSON Pointer to YAML/JSON source mapping

**Files:**

- Create: `resources/js/spec-source-map.js`
- Create: `resources/js/spec-source-map.test.js`

- [ ] **Step 1: Add the complete failing mapper suite**

Create `resources/js/spec-source-map.test.js`:

```js
import { describe, expect, it } from "vitest";
import { createSpecSourceMap } from "./spec-source-map.js";

const mapped = (source, pointer) => createSpecSourceMap(source).rangeFor(pointer);

describe("createSpecSourceMap", () => {
    it("maps a selected mapping pair from key start through node end", () => {
        const source = ["paths:", "  /orders:", "    get:", "      summary: List orders"].join(
            "\n",
        );

        expect(mapped(source, "#/paths/~1orders/get")).toEqual({
            startLine: 3,
            endLine: 4,
        });
    });

    it("splits delimiters before percent-decoding each segment", () => {
        const source = ["components:", "  a/b:", "    value: retained"].join("\n");

        expect(mapped(source, "#/components/a%2Fb")).toEqual({
            startLine: 2,
            endLine: 3,
        });
    });

    it("decodes RFC 6901 escapes after percent decoding", () => {
        expect(mapped('{"a b":{"~key/value":1}}', "#/a%20b/~0key~1value")).toEqual({
            startLine: 1,
            endLine: 1,
        });
    });

    it("uses the last syntactic duplicate key", () => {
        const source = ["operation:", "  summary: first", "  summary:", "    nested: last"].join(
            "\n",
        );

        expect(mapped(source, "#/operation/summary")).toEqual({
            startLine: 3,
            endLine: 4,
        });
    });

    it("resolves aliases to source pairs", () => {
        const source = [
            "defaults: &defaults",
            "  responses:",
            "    '200':",
            "      description: shared",
            "operation: *defaults",
        ].join("\n");

        expect(mapped(source, "#/operation/responses/200")).toEqual({
            startLine: 3,
            endLine: 4,
        });
    });

    it("resolves direct map merge sources and lets explicit keys win", () => {
        const source = [
            "operation:",
            "  <<:",
            "    summary: merged",
            "    responses: {}",
            "  summary: explicit",
        ].join("\n");

        expect(mapped(source, "#/operation/responses")).toEqual({
            startLine: 4,
            endLine: 4,
        });
        expect(mapped(source, "#/operation/summary")).toEqual({
            startLine: 5,
            endLine: 5,
        });
    });

    it("uses YAML merge-sequence precedence and returns the winning source pair", () => {
        const source = [
            "first: &first",
            "  summary: first wins",
            "second: &second",
            "  summary: second loses",
            "operation:",
            "  <<: [*first, *second]",
        ].join("\n");

        expect(mapped(source, "#/operation/summary")).toEqual({
            startLine: 2,
            endLine: 2,
        });
    });

    it("covers root and returns null for missing, malformed pointers, and invalid YAML", () => {
        const valid = createSpecSourceMap("openapi: 3.1.0\ninfo:\n  title: Demo\n");
        expect(valid.rangeFor("#")).toEqual({ startLine: 1, endLine: 4 });
        expect(valid.rangeFor("#/missing")).toBeNull();
        expect(valid.rangeFor("not-a-fragment")).toBeNull();
        expect(valid.rangeFor("#/%E0%A4%A")).toBeNull();

        const invalid = createSpecSourceMap("paths: [unterminated");
        expect(invalid.parseError).toBeTruthy();
        expect(invalid.lines).toEqual(["paths: [unterminated"]);
        expect(invalid.rangeFor("#")).toBeNull();
        expect(invalid.rangeFor("#/paths")).toBeNull();
    });
});
```

The merge-sequence expectation is deliberately line 2: YAML merge precedence gives an earlier map in `[*first, *second]` priority over a later map for the same key.

- [ ] **Step 2: Confirm the suite fails**

Run:

```bash
bunx vitest run resources/js/spec-source-map.test.js
```

Expected: FAIL because `spec-source-map.js` does not exist.

- [ ] **Step 3: Add the complete `yaml` 2.9 mapper**

Create `resources/js/spec-source-map.js`:

```js
import { LineCounter, isAlias, isMap, isScalar, isSeq, parseDocument } from "yaml";

function decodePointer(pointer) {
    if (pointer === "#") return [];
    if (!pointer.startsWith("#/")) return null;

    try {
        return pointer
            .slice(2)
            .split("/")
            .map((segment) =>
                decodeURIComponent(segment).replaceAll("~1", "/").replaceAll("~0", "~"),
            );
    } catch {
        return null;
    }
}

function resolveNode(node, document) {
    if (!node || !isAlias(node)) return node ?? null;

    try {
        return node.resolve(document) ?? null;
    } catch {
        return null;
    }
}

function keyText(node) {
    if (!isScalar(node)) return null;
    if (typeof node.value === "symbol") return node.value.description ?? null;

    return String(node.value);
}

function isMergePair(pair) {
    return keyText(pair.key) === "<<";
}

function mergeMaps(node, document) {
    const value = resolveNode(node, document);
    if (isMap(value)) return [value];
    if (!isSeq(value)) return [];

    return value.items.map((item) => resolveNode(item, document)).filter((item) => isMap(item));
}

function findMapChild(map, segment, document, seen) {
    for (let index = map.items.length - 1; index >= 0; index -= 1) {
        const pair = map.items[index];
        if (!isMergePair(pair) && keyText(pair.key) === segment) {
            return { node: resolveNode(pair.value, document), pair };
        }
    }

    if (seen.has(map)) return null;
    seen.add(map);

    for (let index = map.items.length - 1; index >= 0; index -= 1) {
        const mergePair = map.items[index];
        if (!isMergePair(mergePair)) continue;

        // YAML merge sequences give earlier sources precedence over later sources.
        for (const source of mergeMaps(mergePair.value, document)) {
            const selection = findMapChild(source, segment, document, seen);
            if (selection !== null) return selection;
        }
    }

    return null;
}

function childSelection(selection, segment, document) {
    const node = resolveNode(selection.node, document);
    if (isMap(node)) {
        return findMapChild(node, segment, document, new WeakSet());
    }

    if (!isSeq(node) || !/^(0|[1-9]\d*)$/.test(segment)) return null;
    const item = node.items[Number(segment)];
    if (!item) return null;

    return { node: resolveNode(item, document), pair: null };
}

function finalOffset(range) {
    if (!Array.isArray(range)) return null;

    return range[2] ?? range[1] ?? range[0] ?? null;
}

function selectionRange(selection, lineCounter) {
    const node = selection.node;
    const pair = selection.pair;
    const start = pair?.key?.range?.[0] ?? node?.range?.[0];
    const end = finalOffset(pair?.value?.range) ?? finalOffset(node?.range);
    if (!Number.isInteger(start) || !Number.isInteger(end)) return null;

    return {
        startLine: lineCounter.linePos(start).line,
        endLine: lineCounter.linePos(Math.max(start, end - 1)).line,
    };
}

export function createSpecSourceMap(source) {
    const lines = source.split("\n");
    const lineCounter = new LineCounter();
    let document = null;
    let parseError = null;

    try {
        document = parseDocument(source, {
            lineCounter,
            keepSourceTokens: true,
            merge: true,
            uniqueKeys: false,
        });
        parseError = document.errors[0]?.message ?? null;
    } catch (error) {
        parseError = error instanceof Error ? error.message : "The source could not be parsed.";
    }

    return {
        lines,
        parseError,
        rangeFor(pointer) {
            const segments = decodePointer(pointer);
            if (segments === null || parseError !== null || document === null) return null;
            if (segments.length === 0) {
                return { startLine: 1, endLine: Math.max(1, lines.length) };
            }

            let selection = { node: document.contents, pair: null };
            for (const segment of segments) {
                selection = childSelection(selection, segment, document);
                if (selection === null || selection.node === null) return null;
            }

            return selectionRange(selection, lineCounter);
        },
    };
}
```

This implementation splits on literal `/` delimiters before `decodeURIComponent`, then applies RFC 6901 decoding; retains the selected `Pair`; searches duplicate keys backward; recognizes both string `<<` and `Symbol(<<)` through `value.description`; resolves aliases; searches explicit keys before merges; and walks merge sequences in YAML precedence order.

- [ ] **Step 4: Run the exact mapper gate**

Run:

```bash
bunx vitest run resources/js/spec-source-map.test.js
bun run test:lint
```

Expected: all eight mapper tests pass under `yaml` `^2.9` and formatting passes.

- [ ] **Step 5: Commit**

```bash
git add resources/js/spec-source-map.js resources/js/spec-source-map.test.js
git commit -m "feat: map findings to retained source lines"
```

**Acceptance:** map ranges include mapping key lines, aliases and merges return winning source-pair ranges, and invalid/missing input never transforms or highlights source.

---

### Task 3: Add the exact browser route group, organization history, and navigation

**Files:**

- Create: `app/Http/Controllers/App/ReviewIndexController.php`
- Create: `resources/views/app/reviews/index.blade.php`
- Create: `tests/Feature/WebReviewIndexTest.php`
- Modify: `routes/web.php`, `resources/views/components/app-layout.blade.php`, `resources/css/app.css`

- [ ] **Step 1: Add the failing index tests**

Create `tests/Feature/WebReviewIndexTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Installation;
use App\Models\Organization;
use App\Models\Review;

beforeEach(function (): void {
    Installation::query()->findOrFail(1)->update(['bootstrapped_at' => now()]);
});

it('requires authentication for review history', function (): void {
    $this->get('/app/reviews')->assertRedirect('/login');
});

it('shows only current organization history and all real status labels', function (): void {
    [$user, $organization] = memberFixture(role: 'owner');
    $other = Organization::factory()->create();

    foreach (['queued', 'running', 'judging', 'complete', 'error'] as $status) {
        Review::factory()->for($organization)->create([
            'status' => $status,
            'spec_ref' => $status.'.yaml',
            'metrics' => $status === 'complete' ? [['total_cost_usd' => 0.125]] : null,
            'error' => $status === 'error' ? 'Panel quorum not met' : null,
        ]);
    }
    Review::factory()->for($other)->create(['spec_ref' => 'private.yaml']);

    $response = $this->actingAs($user)->get('/app/reviews')->assertOk();
    foreach (['Queued', 'Running', 'Judging', 'Complete', 'Failed'] as $label) {
        $response->assertSee($label);
    }
    $response->assertSee('$0.1250')->assertSee('Panel quorum not met')->assertDontSee('private.yaml');
});

it('renders an explicit empty state and create action', function (): void {
    [$user] = memberFixture(role: 'owner');

    $this->actingAs($user)->get('/app/reviews')->assertOk()
        ->assertSee('No reviews yet')
        ->assertSee('Start a review')
        ->assertSee(route('app.reviews.create'));
});
```

- [ ] **Step 2: Confirm the index tests fail**

Run:

```bash
vendor/bin/pest tests/Feature/WebReviewIndexTest.php
```

Expected: FAIL because the index route/controller/view do not exist.

- [ ] **Step 3: Replace M3A's browser review routes with one exact group**

In `routes/web.php`, remove the M3A nested `Route::prefix('reviews')->name('reviews.')` block from the `prefix('app')->name('app.')` group. Keep M3A home/settings routes in that group. Add these imports:

```php
use App\Http\Controllers\App\CreateReviewController;
use App\Http\Controllers\App\ReviewIndexController as AppReviewIndexController;
use App\Http\Controllers\App\ShowReviewController as AppShowReviewController;
use App\Http\Controllers\App\StoreReviewController;
use App\Http\Controllers\DeleteReviewController;
use App\Http\Controllers\ReviewEventsController;
```

Add this single replacement group after the M3A `/app` group. Names are relative, so they become `app.reviews.*` exactly—not `app.app.reviews.*`:

```php
Route::prefix('app/reviews')
    ->name('app.reviews.')
    ->middleware(['installation', 'auth', 'verified.configured', 'organization'])
    ->group(function (): void {
        Route::get('/', AppReviewIndexController::class)->name('index');
        Route::get('/create', CreateReviewController::class)->name('create');
        Route::post('/', StoreReviewController::class)->name('store');
        Route::get('/{review}', AppShowReviewController::class)->name('show');
        Route::get('/{review}/events', ReviewEventsController::class)->name('events');
        Route::delete('/{review}', DeleteReviewController::class)->name('destroy');
    });
```

The future M3B controller classes may be referenced before their task creates them; Laravel resolves them only when those routes are dispatched. The group preserves M3A `ReviewEventsController` and `DeleteReviewController`, and puts `/create` before `/{review}`.

- [ ] **Step 4: Add the complete index controller**

Create `app/Http/Controllers/App/ReviewIndexController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Organizations\OrganizationContext;
use Illuminate\Contracts\View\View;

final class ReviewIndexController
{
    public function __invoke(OrganizationContext $context): View
    {
        return view('app.reviews.index', [
            'reviews' => $context->organization()->reviews()->latest()->paginate(20),
            'statusLabels' => [
                'queued' => 'Queued',
                'running' => 'Running',
                'judging' => 'Judging',
                'complete' => 'Complete',
                'error' => 'Failed',
            ],
        ]);
    }
}
```

- [ ] **Step 5: Add the complete history view**

Create `resources/views/app/reviews/index.blade.php`:

```blade
<x-app-layout title="Reviews — oast">
    <section class="o-app-page" x-data="{ ready: false }" x-init="$nextTick(() => ready = true)">
        <header class="o-page-head">
            <div><p class="o-label">organization review history</p><h1 class="o-headline">Reviews</h1></div>
            <a class="o-btn" href="{{ route('app.reviews.create') }}">Start a review</a>
        </header>

        <div class="o-state-card" x-show="!ready" aria-live="polite">Loading reviews…</div>
        <div x-show="ready" x-cloak>
            @if ($reviews->isEmpty())
                <div class="o-state-card">
                    <h2 class="o-title">No reviews yet</h2>
                    <p>Paste or upload an OpenAPI document to ask the Council for a review.</p>
                    <a class="o-btn" href="{{ route('app.reviews.create') }}">Start a review</a>
                </div>
            @else
                <div class="o-review-list">
                    @foreach ($reviews as $review)
                        @php
                            $cost = collect($review->metrics ?? [])->first(
                                fn ($metric) => is_array($metric) && array_key_exists('total_cost_usd', $metric),
                            );
                        @endphp
                        <div class="o-review-row">
                            <a href="{{ route('app.reviews.show', $review->id) }}">
                                <strong>{{ $review->spec_ref ?: 'Pasted specification' }}</strong>
                                <small>{{ $review->created_at->toDayDateTimeString() }}</small>
                            </a>
                            <span class="o-status o-status-{{ $review->status }}">{{ $statusLabels[$review->status] }}</span>
                            <span class="o-review-cost">{{ $cost === null ? '—' : '$'.number_format((float) $cost['total_cost_usd'], 4) }}</span>
                            @if ($review->status === 'error')
                                <span class="o-review-error">{{ $review->error ?: 'The review could not complete.' }}</span>
                            @endif
                            @can('delete', $review)
                                <form method="POST" action="{{ route('app.reviews.destroy', $review->id) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button class="o-btn o-btn-danger" type="submit" data-confirm="Delete this review and its retained specification permanently?">Delete</button>
                                </form>
                            @endcan
                        </div>
                    @endforeach
                </div>
                {{ $reviews->links() }}
            @endif
        </div>
    </section>
</x-app-layout>
```

Add this link inside the existing `.o-settings-nav` in `resources/views/components/app-layout.blade.php`, without removing account/organization/token links or the POST logout form:

```blade
<a href="{{ route('app.reviews.index') }}" @if(request()->routeIs('app.reviews.*')) aria-current="page" @endif>Reviews</a>
```

Append this exact block to `resources/css/app.css`:

```css
@layer components {
    [x-cloak] {
        display: none !important;
    }
    .o-app-page {
        @apply mx-auto flex w-full max-w-page flex-col gap-7 px-6 py-10;
    }
    .o-page-head {
        @apply flex flex-col justify-between gap-5 sm:flex-row sm:items-end;
    }
    .o-state-card {
        @apply flex flex-col items-start gap-4 rounded-card border border-edge bg-raised p-6 font-mono text-mono-ui text-muted;
    }
    .o-review-list {
        @apply overflow-hidden rounded-card border border-edge;
    }
    .o-review-row {
        @apply grid gap-3 border-b border-hairline px-5 py-4 last:border-b-0 md:grid-cols-[1fr_110px_100px_auto] md:items-center;
    }
    .o-review-row:hover {
        @apply bg-raised;
    }
    .o-review-row a {
        @apply no-underline;
    }
    .o-review-row strong {
        @apply block font-mono text-mono-ui font-medium text-ink;
    }
    .o-review-row small {
        @apply mt-1 block font-mono text-mono-small text-subtle;
    }
    .o-status {
        @apply w-fit rounded-badge border border-edge-strong px-2 py-1 font-mono text-badge font-semibold uppercase tracking-badge;
    }
    .o-status-queued,
    .o-status-running {
        @apply text-muted;
    }
    .o-status-judging {
        @apply border-amber text-amber;
    }
    .o-status-complete {
        @apply border-success text-success;
    }
    .o-status-error {
        @apply border-danger text-danger;
    }
    .o-review-cost {
        @apply font-mono text-mono-ui text-ember md:text-right;
    }
    .o-review-error {
        @apply font-mono text-mono-small text-danger md:col-span-4;
    }
    .o-btn-danger {
        @apply border-danger bg-transparent text-danger;
    }
    .o-btn-danger:hover {
        @apply bg-ember text-on-accent;
    }
}
```

- [ ] **Step 6: Run the focused gate and commit**

```bash
vendor/bin/pest tests/Feature/WebReviewIndexTest.php
php artisan route:list --path=app/reviews
bun run build
bun run test:lint
git add app/Http/Controllers/App/ReviewIndexController.php routes/web.php resources/views/app/reviews/index.blade.php resources/views/components/app-layout.blade.php resources/css/app.css tests/Feature/WebReviewIndexTest.php
git commit -m "feat: add organization review history"
```

Expected: three Pest tests pass; the route list contains exactly `app.reviews.index/create/store/show/events/destroy` under the four required middleware; frontend gates pass.

**Acceptance:** no implicit review binding or nested `app.app.*` route names are introduced.

---

### Task 4: Add paste/upload submission with a real `202 + Location` transition

**Files:**

- Create: `app/Http/Controllers/App/CreateReviewController.php`, `app/Http/Controllers/App/StoreReviewController.php`
- Create: `app/Http/Requests/StoreWebReviewRequest.php`
- Create: `resources/views/app/reviews/create.blade.php`
- Create: `resources/js/review-submission.js`, `resources/js/review-submission.test.js`
- Modify: `resources/js/app.js`, `resources/css/app.css`
- Create: `tests/Feature/WebReviewSubmissionTest.php`

- [ ] **Step 1: Add failing PHP submission tests**

Create `tests/Feature/WebReviewSubmissionTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Installation;
use App\Models\Review;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;

beforeEach(function (): void {
    Installation::query()->findOrFail(1)->update(['bootstrapped_at' => now()]);
});

it('renders paste and upload controls', function (): void {
    [$user] = memberFixture();

    $this->actingAs($user)->get('/app/reviews/create')->assertOk()
        ->assertSee('Paste YAML or JSON')
        ->assertSee('Upload a file')
        ->assertSee('name="spec"', false)
        ->assertSee('name="spec_file"', false);
});

it('stores pasted source under trusted organization and creator and returns 202 location', function (): void {
    Bus::fake();
    [$user, $organization] = memberFixture();

    $response = $this->actingAs($user)->post('/app/reviews', [
        'spec' => "openapi: 3.1.0\ninfo:\n  title: Pasted\n",
        'mode' => 'council',
        'dimension' => 'domain-modeling',
        'organization_id' => 999999,
        'created_by_user_id' => 999999,
    ]);

    $review = Review::query()->sole();
    $response->assertAccepted()->assertHeader('Location', route('app.reviews.show', $review->id));
    expect($review->organization_id)->toBe($organization->id)
        ->and($review->created_by_user_id)->toBe($user->id)
        ->and($review->spec_ref)->toBeNull();
});

it('retains uploaded bytes and client filename without reserializing', function (): void {
    Bus::fake();
    [$user] = memberFixture();
    $source = "openapi: 3.1.0\n# retained comment\npaths: {}\n";

    $this->actingAs($user)->post('/app/reviews', [
        'spec_file' => UploadedFile::fake()->createWithContent('petstore.yaml', $source),
        'mode' => 'baseline',
        'dimension' => 'workflows',
    ])->assertAccepted();

    expect(Review::query()->sole()->only(['spec', 'spec_ref']))
        ->toBe(['spec' => $source, 'spec_ref' => 'petstore.yaml']);
});

it('rejects absent simultaneous and oversized sources', function (): void {
    [$user] = memberFixture();

    $this->actingAs($user)->post('/app/reviews', [
        'mode' => 'council', 'dimension' => 'domain-modeling',
    ])->assertSessionHasErrors(['spec', 'spec_file']);
    $this->actingAs($user)->post('/app/reviews', [
        'spec' => 'openapi: 3.1.0',
        'spec_file' => UploadedFile::fake()->createWithContent('also.yaml', 'openapi: 3.1.0'),
        'mode' => 'council', 'dimension' => 'domain-modeling',
    ])->assertSessionHasErrors(['spec', 'spec_file']);
    $this->actingAs($user)->post('/app/reviews', [
        'spec_file' => UploadedFile::fake()->create('huge.yaml', 5121),
        'mode' => 'council', 'dimension' => 'domain-modeling',
    ])->assertSessionHasErrors('spec_file');
});
```

- [ ] **Step 2: Add failing JavaScript submission tests**

Create `resources/js/review-submission.test.js`:

```js
import { describe, expect, it, vi } from "vitest";
import { reviewSubmission } from "./review-submission.js";

const form = {
    action: "/app/reviews",
    querySelector: () => ({ value: "csrf" }),
};

globalThis.FormData = class {
    constructor(value) {
        this.value = value;
    }
};

describe("reviewSubmission", () => {
    it("navigates only after 202 with Location", async () => {
        const assign = vi.fn();
        const fetch = vi.fn().mockResolvedValue({
            status: 202,
            headers: new Headers({ Location: "/app/reviews/42" }),
        });
        const component = reviewSubmission({ fetch, assign });

        await component.submit(form);

        expect(fetch).toHaveBeenCalledWith("/app/reviews", {
            method: "POST",
            body: expect.any(FormData),
            headers: { Accept: "application/json", "X-CSRF-TOKEN": "csrf" },
        });
        expect(assign).toHaveBeenCalledWith("/app/reviews/42");
        expect(component.submitting).toBe(false);
    });

    it("renders 422 errors and does not navigate", async () => {
        const assign = vi.fn();
        const component = reviewSubmission({
            fetch: vi.fn().mockResolvedValue({
                status: 422,
                headers: new Headers(),
                json: async () => ({ errors: { spec: ["Add a specification."] } }),
            }),
            assign,
        });

        await component.submit(form);

        expect(component.errors.spec).toEqual(["Add a specification."]);
        expect(assign).not.toHaveBeenCalled();
    });

    it("shows a safe failure for transport and malformed success responses", async () => {
        const offline = reviewSubmission({
            fetch: vi.fn().mockRejectedValue(new Error("offline")),
            assign: vi.fn(),
        });
        await offline.submit(form);
        expect(offline.failure).toBe("The review could not be submitted. Try again.");

        const malformed = reviewSubmission({
            fetch: vi.fn().mockResolvedValue({ status: 202, headers: new Headers() }),
            assign: vi.fn(),
        });
        await malformed.submit(form);
        expect(malformed.failure).toBe("The review could not be submitted. Try again.");
    });
});
```

- [ ] **Step 3: Confirm both suites fail**

```bash
vendor/bin/pest tests/Feature/WebReviewSubmissionTest.php
bunx vitest run resources/js/review-submission.test.js
```

Expected: missing request/controllers/view and missing JS module failures.

- [ ] **Step 4: Add the exact request contract**

Create `app/Http/Requests/StoreWebReviewRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Council\Dimension;
use App\Council\ReviewMode;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

final class StoreWebReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'spec' => ['nullable', 'string', 'required_without:spec_file', 'prohibited_with:spec_file'],
            'spec_file' => ['nullable', 'file', 'max:5120', 'required_without:spec', 'prohibited_with:spec'],
            'mode' => ['required', Rule::enum(ReviewMode::class)],
            'dimension' => ['required', Rule::enum(Dimension::class)],
        ];
    }

    public function spec(): string
    {
        $upload = $this->file('spec_file');

        return $upload instanceof UploadedFile ? (string) $upload->getContent() : $this->string('spec')->value();
    }

    public function specRef(): ?string
    {
        $upload = $this->file('spec_file');

        return $upload instanceof UploadedFile ? $upload->getClientOriginalName() : null;
    }

    public function mode(): ReviewMode
    {
        return ReviewMode::from((string) $this->validated('mode'));
    }

    public function dimension(): Dimension
    {
        return Dimension::from((string) $this->validated('dimension'));
    }
}
```

- [ ] **Step 5: Add the exact controllers using M3A's action signature**

Create `app/Http/Controllers/App/CreateReviewController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Council\Dimension;
use App\Council\ReviewMode;
use Illuminate\Contracts\View\View;

final class CreateReviewController
{
    public function __invoke(): View
    {
        return view('app.reviews.create', [
            'modes' => ReviewMode::cases(),
            'dimensions' => Dimension::cases(),
        ]);
    }
}
```

Create `app/Http/Controllers/App/StoreReviewController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Reviews\CreateReviewAction;
use App\Http\Requests\StoreWebReviewRequest;
use App\Models\User;
use App\Organizations\OrganizationContext;
use Illuminate\Http\Response;

final class StoreReviewController
{
    public function __invoke(
        StoreWebReviewRequest $request,
        CreateReviewAction $create,
        OrganizationContext $context,
    ): Response {
        $creator = $request->user();
        assert($creator instanceof User);

        $review = $create(
            $request->spec(),
            $request->mode(),
            $context->organization(),
            $creator,
            $request->specRef(),
            $request->dimension(),
        );

        return response('', 202)->header('Location', route('app.reviews.show', $review->id));
    }
}
```

- [ ] **Step 6: Add the complete submission component and page**

Create `resources/js/review-submission.js`:

```js
export function reviewSubmission({
    fetch = globalThis.fetch.bind(globalThis),
    assign = globalThis.location.assign.bind(globalThis.location),
} = {}) {
    return {
        source: "paste",
        submitting: false,
        errors: {},
        failure: null,
        async submit(form) {
            this.submitting = true;
            this.errors = {};
            this.failure = null;

            try {
                const response = await fetch(form.action || "/app/reviews", {
                    method: "POST",
                    body: new FormData(form),
                    headers: {
                        Accept: "application/json",
                        "X-CSRF-TOKEN": form.querySelector('[name="_token"]').value,
                    },
                });
                if (response.status === 422) {
                    this.errors = (await response.json()).errors ?? {};
                    return;
                }

                const location = response.headers.get("Location");
                if (response.status !== 202 || location === null) {
                    throw new Error("Unexpected review response");
                }
                assign(location);
            } catch {
                this.failure = "The review could not be submitted. Try again.";
            } finally {
                this.submitting = false;
            }
        },
    };
}
```

In `resources/js/app.js`, add before `Alpine.start()`:

```js
import { reviewSubmission } from "./review-submission.js";
Alpine.data("reviewSubmission", reviewSubmission);
```

Create `resources/views/app/reviews/create.blade.php`:

```blade
<x-app-layout title="New review — oast">
    <section class="o-app-page" x-data="reviewSubmission">
        <header class="o-page-head"><div><p class="o-label">new council run</p><h1 class="o-headline">Review a specification</h1></div></header>
        <form class="o-review-form" action="{{ route('app.reviews.store') }}" method="POST" enctype="multipart/form-data" @submit.prevent="submit($el)">
            @csrf
            <div class="o-source-tabs" role="tablist" aria-label="Specification source">
                <button type="button" role="tab" :aria-selected="source === 'paste'" @click="source = 'paste'">Paste YAML or JSON</button>
                <button type="button" role="tab" :aria-selected="source === 'upload'" @click="source = 'upload'">Upload a file</button>
            </div>
            <label x-show="source === 'paste'" class="o-field">
                <span>Paste YAML or JSON</span>
                <textarea name="spec" rows="20" :disabled="source !== 'paste'" placeholder="openapi: 3.1.0">{{ old('spec') }}</textarea>
                <template x-for="message in (errors.spec || [])"><small class="o-error" x-text="message"></small></template>
            </label>
            <label x-show="source === 'upload'" class="o-field">
                <span>Upload a file</span>
                <input type="file" name="spec_file" :disabled="source !== 'upload'" accept=".yaml,.yml,.json,application/json,application/yaml,text/yaml">
                <small>JSON or YAML, up to 5 MiB. Original bytes and comments are retained.</small>
                <template x-for="message in (errors.spec_file || [])"><small class="o-error" x-text="message"></small></template>
            </label>
            <div class="o-review-options">
                <label class="o-field"><span>Mode</span><select name="mode">@foreach($modes as $mode)<option value="{{ $mode->value }}">{{ ucfirst($mode->value) }}</option>@endforeach</select></label>
                <label class="o-field"><span>Dimension</span><select name="dimension">@foreach($dimensions as $dimension)<option value="{{ $dimension->value }}">{{ str($dimension->value)->replace('-', ' ')->title() }}</option>@endforeach</select></label>
            </div>
            <p class="o-error" x-show="failure" x-text="failure" role="alert"></p>
            <button class="o-btn" type="submit" :disabled="submitting" x-text="submitting ? 'Starting…' : 'Start review'"></button>
        </form>
    </section>
</x-app-layout>
```

Append to `resources/css/app.css`:

```css
@layer components {
    .o-review-form {
        @apply flex flex-col gap-6 rounded-card border border-edge bg-raised p-6;
    }
    .o-source-tabs {
        @apply flex gap-2;
    }
    .o-source-tabs button {
        @apply rounded-control border border-edge-strong px-4 py-2 font-mono text-mono-ui text-muted;
    }
    .o-source-tabs button[aria-selected="true"] {
        @apply border-ember text-ember;
    }
    .o-field {
        @apply flex flex-col gap-2 font-mono text-mono-ui text-muted;
    }
    .o-field textarea,
    .o-field select,
    .o-field input {
        @apply rounded-control border border-edge-strong bg-sunken px-3 py-3 font-mono text-mono-ui text-ink;
    }
    .o-review-options {
        @apply grid gap-4 sm:grid-cols-2;
    }
}
```

- [ ] **Step 7: Run and commit**

```bash
vendor/bin/pest tests/Feature/WebReviewSubmissionTest.php
bunx vitest run resources/js/review-submission.test.js
bun run build
bun run test:lint
git add app/Http/Controllers/App app/Http/Requests/StoreWebReviewRequest.php resources/views/app/reviews/create.blade.php resources/js resources/css/app.css tests/Feature/WebReviewSubmissionTest.php
git commit -m "feat: add web review submission"
```

Expected: four Pest tests and three Vitest tests pass; the browser navigates only from `202 + Location`.

**Acceptance:** no request field can override organization or creator, and exact uploaded/pasted source remains unchanged.

---

### Task 5: Add scoped show pages, testable EventSource state, and inline reports

**Files:**

- Create: `app/Http/Controllers/App/ShowReviewController.php`
- Create: `resources/views/app/reviews/show.blade.php`
- Create: `resources/js/review-workspace.js`, `resources/js/review-workspace.test.js`
- Modify: `resources/js/app.js`, `resources/css/app.css`
- Create: `tests/Feature/WebReviewShowTest.php`

- [ ] **Step 1: Add failing scoped show tests, including cross-organization 404**

Create `tests/Feature/WebReviewShowTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Installation;
use App\Models\Review;

beforeEach(function (): void {
    Installation::query()->findOrFail(1)->update(['bootstrapped_at' => now()]);
});

it('renders retained source and the same-origin session event route', function (): void {
    [$user, $organization] = memberFixture();
    $review = Review::factory()->for($organization)->create([
        'status' => 'running',
        'spec' => "openapi: 3.1.0\n# exact\n",
    ]);

    $this->actingAs($user)->get(route('app.reviews.show', $review->id))->assertOk()
        ->assertSee(route('app.reviews.events', $review->id))
        ->assertSee('Council progress')
        ->assertSee('Inline specification')
        ->assertDontSee('api.oast.test');
});

it('returns 404 for a review in another organization', function (): void {
    [$user] = memberFixture();
    [, $otherOrganization] = memberFixture();
    $review = Review::factory()->for($otherOrganization)->create();

    $this->actingAs($user)->get(route('app.reviews.show', $review->id))->assertNotFound();
});

it('renders a complete report and a terminal failure', function (): void {
    [$user, $organization] = memberFixture();
    $complete = Review::factory()->for($organization)->create([
        'status' => 'complete',
        'findings' => [validFinding(), validFinding([
            'severity' => 'consider',
            'title' => 'Consider pagination',
            'location' => '#/paths',
        ])],
    ]);
    $failed = Review::factory()->for($organization)->create([
        'status' => 'error',
        'error' => 'Panel quorum not met',
    ]);

    $this->actingAs($user)->get(route('app.reviews.show', $complete->id))->assertOk()
        ->assertSee('Blockers')->assertSee('Consider')->assertSee('#/paths');
    $this->actingAs($user)->get(route('app.reviews.show', $failed->id))->assertOk()
        ->assertSee('Review failed')->assertSee('Panel quorum not met');
});
```

- [ ] **Step 2: Add the complete failing EventSource suite**

Create `resources/js/review-workspace.test.js`:

```js
import { describe, expect, it, vi } from "vitest";
import { reviewWorkspace } from "./review-workspace.js";

class FakeEventSource {
    static CONNECTING = 0;
    static OPEN = 1;
    static CLOSED = 2;

    constructor(url) {
        this.url = url;
        this.readyState = FakeEventSource.CONNECTING;
        this.listeners = {};
        this.close = vi.fn();
    }

    addEventListener(name, listener) {
        this.listeners[name] = listener;
    }
    emit(name, data) {
        this.listeners[name]?.({ data: JSON.stringify(data) });
    }
}

function options(overrides = {}) {
    return {
        eventsUrl: "/app/reviews/1/events",
        status: "running",
        findings: [],
        spec: "paths: {}\n",
        eventSourceConstructor: FakeEventSource,
        eventSourceClosedState: FakeEventSource.CLOSED,
        ...overrides,
    };
}

describe("reviewWorkspace", () => {
    it("streams lifecycle events and becomes terminal on completion", () => {
        const workspace = reviewWorkspace(options());
        workspace.init();
        const source = workspace.eventSource;
        source.readyState = FakeEventSource.OPEN;
        source.onopen();
        source.emit("panel.model.start", { model: "openai/gpt-5.5" });
        source.emit("judge.start", { model: "judge/strong" });
        source.emit("review.completed", {
            findings: [{ severity: "blocker", location: "#/paths", title: "Model paths" }],
        });

        expect(workspace.status).toBe("complete");
        expect(workspace.connection).toBe("terminal");
        expect(workspace.events).toHaveLength(2);
        expect(workspace.groupedFindings.blocker).toHaveLength(1);
        expect(source.close).toHaveBeenCalledOnce();
    });

    it("models reconnecting and disconnected without a Node EventSource global", () => {
        const workspace = reviewWorkspace(options());
        workspace.init();
        const source = workspace.eventSource;

        source.readyState = FakeEventSource.CONNECTING;
        source.onerror();
        expect(workspace.connection).toBe("reconnecting");

        source.readyState = FakeEventSource.CLOSED;
        source.onerror();
        expect(workspace.connection).toBe("disconnected");
    });

    it("does not connect for persisted complete or error states", () => {
        const constructor = vi.fn();
        for (const status of ["complete", "error"]) {
            const workspace = reviewWorkspace(
                options({ status, eventSourceConstructor: constructor }),
            );
            workspace.init();
            expect(workspace.connection).toBe("terminal");
        }
        expect(constructor).not.toHaveBeenCalled();
    });

    it("maps queued judging and failure lifecycle states", () => {
        const workspace = reviewWorkspace(options({ status: "queued" }));
        workspace.init();
        workspace.eventSource.emit("review.queued", {});
        expect(workspace.status).toBe("running");
        workspace.eventSource.emit("judge.start", { model: "judge" });
        expect(workspace.status).toBe("judging");
        workspace.eventSource.emit("review.failed", { problem: { title: "Failed" } });
        expect(workspace.status).toBe("error");
        expect(workspace.connection).toBe("terminal");
    });

    it("selects exact ranges and supplies missing and parse fallbacks", () => {
        const workspace = reviewWorkspace(
            options({
                status: "complete",
                spec: "paths:\n  /orders: {}\n",
            }),
        );
        workspace.selectFinding({ location: "#/paths/~1orders" });
        expect(workspace.selectedRange).toEqual({ startLine: 2, endLine: 2 });
        workspace.selectFinding({ location: "#/missing" });
        expect(workspace.selectedRange).toBeNull();
        expect(workspace.sourceMessage).toContain("could not be located");

        const invalid = reviewWorkspace(options({ status: "complete", spec: "paths: [" }));
        invalid.selectFinding({ location: "#/paths" });
        expect(invalid.sourceMessage).toContain("Exact highlighting is unavailable");
    });
});
```

The fake is injected; the production module never reads a missing Node `EventSource` global or its static `CLOSED` value during these tests.

- [ ] **Step 3: Confirm both suites fail**

```bash
vendor/bin/pest tests/Feature/WebReviewShowTest.php
bunx vitest run resources/js/review-workspace.test.js
```

Expected: missing controller/view/module failures.

- [ ] **Step 4: Add the scoped string-ID show controller**

Create `app/Http/Controllers/App/ShowReviewController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Reviews\ScopedReviewResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

final class ShowReviewController
{
    public function __invoke(string $review, ScopedReviewResolver $resolver): View
    {
        $model = $resolver->findOrFail($review);
        Gate::authorize('view', $model);

        return view('app.reviews.show', ['review' => $model]);
    }
}
```

Do not change this parameter to `Review $review`; the resolver is the tenant boundary.

- [ ] **Step 5: Add the complete testable workspace module**

Create `resources/js/review-workspace.js`:

```js
import { createSpecSourceMap } from "./spec-source-map.js";

const STREAM_EVENTS = [
    "review.queued",
    "panel.model.start",
    "panel.model.done",
    "panel.model.failed",
    "panel.model.late",
    "judge.start",
    "judge.done",
    "review.completed",
    "review.failed",
];
const TERMINAL = new Set(["complete", "error"]);

export function reviewWorkspace({
    eventsUrl,
    status,
    findings = [],
    spec,
    eventSourceConstructor = globalThis.EventSource,
    eventSourceClosedState = 2,
}) {
    const sourceMap = createSpecSourceMap(spec);

    return {
        status,
        connection: "loading",
        events: [],
        findings,
        lines: sourceMap.lines,
        selectedRange: null,
        sourceMessage: sourceMap.parseError
            ? "Exact highlighting is unavailable; showing the retained raw specification."
            : null,
        eventSource: null,
        get groupedFindings() {
            return {
                blocker: this.findings.filter((finding) => finding.severity === "blocker"),
                "should-fix": this.findings.filter((finding) => finding.severity === "should-fix"),
                consider: this.findings.filter((finding) => finding.severity === "consider"),
            };
        },
        init() {
            if (TERMINAL.has(this.status)) {
                this.connection = "terminal";
                return;
            }
            if (typeof eventSourceConstructor !== "function") {
                this.connection = "disconnected";
                return;
            }

            this.eventSource = new eventSourceConstructor(eventsUrl);
            this.eventSource.onopen = () => {
                this.connection = "connected";
            };
            this.eventSource.onerror = () => {
                this.connection =
                    this.eventSource.readyState === eventSourceClosedState
                        ? "disconnected"
                        : "reconnecting";
            };
            for (const name of STREAM_EVENTS) {
                this.eventSource.addEventListener(name, (event) => {
                    this.consume(name, JSON.parse(event.data));
                });
            }
        },
        consume(name, data) {
            if (name === "review.queued") this.status = "running";
            if (name === "judge.start") this.status = "judging";

            if (name === "review.completed") {
                this.status = "complete";
                this.findings = data.findings ?? [];
                this.connection = "terminal";
                this.destroy();
                return;
            }
            if (name === "review.failed") {
                this.status = "error";
                this.connection = "terminal";
                this.destroy();
                return;
            }

            this.events.push({ name, data });
        },
        selectFinding(finding) {
            this.selectedRange = sourceMap.rangeFor(finding.location);
            this.sourceMessage = this.selectedRange
                ? null
                : sourceMap.parseError
                  ? "Exact highlighting is unavailable; showing the retained raw specification."
                  : `The source for ${finding.location} could not be located; showing the retained raw specification.`;
        },
        isHighlighted(line) {
            return (
                this.selectedRange !== null &&
                line >= this.selectedRange.startLine &&
                line <= this.selectedRange.endLine
            );
        },
        destroy() {
            this.eventSource?.close();
        },
    };
}
```

In `resources/js/app.js`, add before `Alpine.start()`:

```js
import { reviewWorkspace } from "./review-workspace.js";
Alpine.data("reviewWorkspace", reviewWorkspace);
```

- [ ] **Step 6: Add the complete combined live/report view**

Create `resources/views/app/reviews/show.blade.php`:

```blade
<x-app-layout title="Review {{ $review->id }} — oast">
    <section class="o-app-page" x-data="reviewWorkspace({
        eventsUrl: @js(route('app.reviews.events', $review->id)),
        status: @js($review->status),
        findings: @js($review->findings ?? []),
        spec: @js((string) $review->spec)
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
```

Append to `resources/css/app.css`:

```css
@layer components {
    .o-page-actions {
        @apply flex items-center gap-3;
    }
    .o-stream-banner {
        @apply rounded-control border border-edge bg-raised px-4 py-3 font-mono text-mono-small text-muted;
    }
    .o-stream-banner-error {
        @apply border-danger text-danger;
    }
    .o-progress-panel {
        @apply rounded-card border border-edge bg-sunken p-6;
    }
    .o-progress-panel ol {
        @apply mt-5 flex flex-col gap-2 font-mono text-mono-small;
    }
    .o-progress-panel li {
        @apply flex justify-between border-b border-hairline py-2 text-muted;
    }
    .o-report-grid {
        @apply grid min-w-0 gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(420px,0.8fr)];
    }
    .o-findings-pane,
    .o-finding-group {
        @apply flex min-w-0 flex-col gap-5;
    }
    .o-source-pane {
        @apply min-w-0 self-start overflow-hidden rounded-card border border-edge bg-sunken xl:sticky xl:top-5;
    }
    .o-source-pane > h2,
    .o-source-fallback {
        @apply border-b border-edge-soft px-4 py-3;
    }
    .o-source-fallback {
        @apply font-mono text-mono-small text-amber;
    }
    .o-source-pane pre {
        @apply max-h-[72vh] overflow-auto py-3 font-mono text-[12px] leading-5;
    }
    .o-source-line {
        @apply grid min-h-5 grid-cols-[52px_1fr] px-3;
    }
    .o-source-line i {
        @apply select-none pr-4 text-right not-italic text-faint;
    }
    .o-source-line b {
        @apply whitespace-pre font-normal text-body;
    }
    .o-source-line.is-highlighted {
        @apply bg-voice-a-wash;
        box-shadow: inset 3px 0 var(--color-ember);
    }
}
```

- [ ] **Step 7: Run and commit**

```bash
vendor/bin/pest tests/Feature/WebReviewShowTest.php
bunx vitest run resources/js/spec-source-map.test.js resources/js/review-workspace.test.js
bun run build
bun run test:lint
git add app/Http/Controllers/App/ShowReviewController.php resources/views/app/reviews/show.blade.php resources/js resources/css/app.css tests/Feature/WebReviewShowTest.php
git commit -m "feat: stream reviews into source-aware reports"
```

Expected: cross-org show is 404; completion expects `connection === 'terminal'`; no Node global `EventSource` is referenced; source mapping and report tests pass.

**Acceptance:** browser SSE is same-origin/session-authenticated and terminal persisted pages do not open a stream.

---

### Task 6: Preserve M3A deletion actions and public publication isolation

**Files:**

- Modify: `app/Http/Controllers/DeleteReviewController.php`
- Modify: `tests/Feature/ReviewDeletionTest.php`
- Create: `tests/Feature/WebReviewDeletionUiTest.php`
- Modify: `tests/Feature/SitePagesTest.php`

- [ ] **Step 1: Add failing UI and public-route regression tests**

Create `tests/Feature/WebReviewDeletionUiTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Installation;
use App\Models\OrganizationMembership;
use App\Models\Review;
use App\Models\User;

beforeEach(function (): void {
    Installation::query()->findOrFail(1)->update(['bootstrapped_at' => now()]);
});

it('shows deletion to creator and owner but not an unrelated member', function (): void {
    [$owner, $organization] = memberFixture(role: 'owner');
    $creator = User::factory()->create();
    $member = User::factory()->create();
    OrganizationMembership::factory()->for($organization)->for($creator)->create();
    OrganizationMembership::factory()->for($organization)->for($member)->create();
    $review = Review::factory()->for($organization)->create(['created_by_user_id' => $creator->id]);
    $action = route('app.reviews.destroy', $review->id);

    $this->actingAs($creator)->get(route('app.reviews.show', $review->id))->assertSee($action)->assertSee('_method', false);
    $this->actingAs($owner)->get(route('app.reviews.show', $review->id))->assertSee($action);
    $this->actingAs($member)->get(route('app.reviews.show', $review->id))->assertDontSee($action);
});

it('deletes through the scoped M3A action and redirects to history', function (): void {
    [$owner, $organization] = memberFixture(role: 'owner');
    $review = Review::factory()->for($organization)->create();

    $this->actingAs($owner)->delete(route('app.reviews.destroy', $review->id))
        ->assertRedirect(route('app.reviews.index'))
        ->assertSessionHas('status', 'Review deleted.');
    $this->assertDatabaseMissing('reviews', ['id' => $review->id]);
});

it('returns 404 before deletion policy for another organization', function (): void {
    [$owner] = memberFixture(role: 'owner');
    [, $otherOrganization] = memberFixture(role: 'owner');
    $review = Review::factory()->for($otherOrganization)->create();

    $this->actingAs($owner)->delete(route('app.reviews.destroy', $review->id))->assertNotFound();
});
```

Append to `tests/Feature/SitePagesTest.php`:

```php
it('keeps public review routes publication-backed and unauthenticated', function (): void {
    $private = App\Models\Review::factory()->create([
        'id' => 999,
        'spec_ref' => 'private-organization.yaml',
    ]);

    $this->get('/reviews')->assertOk()->assertSee('Train Travel API')->assertDontSee($private->spec_ref);
    $this->get('/reviews/train-travel-domain-modeling')->assertOk()->assertSee('Booking lifecycle never modeled as data');
    $this->get('/reviews/999')->assertNotFound();
});
```

- [ ] **Step 2: Confirm the redirect expectation fails**

```bash
vendor/bin/pest tests/Feature/WebReviewDeletionUiTest.php tests/Feature/SitePagesTest.php
```

Expected: M3A's deletion controller still returns 204, so the browser redirect assertion fails.

- [ ] **Step 3: Replace only the M3A browser deletion controller response**

Replace `app/Http/Controllers/DeleteReviewController.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Reviews\DeleteReviewAction;
use App\Reviews\ScopedReviewResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

final class DeleteReviewController
{
    public function __invoke(
        string $review,
        ScopedReviewResolver $resolver,
        DeleteReviewAction $delete,
    ): RedirectResponse {
        $model = $resolver->findOrFail($review);
        Gate::authorize('delete', $model);
        $delete($model);

        return to_route('app.reviews.index')->with('status', 'Review deleted.');
    }
}
```

In `tests/Feature/ReviewDeletionTest.php`, replace each browser `assertNoContent()` after an authorized delete with:

```php
->assertRedirect(route('app.reviews.index'));
```

Do not change `DeleteReviewAction`, `ReviewPolicy`, `ScopedReviewResolver`, cascades, or add an API delete route.

- [ ] **Step 4: Run and commit**

```bash
vendor/bin/pest tests/Feature/WebReviewDeletionUiTest.php tests/Feature/ReviewDeletionTest.php tests/Feature/SitePagesTest.php
git add app/Http/Controllers/DeleteReviewController.php tests/Feature/WebReviewDeletionUiTest.php tests/Feature/ReviewDeletionTest.php tests/Feature/SitePagesTest.php
git commit -m "feat: add authorized review deletion UI"
```

Expected: creator/owner/member authorization, cross-org 404, cascades, redirect, and public publication regressions all pass.

**Acceptance:** public `/reviews/*` remains unchanged and organization-owned numeric review IDs are never exposed there.

---

### Task 7: Replace process-only `/up` with database and migration readiness

**Files:**

- Create: `app/Http/Controllers/ReadinessController.php`
- Create: `tests/Feature/ReadinessTest.php`
- Modify: `bootstrap/app.php`, `routes/web.php`

- [ ] **Step 1: Add complete failing readiness tests**

Create `tests/Feature/ReadinessTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\MigrationRepositoryInterface;
use Illuminate\Database\Migrations\Migrator;

it('is ready only when database and migrations are current', function (): void {
    $this->getJson('/up')->assertOk()->assertExactJson(['status' => 'ready']);
});

it('is not ready when the migration repository is absent', function (): void {
    $migrator = Mockery::mock(Migrator::class);
    $migrator->shouldReceive('repositoryExists')->once()->andReturnFalse();
    app()->instance(Migrator::class, $migrator);

    $this->getJson('/up')->assertServiceUnavailable()->assertExactJson(['status' => 'not ready']);
});

it('is not ready for pending migrations', function (): void {
    $repository = Mockery::mock(MigrationRepositoryInterface::class);
    $repository->shouldReceive('getRan')->once()->andReturn([]);
    $migrator = Mockery::mock(Migrator::class);
    $migrator->shouldReceive('repositoryExists')->once()->andReturnTrue();
    $migrator->shouldReceive('getMigrationFiles')->once()->andReturn([
        '2026_pending' => '/tmp/2026_pending.php',
    ]);
    $migrator->shouldReceive('getRepository')->once()->andReturn($repository);
    app()->instance(Migrator::class, $migrator);

    $this->getJson('/up')->assertServiceUnavailable()->assertExactJson(['status' => 'not ready']);
});

it('does not expose database exceptions', function (): void {
    $migrator = Mockery::mock(Migrator::class);
    $migrator->shouldReceive('repositoryExists')->once()->andThrow(new RuntimeException('database down'));
    app()->instance(Migrator::class, $migrator);

    $this->getJson('/up')->assertServiceUnavailable()->assertExactJson(['status' => 'not ready'])->assertDontSee('database down');
});
```

- [ ] **Step 2: Confirm non-ready branches fail**

```bash
vendor/bin/pest tests/Feature/ReadinessTest.php
```

Expected: the default Laravel health route does not enforce the migration contract.

- [ ] **Step 3: Add the readiness controller and explicit public route**

Create `app/Http/Controllers/ReadinessController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ReadinessController
{
    public function __invoke(Migrator $migrator): JsonResponse
    {
        try {
            DB::connection()->getPdo();
            if (! $migrator->repositoryExists()) {
                return response()->json(['status' => 'not ready'], 503);
            }

            $files = $migrator->getMigrationFiles(database_path('migrations'));
            $ran = $migrator->getRepository()->getRan();
            if (array_diff(array_keys($files), $ran) !== []) {
                return response()->json(['status' => 'not ready'], 503);
            }

            return response()->json(['status' => 'ready']);
        } catch (Throwable) {
            return response()->json(['status' => 'not ready'], 503);
        }
    }
}
```

In `bootstrap/app.php`, replace:

```php
health: '/up',
```

with:

```php
health: null,
```

Add this import and route near the top of `routes/web.php`, outside every setup/auth/organization group and before parameterized public routes:

```php
use App\Http\Controllers\ReadinessController;

Route::get('/up', ReadinessController::class)->name('up');
```

- [ ] **Step 4: Run and commit**

```bash
vendor/bin/pest tests/Feature/ReadinessTest.php tests/Feature/SitePagesTest.php
git add app/Http/Controllers/ReadinessController.php bootstrap/app.php routes/web.php tests/Feature/ReadinessTest.php
git commit -m "feat: add database migration readiness"
```

Expected: all four readiness branches and public site regressions pass.

**Acceptance:** `/up` returns 200 only after DB connectivity, migration repository existence, and zero pending migrations.

---

### Task 8: Ship the supervised persistent self-host image with strict key validation

**Files:**

- Replace: `Dockerfile`
- Create: `docker/validate-app-key`, `docker/entrypoint.sh`, `docker/supervisord.conf`, `docker/oast-worker-health`, `docker/README.md`
- Create: `tests/Feature/SelfHostImageContractTest.php`
- Modify: `.dockerignore`, `.env.example`, `config/site.php`, `config/queue.php`, `.github/workflows/ci.yml`

- [ ] **Step 1: Add complete failing image/key contract tests**

Create `tests/Feature/SelfHostImageContractTest.php`:

```php
<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('defines the supervised persistent database-backed contract', function (): void {
    $dockerfile = (string) file_get_contents(base_path('Dockerfile'));
    $supervisor = (string) file_get_contents(base_path('docker/supervisord.conf'));
    $entrypoint = (string) file_get_contents(base_path('docker/entrypoint.sh'));
    $readme = (string) file_get_contents(base_path('docker/README.md'));

    expect($dockerfile)
        ->toContain('supervisor', 'VOLUME ["/var/lib/oast"]', 'OAST_QUEUE_WORKERS=1', 'DB_QUEUE_RETRY_AFTER=960', 'HEALTHCHECK')
        ->and($supervisor)->toContain('frankenphp php-server', 'queue:listen --tries=3 --timeout=900', 'autorestart=unexpected')
        ->and($entrypoint)->toContain('APP_KEY_FILE', 'rtrim($value, "\\r\\n")', 'php artisan migrate --force', '/var/lib/oast/publications')
        ->and($readme)->toContain('proxy_buffering off', 'proxy_read_timeout 600s', 'OAST_QUEUE_WORKERS', 'Backup and restore', 'DB_QUEUE_RETRY_AFTER=960');
});

it('rejects missing malformed and explicit raw or base64 sentinel keys', function (string $key): void {
    $process = new Process([base_path('docker/validate-app-key')], base_path(), ['APP_KEY' => $key]);
    $process->run();

    expect($process->getExitCode())->toBe(64)
        ->and($process->getErrorOutput())->toContain('APP_KEY must be a non-placeholder 32-byte Laravel key.');
})->with([
    'missing' => '',
    'malformed raw' => 'short',
    'malformed base64' => 'base64:not-valid-***',
    'example raw' => '01234567890123456789012345678901',
    'zero raw' => '00000000000000000000000000000000',
    'example base64' => 'base64:MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTIzNDU2Nzg5MDE=',
    'zero base64' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
]);

it('accepts valid raw and base64 keys', function (string $key): void {
    $process = new Process([base_path('docker/validate-app-key')], base_path(), ['APP_KEY' => $key]);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
})->with([
    'raw' => 'a9!B2@cD3#eF4$gH5%iJ6^kL7&mN8*pQ',
    'base64' => 'base64:YWJjZGVmZ2hpamtsbW5vcHFyc3R1dnd4eXowMTIzNDU=',
]);
```

The raw valid fixture is exactly 32 bytes; the base64 fixture decodes to exactly 32 bytes. The sentinel fixtures are explicit and must remain synchronized with `docker/validate-app-key` and `docker/README.md`.

- [ ] **Step 2: Confirm tests fail**

```bash
vendor/bin/pest tests/Feature/SelfHostImageContractTest.php
```

Expected: missing Docker scripts and incomplete image contract failures.

- [ ] **Step 3: Add executable strict key validation**

Create `docker/validate-app-key`:

```sh
#!/bin/sh
set -eu

if ! php -r '
$key = getenv("APP_KEY");
$key = is_string($key) ? $key : "";
$sentinels = [
    "01234567890123456789012345678901",
    "00000000000000000000000000000000",
];
if ($key === "") { exit(1); }
if (str_starts_with($key, "base64:")) {
    $decoded = base64_decode(substr($key, 7), true);
    if (! is_string($decoded) || strlen($decoded) !== 32 || in_array($decoded, $sentinels, true)) { exit(1); }
    exit(0);
}
if (strlen($key) !== 32 || in_array($key, $sentinels, true)) { exit(1); }
exit(0);
'; then
    echo "APP_KEY must be a non-placeholder 32-byte Laravel key." >&2
    exit 64
fi
```

- [ ] **Step 4: Replace the Dockerfile with the exact multi-stage runtime**

Replace `Dockerfile`:

```dockerfile
FROM oven/bun:1 AS assets
WORKDIR /build
COPY package.json bun.lock .npmrc vite.config.js ./
RUN bun install --frozen-lockfile
COPY resources ./resources
RUN bun run build

FROM composer:2 AS vendor
WORKDIR /build
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
RUN composer dump-autoload --optimize --no-dev

FROM dunglas/frankenphp:1-php8.5 AS runtime
USER root
RUN apt-get update \
 && apt-get install -y --no-install-recommends supervisor curl \
 && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY --from=vendor /build /app
COPY --from=assets /build/public/build /app/public/build
COPY docker/supervisord.conf /etc/supervisor/conf.d/oast.conf
COPY docker/entrypoint.sh /usr/local/bin/oast-entrypoint
COPY docker/validate-app-key /usr/local/bin/validate-app-key
COPY docker/oast-worker-health /usr/local/bin/oast-worker-health
RUN chmod 0755 /usr/local/bin/oast-entrypoint /usr/local/bin/validate-app-key /usr/local/bin/oast-worker-health \
 && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache /var/lib/oast/publications \
 && chown -R www-data:www-data storage bootstrap/cache /var/lib/oast
ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/var/lib/oast/database.sqlite \
    DB_JOURNAL_MODE=WAL \
    DB_BUSY_TIMEOUT=5000 \
    DB_TRANSACTION_MODE=IMMEDIATE \
    DB_QUEUE_RETRY_AFTER=960 \
    SESSION_DRIVER=database \
    CACHE_STORE=database \
    QUEUE_CONNECTION=database \
    SITE_PUBLICATIONS_PATH=/var/lib/oast/publications \
    OAST_QUEUE_WORKERS=1 \
    SERVER_NAME=:8080
VOLUME ["/var/lib/oast"]
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 CMD curl --fail --silent http://127.0.0.1:8080/up >/dev/null && /usr/local/bin/oast-worker-health
ENTRYPOINT ["/usr/local/bin/oast-entrypoint"]
```

- [ ] **Step 5: Add the exact entrypoint and supervisor configuration**

Create `docker/entrypoint.sh`:

```sh
#!/bin/sh
set -eu

if [ -z "${APP_KEY:-}" ] && [ -n "${APP_KEY_FILE:-}" ]; then
    if [ ! -r "$APP_KEY_FILE" ]; then
        echo "APP_KEY_FILE is not readable." >&2
        exit 64
    fi
    APP_KEY="$(php -r '$value = file_get_contents($argv[1]); if (! is_string($value)) { exit(1); } echo rtrim($value, "\r\n");' "$APP_KEY_FILE")"
    export APP_KEY
fi

/usr/local/bin/validate-app-key

OAST_QUEUE_WORKERS="${OAST_QUEUE_WORKERS:-1}"
export OAST_QUEUE_WORKERS
case "$OAST_QUEUE_WORKERS" in
    ''|*[!0-9]*|0) echo "OAST_QUEUE_WORKERS must be a positive integer." >&2; exit 64 ;;
esac
if [ "$OAST_QUEUE_WORKERS" -gt 1 ]; then
    echo "Warning: multiple queue workers increase SQLite write contention." >&2
fi

mkdir -p /var/lib/oast/publications storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache
touch /var/lib/oast/database.sqlite
cp -n database/publications/*.json /var/lib/oast/publications/ 2>/dev/null || true
chown -R www-data:www-data /var/lib/oast storage bootstrap/cache
su -s /bin/sh www-data -c 'php artisan config:clear && php artisan migrate --force'
exec /usr/bin/supervisord -n -c /etc/supervisor/supervisord.conf
```

`rtrim($value, "\r\n")` removes only mounted-secret trailing CR/LF bytes; it does not trim spaces or mutate an environment-provided key.

Create `docker/supervisord.conf`:

```ini
[supervisord]
nodaemon=true
logfile=/dev/null
pidfile=/tmp/supervisord.pid

[unix_http_server]
file=/tmp/supervisor.sock
chmod=0700

[supervisorctl]
serverurl=unix:///tmp/supervisor.sock

[rpcinterface:supervisor]
supervisor.rpcinterface_factory=supervisor.rpcinterface:make_main_rpcinterface

[program:web]
command=frankenphp php-server --root /app/public --listen :8080
directory=/app
user=www-data
autostart=true
autorestart=unexpected
stopsignal=TERM
stopasgroup=true
killasgroup=true
stdout_logfile=/dev/fd/1
stdout_logfile_maxbytes=0
stderr_logfile=/dev/fd/2
stderr_logfile_maxbytes=0

[program:queue]
command=php artisan queue:listen --tries=3 --timeout=900
directory=/app
user=www-data
numprocs=%(ENV_OAST_QUEUE_WORKERS)s
process_name=%(program_name)s_%(process_num)02d
autostart=true
autorestart=unexpected
stopsignal=TERM
stopwaitsecs=930
stopasgroup=true
killasgroup=true
stdout_logfile=/dev/fd/1
stdout_logfile_maxbytes=0
stderr_logfile=/dev/fd/2
stderr_logfile_maxbytes=0
```

Create `docker/oast-worker-health`:

```sh
#!/bin/sh
set -eu
expected="${OAST_QUEUE_WORKERS:-1}"
running="$(supervisorctl status 'queue:*' 2>/dev/null | awk '$2 == "RUNNING" { count++ } END { print count + 0 }')"
[ "$running" -eq "$expected" ]
```

Run:

```bash
chmod 0755 docker/validate-app-key docker/entrypoint.sh docker/oast-worker-health
```

- [ ] **Step 6: Set database queue timing and self-host environment defaults**

In `config/queue.php`, replace the database connection retry line and its comment with:

```php
// Must exceed the supervised worker timeout (900 seconds), otherwise a still-running
// panel or judge job may be reserved and delivered again before the worker is killed.
'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 960),
```

Append/replace these entries in `.env.example`:

```dotenv
APP_KEY_FILE=
OAST_BOOTSTRAP_SECRET=
OAST_QUEUE_WORKERS=1
SITE_PUBLICATIONS_PATH=
DB_CONNECTION=sqlite
DB_DATABASE=database/database.sqlite
DB_BUSY_TIMEOUT=5000
DB_JOURNAL_MODE=WAL
DB_TRANSACTION_MODE=IMMEDIATE
DB_QUEUE_RETRY_AFTER=960
SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
```

Replace `config/site.php` with:

```php
<?php

declare(strict_types=1);

return [
    // The self-host image sets this to /var/lib/oast/publications.
    'publications_path' => env('SITE_PUBLICATIONS_PATH', base_path('database/publications')),
];
```

Remove the `docs` line from `.dockerignore` only if `docker/README.md` is copied into the final image in a later change; keep `docker/` included. The Dockerfile above does not copy docs into runtime, so `.dockerignore` may continue to ignore `docs`.

- [ ] **Step 7: Add complete operator documentation**

Create `docker/README.md`:

````markdown
# oast self-host image

Generate and retain one real key with `php artisan key:generate --show`. Start on host loopback:

```bash
docker volume create oast-data
docker run -d --name oast -p 127.0.0.1:8080:8080 \
  -e APP_KEY='base64:replace-with-output-from-key-generate' \
  -e OAST_BOOTSTRAP_SECRET='replace-with-a-high-entropy-secret' \
  -v oast-data:/var/lib/oast oast-server:latest
```

The strings `01234567890123456789012345678901`, `00000000000000000000000000000000`, `base64:MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTIzNDU2Nzg5MDE=`, and `base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=` are rejected sentinels, not usable examples. A Docker secret may instead be mounted with `APP_KEY_FILE=/run/secrets/app_key`; trailing CR/LF is removed safely. Missing, malformed, and sentinel raw/base64 keys exit 64. Startup runs `php artisan migrate --force` before either supervised child starts; migration failure exits non-zero.

`/var/lib/oast` contains `database.sqlite`, its WAL/SHM files, and `publications/`. Keep the volume across replacement containers. Database-backed queue, cache, and sessions are defaults. One queue listener is supported by default. `OAST_QUEUE_WORKERS=2` or greater enables more listeners but increases SQLite write contention.

The worker uses `--timeout=900`. Keep `DB_QUEUE_RETRY_AFTER=960` (or another value strictly greater than 900) so a still-running job cannot be redelivered before the worker timeout.

## Reverse proxy and TLS

Remote exposure requires TLS and secure cookies. Keep Docker published on `127.0.0.1` and put the TLS proxy on the host. Nginx's SSE location must include:

```nginx
location /app/reviews/ {
    proxy_pass http://127.0.0.1:8080;
    proxy_http_version 1.1;
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_buffering off;
    proxy_cache off;
    proxy_read_timeout 600s;
}
```

The application also emits `X-Accel-Buffering: no`. Other proxies must disable response buffering and allow streams for at least 600 seconds.

## Health

`GET /up` verifies database connectivity and zero pending migrations. `/usr/local/bin/oast-worker-health` separately verifies exactly `OAST_QUEUE_WORKERS` queue processes are `RUNNING`. Docker combines both checks.

## Backup and restore

Stop the container so SQLite and the worker are quiescent: `docker stop oast`. Copy the complete volume, including database/WAL/SHM and publications. Restore the complete directory into a fresh volume, then start a replacement container with the same stable `APP_KEY`. Upgrade by pulling the image and recreating the container with the same volume and key; migrations run automatically.
````

- [ ] **Step 8: Add JS/image CI gates**

In `.github/workflows/ci.yml`, add immediately after `bun run build`:

```yaml
- run: bunx vitest run
```

Add after `composer test`:

```yaml
- run: docker build --tag oast-server:ci .
```

- [ ] **Step 9: Run static, build, and runtime checks**

```bash
vendor/bin/pest tests/Feature/ReadinessTest.php tests/Feature/SelfHostImageContractTest.php
bunx vitest run
docker build -t oast-server:m3b .
```

Then run:

```bash
docker run --name oast-key-missing oast-server:m3b; test $? -eq 64
docker run --name oast-key-sentinel -e APP_KEY='01234567890123456789012345678901' oast-server:m3b; test $? -eq 64
docker volume create oast-m3b-data
APP_KEY="$(php artisan key:generate --show)"
docker run -d --name oast-m3b -p 127.0.0.1:18080:8080 \
  -e APP_KEY="$APP_KEY" -e OAST_BOOTSTRAP_SECRET='m3b-test-bootstrap-secret' \
  -v oast-m3b-data:/var/lib/oast oast-server:m3b
for attempt in $(seq 1 30); do curl -fsS http://127.0.0.1:18080/up && break; sleep 1; done
docker exec oast-m3b supervisorctl status web queue:*
docker exec oast-m3b /usr/local/bin/oast-worker-health
docker exec oast-m3b sh -c 'test -f /var/lib/oast/database.sqlite && touch /var/lib/oast/publications/persistence-check'
docker restart oast-m3b
for attempt in $(seq 1 30); do curl -fsS http://127.0.0.1:18080/up && break; sleep 1; done
docker exec oast-m3b test -f /var/lib/oast/publications/persistence-check
docker stop oast-m3b
```

Expected: missing/sentinel containers exit 64; image builds; readiness returns `{"status":"ready"}`; `web` and `queue_00` are running; worker health succeeds; database and publication marker survive restart. Clean up test containers/volume with the repository's safe cleanup workflow after recording evidence.

- [ ] **Step 10: Commit**

```bash
git add Dockerfile docker .env.example config/site.php config/queue.php .github/workflows/ci.yml tests/Feature/SelfHostImageContractTest.php
git commit -m "feat: ship supervised self-host image"
```

**Acceptance:** `DB_QUEUE_RETRY_AFTER=960` is strictly greater than `--timeout=900`; database queue/cache/session and `/var/lib/oast` persistence are explicit; malformed and sentinel raw/base64 keys are rejected.

---

### Task 9: Run M3B scope, security, and quality gates

**Files:**

- Modify only files implicated by failures in Tasks 1–8.
- Modify: `AGENTS.md` only after all gates pass.

- [ ] **Step 1: Run the complete focused PHP suite**

```bash
vendor/bin/pest \
  tests/Feature/WebReviewIndexTest.php \
  tests/Feature/WebReviewSubmissionTest.php \
  tests/Feature/WebReviewShowTest.php \
  tests/Feature/WebReviewDeletionUiTest.php \
  tests/Feature/ReadinessTest.php \
  tests/Feature/SelfHostImageContractTest.php \
  tests/Feature/ReviewAuthorizationTest.php \
  tests/Feature/ReviewDeletionTest.php \
  tests/Feature/SseAuthorizationTest.php \
  tests/Feature/SitePagesTest.php
```

Expected: PASS with no live model calls, no cross-organization reads, and unchanged publication pages.

- [ ] **Step 2: Run all browser and asset gates**

```bash
bunx vitest run
bun run build
bun run test:lint
```

Expected: smoke, mapper, submission, and workspace suites pass; Vite Plus builds and formats cleanly.

- [ ] **Step 3: Run the full PHP quality gate**

```bash
composer test
```

Expected: 100% type coverage, exactly 100% line coverage, Pint/Rector/Vite formatting clean, and PHPStan max/bleeding-edge clean.

- [ ] **Step 4: Verify route and architecture separation**

```bash
php artisan route:list --path=app/reviews
php artisan route:list --path=reviews
rg "Route::prefix\('app/reviews'\)|name\('app\.reviews\.'\)" routes/web.php
rg "Review \$review" app/Http/Controllers/App app/Http/Controllers/ReviewEventsController.php app/Http/Controllers/DeleteReviewController.php && exit 1 || true
rg "Authorization.*Bearer|lastEventId=|api\.reviews\.events" resources/js resources/views/app/reviews && exit 1 || true
rg "Livewire|React|Vue|organization_id.*input" resources/js resources/views/app/reviews && exit 1 || true
```

Expected:

- one `prefix('app/reviews')->name('app.reviews.')` group;
- relative `index/create/store/show/events/destroy` names and required middleware;
- static routes before `{review}`;
- show/events/delete controllers accept string IDs and use `ScopedReviewResolver`;
- public `/reviews` routes still point to `App\Http\Controllers\Site`;
- forbidden searches return no product-code matches.

- [ ] **Step 5: Manually validate the browser and proxy flow**

Run `composer dev`, sign in as an organization member, and perform these exact checks:

1. `/app/reviews` settles from loading to empty/history and never displays another organization's rows.
2. Pasted YAML and uploaded JSON each preserve raw source, POST with 202, and navigate from `Location`.
3. Panel/judge events stream from `/app/reviews/{id}/events`; a forced disconnect shows reconnecting; native replay does not duplicate terminal state.
4. Completed reports group blocker/should-fix/consider findings. Click pointers covering percent-encoded slash, `~0/~1`, duplicate keys, aliases, direct map merge, merge sequence precedence, root, missing, and invalid input; verify exact retained lines or fallback copy.
5. Queued/running/judging/complete/error labels and failure copy match the status table.
6. Creator/owner controls delete; unrelated member is denied; cross-org show/delete is 404; deletion is permanent.
7. Public `/reviews` and `/reviews/{slug}` remain unauthenticated publications.
8. Put Nginx in front with the documented stanza and verify progressive SSE delivery without buffering for at least 600 seconds.

- [ ] **Step 6: Add exact operational notes to `AGENTS.md`**

Append:

```markdown
### M3B browser and self-host operations

- Browser tests: `bunx vitest run` (`bun run test:js` invokes the same runner); production assets: `bun run build`.
- Authenticated organization reviews live at `/app/reviews`; public `/reviews/*` remains publication-only.
- The self-host image supervises FrankenPHP plus database queue listeners, persists `/var/lib/oast`, requires a stable non-placeholder `APP_KEY`, runs startup migrations, exposes `/up` readiness, and uses `docker/oast-worker-health` for queue health. `DB_QUEUE_RETRY_AFTER` must remain greater than the worker `--timeout=900`. See `docker/README.md`.
```

- [ ] **Step 7: Commit gate-only corrections and documentation**

```bash
git add -A
git commit -m "chore: finish M3B web client"
```

Expected: only gate-driven fixes and the three operational notes are included; no M4 behavior appears.

**Acceptance:** all automated gates, route inspection, container checks, and manual source/SSE/authorization checks pass.

## Files to Modify

- `package.json` - Alpine/yaml/Vitest dependencies and runnable `bunx vitest run` script.
- `bun.lock` - locked JavaScript dependencies.
- `vite.config.js` - Vitest discovery in Node environment.
- `resources/js/app.js` - Alpine bootstrap and component registration while preserving M3A copy/confirm behavior.
- `routes/web.php` - one exact protected `app/reviews` group plus public readiness route; public publications unchanged.
- `resources/views/components/app-layout.blade.php` - Reviews navigation link.
- `resources/css/app.css` - history, submission, progress, report, source, and deletion styles.
- `app/Http/Controllers/DeleteReviewController.php` - keep scoped M3A action/policy flow and return browser redirect.
- `tests/Feature/ReviewDeletionTest.php` - update browser response expectation only.
- `tests/Feature/SitePagesTest.php` - publication isolation regression.
- `bootstrap/app.php` - disable default process-only health route.
- `Dockerfile` - supervised persistent multi-stage image.
- `.env.example` - self-host database queue/cache/session, persistence, and retry defaults.
- `config/site.php` - document image publication path.
- `config/queue.php` - database `retry_after` default 960, greater than worker timeout 900.
- `.github/workflows/ci.yml` - direct Vitest and Docker build gates.
- `AGENTS.md` - verified M3B developer/operator commands after gates pass.

## New Files

- `resources/js/smoke.test.js` - dependency smoke coverage.
- `resources/js/spec-source-map.js` - `yaml` 2.9 CST-aware JSON Pointer mapper.
- `resources/js/spec-source-map.test.js` - pointer, pair range, duplicate, alias, merge, root, missing, and invalid coverage.
- `resources/js/review-submission.js`, `resources/js/review-submission.test.js` - `202 + Location` submission state and tests.
- `resources/js/review-workspace.js`, `resources/js/review-workspace.test.js` - injected EventSource lifecycle/report state and tests.
- `app/Http/Controllers/App/ReviewIndexController.php` - organization history query.
- `app/Http/Controllers/App/CreateReviewController.php` - submission form data.
- `app/Http/Controllers/App/StoreReviewController.php` - exact M3A action invocation.
- `app/Http/Controllers/App/ShowReviewController.php` - string ID plus `ScopedReviewResolver`.
- `app/Http/Requests/StoreWebReviewRequest.php` - mutually exclusive source and typed enum accessors.
- `resources/views/app/reviews/index.blade.php`, `create.blade.php`, `show.blade.php` - complete authenticated review workspace.
- `tests/Feature/WebReviewIndexTest.php`, `WebReviewSubmissionTest.php`, `WebReviewShowTest.php`, `WebReviewDeletionUiTest.php` - browser authorization/state coverage.
- `app/Http/Controllers/ReadinessController.php`, `tests/Feature/ReadinessTest.php` - database/migration readiness.
- `docker/validate-app-key` - strict raw/base64 Laravel key validation with explicit sentinel rejection.
- `docker/entrypoint.sh` - mounted-key load, migration, persistence, and supervisor startup.
- `docker/supervisord.conf` - exact web/queue child supervision.
- `docker/oast-worker-health` - exact configured queue process count health.
- `docker/README.md` - startup, keys, persistence, proxy, timeout, health, backup, and restore operations.
- `tests/Feature/SelfHostImageContractTest.php` - executable key checks and static image contract.

## Dependencies

1. Task 1 supplies Alpine, `yaml`, Vitest, and the direct test command.
2. Task 2 depends on Task 1 and supplies `createSpecSourceMap()` to Task 5.
3. Task 3 depends on completed M3A routes, middleware, models, `OrganizationContext`, policies, and `memberFixture`; it establishes the one exact route group and history UI.
4. Task 4 depends on Tasks 1 and 3 and M3A's final `CreateReviewAction` signature.
5. Task 5 depends on Tasks 2–4 and M3A's `ScopedReviewResolver` and `ReviewEventsController`.
6. Task 6 depends on Tasks 3 and 5 and preserves M3A's `DeleteReviewAction`, resolver, and policy.
7. Task 7 is independent of browser modules but must precede Task 8's health check.
8. Task 8 depends on Tasks 1 and 7 plus M3A's database schema and setup flow.
9. Task 9 depends on all earlier tasks and is the only full-suite/manual/documentation task.

## Risks

- **`yaml` node API drift:** pair/node ranges and merge-key symbol representation are dependency-sensitive. Keep the complete Task 2 suite pinned to `yaml ^2.9`; do not simplify pair tracking or change merge sequence order without verifying YAML precedence.
- **Range semantics:** a selected mapping range starts at `pair.key.range[0]` and ends at the selected value/node end. Returning only `pair.value` reintroduces the key-line highlighting blocker.
- **M3A integration:** execute only after the revised M3A plan lands. Namespace drift must be reconciled to the exact names in this plan; do not replace `ScopedReviewResolver` with implicit binding or rename the shared fixture.
- **EventSource environments:** production defaults to `globalThis.EventSource`, but tests inject both the constructor and numeric CLOSED state. Never read `EventSource.CLOSED` as an unqualified Node global.
- **Terminal state:** completion and failure set `connection = 'terminal'` before closing. Tests must never expect `connected` after a terminal event.
- **202 semantics:** HTTP cannot be both 202 and a 3xx redirect. The fetch component requires 202 plus Location and then performs browser navigation.
- **SSE state recovery:** native EventSource owns `Last-Event-ID`; persisted complete/error state prevents unnecessary streams. Do not add a browser cursor protocol.
- **SQLite contention:** more than one worker is configurable but warning-worthy. WAL, busy timeout, immediate transactions, one-worker default, and complete-volume backup remain mandatory.
- **Queue redelivery:** `DB_QUEUE_RETRY_AFTER` must stay strictly greater than the supervisor worker timeout. The plan uses 960 > 900 and documents the invariant in code, environment, image, and README.
- **Mounted secrets:** only trailing CR/LF is removed from `APP_KEY_FILE`; spaces and internal bytes are not silently normalized. Environment `APP_KEY` takes precedence.
- **Sentinel drift:** executable tests, validator, and README must list the same raw/base64 sentinels.
- **Readiness startup:** migrations run before supervisor; `/up` is unreachable until successful startup. Migration failure intentionally exits the container.
- **Public route collision:** `/app/reviews` is unrelated to public `/reviews`. Route inspection and publication-backed regressions are release blockers.
- **Coverage:** new PHP branches and JS fallback states can expose the repository's 100% coverage gate. Add focused tests for any formatter/static-analysis-driven branch changes rather than excluding files.

## Self-Review

- Every code-producing task contains exact file content or a bounded exact replacement/addition, imports, signatures, failing command, passing command, and commit boundary.
- YAML pointer decoding removes `#`, splits on literal `/` first, percent-decodes each segment, then decodes `~1/~0`.
- Mapping retains the selected `Pair`, starts map highlights at the key, searches duplicate keys backward, recognizes symbol/string merge keys, resolves aliases, implements direct-map and sequence merges in YAML precedence order, lets explicit keys win, and defines root/missing/invalid behavior.
- EventSource tests inject a constructor and numeric CLOSED state; no missing Node global is read; completion expects terminal.
- `ShowReviewController` accepts `string $review`, calls `ScopedReviewResolver::findOrFail()`, authorizes the resolved model, and has an explicit cross-organization 404 test.
- Routes use exactly one `prefix('app/reviews')->name('app.reviews.')` group with the required middleware and relative names; static routes precede `{review}`; M3A event/deletion controllers are preserved.
- The store controller calls `CreateReviewAction` in the exact revised M3A argument order. `DeleteReviewAction`, `ReviewEventsController`, `ScopedReviewResolver`, and `memberFixture` names match M3A.
- Browser SSE is same-origin/session-authenticated. Public `/reviews` is unchanged. Source mapping is implemented, not deferred.
- Docker rejects missing, malformed, and explicit sentinel raw/base64 keys; mounted-secret newline trimming is exact; database queue/cache/session and `/var/lib/oast` persistence are explicit; 960-second retry is documented against 900-second timeout.
- JavaScript gates use `bunx vitest run`, not the broken Vite Plus test command.
- Dependency map, risks, final route/architecture checks, manual browser/proxy checks, and full quality gates are present.
