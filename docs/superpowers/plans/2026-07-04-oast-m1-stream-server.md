# oast.sh M1 — Streaming Server Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Convert the blocking review into an async review resource: 202 on POST, per-panelist queued jobs in a batch, quorum-early judge with a CAS guard, an SSE events endpoint with replay, dollar-cost metrics, and the artisan command rebuilt on the same pipeline.

**Architecture:** `CreateReviewAction` persists a `queued` review (including the spec text) and dispatches a `Bus::batch()` of `RunPanelist` jobs. Jobs append rows to `review_events` (the SSE/tail source) and `review_panel_responses` (per-row, no JSON-column races). Quorum triggers a grace-delayed `RunJudge`; batch-finally triggers it immediately; a `status` CAS makes the judge exactly-once. `GET /reviews/{id}/events` streams the event log with `Last-Event-ID` replay.

**Tech Stack:** PHP 8.5, Laravel 13 (`Bus::batch`, `response()->stream()`, `Sleep`), Laravel AI SDK agents (existing `Panelist`/`Judge`), Pest 4, SQLite.

## Global Constraints

- 100% line AND type coverage enforced by `composer test`; every new class needs tests.
- PHPStan level max + Rector + Pint (PER preset); the post-edit hook runs them — write types accordingly, no `@phpstan-ignore`.
- All LLM tests use SDK fakes (`Panelist::fake()`, `Judge::fake()`); live tests only in `->group('live')`.
- API errors are RFC 9457 problem+json; routes live on the `api.*` subdomain (`config('oast.api_domain')`).
- `declare(strict_types=1)`; final classes; Pest functional style (`it(...)`).
- Finding schema and prompts are unchanged from M0. Dimension enum: `domain-modeling | resource-relationships | workflows`.
- Queue in dev/tests: `sync` unless a test sets otherwise. `composer dev` runs a real listener.
- Quorum: council = `config('oast.quorum')` (2); baseline = 1. Grace: `config('oast.quorum_grace')` (60s).
- The events table is append-only; `review_events.id` is the SSE event id.

---

### Task 1: Migrations + models (`ReviewEvent`, `ReviewPanelResponse`, reviews changes)

**Files:**
- Create: `database/migrations/2026_07_04_000001_create_review_events_table.php`
- Create: `database/migrations/2026_07_04_000002_create_review_panel_responses_table.php`
- Create: `database/migrations/2026_07_04_000003_add_async_columns_to_reviews_table.php`
- Create: `app/Models/ReviewEvent.php`, `app/Models/ReviewPanelResponse.php`
- Modify: `app/Models/Review.php` (relations, `spec` column, `appendEvent()`)
- Test: `tests/Unit/Models/ReviewEventTest.php`, `tests/Unit/Models/ReviewPanelResponseTest.php`, extend `tests/Unit/Models/ReviewTest.php`

**Interfaces:**
- Produces: `Review::appendEvent(string $event, array $data): ReviewEvent`; `Review::events(): HasMany`; `Review::panelResponses(): HasMany`; `reviews.spec` (text), `reviews.status` default `'queued'`; `raw_panel_responses` column dropped.

- [ ] **Step 1: Write failing tests**

```php
// tests/Unit/Models/ReviewEventTest.php
<?php

declare(strict_types=1);

use App\Models\Review;

it('appends ordered events with array data', function (): void {
    $review = Review::factory()->create();

    $first = $review->appendEvent('panel.model.start', ['model' => 'openai/gpt-5.5']);
    $second = $review->appendEvent('panel.model.done', ['model' => 'openai/gpt-5.5', 'ms' => 42]);

    expect($second->id)->toBeGreaterThan($first->id)
        ->and($first->data)->toBe(['model' => 'openai/gpt-5.5'])
        ->and($review->events)->toHaveCount(2);
});
```

```php
// tests/Unit/Models/ReviewPanelResponseTest.php
<?php

declare(strict_types=1);

use App\Models\Review;

it('stores per-panelist rows with usage and late flag', function (): void {
    $review = Review::factory()->create();

    $row = $review->panelResponses()->create([
        'model' => 'z-ai/glm-5.2',
        'ok' => true,
        'content' => 'critique',
        'ms' => 166306,
        'usage' => ['prompt_tokens' => 10149],
        'cost_usd' => 0.0123,
        'late' => true,
    ]);

    expect($row->usage)->toBe(['prompt_tokens' => 10149])
        ->and($row->late)->toBeTrue()
        ->and($review->panelResponses()->count())->toBe(1);
});
```

