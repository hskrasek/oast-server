# oast.sh M0 — Council Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the M0 "Council" — a Laravel review engine that fans out an OpenAPI spec to 3 LLM panelists via the Laravel AI SDK, has a dedicated judge organize their critiques into structured, validated findings, and exposes it via one HTTP endpoint (on an `api.*` subdomain) and one artisan command, with a single-model baseline mode for comparison.

**Architecture:** One stateless engine (`CouncilOrchestrator`) is a pure-ish function `(spec, request) → ReviewResult` that performs no DB writes. Panelists and the judge are **Laravel AI SDK agents** (`PanelistAgent`, `JudgeAgent`), reaching models through OpenRouter (the SDK's built-in `openrouter` provider, single-key BYOK). Two thin entry points — an invokable Action behind `POST https://api.<domain>/v1/reviews` (ADR pattern) and the `oast:review` command — call the engine and own persistence to a `reviews` table.

**Tech Stack:** PHP 8.5, Laravel 13, Laravel AI SDK (`laravel/ai`), Pest 4, SQLite, OpenRouter (via the SDK).

## Global Constraints

- PHP `^8.5`, Laravel `^13.8`, Pest `^4.7`. Add exactly one runtime dependency: `laravel/ai`.
- **Models reach OpenRouter via the Laravel AI SDK's built-in `openrouter` provider** (`Lab::OpenRouter`), single key `OPENROUTER_API_KEY`. Panelist/judge model slugs come from `config/oast.php` and are passed as per-prompt `model:` overrides. (Native multi-provider is an M1 option.)
- Tests run against in-memory SQLite (`phpunit.xml`); `RefreshDatabase` is auto-applied to `Feature` via `tests/Pest.php`. The `Unit` suite is bound to `Tests\TestCase` (Task 1) so SDK facades/helpers work there too.
- **All LLM tests use the SDK's native fakes** (`PanelistAgent::fake()`, `JudgeAgent::fake()`) — no live calls in the default suite. Live tests are tagged `->group('live')` and excluded from `composer test`.
- M0 dimension is fixed: `domain-modeling` (Dimension 1).
- Quorum floor: **2** successful panelists. Per-call retry: **1**. Panel calls run **sequentially** in M0 (concurrency is an M1 SSE-era concern).
- **Code style: PER preset via a committed `pint.json`** (Pint is already installed). Format with `vendor/bin/pint` before each commit.
- The API is served on the `api.*` subdomain (`config('oast.api_domain')`), not an `/api/` path prefix.
- Entry points use the **ADR pattern**: invokable single-action controllers in `app/Actions`, output shaped by an API Resource (the "Responder").
- Finding schema (the validated judge output contract):

  ```jsonc
  {
    "dimension": "domain-modeling",
    "title": "string",
    "severity": "blocker | should-fix | consider",
    "confidence": "consensus | majority | split | lone-flag",
    "location": "#/json/pointer",
    "finding": "string",
    "why_it_matters": "string",
    "disagreement": "string — required only when confidence = split",
    "suggested_change": "string"
  }
  ```

> **Two SDK details to confirm against the installed `laravel/ai` version (flagged, not blocking):**
> 1. **Per-call usage/cost accessor.** The SDK exposes `$response->text` and array access for structured output; a per-response token/cost accessor isn't documented for non-streaming prompts. **M0 metrics record measured latency (`ms`) only**; wiring per-model token/cost (likely via `$response->usage` or the SDK's invocation logging) is a fast follow-up once the accessor is confirmed.
> 2. **Faking structured output with explicit data.** This plan assumes `JudgeAgent::fake([['findings' => [...]]])` supplies an explicit structured response (and `JudgeAgent::fake()` auto-generates schema-matching data). If the installed version only supports string fakes for structured agents, adapt the judge tests accordingly.

---

### Task 1: Project setup — deps, config, tooling

**Files:**
- Modify: `composer.json` (PHP `^8.5`, add `laravel/ai`)
- Create: `config/ai.php` (published, then ensure `openrouter` provider)
- Create: `config/oast.php`
- Create: `pint.json`
- Modify: `tests/Pest.php` (bind `TestCase` to the `Unit` suite)
- Modify: `.env.example` (OpenRouter + API domain keys)
- Test: `tests/Unit/SetupConfigTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `config('ai.providers.openrouter')` → `['driver' => 'openrouter', 'key' => ..., 'url' => ...]`.
  - `config('oast')` → keys `timeout` (int), `api_domain` (string), `panelists` (3 model-ID strings), `judge` (string), `baseline` (?string), `quorum` (int).
  - `pint.json` with `"preset": "per"`.
  - `Unit` suite runs with the Laravel application booted.

- [ ] **Step 1: Install the SDK and bump PHP**

```bash
# Set "php": "^8.5" in composer.json's require block first, then:
composer require laravel/ai
php artisan vendor:publish --tag=ai-config   # writes config/ai.php
```

Edit `composer.json` `require` so it reads:

```json
"require": {
    "php": "^8.5",
    "laravel/ai": "^0.1",
    "laravel/framework": "^13.8",
    "laravel/tinker": "^3.0"
},
```

(Use whatever stable `laravel/ai` constraint `composer require` resolves; the line above is the shape.)

- [ ] **Step 2: Write the failing test**

```php
<?php

use Illuminate\Support\Facades\File;

it('configures the openrouter provider for the AI SDK', function () {
    expect(config('ai.providers.openrouter.driver'))->toBe('openrouter');
});

it('exposes oast config defaults', function () {
    expect(config('oast.quorum'))->toBe(2)
        ->and(config('oast.panelists'))->toBeArray()->toHaveCount(3)
        ->and(config('oast.api_domain'))->toBeString();
});