(If `Review::factory()` does not exist yet, add `database/factories/ReviewFactory.php` with sane defaults: `spec_ref: 'spec.yaml'`, `spec_hash: hash('sha256', 'spec')`, `spec: 'openapi: 3.1.0'`, `mode: 'council'`, `dimension: 'domain-modeling'`, `panel_models: []`, `panel_size: 0`, `status: 'queued'`, and `use HasFactory` on `Review`.)

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Unit/Models/ReviewEventTest.php tests/Unit/Models/ReviewPanelResponseTest.php`
Expected: FAIL (missing tables/methods).

- [ ] **Step 3: Write migrations and models**

```php
// database/migrations/2026_07_04_000001_create_review_events_table.php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('review_id')->constrained()->cascadeOnDelete();
            $table->string('event');
            $table->json('data');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['review_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_events');
    }
};
```

```php
// database/migrations/2026_07_04_000002_create_review_panel_responses_table.php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_panel_responses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('review_id')->constrained()->cascadeOnDelete();
            $table->string('model');
            $table->boolean('ok');
            $table->longText('content')->nullable();
            $table->string('error')->nullable();
            $table->unsignedInteger('ms')->default(0);
            $table->json('usage')->nullable();
            $table->decimal('cost_usd', 10, 6)->nullable();
            $table->boolean('late')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_panel_responses');
    }
};
```

```php
// database/migrations/2026_07_04_000003_add_async_columns_to_reviews_table.php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table): void {
            $table->longText('spec')->nullable();
            $table->string('status')->default('queued')->change();
            $table->dropColumn('raw_panel_responses');
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table): void {
            $table->dropColumn('spec');
            $table->json('raw_panel_responses')->nullable();
        });
    }
};
```

```php
// app/Models/ReviewEvent.php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ReviewEvent extends Model
{
    public const null UPDATED_AT = null;

    protected $fillable = ['event', 'data'];

    /**
     * @return BelongsTo<Review, $this>
     */
    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }

    /**
     * @return array{data: 'array'}
     */
    protected function casts(): array
    {
        return ['data' => 'array'];
    }
}
```

```php
// app/Models/ReviewPanelResponse.php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ReviewPanelResponse extends Model
{
    protected $fillable = ['model', 'ok', 'content', 'error', 'ms', 'usage', 'cost_usd', 'late'];

    /**
     * @return BelongsTo<Review, $this>
     */
    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }

    /**
     * @return array{ok: 'boolean', late: 'boolean', usage: 'array', cost_usd: 'float'}
     */
    protected function casts(): array
    {
        return ['ok' => 'boolean', 'late' => 'boolean', 'usage' => 'array', 'cost_usd' => 'float'];
    }
}
```

Additions to `app/Models/Review.php` (keep existing casts/fillable; add `spec` to `$fillable`, drop `raw_panel_responses` references):

```php
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @return HasMany<ReviewEvent, $this>
 */
public function events(): HasMany
{
    return $this->hasMany(ReviewEvent::class);
}

/**
 * @return HasMany<ReviewPanelResponse, $this>
 */
public function panelResponses(): HasMany
{
    return $this->hasMany(ReviewPanelResponse::class);
}

/**
 * @param  array<string, mixed>  $data
 */
public function appendEvent(string $event, array $data): ReviewEvent
{
    return $this->events()->create(['event' => $event, 'data' => $data]);
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Unit/Models/ --compact` — Expected: PASS. Fix any existing `ReviewTest`/`ReviewResource` breakage from the dropped `raw_panel_responses` column now (resource task comes later; here only the model test file may need its fixture updated).

- [ ] **Step 5: Commit**

```bash
git add database app/Models tests/Unit/Models
git commit -m "feat: Add review_events and review_panel_responses tables"
```

---

### Task 2: `ModelPricing` service

**Files:**
- Create: `app/Ai/ModelPricing.php`
- Modify: `config/oast.php` (add `quorum_grace`)
- Test: `tests/Unit/Ai/ModelPricingTest.php`

**Interfaces:**
- Produces: `ModelPricing::costUsd(string $model, array $usage): ?float` — usage keys are the metric keys from `CouncilOrchestrator::usageMetrics()` (`prompt_tokens`, `completion_tokens`, `reasoning_tokens`, ...). Returns `null` for unknown slugs.
- Consumes: OpenRouter `GET https://openrouter.ai/api/v1/models` → `{data: [{id, pricing: {prompt: "0.000003", completion: "0.000015"}}]}` (string per-token USD rates).

- [ ] **Step 1: Write failing tests**

```php
// tests/Unit/Ai/ModelPricingTest.php
<?php

declare(strict_types=1);

use App\Ai\ModelPricing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Cache::flush();
    Http::fake([
        'openrouter.ai/api/v1/models' => Http::response(['data' => [
            ['id' => 'openai/gpt-5.5', 'pricing' => ['prompt' => '0.000002', 'completion' => '0.00001']],
        ]]),
    ]);
});

it('prices a known model from prompt, completion, and reasoning tokens', function (): void {
    $cost = new ModelPricing()->costUsd('openai/gpt-5.5', [
        'prompt_tokens' => 1000, 'completion_tokens' => 500, 'reasoning_tokens' => 100,
    ]);

    // 1000*0.000002 + (500+100)*0.00001 = 0.002 + 0.006
    expect($cost)->toBe(0.008);
});

it('returns null for an unknown slug (e.g. a ~latest alias)', function (): void {
    expect(new ModelPricing()->costUsd('~anthropic/claude-sonnet-latest', ['prompt_tokens' => 10]))->toBeNull();
});

it('caches the price list', function (): void {
    $pricing = new ModelPricing();
    $pricing->costUsd('openai/gpt-5.5', []);
    $pricing->costUsd('openai/gpt-5.5', []);

    Http::assertSentCount(1);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Unit/Ai/ModelPricingTest.php` — Expected: FAIL, class not found.

- [ ] **Step 3: Implement**

```php
// app/Ai/ModelPricing.php
<?php

declare(strict_types=1);

namespace App\Ai;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final class ModelPricing
{
    /**
     * @param  array<string, int>  $usage
     */
    public function costUsd(string $model, array $usage): ?float
    {
        $rates = $this->rates()[$model] ?? null;

        if ($rates === null) {
            return null;
        }

        $prompt = $usage['prompt_tokens'] ?? 0;
        $completion = ($usage['completion_tokens'] ?? 0) + ($usage['reasoning_tokens'] ?? 0);

        return round($prompt * $rates['prompt'] + $completion * $rates['completion'], 6);
    }

    /**
     * @return array<string, array{prompt: float, completion: float}>
     */
    private function rates(): array
    {
        return Cache::remember('oast.model-pricing', now()->addDay(), function (): array {
            $models = Http::get('https://openrouter.ai/api/v1/models')->json('data');
            $rates = [];

            foreach (is_array($models) ? $models : [] as $model) {
                if (! is_array($model) || ! is_string($model['id'] ?? null) || ! is_array($model['pricing'] ?? null)) {
                    continue;
                }

                $rates[$model['id']] = [
                    'prompt' => (float) ($model['pricing']['prompt'] ?? 0),
                    'completion' => (float) ($model['pricing']['completion'] ?? 0),
                ];
            }

            return $rates;
        });
    }
}
```

Add to `config/oast.php` after `quorum`:

```php
// Seconds to wait for a straggling panelist after quorum before the judge starts.
'quorum_grace' => (int) env('OAST_QUORUM_GRACE', 60),
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Unit/Ai/ModelPricingTest.php` — Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Ai/ModelPricing.php config/oast.php tests/Unit/Ai
git commit -m "feat: Add OpenRouter model pricing service and quorum_grace config"
```

---

### Task 3: `RunPanelist` job

**Files:**
- Create: `app/Jobs/RunPanelist.php`
- Test: `tests/Unit/Jobs/RunPanelistTest.php`

**Interfaces:**
- Consumes: `Panelist` agent (M0), `PanelistPrompt::userPrompt(string)`, `Review::appendEvent()`, `ModelPricing::costUsd()`, `CouncilOrchestrator` NOT used here.
- Produces: `new RunPanelist(int $reviewId, string $model, Dimension $dimension)`; on success a `review_panel_responses` row (`ok: true`) + `panel.model.done` event (or `.late`); on thrown failure the queue retries (`tries = 2`); `failed()` hook writes the `ok: false` row + `panel.model.failed` event. Quorum-early: dispatches `RunJudge` delayed by `quorum_grace` when success count hits quorum (skipped on the sync driver). `RunPanelist::quorumFor(Review $review): int` (public static; baseline → 1, council → `config('oast.quorum')`).

- [ ] **Step 1: Write failing tests**

```php
// tests/Unit/Jobs/RunPanelistTest.php
<?php

declare(strict_types=1);

use App\Ai\Agents\Panelist;
use App\Council\Dimension;
use App\Jobs\RunJudge;
use App\Jobs\RunPanelist;
use App\Models\Review;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Http::fake(['openrouter.ai/api/v1/models' => Http::response(['data' => []])]);
});

it('stores a successful response and emits start/done events', function (): void {
    Panelist::fake(['a sharp critique']);
    $review = Review::factory()->create(['status' => 'running', 'mode' => 'council']);

    new RunPanelist($review->id, 'openai/gpt-5.5', Dimension::DomainModeling)->handle();

    $row = $review->panelResponses()->sole();
    expect($row->ok)->toBeTrue()
        ->and($row->content)->toBe('a sharp critique')
        ->and($review->events()->pluck('event')->all())->toBe(['panel.model.start', 'panel.model.done']);
});

it('marks the response late and skips quorum when the judge already started', function (): void {
    Panelist::fake(['slow critique']);
    Queue::fake();
    $review = Review::factory()->create(['status' => 'judging', 'mode' => 'council']);

    new RunPanelist($review->id, 'z-ai/glm-5.2', Dimension::DomainModeling)->handle();

    expect($review->panelResponses()->sole()->late)->toBeTrue()
        ->and($review->events()->pluck('event')->all())->toBe(['panel.model.start', 'panel.model.late']);
    Queue::assertNotPushed(RunJudge::class);
});

it('dispatches a grace-delayed judge when quorum is reached off the sync driver', function (): void {
    config()->set('queue.default', 'database');
    config()->set('oast.quorum', 1);
    config()->set('oast.quorum_grace', 60);
    Panelist::fake(['critique']);
    Queue::fake();
    $review = Review::factory()->create(['status' => 'running', 'mode' => 'council']);

    new RunPanelist($review->id, 'openai/gpt-5.5', Dimension::DomainModeling)->handle();

    Queue::assertPushed(RunJudge::class, fn (RunJudge $job): bool => $job->delay === 60);
});