it('uses the PER pint preset', function () {
    $pint = json_decode(File::get(base_path('pint.json')), true);
    expect($pint['preset'])->toBe('per');
});
```

- [ ] **Step 3: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/SetupConfigTest.php`
Expected: FAIL — `config('oast...')` null / `pint.json` missing. (If the `Unit` suite errors because the app isn't booted, that confirms Step 4 is required.)

- [ ] **Step 4: Write minimal implementation**

In `tests/Pest.php`, after the existing `->in('Feature');` line, add:

```php
pest()->extend(Tests\TestCase::class)->in('Unit');
```

Ensure `config/ai.php` contains the `openrouter` provider (add it if `vendor:publish` didn't):

```php
'openrouter' => [
    'driver' => 'openrouter',
    'key' => env('OPENROUTER_API_KEY'),
    'url' => env('OPENROUTER_URL'),
],
```

`config/oast.php`:

```php
<?php

return [
    'timeout' => (int) env('OAST_TIMEOUT', 120),

    // The api.* subdomain the REST API is served on.
    'api_domain' => env('OAST_API_DOMAIN', 'api.oast.test'),

    // Panelist model slugs (OpenRouter). Hardcoded for M0; config-driven roster in M1.
    // Confirm exact OpenRouter slugs before the first live run.
    'panelists' => [
        'anthropic/claude-sonnet-4',
        'openai/gpt-5',
        'google/gemini-2.5-pro',
    ],

    // Dedicated strong judge — never a panelist.
    'judge' => 'anthropic/claude-opus-4',

    // Baseline single model; null => first panelist.
    'baseline' => null,

    'quorum' => 2,
];
```

`pint.json`:

```json
{
    "preset": "per"
}
```

Append to `.env.example`:

```
OPENROUTER_API_KEY=
OPENROUTER_URL=
OAST_API_DOMAIN=api.oast.test
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/SetupConfigTest.php`
Expected: PASS (3 passed).

- [ ] **Step 6: Reformat to PER and commit**

```bash
vendor/bin/pint
git add composer.json composer.lock config/ai.php config/oast.php pint.json tests/Pest.php .env.example tests/Unit/SetupConfigTest.php
git commit -m "chore: install Laravel AI SDK, add oast config, adopt PER pint preset"
```

---

### Task 2: Value objects

**Files:**
- Create: `app/Council/ReviewMode.php`
- Create: `app/Council/ReviewRequest.php`
- Create: `app/Council/PanelResponse.php`
- Create: `app/Council/ReviewResult.php`
- Test: `tests/Unit/Council/ValueObjectsTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `App\Council\ReviewMode` enum (string-backed): `ReviewMode::Council` (`'council'`), `ReviewMode::Baseline` (`'baseline'`).
  - `App\Council\ReviewRequest` readonly: `new ReviewRequest(ReviewMode $mode, string $dimension = 'domain-modeling')`.
  - `App\Council\PanelResponse` readonly: `string $model, bool $ok, ?string $content, int $ms, ?string $error`; statics `PanelResponse::ok(string $model, string $content, int $ms): self` and `PanelResponse::failed(string $model, string $error): self`.
  - `App\Council\ReviewResult` readonly: `ReviewMode $mode, string $dimension, array $panelModels, int $panelSize, array $rawPanelResponses, array $findings, array $metrics, string $status`; `toArray(): array` (snake_case keys, `mode` as its value).

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Council\PanelResponse;
use App\Council\ReviewMode;
use App\Council\ReviewResult;

it('builds review mode from string', function () {
    expect(ReviewMode::from('council'))->toBe(ReviewMode::Council)
        ->and(ReviewMode::from('baseline'))->toBe(ReviewMode::Baseline);
});

it('constructs ok and failed panel responses', function () {
    $ok = PanelResponse::ok('openai/gpt', 'critique text', 1200);
    expect($ok->ok)->toBeTrue()
        ->and($ok->content)->toBe('critique text')
        ->and($ok->ms)->toBe(1200);

    $failed = PanelResponse::failed('google/gemini', 'timeout');
    expect($failed->ok)->toBeFalse()
        ->and($failed->content)->toBeNull()
        ->and($failed->error)->toBe('timeout');
});

it('serializes a review result to a snake_case array', function () {
    $result = new ReviewResult(
        mode: ReviewMode::Council,
        dimension: 'domain-modeling',
        panelModels: ['a', 'b'],
        panelSize: 2,
        rawPanelResponses: [['model' => 'a', 'ok' => true, 'content' => 'x', 'error' => null]],
        findings: [['title' => 'f']],
        metrics: [['model' => 'a', 'ms' => 10]],
        status: 'complete',
    );

    expect($result->toArray())->toMatchArray([
        'mode' => 'council',
        'dimension' => 'domain-modeling',
        'panel_models' => ['a', 'b'],
        'panel_size' => 2,
        'status' => 'complete',
    ]);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Council/ValueObjectsTest.php`
Expected: FAIL — `Class "App\Council\ReviewMode" not found`.

- [ ] **Step 3: Write minimal implementation**

`app/Council/ReviewMode.php`:

```php
<?php

namespace App\Council;

enum ReviewMode: string
{
    case Council = 'council';
    case Baseline = 'baseline';
}
```

`app/Council/ReviewRequest.php`:

```php
<?php

namespace App\Council;

final readonly class ReviewRequest
{
    public function __construct(
        public ReviewMode $mode,
        public string $dimension = 'domain-modeling',
    ) {}
}
```

`app/Council/PanelResponse.php`:

```php
<?php

namespace App\Council;

final readonly class PanelResponse
{
    public function __construct(
        public string $model,
        public bool $ok,
        public ?string $content,
        public int $ms,
        public ?string $error = null,
    ) {}

    public static function ok(string $model, string $content, int $ms): self
    {
        return new self($model, true, $content, $ms);
    }

    public static function failed(string $model, string $error): self
    {
        return new self($model, false, null, 0, $error);
    }
}
```

`app/Council/ReviewResult.php`:

```php
<?php

namespace App\Council;

final readonly class ReviewResult
{
    public function __construct(
        public ReviewMode $mode,
        public string $dimension,
        public array $panelModels,
        public int $panelSize,
        public array $rawPanelResponses,
        public array $findings,
        public array $metrics,
        public string $status,
    ) {}

    public function toArray(): array
    {
        return [
            'mode' => $this->mode->value,
            'dimension' => $this->dimension,
            'panel_models' => $this->panelModels,
            'panel_size' => $this->panelSize,
            'raw_panel_responses' => $this->rawPanelResponses,
            'findings' => $this->findings,
            'metrics' => $this->metrics,
            'status' => $this->status,
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/Council/ValueObjectsTest.php`
Expected: PASS (3 passed).

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint app/Council
git add app/Council tests/Unit/Council/ValueObjectsTest.php
git commit -m "feat: add council value objects"
```

---

### Task 3: Finding validator

**Files:**
- Create: `app/Council/FindingValidator.php`
- Create: `app/Council/Exceptions/InvalidJudgeOutputException.php`
- Test: `tests/Unit/Council/FindingValidatorTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `App\Council\FindingValidator::validate(array $findings): array` — returns the findings on success; throws on any violation. The SDK's structured output already enforces enums/required fields at the provider layer; this validator is defense-in-depth **and** owns the conditional rule (`disagreement` required when `confidence = split`) that the schema marks optional.
  - `App\Council\Exceptions\InvalidJudgeOutputException extends \RuntimeException` with public `array $errors`; `__construct(array $errors)`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Council\Exceptions\InvalidJudgeOutputException;
use App\Council\FindingValidator;

function validFinding(array $overrides = []): array
{
    return array_merge([
        'dimension' => 'domain-modeling',
        'title' => 'Order exposes DB join table',
        'severity' => 'blocker',
        'confidence' => 'consensus',
        'location' => '#/paths/~1order_line_items',
        'finding' => 'A join table is exposed as a resource.',
        'why_it_matters' => 'Chains the public contract to the DB schema.',
        'suggested_change' => 'Model orders and line items as domain resources.',
    ], $overrides);
}

it('accepts a well-formed finding', function () {
    $findings = [validFinding()];
    expect((new FindingValidator)->validate($findings))->toBe($findings);
});

it('requires disagreement when confidence is split', function () {
    (new FindingValidator)->validate([validFinding(['confidence' => 'split'])]);
})->throws(InvalidJudgeOutputException::class);

it('accepts a split finding that includes disagreement', function () {
    $findings = [validFinding(['confidence' => 'split', 'disagreement' => 'Model A says X; Model B disagrees.'])];
    expect((new FindingValidator)->validate($findings))->toBe($findings);
});

it('rejects an invalid severity enum', function () {
    (new FindingValidator)->validate([validFinding(['severity' => 'critical'])]);
})->throws(InvalidJudgeOutputException::class);

it('rejects a finding missing location', function () {
    $finding = validFinding();
    unset($finding['location']);
    (new FindingValidator)->validate([$finding]);
})->throws(InvalidJudgeOutputException::class);

it('rejects an empty payload', function () {
    (new FindingValidator)->validate([]);
})->throws(InvalidJudgeOutputException::class);

it('exposes validation errors on the exception', function () {
    try {
        (new FindingValidator)->validate([validFinding(['severity' => 'nope'])]);
        $this->fail('expected exception');
    } catch (InvalidJudgeOutputException $e) {
        expect($e->errors)->toBeArray()->not->toBeEmpty();
    }
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Council/FindingValidatorTest.php`
Expected: FAIL — `Class "App\Council\FindingValidator" not found`.

- [ ] **Step 3: Write minimal implementation**

`app/Council/Exceptions/InvalidJudgeOutputException.php`:

```php
<?php

namespace App\Council\Exceptions;

use RuntimeException;

class InvalidJudgeOutputException extends RuntimeException
{
    public function __construct(public array $errors)
    {
        parent::__construct('Judge output failed validation.');
    }
}
```

`app/Council/FindingValidator.php`:

```php
<?php

namespace App\Council;

use App\Council\Exceptions\InvalidJudgeOutputException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class FindingValidator
{
    public function validate(array $findings): array
    {
        if ($findings === [] || ! array_is_list($findings)) {
            throw new InvalidJudgeOutputException(['findings' => 'Expected a non-empty list of findings.']);
        }

        foreach ($findings as $index => $finding) {
            $validator = Validator::make(
                is_array($finding) ? $finding : [],
                [
                    'dimension' => ['required', 'string'],
                    'title' => ['required', 'string'],
                    'severity' => ['required', Rule::in(['blocker', 'should-fix', 'consider'])],
                    'confidence' => ['required', Rule::in(['consensus', 'majority', 'split', 'lone-flag'])],
                    'location' => ['required', 'string'],
                    'finding' => ['required', 'string'],
                    'why_it_matters' => ['required', 'string'],
                    'suggested_change' => ['required', 'string'],
                    'disagreement' => [
                        Rule::requiredIf(($finding['confidence'] ?? null) === 'split'),
                        'string',
                    ],
                ],
            );

            if ($validator->fails()) {
                throw new InvalidJudgeOutputException([$index => $validator->errors()->toArray()]);
            }
        }

        return $findings;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/Council/FindingValidatorTest.php`
Expected: PASS (7 passed).

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint app/Council
git add app/Council tests/Unit/Council/FindingValidatorTest.php
git commit -m "feat: add finding schema validator"
```

---

### Task 4: Agents & prompts

**Files:**
- Create: `resources/prompts/panel.md`
- Create: `resources/prompts/judge.md`
- Create: `app/Ai/Agents/PanelistAgent.php`
- Create: `app/Ai/Agents/JudgeAgent.php`
- Create: `app/Council/Prompts/PanelPrompt.php`
- Create: `app/Council/Prompts/JudgePrompt.php`
- Test: `tests/Unit/Council/AgentTest.php`

**Interfaces:**
- Consumes: Laravel AI SDK (`Agent`, `HasStructuredOutput`, `Promptable`, `JsonSchema`).
- Produces:
  - `App\Ai\Agents\PanelistAgent` — `instructions()` returns `resources/prompts/panel.md` (the critique system prompt; **no rubric**). Prompted with the spec user-text and a per-prompt `model:` override.
  - `App\Ai\Agents\JudgeAgent implements Agent, HasStructuredOutput` — `instructions()` returns `resources/prompts/judge.md`; `schema(JsonSchema $schema)` defines `{ findings: [ <finding object> ] }`. Structured response read via `$response['findings']`.
  - `App\Council\Prompts\PanelPrompt::userPrompt(string $spec): string`.
  - `App\Council\Prompts\JudgePrompt::userPrompt(string $spec, array $panelCritiques): string` where each critique is `['model' => string, 'content' => string]`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Ai\Agents\JudgeAgent;
use App\Ai\Agents\PanelistAgent;
use App\Council\Prompts\JudgePrompt;
use App\Council\Prompts\PanelPrompt;

it('panelist instructions carry critique guidance but no rubric severities', function () {
    $instructions = (new PanelistAgent)->instructions();
    expect(strtolower($instructions))->toContain('domain')
        ->and(strtolower($instructions))->not->toContain('blocker'); // rubric not leaked to panel
});

it('judge schema defines a findings array with the required keys', function () {
    $schema = (new JudgeAgent)->schema(app(\Illuminate\Contracts\JsonSchema\JsonSchema::class));
    expect($schema)->toHaveKey('findings');
});

it('builds a panel user prompt embedding the raw spec', function () {
    expect(PanelPrompt::userPrompt("openapi: 3.1.0"))->toContain('openapi: 3.1.0');
});

it('builds a judge user prompt embedding spec and labeled critiques', function () {
    $prompt = JudgePrompt::userPrompt('SPEC_BODY', [
        ['model' => 'a/one', 'content' => 'critique one'],
    ]);
    expect($prompt)->toContain('SPEC_BODY')
        ->and($prompt)->toContain('a/one')
        ->and($prompt)->toContain('critique one');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Council/AgentTest.php`
Expected: FAIL — `Class "App\Ai\Agents\PanelistAgent" not found`.

- [ ] **Step 3: Write minimal implementation**

`resources/prompts/panel.md`:

```markdown
You are a senior API designer reviewing an OpenAPI specification. Critique the spec's
**domain and resource modeling**: does it expose the right business-domain concepts, or
does it leak implementation detail (DB tables, internal services, UI screens, RPC verbs)?

Consider: resources as domain nouns vs. DB tables mapped 1:1; RPC-in-disguise endpoints;
ubiquitous-language naming; aggregate boundaries; missing resources the client clearly
needs; granularity; whether real state transitions are modeled or smuggled into PATCH.

Write your critique freely, in your own words. Be specific and cite the parts of the spec
you mean. Do not use any predefined scoring scale — just your honest expert judgment.
```

`resources/prompts/judge.md`:

```markdown
You are the judge of a multi-model API design review panel. You receive the spec and each
panelist's independent critique. You do NOT merge them and you do NOT add new critiques of
your own. You ORGANIZE the panel's critiques into discrete findings for the dimension
**domain-modeling** (Domain & Resource Modeling), assigning each a severity and a
confidence.

Severity:
- blocker: will force a breaking change, corrupt data, or break clients later.
- should-fix: real design debt — friction, hard to reverse, but survivable.
- consider: genuine judgment call, context-dependent or stylistic.

Confidence (from how many panelists independently raised it):
- consensus: all/most panelists raised it.
- majority: more panelists than not.
- split: genuine disagreement — you MUST summarize each position in `disagreement`.
- lone-flag: only one panelist raised it.

Each finding's `location` must be a JSON Pointer into the provided spec. Populate every
required field. A split / blocker (two panelists say a boundary forces a v2, one disagrees)
is the most valuable finding you can produce.
```

`app/Ai/Agents/PanelistAgent.php`:

```php
<?php

namespace App\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

class PanelistAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return file_get_contents(resource_path('prompts/panel.md'));
    }
}
```

`app/Ai/Agents/JudgeAgent.php`:

```php
<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

class JudgeAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return file_get_contents(resource_path('prompts/judge.md'));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'findings' => $schema->array()->items(
                $schema->object(fn ($schema) => [
                    'dimension' => $schema->string()->required(),
                    'title' => $schema->string()->required(),
                    'severity' => $schema->string()->enum(['blocker', 'should-fix', 'consider'])->required(),
                    'confidence' => $schema->string()->enum(['consensus', 'majority', 'split', 'lone-flag'])->required(),
                    'location' => $schema->string()->required(),
                    'finding' => $schema->string()->required(),
                    'why_it_matters' => $schema->string()->required(),
                    'disagreement' => $schema->string(),
                    'suggested_change' => $schema->string()->required(),
                ])
            )->required(),
        ];
    }
}
```

`app/Council/Prompts/PanelPrompt.php`:

```php
<?php

namespace App\Council\Prompts;

class PanelPrompt
{
    public static function userPrompt(string $spec): string
    {
        return "Here is the OpenAPI specification to review:\n\n{$spec}";
    }
}
```

`app/Council/Prompts/JudgePrompt.php`:

```php
<?php

namespace App\Council\Prompts;

class JudgePrompt
{
    public static function userPrompt(string $spec, array $panelCritiques): string
    {
        $critiques = collect($panelCritiques)
            ->map(fn (array $c) => "### Panelist: {$c['model']}\n{$c['content']}")
            ->join("\n\n");

        return "## Specification under review\n\n{$spec}\n\n## Panel critiques\n\n{$critiques}";
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/Council/AgentTest.php`
Expected: PASS (4 passed).

> If `app(JsonSchema::class)` does not resolve a builder directly, construct it however the installed SDK exposes its schema builder; the assertion only checks that `schema()` returns a `findings` key.

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint app/Ai app/Council
git add app/Ai app/Council resources/prompts tests/Unit/Council/AgentTest.php
git commit -m "feat: add panelist and judge agents with prompts"
```

---

### Task 5: Orchestrator — panel collection (sequential, retry, quorum)

**Files:**
- Create: `app/Council/CouncilOrchestrator.php`
- Create: `app/Council/Exceptions/QuorumNotMetException.php`
- Modify: `tests/Pest.php` (add the `orchestrator()` test helper)
- Test: `tests/Unit/Council/CollectPanelTest.php`

**Interfaces:**
- Consumes: `PanelistAgent`, `FindingValidator`, `config('oast')`, `Lab::OpenRouter`.
- Produces:
  - `App\Council\CouncilOrchestrator` — `new CouncilOrchestrator(FindingValidator $validator, array $config)`.
  - `collectPanel(string $spec): array` — returns a list of `PanelResponse`, one per panelist, each retried once on failure, sequentially.
  - `App\Council\Exceptions\QuorumNotMetException extends \RuntimeException` with public `array $deadModels`; `__construct(array $deadModels, int $succeeded, int $required)`.

- [ ] **Step 1a: Add the `orchestrator()` helper to `tests/Pest.php`**

Append to `tests/Pest.php` (FQCN so no new `use` lines needed). Shared by Tasks 5, 6, 7.

```php
function orchestrator(array $configOverrides = []): \App\Council\CouncilOrchestrator
{
    $config = array_merge([
        'timeout' => 30,
        'api_domain' => 'api.oast.test',
        'panelists' => ['a/one', 'b/two', 'c/three'],
        'judge' => 'judge/strong',
        'baseline' => null,
        'quorum' => 2,
    ], $configOverrides);

    return new \App\Council\CouncilOrchestrator(new \App\Council\FindingValidator, $config);
}
```

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Ai\Agents\PanelistAgent;
use App\Council\PanelResponse;

// orchestrator() comes from tests/Pest.php (Step 1a).

it('collects all three panelists on the happy path', function () {
    PanelistAgent::fake(['critique one', 'critique two', 'critique three']);

    $responses = orchestrator()->collectPanel('SPEC');

    expect($responses)->toHaveCount(3)
        ->and(collect($responses)->every(fn (PanelResponse $r) => $r->ok))->toBeTrue();
});

it('retries a failed panelist once and succeeds on retry', function () {
    $calls = 0;
    PanelistAgent::fake(function () use (&$calls) {
        $calls++;
        if ($calls === 1) {
            throw new RuntimeException('transient');
        }

        return 'critique';
    });

    $responses = orchestrator()->collectPanel('SPEC');

    expect(collect($responses)->every(fn (PanelResponse $r) => $r->ok))->toBeTrue()
        ->and($calls)->toBe(4); // 1 fail + 1 retry + 2 more panelists
});

it('marks a panelist failed when both attempts fail', function () {
    $calls = 0;
    PanelistAgent::fake(function () use (&$calls) {
        $calls++;
        // first panelist (calls 1 & 2) always fails; later panelists succeed
        if ($calls <= 2) {
            throw new RuntimeException('down');
        }

        return 'critique';
    });

    $responses = collect(orchestrator()->collectPanel('SPEC'));

    expect($responses->first()->ok)->toBeFalse()
        ->and($responses->skip(1)->every(fn (PanelResponse $r) => $r->ok))->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Council/CollectPanelTest.php`
Expected: FAIL — `Class "App\Council\CouncilOrchestrator" not found`.

- [ ] **Step 3: Write minimal implementation**

`app/Council/Exceptions/QuorumNotMetException.php`:

```php
<?php

namespace App\Council\Exceptions;

use RuntimeException;

class QuorumNotMetException extends RuntimeException
{
    public function __construct(
        public array $deadModels,
        int $succeeded,
        int $required,
    ) {
        parent::__construct(
            "Quorum not met: {$succeeded} panelist(s) succeeded, {$required} required. Failed: ".implode(', ', $deadModels),
        );
    }
}
```

`app/Council/CouncilOrchestrator.php`:

```php
<?php

namespace App\Council;

use App\Ai\Agents\PanelistAgent;
use App\Council\Prompts\PanelPrompt;
use Laravel\Ai\Enums\Lab;
use Throwable;

class CouncilOrchestrator
{
    public function __construct(
        private FindingValidator $validator,
        private array $config,
    ) {}

    /** @return PanelResponse[] */
    public function collectPanel(string $spec): array
    {
        $userPrompt = PanelPrompt::userPrompt($spec);

        $responses = [];
        foreach ($this->config['panelists'] as $model) {
            $responses[] = $this->promptPanelist($userPrompt, $model)
                ?? $this->promptPanelist($userPrompt, $model)            // retry once
                ?? PanelResponse::failed($model, 'panel call failed after retry');
        }

        return $responses;
    }

    private function promptPanelist(string $userPrompt, string $model): ?PanelResponse
    {
        $start = microtime(true);

        try {
            $response = (new PanelistAgent)->prompt(
                $userPrompt,
                provider: Lab::OpenRouter,
                model: $model,
                timeout: $this->config['timeout'],
            );
        } catch (Throwable) {
            return null;
        }

        $ms = (int) round((microtime(true) - $start) * 1000);

        return PanelResponse::ok($model, $response->text, $ms);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/Council/CollectPanelTest.php`
Expected: PASS (3 passed).

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint app/Council tests/Pest.php
git add app/Council tests/Pest.php tests/Unit/Council/CollectPanelTest.php
git commit -m "feat: add sequential panel collection with retry"
```

---

### Task 6: Orchestrator — judge pass (structured output + retry)

**Files:**
- Modify: `app/Council/CouncilOrchestrator.php`
- Test: `tests/Unit/Council/RunJudgeTest.php`

**Interfaces:**
- Consumes: `JudgeAgent` (structured output), `FindingValidator`, `JudgePrompt`, `config('oast.judge')`.
- Produces:
  - `CouncilOrchestrator::runJudge(string $spec, array $panelCritiques): array` → `['findings' => array, 'ms' => int]`. Reads `$response['findings']`, validates, and on failure re-prompts the judge once with the validation error appended; throws `InvalidJudgeOutputException` if the second attempt also fails.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Ai\Agents\JudgeAgent;
use App\Council\Exceptions\InvalidJudgeOutputException;

// orchestrator() comes from tests/Pest.php (Task 5, Step 1a); validFinding() from FindingValidatorTest is
// also globally available within the suite. If running this file in isolation, copy validFinding() locally.

it('runs the judge and returns validated findings', function () {
    JudgeAgent::fake([['findings' => [validFinding()]]]);

    $result = orchestrator()->runJudge('SPEC', [['model' => 'a/one', 'content' => 'crit']]);

    expect($result['findings'])->toHaveCount(1)
        ->and($result['findings'][0]['severity'])->toBe('blocker')
        ->and($result['ms'])->toBeInt();

    JudgeAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'crit'));
});

it('re-prompts once when the first judge output is invalid, then succeeds', function () {
    JudgeAgent::fake([
        ['findings' => [validFinding(['confidence' => 'split'])]], // invalid: split w/o disagreement
        ['findings' => [validFinding()]],                          // valid
    ]);

    $result = orchestrator()->runJudge('SPEC', [['model' => 'a/one', 'content' => 'crit']]);

    expect($result['findings'])->toHaveCount(1);
});

it('throws when the judge is invalid twice', function () {
    JudgeAgent::fake([
        ['findings' => [validFinding(['confidence' => 'split'])]],
        ['findings' => [validFinding(['confidence' => 'split'])]],
    ]);

    orchestrator()->runJudge('SPEC', [['model' => 'a/one', 'content' => 'crit']]);
})->throws(InvalidJudgeOutputException::class);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Council/RunJudgeTest.php`
Expected: FAIL — `Call to undefined method ...::runJudge()`.

- [ ] **Step 3: Write minimal implementation**

Add `use` lines to `app/Council/CouncilOrchestrator.php`:

```php
use App\Ai\Agents\JudgeAgent;
use App\Council\Exceptions\InvalidJudgeOutputException;
use App\Council\Prompts\JudgePrompt;
```

Add this method to `CouncilOrchestrator`:

```php
public function runJudge(string $spec, array $panelCritiques): array
{
    $base = JudgePrompt::userPrompt($spec, $panelCritiques);
    $lastErrors = [];

    for ($attempt = 0; $attempt < 2; $attempt++) {
        $prompt = $attempt === 0
            ? $base
            : $base."\n\nYour previous response was invalid: ".json_encode($lastErrors)
                .". Return findings that satisfy every rule (a split finding MUST include `disagreement`).";

        $start = microtime(true);
        $response = (new JudgeAgent)->prompt($prompt, provider: Lab::OpenRouter, model: $this->config['judge']);
        $ms = (int) round((microtime(true) - $start) * 1000);

        try {
            $findings = $this->validator->validate($response['findings'] ?? []);

            return ['findings' => $findings, 'ms' => $ms];
        } catch (InvalidJudgeOutputException $e) {
            $lastErrors = $e->errors;
        }
    }

    throw new InvalidJudgeOutputException($lastErrors);
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/Council/RunJudgeTest.php`
Expected: PASS (3 passed).

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint app/Council
git add app/Council tests/Unit/Council/RunJudgeTest.php
git commit -m "feat: add judge pass with structured output and retry"
```

---

### Task 7: Orchestrator — review() wiring & container binding

**Files:**
- Modify: `app/Council/CouncilOrchestrator.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Unit/Council/ReviewTest.php`

**Interfaces:**
- Consumes: `collectPanel`, `runJudge`, `ReviewRequest`, `ReviewMode`, `config('oast')`.
- Produces:
  - `CouncilOrchestrator::review(string $spec, ReviewRequest $request): ReviewResult`. Council mode: `collectPanel`; if fewer than `quorum` succeed, throw `QuorumNotMetException`; judge the successful critiques. Baseline mode: one panelist (`config['baseline'] ?? config['panelists'][0]`), retried once, then judge.
  - `ReviewResult.metrics` = list of `['model' => string, 'ms' => int]` (one per panel attempt + one for the judge).
  - `ReviewResult.rawPanelResponses` = list of `['model' => string, 'ok' => bool, 'content' => ?string, 'error' => ?string]`.
  - Container resolves `CouncilOrchestrator` with live `oast` config.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Ai\Agents\JudgeAgent;
use App\Ai\Agents\PanelistAgent;
use App\Council\CouncilOrchestrator;
use App\Council\Exceptions\QuorumNotMetException;
use App\Council\ReviewMode;
use App\Council\ReviewRequest;

it('produces a complete council review', function () {
    PanelistAgent::fake(['c1', 'c2', 'c3']);
    JudgeAgent::fake([['findings' => [validFinding()]]]);

    $result = orchestrator()->review('SPEC', new ReviewRequest(ReviewMode::Council));

    expect($result->status)->toBe('complete')
        ->and($result->mode)->toBe(ReviewMode::Council)
        ->and($result->panelSize)->toBe(3)
        ->and($result->findings)->toHaveCount(1)
        ->and($result->metrics)->toHaveCount(4); // 3 panel + 1 judge
});

it('fails the council review when quorum is not met', function () {
    PanelistAgent::fake(fn () => throw new RuntimeException('down')); // all panelists fail both attempts
    JudgeAgent::fake();

    orchestrator()->review('SPEC', new ReviewRequest(ReviewMode::Council));
})->throws(QuorumNotMetException::class);

it('produces a baseline review from a single model', function () {
    PanelistAgent::fake(['only critique']);
    JudgeAgent::fake([['findings' => [validFinding()]]]);

    $result = orchestrator()->review('SPEC', new ReviewRequest(ReviewMode::Baseline));

    expect($result->mode)->toBe(ReviewMode::Baseline)
        ->and($result->panelSize)->toBe(1)
        ->and($result->panelModels)->toBe(['a/one']) // first panelist as baseline
        ->and($result->findings)->toHaveCount(1);
});

it('resolves the orchestrator from the container', function () {
    expect(app(CouncilOrchestrator::class))->toBeInstanceOf(CouncilOrchestrator::class);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Council/ReviewTest.php`
Expected: FAIL — `Call to undefined method ...::review()`.

- [ ] **Step 3: Write minimal implementation**

Add `use App\Council\Exceptions\QuorumNotMetException;` to `CouncilOrchestrator`, then add:

```php
public function review(string $spec, ReviewRequest $request): ReviewResult
{
    $panel = $request->mode === ReviewMode::Baseline
        ? $this->baselinePanel($spec)
        : $this->collectPanel($spec);

    $ok = array_values(array_filter($panel, fn (PanelResponse $r) => $r->ok));

    if ($request->mode === ReviewMode::Council && count($ok) < $this->config['quorum']) {
        $dead = array_values(array_map(
            fn (PanelResponse $r) => $r->model,
            array_filter($panel, fn (PanelResponse $r) => ! $r->ok),
        ));
        throw new QuorumNotMetException($dead, count($ok), $this->config['quorum']);
    }

    $critiques = array_map(fn (PanelResponse $r) => ['model' => $r->model, 'content' => $r->content], $ok);
    $judge = $this->runJudge($spec, $critiques);

    $metrics = array_map(fn (PanelResponse $r) => ['model' => $r->model, 'ms' => $r->ms], $panel);
    $metrics[] = ['model' => $this->config['judge'], 'ms' => $judge['ms']];

    return new ReviewResult(
        mode: $request->mode,
        dimension: $request->dimension,
        panelModels: array_map(fn (PanelResponse $r) => $r->model, $ok),
        panelSize: count($ok),
        rawPanelResponses: array_map(fn (PanelResponse $r) => [
            'model' => $r->model,
            'ok' => $r->ok,
            'content' => $r->content,
            'error' => $r->error,
        ], $panel),
        findings: $judge['findings'],
        metrics: $metrics,
        status: 'complete',
    );
}

/** @return PanelResponse[] */
private function baselinePanel(string $spec): array
{
    $model = $this->config['baseline'] ?? $this->config['panelists'][0];
    $userPrompt = PanelPrompt::userPrompt($spec);

    return [
        $this->promptPanelist($userPrompt, $model)
            ?? $this->promptPanelist($userPrompt, $model)
            ?? PanelResponse::failed($model, 'baseline call failed after retry'),
    ];
}
```

Add binding in `app/Providers/AppServiceProvider.php` `register()`:

```php
$this->app->singleton(\App\Council\CouncilOrchestrator::class, function ($app) {
    return new \App\Council\CouncilOrchestrator(
        $app->make(\App\Council\FindingValidator::class),
        $app['config']->get('oast'),
    );
});
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/Council/ReviewTest.php`
Expected: PASS (4 passed).

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint app/Council app/Providers/AppServiceProvider.php
git add app/Council app/Providers/AppServiceProvider.php tests/Unit/Council/ReviewTest.php
git commit -m "feat: wire council and baseline review modes"
```

---

### Task 8: Reviews persistence (migration + model)

**Files:**
- Create: `database/migrations/2026_06_19_000001_create_reviews_table.php`
- Create: `app/Models/Review.php`
- Test: `tests/Feature/ReviewModelTest.php`

**Interfaces:**
- Consumes: `App\Council\ReviewResult`.
- Produces:
  - `App\Models\Review` with JSON-cast columns and `Review::fromResult(ReviewResult $result, ?string $specRef, string $specHash): self`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Council\ReviewMode;
use App\Council\ReviewResult;
use App\Models\Review;

it('persists a review result and casts json columns', function () {
    $result = new ReviewResult(
        mode: ReviewMode::Council,
        dimension: 'domain-modeling',
        panelModels: ['a/one', 'b/two'],
        panelSize: 2,
        rawPanelResponses: [['model' => 'a/one', 'ok' => true, 'content' => 'c', 'error' => null]],
        findings: [['title' => 'finding one']],
        metrics: [['model' => 'a/one', 'ms' => 10]],
        status: 'complete',
    );

    $review = Review::fromResult($result, 'openapi.yaml', 'abc123');

    $fresh = Review::find($review->id);
    expect($fresh->spec_ref)->toBe('openapi.yaml')
        ->and($fresh->spec_hash)->toBe('abc123')
        ->and($fresh->mode)->toBe('council')
        ->and($fresh->panel_size)->toBe(2)
        ->and($fresh->findings)->toBe([['title' => 'finding one']])
        ->and($fresh->metrics[0]['model'])->toBe('a/one')
        ->and($fresh->status)->toBe('complete');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/ReviewModelTest.php`
Expected: FAIL — `Class "App\Models\Review" not found`.

- [ ] **Step 3: Write minimal implementation**

`database/migrations/2026_06_19_000001_create_reviews_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->string('spec_ref')->nullable();
            $table->string('spec_hash')->index();
            $table->string('mode');
            $table->string('dimension');
            $table->json('panel_models')->nullable();
            $table->unsignedInteger('panel_size')->default(0);
            $table->json('raw_panel_responses')->nullable();
            $table->json('findings')->nullable();
            $table->json('metrics')->nullable();
            $table->string('status');
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
```

`app/Models/Review.php`:

```php
<?php

namespace App\Models;

use App\Council\ReviewResult;
use Illuminate\Database\Eloquent\Model;

class Review extends Model
{
    protected $fillable = [
        'spec_ref', 'spec_hash', 'mode', 'dimension', 'panel_models',
        'panel_size', 'raw_panel_responses', 'findings', 'metrics', 'status', 'error',
    ];

    protected $casts = [
        'panel_models' => 'array',
        'raw_panel_responses' => 'array',
        'findings' => 'array',
        'metrics' => 'array',
        'panel_size' => 'integer',
    ];

    public static function fromResult(ReviewResult $result, ?string $specRef, string $specHash): self
    {
        return static::create(array_merge($result->toArray(), [
            'spec_ref' => $specRef,
            'spec_hash' => $specHash,
        ]));
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Feature/ReviewModelTest.php`
Expected: PASS (1 passed).

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint app/Models
git add app/Models database/migrations tests/Feature/ReviewModelTest.php
git commit -m "feat: add reviews table and model"
```

---

### Task 9: API endpoint — `POST https://api.<domain>/v1/reviews` (ADR action)

**Files:**
- Create: `routes/api.php`
- Create: `app/Http/Requests/StoreReviewRequest.php`
- Create: `app/Actions/Reviews/CreateReviewAction.php`
- Create: `app/Http/Resources/ReviewResource.php`
- Modify: `bootstrap/app.php`
- Modify: `tests/Pest.php` (add `fakeCouncil()` helper, reused by Task 10)
- Test: `tests/Feature/ReviewEndpointTest.php`

**Interfaces:**
- Consumes: `CouncilOrchestrator`, `Review::fromResult`, domain exceptions, `config('oast.api_domain')`.
- Produces:
  - Route `POST /v1/reviews` bound to the `api.*` subdomain, handled by the invokable `CreateReviewAction` (ADR action). Request body `{ "spec": "<raw spec>", "mode": "council|baseline" }` (mode optional, defaults `council`).
  - Success → `ReviewResource` (`200`, JSON under `data`).
  - Domain failure → persisted `status = error` row + `422` JSON `{ status: "error", message }`.

- [ ] **Step 1a: Add the `fakeCouncil()` helper to `tests/Pest.php`**

Append to `tests/Pest.php`. Fakes a full successful council/baseline flow for the **real** agents.

```php
function fakeCouncil(): void
{
    \App\Ai\Agents\PanelistAgent::fake(['critique a', 'critique b', 'critique c']);
    \App\Ai\Agents\JudgeAgent::fake([['findings' => [validFinding()]]]);
}
```

> `validFinding()` is defined in `tests/Unit/Council/FindingValidatorTest.php` and is available across the suite. If you prefer, move `validFinding()` into `tests/Pest.php` so all helper data lives in one place.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Ai\Agents\PanelistAgent;
use App\Models\Review;

// fakeCouncil() comes from tests/Pest.php (Step 1a).

beforeEach(fn () => config(['oast.api_domain' => 'api.oast.test']));

it('runs a council review over http and persists it', function () {
    fakeCouncil();

    $response = $this->postJson('http://api.oast.test/v1/reviews', [
        'spec' => 'openapi: 3.1.0',
        'mode' => 'council',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.status', 'complete')
        ->assertJsonPath('data.mode', 'council')
        ->assertJsonCount(1, 'data.findings');

    expect(Review::where('status', 'complete')->count())->toBe(1);
});

it('requires a spec', function () {
    $this->postJson('http://api.oast.test/v1/reviews', ['mode' => 'council'])
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('spec');
});

it('persists an error row and returns 422 when quorum is not met', function () {
    PanelistAgent::fake(fn () => throw new RuntimeException('down'));

    $this->postJson('http://api.oast.test/v1/reviews', ['spec' => 'openapi: 3.1.0', 'mode' => 'council'])
        ->assertStatus(422)
        ->assertJsonPath('status', 'error');

    expect(Review::where('status', 'error')->count())->toBe(1);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/ReviewEndpointTest.php`
Expected: FAIL — 404 (route not registered) / class not found.

- [ ] **Step 3: Write minimal implementation**

Modify `bootstrap/app.php` — register API routes (no path prefix; the subdomain is applied in the route file) and JSON rendering for `v1/*`:

```php
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('v1/*'),
        );
    })->create();
```

`routes/api.php`:

```php
<?php

use App\Actions\Reviews\CreateReviewAction;
use Illuminate\Support\Facades\Route;

Route::domain(config('oast.api_domain'))->group(function () {
    Route::post('/v1/reviews', CreateReviewAction::class);
});
```

`app/Http/Requests/StoreReviewRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Council\ReviewMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'spec' => ['required', 'string'],
            'mode' => ['nullable', Rule::enum(ReviewMode::class)],
        ];
    }
}
```

`app/Http/Resources/ReviewResource.php`:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ReviewResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'mode' => $this->mode,
            'dimension' => $this->dimension,
            'panel_size' => $this->panel_size,
            'findings' => $this->findings,
            'metrics' => $this->metrics,
            'status' => $this->status,
        ];
    }
}
```

`app/Actions/Reviews/CreateReviewAction.php`:

```php
<?php

namespace App\Actions\Reviews;

use App\Council\CouncilOrchestrator;
use App\Council\Exceptions\InvalidJudgeOutputException;
use App\Council\Exceptions\QuorumNotMetException;
use App\Council\ReviewMode;
use App\Council\ReviewRequest;
use App\Http\Requests\StoreReviewRequest;
use App\Http\Resources\ReviewResource;
use App\Models\Review;
use Illuminate\Http\JsonResponse;

class CreateReviewAction
{
    public function __construct(private CouncilOrchestrator $orchestrator) {}

    public function __invoke(StoreReviewRequest $request): ReviewResource|JsonResponse
    {
        $spec = (string) $request->string('spec');
        $mode = ReviewMode::from($request->input('mode', 'council'));
        $hash = hash('sha256', $spec);

        try {
            $result = $this->orchestrator->review($spec, new ReviewRequest($mode));
        } catch (QuorumNotMetException|InvalidJudgeOutputException $e) {
            Review::create([
                'spec_ref' => null,
                'spec_hash' => $hash,
                'mode' => $mode->value,
                'dimension' => 'domain-modeling',
                'panel_size' => 0,
                'status' => 'error',
                'error' => $e->getMessage(),
            ]);

            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }

        return new ReviewResource(Review::fromResult($result, null, $hash));
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Feature/ReviewEndpointTest.php`
Expected: PASS (3 passed).

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint app/Actions app/Http routes/api.php bootstrap/app.php tests/Pest.php
git add app/Actions app/Http routes/api.php bootstrap/app.php tests/Pest.php tests/Feature/ReviewEndpointTest.php
git commit -m "feat: add POST /v1/reviews on api subdomain via ADR action"
```

---

### Task 10: Artisan command `oast:review`

**Files:**
- Create: `app/Console/Commands/ReviewCommand.php`
- Test: `tests/Feature/ReviewCommandTest.php`

**Interfaces:**
- Consumes: `CouncilOrchestrator`, `Review::fromResult`.
- Produces:
  - `php artisan oast:review {spec : path to spec file} {--baseline}`. Reads the file, runs the orchestrator live, persists, prints a findings table. `--baseline` selects baseline mode. Returns `Command::FAILURE` (and persists an error row) on a missing file or domain exception.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Review;

// fakeCouncil() comes from tests/Pest.php (Task 9, Step 1a).

it('runs a baseline review from a spec file and persists it', function () {
    fakeCouncil();
    $path = sys_get_temp_dir().'/oast-spec-'.uniqid().'.yaml';
    file_put_contents($path, 'openapi: 3.1.0');

    $this->artisan('oast:review', ['spec' => $path, '--baseline' => true])
        ->assertSuccessful();

    expect(Review::where('mode', 'baseline')->where('status', 'complete')->count())->toBe(1);

    unlink($path);
});

it('fails when the spec file is missing', function () {
    $this->artisan('oast:review', ['spec' => '/no/such/file.yaml'])
        ->assertFailed();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/ReviewCommandTest.php`
Expected: FAIL — command `oast:review` not found.

- [ ] **Step 3: Write minimal implementation**

`app/Console/Commands/ReviewCommand.php`:

```php
<?php

namespace App\Console\Commands;

use App\Council\CouncilOrchestrator;
use App\Council\Exceptions\InvalidJudgeOutputException;
use App\Council\Exceptions\QuorumNotMetException;
use App\Council\ReviewMode;
use App\Council\ReviewRequest;
use App\Models\Review;
use Illuminate\Console\Command;

class ReviewCommand extends Command
{
    protected $signature = 'oast:review {spec : Path to the OpenAPI spec file} {--baseline : Run a single-model baseline}';

    protected $description = 'Convene the Council on an OpenAPI spec (or a single-model baseline).';

    public function handle(CouncilOrchestrator $orchestrator): int
    {
        $path = $this->argument('spec');

        if (! is_file($path)) {
            $this->error("Spec file not found: {$path}");

            return self::FAILURE;
        }

        $spec = file_get_contents($path);
        $mode = $this->option('baseline') ? ReviewMode::Baseline : ReviewMode::Council;
        $hash = hash('sha256', $spec);

        $this->info("Convening {$mode->value} review for {$path} ...");

        try {
            $result = $orchestrator->review($spec, new ReviewRequest($mode));
        } catch (QuorumNotMetException|InvalidJudgeOutputException $e) {
            Review::create([
                'spec_ref' => $path,
                'spec_hash' => $hash,
                'mode' => $mode->value,
                'dimension' => 'domain-modeling',
                'panel_size' => 0,
                'status' => 'error',
                'error' => $e->getMessage(),
            ]);
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $review = Review::fromResult($result, $path, $hash);

        $this->table(
            ['Severity', 'Confidence', 'Title', 'Location'],
            collect($result->findings)->map(fn ($f) => [
                $f['severity'], $f['confidence'], $f['title'], $f['location'],
            ])->all(),
        );

        $this->info(sprintf('Panel size: %d  |  Findings: %d  |  Review #%d',
            $result->panelSize, count($result->findings), $review->id));

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Feature/ReviewCommandTest.php`
Expected: PASS (2 passed).

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint app/Console
git add app/Console tests/Feature/ReviewCommandTest.php
git commit -m "feat: add oast:review artisan command"
```

---

### Task 11: Live smoke tests & default-suite exclusion

**Files:**
- Create: `tests/Feature/LiveCouncilTest.php`
- Modify: `composer.json` (the `test` script)

**Interfaces:**
- Consumes: the real orchestrator path and live OpenRouter (via the SDK).
- Produces: a `live`-grouped test excluded from `composer test`, runnable via `vendor/bin/pest --group=live`.

- [ ] **Step 1: Write the live test (no fakes)**

```php
<?php

use App\Council\CouncilOrchestrator;
use App\Council\ReviewMode;
use App\Council\ReviewRequest;

it('runs a real council review against OpenRouter', function () {
    if (blank(config('ai.providers.openrouter.key'))) {
        $this->markTestSkipped('OPENROUTER_API_KEY not set.');
    }

    $spec = <<<'YAML'
    openapi: 3.1.0
    info: { title: Demo, version: 1.0.0 }
    paths:
      /order_line_items:
        get: { responses: { '200': { description: ok } } }
    YAML;

    $result = app(CouncilOrchestrator::class)->review($spec, new ReviewRequest(ReviewMode::Council));

    expect($result->status)->toBe('complete')
        ->and($result->findings)->not->toBeEmpty();
})->group('live');
```

- [ ] **Step 2: Exclude the live group from the default suite**

Change the `test` script in `composer.json`:

```json
"test": [
    "@php artisan config:clear --ansi",
    "@php artisan test --exclude-group=live"
],
```

- [ ] **Step 3: Run the default suite and confirm the live test does not execute**

Run: `composer test`
Expected: PASS; live test reported as not run (excluded). All faked tests pass.

- [ ] **Step 4: (Manual, optional) Run the live test on demand**

Run: `vendor/bin/pest --group=live`
Expected: With `OPENROUTER_API_KEY` set and valid model slugs, PASS with non-empty findings; otherwise SKIPPED.

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint
git add tests/Feature/LiveCouncilTest.php composer.json
git commit -m "test: add live council smoke test excluded from default suite"
```

---

## Final verification

- [ ] Run the full default suite: `composer test` — all green, live excluded.
- [ ] Run Pint (PER) across the project: `vendor/bin/pint --test` — no style violations.
- [ ] Confirm the two flagged SDK details (per-call usage/cost accessor; structured-output fake API) against the installed `laravel/ai` version; wire per-model token/cost into `metrics` if available.
- [ ] Confirm the M0 fixture decision (build-spec Decision #4) and set valid OpenRouter model slugs in `config/oast.php` before the first `vendor/bin/pest --group=live` run.
- [ ] Update `AGENTS.md`: note the `pint.json` PER preset (the file currently says "no `pint.json`, default preset") and the Laravel AI SDK / OpenRouter provider setup.