it('records the failure row and event via the failed hook', function (): void {
    $review = Review::factory()->create(['status' => 'running']);
    $job = new RunPanelist($review->id, 'openai/gpt-5.5', Dimension::DomainModeling);

    $job->failed(new RuntimeException('upstream 500'));

    $row = $review->panelResponses()->sole();
    expect($row->ok)->toBeFalse()
        ->and($row->error)->toBe('upstream 500')
        ->and($review->events()->pluck('event')->all())->toBe(['panel.model.failed']);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Unit/Jobs/RunPanelistTest.php` — Expected: FAIL, class not found.

- [ ] **Step 3: Implement**

```php
// app/Jobs/RunPanelist.php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\Agents\Panelist;
use App\Ai\ModelPricing;
use App\Council\Dimension;
use App\Council\Prompts\PanelistPrompt;
use App\Models\Review;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Responses\Data\Usage;
use Throwable;

final class RunPanelist implements ShouldQueue
{
    use Batchable;
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public readonly int $reviewId,
        public readonly string $model,
        public readonly Dimension $dimension,
    ) {}

    public static function quorumFor(Review $review): int
    {
        return $review->mode === 'baseline' ? 1 : (int) config('oast.quorum');
    }

    public function handle(): void
    {
        $review = Review::query()->findOrFail($this->reviewId);
        $review->appendEvent('panel.model.start', ['model' => $this->model]);

        $start = microtime(true);

        $response = new Panelist($this->dimension)->prompt(
            PanelistPrompt::userPrompt((string) $review->spec),
            provider: Lab::OpenRouter,
            model: $this->model,
            timeout: (int) config('oast.timeout'),
        );

        $ms = (int) round((microtime(true) - $start) * 1000);
        $usage = self::usageMetrics($response->usage);
        $late = in_array($review->refresh()->status, ['judging', 'complete', 'error'], true);

        $review->panelResponses()->create([
            'model' => $this->model,
            'ok' => true,
            'content' => $response->text,
            'ms' => $ms,
            'usage' => $usage,
            'cost_usd' => new ModelPricing()->costUsd($this->model, $usage),
            'late' => $late,
        ]);

        if ($late) {
            $review->appendEvent('panel.model.late', ['model' => $this->model, 'ms' => $ms]);

            return;
        }

        $review->appendEvent('panel.model.done', [
            'model' => $this->model,
            'ms' => $ms,
            'usage' => $usage,
            'cost_usd' => new ModelPricing()->costUsd($this->model, $usage),
        ]);

        $this->dispatchJudgeAtQuorum($review);
    }

    public function failed(?Throwable $exception): void
    {
        $review = Review::query()->find($this->reviewId);

        if ($review === null) {
            return;
        }

        $review->panelResponses()->create([
            'model' => $this->model,
            'ok' => false,
            'error' => $exception?->getMessage() ?? 'panel call failed',
        ]);

        $review->appendEvent('panel.model.failed', [
            'model' => $this->model,
            'error' => $exception?->getMessage() ?? 'panel call failed',
            'attempt' => $this->tries,
        ]);
    }

    private function dispatchJudgeAtQuorum(Review $review): void
    {
        // ponytail: sync driver runs delayed jobs inline, which would always cut the
        // last panelist — quorum-early is a real-queue optimization only.
        if (config('queue.default') === 'sync') {
            return;
        }

        $successes = $review->panelResponses()->where('ok', true)->where('late', false)->count();

        if ($successes === self::quorumFor($review)) {
            RunJudge::dispatch($this->reviewId, $this->dimension)
                ->delay((int) config('oast.quorum_grace'));
        }
    }

    /**
     * @return array<string, int>
     */
    private static function usageMetrics(Usage $usage): array
    {
        return [
            'prompt_tokens' => $usage->promptTokens,
            'completion_tokens' => $usage->completionTokens,
            'cache_write_input_tokens' => $usage->cacheWriteInputTokens,
            'cache_read_input_tokens' => $usage->cacheReadInputTokens,
            'reasoning_tokens' => $usage->reasoningTokens,
        ];
    }
}
```

(Note: `RunJudge` doesn't exist until Task 4 — create an empty queued-job stub in this task so the code compiles: constructor `(public readonly int $reviewId, public readonly Dimension $dimension)`, empty `handle(): void`. Task 4 fills it in.)

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Unit/Jobs/RunPanelistTest.php` — Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Jobs tests/Unit/Jobs
git commit -m "feat: Add RunPanelist job with quorum-early judge dispatch"
```

---

### Task 4: `RunJudge` job with CAS + `PanelFinalizer`

**Files:**
- Create: `app/Jobs/RunJudge.php` (replace Task 3 stub), `app/Council/PanelFinalizer.php`
- Test: `tests/Unit/Jobs/RunJudgeTest.php`, `tests/Unit/Council/PanelFinalizerTest.php`

**Interfaces:**
- Consumes: `CouncilOrchestrator::runJudge(string $spec, array $panelCritiques, Dimension $dimension): array{findings: ..., ms: int, usage: array<string, int>}` (unchanged from M0), `ModelPricing`, `Review::appendEvent()`, `RunPanelist::quorumFor()`.
- Produces: `RunJudge(int $reviewId, Dimension $dimension)` — CAS `running → judging`; loser no-ops. On success: persists `findings`, `metrics` (per-model rows + judge + `total_cost_usd`), `panel_size`, `panelists`, status `complete`, events `judge.start`/`judge.done`/`review.completed`. On `JudgeException`: status `error` + `review.failed {stage: judge}`. `PanelFinalizer::finalize(int $reviewId, Dimension $dimension): void` — called from batch `finally`: quorum met → `RunJudge::dispatch` now; missed → status `error` + `review.failed {stage: panel}`.

- [ ] **Step 1: Write failing tests**

```php
// tests/Unit/Jobs/RunJudgeTest.php
<?php

declare(strict_types=1);

use App\Ai\Agents\Judge;
use App\Council\Dimension;
use App\Jobs\RunJudge;
use App\Models\Review;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::fake(['openrouter.ai/api/v1/models' => Http::response(['data' => []])]);
});

function reviewWithPanel(string $status = 'running'): Review
{
    $review = Review::factory()->create(['status' => $status, 'spec' => 'openapi: 3.1.0']);
    $review->panelResponses()->create(['model' => 'a', 'ok' => true, 'content' => 'crit-a', 'ms' => 10, 'usage' => ['prompt_tokens' => 5]]);
    $review->panelResponses()->create(['model' => 'b', 'ok' => true, 'content' => 'crit-b', 'ms' => 20, 'usage' => ['prompt_tokens' => 6]]);
    $review->panelResponses()->create(['model' => 'c', 'ok' => true, 'content' => 'late', 'ms' => 99, 'late' => true]);

    return $review;
}

it('judges non-late critiques and completes the review', function (): void {
    Judge::fake([['findings' => [validFinding()]]]);
    $review = reviewWithPanel();

    new RunJudge($review->id, Dimension::DomainModeling)->handle();

    $review->refresh();
    expect($review->status)->toBe('complete')
        ->and($review->findings)->toHaveCount(1)
        ->and($review->panel_size)->toBe(2) // late panelist excluded
        ->and($review->events()->pluck('event')->all())
        ->toBe(['judge.start', 'judge.done', 'review.completed']);
});

it('no-ops when another judge won the CAS', function (): void {
    Judge::fake([['findings' => [validFinding()]]]);
    $review = reviewWithPanel(status: 'judging');

    new RunJudge($review->id, Dimension::DomainModeling)->handle();

    expect($review->refresh()->status)->toBe('judging')
        ->and($review->events()->count())->toBe(0);
});

it('fails the review when the judge output stays invalid', function (): void {
    Judge::fake([
        ['findings' => [validFinding(['confidence' => 'split'])]],
        ['findings' => [validFinding(['confidence' => 'split'])]],
    ]);
    $review = reviewWithPanel();

    new RunJudge($review->id, Dimension::DomainModeling)->handle();

    $review->refresh();
    expect($review->status)->toBe('error')
        ->and($review->events()->pluck('event')->all())->toBe(['judge.start', 'review.failed']);
});
```

```php
// tests/Unit/Council/PanelFinalizerTest.php
<?php

declare(strict_types=1);

use App\Council\Dimension;
use App\Council\PanelFinalizer;
use App\Jobs\RunJudge;
use App\Models\Review;
use Illuminate\Support\Facades\Queue;

it('dispatches the judge immediately when quorum is met', function (): void {
    Queue::fake();
    $review = Review::factory()->create(['status' => 'running', 'mode' => 'council']);
    $review->panelResponses()->create(['model' => 'a', 'ok' => true, 'content' => 'x']);
    $review->panelResponses()->create(['model' => 'b', 'ok' => true, 'content' => 'y']);

    new PanelFinalizer()->finalize($review->id, Dimension::DomainModeling);

    Queue::assertPushed(RunJudge::class, fn (RunJudge $job): bool => $job->delay === null);
});

it('fails the review when quorum is missed', function (): void {
    Queue::fake();
    $review = Review::factory()->create(['status' => 'running', 'mode' => 'council']);
    $review->panelResponses()->create(['model' => 'a', 'ok' => true, 'content' => 'x']);
    $review->panelResponses()->create(['model' => 'b', 'ok' => false, 'error' => 'dead']);

    new PanelFinalizer()->finalize($review->id, Dimension::DomainModeling);

    $review->refresh();
    Queue::assertNothingPushed();
    expect($review->status)->toBe('error')
        ->and($review->events()->sole()->event)->toBe('review.failed');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Unit/Jobs/RunJudgeTest.php tests/Unit/Council/PanelFinalizerTest.php` — Expected: FAIL.

- [ ] **Step 3: Implement**

```php
// app/Jobs/RunJudge.php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\ModelPricing;
use App\Council\CouncilOrchestrator;
use App\Council\Dimension;
use App\Council\Exceptions\JudgeException;
use App\Models\Review;
use App\Models\ReviewPanelResponse;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class RunJudge implements ShouldQueue
{
    use Batchable;
    use Queueable;

    public function __construct(
        public readonly int $reviewId,
        public readonly Dimension $dimension,
    ) {}

    public function handle(CouncilOrchestrator $orchestrator): void
    {
        $claimed = Review::query()
            ->whereKey($this->reviewId)
            ->where('status', 'running')
            ->update(['status' => 'judging']);

        if ($claimed === 0) {
            return; // another dispatch won the CAS
        }

        $review = Review::query()->findOrFail($this->reviewId);
        $panel = $review->panelResponses()->where('ok', true)->where('late', false)->get();

        $review->appendEvent('judge.start', [
            'model' => config('oast.judge'),
            'panel_size' => $panel->count(),
        ]);

        $critiques = $panel
            ->map(fn (ReviewPanelResponse $r): array => ['model' => $r->model, 'content' => $r->content])
            ->all();

        try {
            $judge = $orchestrator->runJudge((string) $review->spec, $critiques, $this->dimension);
        } catch (JudgeException $exception) {
            $review->update(['status' => 'error']);
            $review->appendEvent('review.failed', ['stage' => 'judge', 'problem' => [
                'title' => 'Judge produced invalid output',
                'detail' => $exception->getMessage(),
            ]]);

            return;
        }

        $judgeCost = new ModelPricing()->costUsd((string) config('oast.judge'), $judge['usage']);

        $metrics = $panel->map(fn (ReviewPanelResponse $r): array => [
            'model' => $r->model, 'ms' => $r->ms, 'usage' => $r->usage, 'cost_usd' => $r->cost_usd,
        ])->all();
        $metrics[] = ['model' => config('oast.judge'), 'ms' => $judge['ms'], 'usage' => $judge['usage'], 'cost_usd' => $judgeCost];

        $totalCost = collect($metrics)->sum(fn (array $m): float => (float) ($m['cost_usd'] ?? 0.0));

        $review->update([
            'status' => 'complete',
            'findings' => $judge['findings'],
            'panel_models' => $panel->pluck('model')->all(),
            'panel_size' => $panel->count(),
            'metrics' => [...$metrics, ['total_cost_usd' => $totalCost]],
        ]);

        $review->appendEvent('judge.done', [
            'model' => config('oast.judge'),
            'ms' => $judge['ms'],
            'usage' => $judge['usage'],
            'cost_usd' => $judgeCost,
            'findings_count' => count($judge['findings']),
        ]);
        $review->appendEvent('review.completed', [
            'findings' => $judge['findings'],
            'total_cost_usd' => $totalCost,
        ]);
    }
}
```

```php
// app/Council/PanelFinalizer.php
<?php

declare(strict_types=1);

namespace App\Council;

use App\Jobs\RunJudge;
use App\Jobs\RunPanelist;
use App\Models\Review;

final class PanelFinalizer
{
    public function finalize(int $reviewId, Dimension $dimension): void
    {
        $review = Review::query()->findOrFail($reviewId);

        if (in_array($review->status, ['judging', 'complete', 'error'], true)) {
            return; // quorum-early judge already ran or review already terminal
        }

        $successes = $review->panelResponses()->where('ok', true)->where('late', false)->count();

        if ($successes >= RunPanelist::quorumFor($review)) {
            RunJudge::dispatch($reviewId, $dimension);

            return;
        }

        $failed = $review->panelResponses()->where('ok', false)->pluck('model')->all();
        $review->update(['status' => 'error']);
        $review->appendEvent('review.failed', ['stage' => 'panel', 'problem' => [
            'title' => 'Panel quorum not met',
            'detail' => sprintf('%d of %d required panelists succeeded.', $successes, RunPanelist::quorumFor($review)),
            'failed_models' => $failed,
        ]]);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Unit/Jobs tests/Unit/Council/PanelFinalizerTest.php` — Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/RunJudge.php app/Council/PanelFinalizer.php tests/Unit
git commit -m "feat: Add RunJudge with CAS exactly-once and PanelFinalizer quorum gate"
```

---

### Task 5: `CreateReviewAction` dispatches the batch; orchestrator slimmed

**Files:**
- Modify: `app/Actions/Reviews/CreateReviewAction.php` (create review + dispatch batch, return immediately)
- Modify: `app/Council/CouncilOrchestrator.php` (delete `review()`, `deliberateOn()`, `baselinePanel()`, `promptPanelist()`, `usageMetrics()`; keep `runJudge()` and constructor)
- Delete: `app/Council/PanelResponse.php`, `app/Council/ReviewResult.php`, `app/Council/ReviewRequest.php` (jobs write the DB directly; nothing consumes these now)
- Test: rewrite `tests/Unit/Actions/Reviews/CreateReviewActionTest.php`; prune orchestrator tests to judge-pass cases; delete tests of removed classes
- Modify: `tests/Pest.php` — `fakeCouncil()` keeps working (agents unchanged)

**Interfaces:**
- Produces: `CreateReviewAction::__invoke(string $spec, ReviewMode $mode, ?string $specRef = null, Dimension $dimension = Dimension::DomainModeling): Review` — returns the **queued/running** review immediately (no findings yet). Batch name: `"review:{id}"`.
- Consumes: `RunPanelist`, `PanelFinalizer`, `config('oast.panelists'|'baseline')`.

- [ ] **Step 1: Write failing tests**

```php
// tests/Unit/Actions/Reviews/CreateReviewActionTest.php (rewrite)
<?php

declare(strict_types=1);

use App\Actions\Reviews\CreateReviewAction;
use App\Council\Dimension;
use App\Council\ReviewMode;
use App\Jobs\RunPanelist;
use Illuminate\Support\Facades\Bus;

it('creates a running review and batches one job per panelist', function (): void {
    Bus::fake();

    $review = app(CreateReviewAction::class)('openapi: 3.1.0', ReviewMode::Council, 'spec.yaml');

    expect($review->status)->toBe('running')
        ->and($review->spec)->toBe('openapi: 3.1.0')
        ->and($review->mode)->toBe('council');

    Bus::assertBatched(fn ($batch): bool => $batch->jobs->count() === count(config('oast.panelists'))
        && $batch->jobs->every(fn ($job): bool => $job instanceof RunPanelist));
});

it('batches a single job for baseline mode', function (): void {
    Bus::fake();

    app(CreateReviewAction::class)('openapi: 3.1.0', ReviewMode::Baseline, 'spec.yaml');

    Bus::assertBatched(fn ($batch): bool => $batch->jobs->count() === 1);
});

it('runs end-to-end on the sync queue', function (): void {
    fakeCouncil(); // Panelist + Judge fakes from tests/Pest.php
    Illuminate\Support\Facades\Http::fake(['openrouter.ai/api/v1/models' => Illuminate\Support\Facades\Http::response(['data' => []])]);

    $review = app(CreateReviewAction::class)('openapi: 3.1.0', ReviewMode::Council, 'spec.yaml');

    expect($review->refresh()->status)->toBe('complete')
        ->and($review->findings)->not->toBeEmpty()
        ->and($review->events()->pluck('event'))->toContain('review.completed');
});
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Unit/Actions` — Expected: FAIL (old signature persists synchronously).

- [ ] **Step 3: Implement**

```php
// app/Actions/Reviews/CreateReviewAction.php (rewrite)
<?php

declare(strict_types=1);

namespace App\Actions\Reviews;

use App\Council\Dimension;
use App\Council\PanelFinalizer;
use App\Council\ReviewMode;
use App\Jobs\RunPanelist;
use App\Models\Review;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;

final readonly class CreateReviewAction
{
    public function __invoke(
        string $spec,
        ReviewMode $mode,
        ?string $specRef = null,
        Dimension $dimension = Dimension::DomainModeling,
    ): Review {
        $panelists = $mode === ReviewMode::Baseline
            ? [config('oast.baseline') ?? config('oast.panelists')[0]]
            : config('oast.panelists');

        $review = Review::query()->create([
            'spec_ref' => $specRef,
            'spec_hash' => hash('sha256', $spec),
            'spec' => $spec,
            'mode' => $mode->value,
            'dimension' => $dimension->value,
            'panel_models' => $panelists,
            'panel_size' => 0,
            'status' => 'running',
        ]);

        $review->appendEvent('review.queued', [
            'mode' => $mode->value,
            'dimension' => $dimension->value,
            'panelists' => $panelists,
        ]);

        $reviewId = $review->id;

        Bus::batch(
            collect($panelists)
                ->map(fn (string $model): RunPanelist => new RunPanelist($reviewId, $model, $dimension))
                ->all(),
        )
            ->name("review:{$reviewId}")
            ->allowFailures()
            ->finally(fn (Batch $batch) => new PanelFinalizer()->finalize($reviewId, $dimension))
            ->dispatch();

        return $review;
    }
}
```

Orchestrator slimming: delete `review()`, `deliberateOn()`, `baselinePanel()`, `promptPanelist()`, `usageMetrics()` and the now-unused imports (`Panelist`, `PanelistPrompt`, `PanelException`, `PanelResponse`, `Throwable`, `Usage` — keep `Usage` only if `runJudge` still calls a local usage helper; move the `usageMetrics()` body into `runJudge`'s file scope as a private method it still uses). Delete `PanelResponse.php`, `ReviewResult.php`, `ReviewRequest.php` and their test files; prune `CouncilOrchestratorTest` to the judge-pass cases (valid output, re-prompt retry, double failure).

**Note:** `allowFailures()` is required — without it one failed panelist cancels the batch and `finally` semantics change; quorum logic owns failure policy, not the batch.

- [ ] **Step 4: Run the full unit suite**

Run: `composer test:unit` — Expected: PASS with 100% coverage (deleted classes no longer count; `ReviewController`/`ReviewCommand` still compile — they break in behavior until Tasks 6–7 rewire them; fix compile errors only, behavior comes next).

- [ ] **Step 5: Commit**

```bash
git add -A app tests
git commit -m "feat: Dispatch panel as a job batch from CreateReviewAction"
```

---

### Task 6: HTTP surface — 202, show, SSE events endpoint

**Files:**
- Modify: `app/Http/Controllers/ReviewController.php` (202 + Location), `app/Http/Resources/ReviewResource.php` (status/spec fields, no raw_panel_responses)
- Create: `app/Http/Controllers/ReviewEventsController.php`, `app/Http/Controllers/ShowReviewController.php`
- Modify: `routes/web.php` (or wherever the api-domain group lives — follow the existing `POST /reviews` route registration)
- Test: `tests/Feature/ReviewApiTest.php` (extend), `tests/Feature/ReviewEventsStreamTest.php`

**Interfaces:**
- Produces: `POST /reviews` → 202, `Location: https://api.<domain>/reviews/{id}`, body = `ReviewResource` (status `running`, no findings). `GET /reviews/{id}` → 200 `ReviewResource`. `GET /reviews/{id}/events` → `text/event-stream`; frames `id: <event id>\nevent: <name>\ndata: <json>\n\n`; honors `Last-Event-ID` header and `?lastEventId=` query; closes after a terminal event (`review.completed` / `review.failed`).

- [ ] **Step 1: Write failing tests**

```php
// tests/Feature/ReviewEventsStreamTest.php
<?php

declare(strict_types=1);

use App\Models\Review;

it('streams stored events as SSE frames and closes on terminal', function (): void {
    $review = Review::factory()->create(['status' => 'complete']);
    $review->appendEvent('review.queued', ['mode' => 'council']);
    $review->appendEvent('review.completed', ['findings' => [], 'total_cost_usd' => 0.1]);

    $response = $this->get("https://{$this->apiHost()}/reviews/{$review->id}/events");

    $response->assertOk()->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8');
    $body = $response->streamedContent();

    expect($body)->toContain("event: review.queued\n")
        ->and($body)->toContain('"total_cost_usd":0.1')
        ->and($body)->toMatch('/id: \d+\nevent: review\.completed/');
});

it('replays only events after Last-Event-ID', function (): void {
    $review = Review::factory()->create(['status' => 'complete']);
    $first = $review->appendEvent('review.queued', []);
    $review->appendEvent('review.completed', ['findings' => []]);

    $body = $this->withHeader('Last-Event-ID', (string) $first->id)
        ->get("https://{$this->apiHost()}/reviews/{$review->id}/events")
        ->streamedContent();

    expect($body)->not->toContain('review.queued')
        ->and($body)->toContain('review.completed');
});

it('404s an unknown review as problem+json', function (): void {
    $this->get("https://{$this->apiHost()}/reviews/999/events")
        ->assertNotFound();
});
```

(`apiHost()` = whatever helper existing feature tests use for the `api.*` host — reuse the established pattern from `ReviewApiTest`. Extend `ReviewApiTest`: POST asserts 202 + Location header + `status: running` + `Bus::assertBatched`; add a `GET /reviews/{id}` 200 case.)

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Feature/ReviewEventsStreamTest.php` — Expected: FAIL (route missing).

- [ ] **Step 3: Implement**

```php
// app/Http/Controllers/ReviewEventsController.php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Review;
use App\Models\ReviewEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Sleep;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ReviewEventsController
{
    private const array TERMINAL = ['review.completed', 'review.failed'];

    public function __invoke(Request $request, Review $review): StreamedResponse
    {
        $lastId = (int) ($request->headers->get('Last-Event-ID') ?? $request->query('lastEventId', '0'));

        return response()->stream(function () use ($review, $lastId): void {
            $cursor = $lastId;

            while (true) {
                $events = $review->events()->where('id', '>', $cursor)->orderBy('id')->get();

                foreach ($events as $event) {
                    $this->emit($event);
                    $cursor = $event->id;

                    if (in_array($event->event, self::TERMINAL, true)) {
                        return;
                    }
                }

                if (connection_aborted() === 1) {
                    return;
                }

                Sleep::for(500)->milliseconds();
            }
        }, headers: [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function emit(ReviewEvent $event): void
    {
        echo "id: {$event->id}\n";
        echo "event: {$event->event}\n";
        echo 'data: ' . json_encode($event->data) . "\n\n";

        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }
}
```

```php
// app/Http/Controllers/ShowReviewController.php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\ReviewResource;
use App\Models\Review;

final class ShowReviewController
{
    public function __invoke(Review $review): ReviewResource
    {
        return new ReviewResource($review);
    }
}
```

`ReviewController` (the POST responder): change the success response to
`(new ReviewResource($review))->response()->setStatusCode(202)->header('Location', route('reviews.show', $review))`.
Routes (in the existing api-domain group):

```php
Route::get('/reviews/{review}', ShowReviewController::class)->name('reviews.show');
Route::get('/reviews/{review}/events', ReviewEventsController::class)->name('reviews.events');
```

`ReviewResource`: drop any `raw_panel_responses` output; expose `id`, `status`, `mode`, `dimension`, `panel_models`, `panel_size`, `findings` (when non-null), `metrics` (when non-null), `created_at`.

- [ ] **Step 4: Run to verify pass**

Run: `vendor/bin/pest tests/Feature` — Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http routes tests/Feature
git commit -m "feat: Serve reviews as async resources with an SSE events endpoint"
```

---

### Task 7: Rebuild `artisan oast:review` on the async pipeline

**Files:**
- Modify: `app/Console/Commands/ReviewCommand.php`
- Test: rewrite `tests/Unit/Console/Commands/ReviewCommandTest.php` (or its existing path — follow the current test file location)

**Interfaces:**
- Consumes: `CreateReviewAction` (Task 5 signature), `review_events` tailing, `Sleep` facade (fake in tests).
- Produces: same UX as M0 (event lines + findings table + exit codes 0/1) on both sync and real queues. New `--timeout=900` option: give up tailing after N seconds with exit FAILURE.

- [ ] **Step 1: Write failing tests**

```php
// tests/Unit/Console/Commands/ReviewCommandTest.php (rewrite core cases)
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::fake(['openrouter.ai/api/v1/models' => Http::response(['data' => []])]);
});

it('runs a council review end-to-end on the sync queue and prints findings', function (): void {
    fakeCouncil();
    $spec = tempnam(sys_get_temp_dir(), 'spec');
    file_put_contents((string) $spec, 'openapi: 3.1.0');

    $this->artisan('oast:review', ['spec' => $spec])
        ->expectsOutputToContain('review.completed')
        ->expectsOutputToContain('Order exposes DB join table')
        ->assertExitCode(0);
});

it('exits non-zero when the review fails', function (): void {
    App\Ai\Agents\Panelist::fake(fn () => throw new RuntimeException('all dead'));
    $spec = tempnam(sys_get_temp_dir(), 'spec');
    file_put_contents((string) $spec, 'openapi: 3.1.0');

    $this->artisan('oast:review', ['spec' => $spec])->assertExitCode(1);
});
```

(If `Panelist::fake(fn () => throw ...)` isn't supported by the SDK fake, drive the failure by setting `config(['oast.panelists' => []])`-adjacent quorum config: `config(['oast.quorum' => 5])` with normal fakes — quorum can't be met, review fails.)

- [ ] **Step 2: Run to verify failure** — `vendor/bin/pest tests/Unit/Console` — FAIL (old synchronous expectations).

- [ ] **Step 3: Implement**

```php
// app/Console/Commands/ReviewCommand.php — handle() core (keep file-not-found and dimension guards)
public function handle(CreateReviewAction $review): int
{
    $path = $this->argument('spec');

    if (! is_file($path)) {
        $this->error('Spec file not found: ' . $path);

        return self::FAILURE;
    }

    $dimension = Dimension::tryFrom((string) $this->option('dimension'));

    if (! $dimension instanceof Dimension) {
        $this->error('Unknown dimension: ' . $this->option('dimension'));

        return self::FAILURE;
    }

    $mode = $this->option('baseline') ? ReviewMode::Baseline : ReviewMode::Council;
    $this->info(sprintf('Convening %s review (%s) for %s ...', $mode->value, $dimension->value, $path));

    $created = $review(File::get($path), $mode, $path, $dimension);

    $cursor = 0;
    $deadline = now()->addSeconds((int) $this->option('timeout'));
    $terminal = null;

    while ($terminal === null) {
        foreach ($created->events()->where('id', '>', $cursor)->orderBy('id')->get() as $event) {
            $cursor = $event->id;
            $this->line(sprintf('%s  %s', $event->event, json_encode($event->data)));

            if (in_array($event->event, ['review.completed', 'review.failed'], true)) {
                $terminal = $event->event;
            }
        }

        if ($terminal !== null) {
            break;
        }

        if (now()->greaterThan($deadline)) {
            $this->error('Timed out waiting for the review to finish.');

            return self::FAILURE;
        }

        Sleep::for(500)->milliseconds();
    }

    if ($terminal === 'review.failed') {
        return self::FAILURE;
    }

    $created->refresh();
    $findings = $created->findings ?? [];

    $this->table(
        ['Severity', 'Confidence', 'Title', 'Location'],
        array_map(fn (mixed $finding): array => [
            $this->cell($finding, 'severity'),
            $this->cell($finding, 'confidence'),
            $this->cell($finding, 'title'),
            $this->cell($finding, 'location'),
        ], $findings),
    );

    $this->info(sprintf(
        'Panel size: %d  |  Findings: %d  |  Cost: $%s  |  Review #%d',
        $created->panel_size,
        count($findings),
        number_format((float) collect($created->metrics ?? [])->sum(fn (mixed $m): float => is_array($m) ? (float) ($m['total_cost_usd'] ?? 0) : 0.0), 4),
        $created->id,
    ));

    return self::SUCCESS;
}
```

Signature gains `{--timeout=900 : Seconds to wait for completion}`. Keep the private `cell()` helper. Imports: `Illuminate\Support\Facades\Sleep`.

- [ ] **Step 4: Run to verify pass** — `vendor/bin/pest tests/Unit/Console` — PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Console tests
git commit -m "feat: Rebuild oast:review on the async job pipeline"
```

---

### Task 8: Full-suite green + docs

**Files:**
- Modify: whatever the suite flags; `AGENTS.md` (queue note), `docs/oast-build-spec.md` M1 checkbox note if desired.

- [ ] **Step 1:** Run `composer test` — fix failures until: type coverage 100, line coverage 100, Pint/Rector/PHPStan clean.
- [ ] **Step 2:** Run `composer lint`.
- [ ] **Step 3:** Live smoke (manual, needs worker + key): `composer dev` in one terminal; `php artisan oast:review fixtures/specs/train-travel.yaml` in another; confirm concurrent panel events interleave and `total_cost_usd` is non-null for exact-slug models. Then `curl -N https://api.<domain>/reviews/<id>/events` replays the stream.
- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "chore: M1 server suite green; document queue requirement"
```

---

## Self-review notes (already applied)

- Spec coverage: 202/Location (T6), batch fan-out (T5), quorum-early + grace + CAS (T3/T4), events table + SSE replay (T1/T6), cost (T2, consumed in T3/T4), artisan rebuild (T7), panel-responses table replacing the JSON column (T1/T5). CLI is a separate plan (separate repo): `2026-07-04-oast-m1-oast-cli.md`.
- The `ReviewStatus` stays a plain string column — a backed enum adds churn across ReviewResource/tests with no M1 payoff.
- `allowFailures()` on the batch is load-bearing (see Task 5 note).
