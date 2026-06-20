# oast.sh M0 — Council Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the M0 "Council" — a Laravel review engine that fans out an OpenAPI spec to 3 hardcoded LLM panelists, has a dedicated judge organize their critiques into validated, structured findings, and exposes it via one HTTP endpoint and one artisan command, with a single-model baseline mode for comparison.

**Architecture:** One stateless engine (`CouncilOrchestrator`) is a pure-ish function `(spec, request) → ReviewResult` that performs no DB writes. Two thin entry points — `POST /v1/reviews` (the M0 deliverable) and `oast:review` (the experiment driver) — call the engine and own persistence to a `reviews` table. All model traffic goes through OpenRouter (BYOK).

**Tech Stack:** PHP 8.3, Laravel 13, Pest 4, Laravel HTTP client (`Http::pool`), SQLite, OpenRouter API.

## Global Constraints

- PHP `^8.3`, Laravel `^13.8`, Pest `^4.7` — do not add other major deps for M0.
- Tests run against in-memory SQLite (`phpunit.xml`); `RefreshDatabase` is auto-applied to the `Feature` suite via `tests/Pest.php`.
- BYOK: OpenRouter key read from `OPENROUTER_API_KEY` env via `config/oast.php`. No auth layer on the endpoint in M0.
- M0 dimension is fixed: `domain-modeling` (Dimension 1).
- Quorum floor: **2** successful panelists. Per-call retry count: **1**.
- Format with `vendor/bin/pint` (default Laravel preset) before each commit.
- All LLM-orchestration tests use `Http::fake()` — no live calls in the default suite. Live tests are tagged `->group('live')` and excluded from `composer test`.
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

---

### Task 1: Config & value objects

**Files:**
- Create: `config/oast.php`
- Create: `app/Council/ReviewMode.php`
- Create: `app/Council/ReviewRequest.php`
- Create: `app/Council/PanelResponse.php`
- Create: `app/Council/ReviewResult.php`
- Modify: `tests/Pest.php` (bind `TestCase` to the `Unit` suite)
- Test: `tests/Unit/Council/ValueObjectsTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `App\Council\ReviewMode` enum (string-backed): `ReviewMode::Council` (`'council'`), `ReviewMode::Baseline` (`'baseline'`).
  - `App\Council\ReviewRequest` readonly: `new ReviewRequest(ReviewMode $mode, string $dimension = 'domain-modeling')`.
  - `App\Council\PanelResponse` readonly with `string $model, bool $ok, ?string $content, int $ms, float $costUsd, ?string $error`; static `PanelResponse::ok(string $model, string $content, int $ms, float $costUsd): self` and `PanelResponse::failed(string $model, string $error): self`.
  - `App\Council\ReviewResult` readonly with `ReviewMode $mode, string $dimension, array $panelModels, int $panelSize, array $rawPanelResponses, array $findings, array $metrics, string $status` and `toArray(): array` returning snake_case keys (`mode` as its string value).
  - `config('oast')`: keys `base_url, api_key, timeout, panelists` (array of 3 model IDs), `judge` (string), `baseline` (?string), `quorum` (int).

- [ ] **Step 1a: Boot the framework in the Unit suite**

The Council unit tests use Laravel facades/helpers (`Http::fake()`, `config()`, `resource_path()`, `Validator`), which need the application booted. By default `tests/Pest.php` only binds `TestCase` to `Feature`. Add a binding for `Unit` (no `RefreshDatabase` — unit tests touch no DB). In `tests/Pest.php`, after the existing `->in('Feature');` line, add:

```php
pest()->extend(Tests\TestCase::class)->in('Unit');
```

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
    $ok = PanelResponse::ok('openai/gpt', 'critique text', 1200, 0.012);
    expect($ok->ok)->toBeTrue()
        ->and($ok->content)->toBe('critique text')
        ->and($ok->ms)->toBe(1200)
        ->and($ok->costUsd)->toBe(0.012);

    $failed = PanelResponse::failed('google/gemini', 'HTTP 500');
    expect($failed->ok)->toBeFalse()
        ->and($failed->content)->toBeNull()
        ->and($failed->error)->toBe('HTTP 500');
});

it('serializes a review result to a snake_case array', function () {
    $result = new ReviewResult(
        mode: ReviewMode::Council,
        dimension: 'domain-modeling',
        panelModels: ['a', 'b'],
        panelSize: 2,
        rawPanelResponses: [['model' => 'a', 'ok' => true, 'content' => 'x', 'error' => null]],
        findings: [['title' => 'f']],
        metrics: [['model' => 'a', 'ms' => 10, 'costUsd' => 0.1]],
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

it('exposes oast config defaults', function () {
    expect(config('oast.quorum'))->toBe(2)
        ->and(config('oast.panelists'))->toBeArray()->toHaveCount(3);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Council/ValueObjectsTest.php`
Expected: FAIL — `Class "App\Council\ReviewMode" not found`.

- [ ] **Step 3: Write minimal implementation**

`config/oast.php`:

```php
<?php

return [
    'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
    'api_key' => env('OPENROUTER_API_KEY'),
    'timeout' => (int) env('OAST_TIMEOUT', 120),

    // Hardcoded for M0; becomes config-driven roster in M1.
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
        public float $costUsd,
        public ?string $error = null,
    ) {}

    public static function ok(string $model, string $content, int $ms, float $costUsd): self
    {
        return new self($model, true, $content, $ms, $costUsd);
    }

    public static function failed(string $model, string $error): self
    {
        return new self($model, false, null, 0, 0.0, $error);
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
Expected: PASS (4 passed).

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint app/Council config/oast.php
git add app/Council config/oast.php tests/Unit/Council/ValueObjectsTest.php
git commit -m "feat: add M0 council config and value objects"
```

---

### Task 2: OpenRouter client

**Files:**
- Create: `app/Council/OpenRouter/OpenRouterClient.php`
- Create: `app/Council/Exceptions/OpenRouterException.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `.env.example` (append `OPENROUTER_API_KEY=`)
- Test: `tests/Unit/Council/OpenRouterClientTest.php`

**Interfaces:**
- Consumes: `config('oast')`.
- Produces:
  - `App\Council\OpenRouter\OpenRouterClient` constructed as `new OpenRouterClient(string $baseUrl, ?string $apiKey, int $timeout)`.
  - `request(string $model, array $messages, ?array $responseFormat = null): array` → `['content' => string, 'costUsd' => float, 'ms' => int]`; throws `OpenRouterException` on HTTP/transport failure.
  - `pool(array $calls): array` where `$calls` is keyed by model ID, each `['messages' => array, 'responseFormat' => ?array]`. Returns array keyed by model: `['ok' => bool, 'content' => ?string, 'costUsd' => float, 'ms' => int, 'error' => ?string]`. Never throws per-call — captures failures.
  - `App\Council\Exceptions\OpenRouterException extends \RuntimeException`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Council\Exceptions\OpenRouterException;
use App\Council\OpenRouter\OpenRouterClient;
use Illuminate\Support\Facades\Http;

function fakeChat(string $content, float $cost = 0.01): array
{
    return [
        'choices' => [['message' => ['content' => $content]]],
        'usage' => ['cost' => $cost],
    ];
}

function client(): OpenRouterClient
{
    return new OpenRouterClient('https://openrouter.ai/api/v1', 'test-key', 30);
}

it('sends a single chat request and returns content and cost', function () {
    Http::fake(['*/chat/completions' => Http::response(fakeChat('hello', 0.02))]);

    $result = client()->request('openai/gpt', [['role' => 'user', 'content' => 'hi']]);

    expect($result['content'])->toBe('hello')
        ->and($result['costUsd'])->toBe(0.02)
        ->and($result['ms'])->toBeInt();

    Http::assertSent(fn ($req) => $req['model'] === 'openai/gpt'
        && $req->hasHeader('Authorization', 'Bearer test-key'));
});

it('throws OpenRouterException on http failure for single request', function () {
    Http::fake(['*/chat/completions' => Http::response('boom', 500)]);

    client()->request('openai/gpt', [['role' => 'user', 'content' => 'hi']]);
})->throws(OpenRouterException::class);

it('passes response_format through when provided', function () {
    Http::fake(['*/chat/completions' => Http::response(fakeChat('{}'))]);

    client()->request('m', [['role' => 'user', 'content' => 'x']], ['type' => 'json_object']);

    Http::assertSent(fn ($req) => data_get($req->data(), 'response_format.type') === 'json_object');
});

it('pools multiple models and keys results by model', function () {
    Http::fake(function ($request) {
        return Http::response(fakeChat('critique from '.$request['model']));
    });

    $results = client()->pool([
        'a/one' => ['messages' => [['role' => 'user', 'content' => 'x']]],
        'b/two' => ['messages' => [['role' => 'user', 'content' => 'x']]],
    ]);

    expect($results['a/one']['ok'])->toBeTrue()
        ->and($results['a/one']['content'])->toBe('critique from a/one')
        ->and($results['b/two']['ok'])->toBeTrue();
});

it('captures per-call failure in pool without throwing', function () {
    Http::fake(function ($request) {
        return $request['model'] === 'bad/model'
            ? Http::response('err', 500)
            : Http::response(fakeChat('ok'));
    });

    $results = client()->pool([
        'good/model' => ['messages' => [['role' => 'user', 'content' => 'x']]],
        'bad/model' => ['messages' => [['role' => 'user', 'content' => 'x']]],
    ]);

    expect($results['good/model']['ok'])->toBeTrue()
        ->and($results['bad/model']['ok'])->toBeFalse()
        ->and($results['bad/model']['error'])->toContain('500');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Council/OpenRouterClientTest.php`
Expected: FAIL — `Class "App\Council\OpenRouter\OpenRouterClient" not found`.

- [ ] **Step 3: Write minimal implementation**

`app/Council/Exceptions/OpenRouterException.php`:

```php
<?php

namespace App\Council\Exceptions;

use RuntimeException;

class OpenRouterException extends RuntimeException {}
```

`app/Council/OpenRouter/OpenRouterClient.php`:

```php
<?php

namespace App\Council\OpenRouter;

use App\Council\Exceptions\OpenRouterException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class OpenRouterClient
{
    public function __construct(
        private string $baseUrl,
        private ?string $apiKey,
        private int $timeout,
    ) {}

    public function request(string $model, array $messages, ?array $responseFormat = null): array
    {
        try {
            $response = Http::withToken((string) $this->apiKey)
                ->timeout($this->timeout)
                ->acceptJson()
                ->post($this->baseUrl.'/chat/completions', $this->body($model, $messages, $responseFormat));
        } catch (Throwable $e) {
            throw new OpenRouterException("OpenRouter request to {$model} failed: {$e->getMessage()}", previous: $e);
        }

        if ($response->failed()) {
            throw new OpenRouterException("OpenRouter request to {$model} failed: HTTP {$response->status()}");
        }

        $normalized = $this->normalize($model, $response);

        return ['content' => $normalized['content'] ?? '', 'costUsd' => $normalized['costUsd'], 'ms' => $normalized['ms']];
    }

    public function pool(array $calls): array
    {
        $models = array_keys($calls);

        $responses = Http::pool(function (Pool $pool) use ($calls) {
            $requests = [];
            foreach ($calls as $model => $call) {
                $requests[] = $pool->as($model)
                    ->withToken((string) $this->apiKey)
                    ->timeout($this->timeout)
                    ->acceptJson()
                    ->post(
                        $this->baseUrl.'/chat/completions',
                        $this->body($model, $call['messages'], $call['responseFormat'] ?? null),
                    );
            }

            return $requests;
        });

        $out = [];
        foreach ($models as $model) {
            $out[$model] = $this->normalize($model, $responses[$model]);
        }

        return $out;
    }

    private function body(string $model, array $messages, ?array $responseFormat): array
    {
        $body = [
            'model' => $model,
            'messages' => $messages,
            'usage' => ['include' => true],
        ];

        if ($responseFormat !== null) {
            $body['response_format'] = $responseFormat;
        }

        return $body;
    }

    private function normalize(string $model, Response|Throwable $response): array
    {
        if ($response instanceof Throwable) {
            return ['ok' => false, 'content' => null, 'costUsd' => 0.0, 'ms' => 0, 'error' => $response->getMessage()];
        }

        if ($response->failed()) {
            return ['ok' => false, 'content' => null, 'costUsd' => 0.0, 'ms' => 0, 'error' => "HTTP {$response->status()}"];
        }

        $json = $response->json();

        return [
            'ok' => true,
            'content' => (string) data_get($json, 'choices.0.message.content', ''),
            'costUsd' => (float) data_get($json, 'usage.cost', 0.0),
            'ms' => (int) round(((float) ($response->handlerStats()['total_time'] ?? 0)) * 1000),
            'error' => null,
        ];
    }
}
```

Add binding in `app/Providers/AppServiceProvider.php` `register()` method body:

```php
$this->app->singleton(\App\Council\OpenRouter\OpenRouterClient::class, function ($app) {
    $config = $app['config']->get('oast');

    return new \App\Council\OpenRouter\OpenRouterClient(
        $config['base_url'],
        $config['api_key'],
        $config['timeout'],
    );
});
```

Append to `.env.example`:

```
OPENROUTER_API_KEY=
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/Council/OpenRouterClientTest.php`
Expected: PASS (5 passed).

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint app/Council app/Providers/AppServiceProvider.php
git add app/Council app/Providers/AppServiceProvider.php .env.example tests/Unit/Council/OpenRouterClientTest.php
git commit -m "feat: add OpenRouter client with pool and structured-output support"
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
  - `App\Council\FindingValidator` with `validate(array $findings): array` — returns the validated findings unchanged on success; throws `InvalidJudgeOutputException` on any violation.
  - `App\Council\Exceptions\InvalidJudgeOutputException extends \RuntimeException` with public `array $errors` and constructor `__construct(array $errors)`.

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
    (new FindingValidator)->validate([
        validFinding(['confidence' => 'split']),
    ]);
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

it('rejects a non-list / empty payload', function () {
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

### Task 4: Prompt builders

**Files:**
- Create: `resources/prompts/panel.md`
- Create: `resources/prompts/judge.md`
- Create: `app/Council/Prompts/PanelPrompt.php`
- Create: `app/Council/Prompts/JudgePrompt.php`
- Test: `tests/Unit/Council/PromptTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `App\Council\Prompts\PanelPrompt::build(string $spec): array` — returns OpenAI-style messages `[['role' => 'system', 'content' => ...], ['role' => 'user', 'content' => ...]]`. The user message embeds the raw spec. **No rubric** is included (keeps panel disagreement genuine).
  - `App\Council\Prompts\JudgePrompt::build(string $spec, array $panelCritiques): array` — `$panelCritiques` is a list of `['model' => string, 'content' => string]`. Returns messages whose system content includes the Dimension 1 rubric and a strict instruction to return JSON `{"findings": [...]}`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Council\Prompts\JudgePrompt;
use App\Council\Prompts\PanelPrompt;

it('builds panel messages embedding the spec and no rubric', function () {
    $messages = PanelPrompt::build("openapi: 3.1.0\ntitle: Demo");

    expect($messages)->toBeArray()->and($messages[0]['role'])->toBe('system');
    $joined = collect($messages)->pluck('content')->join("\n");
    expect($joined)->toContain('openapi: 3.1.0')
        ->and(strtolower($joined))->not->toContain('blocker'); // rubric severities not leaked to panel
});

it('builds judge messages embedding spec, critiques, rubric, and json instruction', function () {
    $messages = JudgePrompt::build('SPEC_BODY', [
        ['model' => 'a/one', 'content' => 'critique one'],
        ['model' => 'b/two', 'content' => 'critique two'],
    ]);

    $joined = collect($messages)->pluck('content')->join("\n");
    expect($joined)->toContain('SPEC_BODY')
        ->and($joined)->toContain('critique one')
        ->and($joined)->toContain('a/one')
        ->and($joined)->toContain('domain-modeling')
        ->and($joined)->toContain('findings'); // json instruction present
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Council/PromptTest.php`
Expected: FAIL — `Class "App\Council\Prompts\PanelPrompt" not found`.

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
- split: genuine disagreement — summarize each position in `disagreement`.
- lone-flag: only one panelist raised it.

Return ONLY a JSON object of the exact shape:
{"findings": [{
  "dimension": "domain-modeling",
  "title": "...",
  "severity": "blocker|should-fix|consider",
  "confidence": "consensus|majority|split|lone-flag",
  "location": "#/json/pointer/into/the/spec",
  "finding": "...",
  "why_it_matters": "...",
  "disagreement": "... (include ONLY when confidence is split)",
  "suggested_change": "..."
}]}

`location` must be a JSON Pointer into the provided spec. Output no prose outside the JSON.
```

`app/Council/Prompts/PanelPrompt.php`:

```php
<?php

namespace App\Council\Prompts;

class PanelPrompt
{
    public static function build(string $spec): array
    {
        $system = file_get_contents(resource_path('prompts/panel.md'));

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => "Here is the OpenAPI specification to review:\n\n{$spec}"],
        ];
    }
}
```

`app/Council/Prompts/JudgePrompt.php`:

```php
<?php

namespace App\Council\Prompts;

class JudgePrompt
{
    public static function build(string $spec, array $panelCritiques): array
    {
        $system = file_get_contents(resource_path('prompts/judge.md'));

        $critiques = collect($panelCritiques)
            ->map(fn (array $c) => "### Panelist: {$c['model']}\n{$c['content']}")
            ->join("\n\n");

        $user = "## Specification under review\n\n{$spec}\n\n"
            ."## Panel critiques\n\n{$critiques}";

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/Council/PromptTest.php`
Expected: PASS (2 passed).

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint app/Council
git add app/Council resources/prompts tests/Unit/Council/PromptTest.php
git commit -m "feat: add panel and judge prompt builders"
```

---

### Task 5: Orchestrator — panel collection with retry & quorum

**Files:**
- Create: `app/Council/CouncilOrchestrator.php`
- Create: `app/Council/Exceptions/QuorumNotMetException.php`
- Test: `tests/Unit/Council/CollectPanelTest.php`

**Interfaces:**
- Consumes: `OpenRouterClient`, `FindingValidator`, `config('oast')`.
- Produces:
  - `App\Council\CouncilOrchestrator` constructed as `new CouncilOrchestrator(OpenRouterClient $client, FindingValidator $validator, array $config)` where `$config` is the `oast` config array.
  - `collectPanel(string $spec): array` — returns a list of `PanelResponse` (one per panelist, including failed ones after a single retry of failures).
  - `App\Council\Exceptions\QuorumNotMetException extends \RuntimeException` with public `array $deadModels` and constructor `__construct(array $deadModels, int $succeeded, int $required)`.

- [ ] **Step 1a: Add shared council test helpers to `tests/Pest.php`**

Append these helpers to `tests/Pest.php` (use fully-qualified names so no new `use` lines are needed). They are shared by Tasks 5, 6, and 7. `findingsJson()` is first used in Task 6 but lives here to keep all council helpers in one place.

```php
function chatBody(string $content, float $cost = 0.01): array
{
    return ['choices' => [['message' => ['content' => $content]]], 'usage' => ['cost' => $cost]];
}

function orchestrator(array $configOverrides = []): \App\Council\CouncilOrchestrator
{
    $config = array_merge([
        'base_url' => 'https://openrouter.ai/api/v1',
        'api_key' => 'test',
        'timeout' => 30,
        'panelists' => ['a/one', 'b/two', 'c/three'],
        'judge' => 'judge/strong',
        'baseline' => null,
        'quorum' => 2,
    ], $configOverrides);

    return new \App\Council\CouncilOrchestrator(
        new \App\Council\OpenRouter\OpenRouterClient($config['base_url'], $config['api_key'], $config['timeout']),
        new \App\Council\FindingValidator,
        $config,
    );
}

function findingsJson(): string
{
    return json_encode(['findings' => [[
        'dimension' => 'domain-modeling',
        'title' => 'Join table exposed',
        'severity' => 'blocker',
        'confidence' => 'consensus',
        'location' => '#/paths/~1items',
        'finding' => 'x',
        'why_it_matters' => 'y',
        'suggested_change' => 'z',
    ]]]);
}
```

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Council\PanelResponse;
use Illuminate\Support\Facades\Http;

// orchestrator() and chatBody() come from tests/Pest.php (Step 1a).

it('collects all three panelists on the happy path', function () {
    Http::fake(fn ($req) => Http::response(chatBody("critique {$req['model']}")));

    $responses = orchestrator()->collectPanel('SPEC');

    expect($responses)->toHaveCount(3)
        ->and(collect($responses)->every(fn (PanelResponse $r) => $r->ok))->toBeTrue();
});

it('retries a failed panelist once and succeeds on retry', function () {
    $calls = [];
    Http::fake(function ($req) use (&$calls) {
        $model = $req['model'];
        $calls[$model] = ($calls[$model] ?? 0) + 1;
        if ($model === 'b/two' && $calls[$model] === 1) {
            return Http::response('err', 500); // fail first attempt only
        }

        return Http::response(chatBody("critique {$model}"));
    });

    $responses = collect(orchestrator()->collectPanel('SPEC'))->keyBy->model;

    expect($responses['b/two']->ok)->toBeTrue()
        ->and($calls['b/two'])->toBe(2);
});

it('marks a panelist failed when both attempts fail', function () {
    Http::fake(function ($req) {
        return $req['model'] === 'c/three'
            ? Http::response('err', 500)
            : Http::response(chatBody("critique {$req['model']}"));
    });

    $responses = collect(orchestrator()->collectPanel('SPEC'))->keyBy->model;

    expect($responses['c/three']->ok)->toBeFalse()
        ->and($responses['a/one']->ok)->toBeTrue();
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

use App\Council\OpenRouter\OpenRouterClient;
use App\Council\Prompts\PanelPrompt;

class CouncilOrchestrator
{
    public function __construct(
        private OpenRouterClient $client,
        private FindingValidator $validator,
        private array $config,
    ) {}

    /** @return PanelResponse[] */
    public function collectPanel(string $spec): array
    {
        $calls = [];
        foreach ($this->config['panelists'] as $model) {
            $calls[$model] = ['messages' => PanelPrompt::build($spec)];
        }

        $responses = [];
        $retry = [];
        foreach ($this->client->pool($calls) as $model => $result) {
            if ($result['ok']) {
                $responses[$model] = PanelResponse::ok($model, $result['content'], $result['ms'], $result['costUsd']);
            } else {
                $retry[$model] = $calls[$model];
            }
        }

        if ($retry !== []) {
            foreach ($this->client->pool($retry) as $model => $result) {
                $responses[$model] = $result['ok']
                    ? PanelResponse::ok($model, $result['content'], $result['ms'], $result['costUsd'])
                    : PanelResponse::failed($model, $result['error'] ?? 'unknown error');
            }
        }

        return array_values($responses);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/Council/CollectPanelTest.php`
Expected: PASS (3 passed).

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint app/Council
git add app/Council tests/Unit/Council/CollectPanelTest.php
git commit -m "feat: add panel collection with retry"
```

---

### Task 6: Orchestrator — judge pass with structured output & retry

**Files:**
- Modify: `app/Council/CouncilOrchestrator.php`
- Test: `tests/Unit/Council/RunJudgeTest.php`

**Interfaces:**
- Consumes: `OpenRouterClient::request` with `responseFormat = ['type' => 'json_object']`, `FindingValidator`, `JudgePrompt`.
- Produces:
  - `CouncilOrchestrator::runJudge(string $spec, array $panelCritiques): array` where `$panelCritiques` is a list of `['model' => string, 'content' => string]`. Returns `['findings' => array, 'ms' => int, 'costUsd' => float]`. Re-prompts the judge once with the validation error if the first attempt fails validation; throws `InvalidJudgeOutputException` if the second attempt also fails.

> **Structured-output note:** M0 uses OpenRouter's `response_format: {type: json_object}` (forces valid JSON, broadly supported) plus the `FindingValidator` and one re-prompt to enforce the *shape*. The design doc's stronger "native structured output" goal — a full `json_schema` response_format that forces the finding shape at the API layer — is a deliberate post-M0 hardening step (it depends on per-model `json_schema` support, an unnecessary variable for the experiment).

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Council\Exceptions\InvalidJudgeOutputException;
use Illuminate\Support\Facades\Http;

// orchestrator(), chatBody(), and findingsJson() come from tests/Pest.php (Task 5, Step 1a).

it('runs the judge and returns validated findings', function () {
    Http::fake(fn ($req) => Http::response(chatBody(findingsJson(), 0.05)));

    $result = orchestrator()->runJudge('SPEC', [['model' => 'a/one', 'content' => 'crit']]);

    expect($result['findings'])->toHaveCount(1)
        ->and($result['findings'][0]['severity'])->toBe('blocker')
        ->and($result['costUsd'])->toBe(0.05);

    Http::assertSent(fn ($req) => $req['model'] === 'judge/strong'
        && data_get($req->data(), 'response_format.type') === 'json_object');
});

it('re-prompts once when the first judge output is invalid, then succeeds', function () {
    $attempt = 0;
    Http::fake(function () use (&$attempt) {
        $attempt++;

        return $attempt === 1
            ? Http::response(chatBody('not json at all'))
            : Http::response(chatBody(findingsJson()));
    });

    $result = orchestrator()->runJudge('SPEC', [['model' => 'a/one', 'content' => 'crit']]);

    expect($result['findings'])->toHaveCount(1)
        ->and($attempt)->toBe(2);
});

it('throws when the judge is invalid twice', function () {
    Http::fake(fn () => Http::response(chatBody('still not json')));

    orchestrator()->runJudge('SPEC', [['model' => 'a/one', 'content' => 'crit']]);
})->throws(InvalidJudgeOutputException::class);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Council/RunJudgeTest.php`
Expected: FAIL — `Call to undefined method ...::runJudge()`.

- [ ] **Step 3: Write minimal implementation**

Add `use` for the judge prompt and exception at the top of `app/Council/CouncilOrchestrator.php`:

```php
use App\Council\Exceptions\InvalidJudgeOutputException;
use App\Council\Prompts\JudgePrompt;
```

Add this method to `CouncilOrchestrator`:

```php
public function runJudge(string $spec, array $panelCritiques): array
{
    $messages = JudgePrompt::build($spec, $panelCritiques);
    $lastErrors = [];

    for ($attempt = 0; $attempt < 2; $attempt++) {
        $response = $this->client->request($this->config['judge'], $messages, ['type' => 'json_object']);

        $decoded = json_decode($response['content'], true);
        $findings = is_array($decoded) ? ($decoded['findings'] ?? null) : null;

        try {
            $validated = $this->validator->validate(is_array($findings) ? $findings : []);

            return ['findings' => $validated, 'ms' => $response['ms'], 'costUsd' => $response['costUsd']];
        } catch (InvalidJudgeOutputException $e) {
            $lastErrors = $e->errors;
            $messages[] = [
                'role' => 'user',
                'content' => 'Your previous response was not valid. Errors: '
                    .json_encode($e->errors)
                    .'. Return ONLY the JSON object {"findings": [...]} matching the required schema.',
            ];
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

### Task 7: Orchestrator — review() wiring (council + baseline) & container binding

**Files:**
- Modify: `app/Council/CouncilOrchestrator.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Unit/Council/ReviewTest.php`

**Interfaces:**
- Consumes: `collectPanel`, `runJudge`, `ReviewRequest`, `ReviewMode`, `config('oast')`.
- Produces:
  - `CouncilOrchestrator::review(string $spec, ReviewRequest $request): ReviewResult`.
    - Council mode: `collectPanel`; filter ok responses; if fewer than `quorum` succeed, throw `QuorumNotMetException`; run judge on ok critiques.
    - Baseline mode: single request to `config['baseline'] ?? config['panelists'][0]`; wrap as one ok `PanelResponse`; run judge on it.
  - `ReviewResult.metrics` is a list of `['model' => string, 'ms' => int, 'costUsd' => float]` for every panel attempt plus one entry for the judge.
  - `ReviewResult.rawPanelResponses` is a list of `['model' => string, 'ok' => bool, 'content' => ?string, 'error' => ?string]`.
  - Container resolves `CouncilOrchestrator` with the live `oast` config.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Council\Exceptions\QuorumNotMetException;
use App\Council\ReviewMode;
use App\Council\ReviewRequest;
use Illuminate\Support\Facades\Http;

// orchestrator(), chatBody(), and findingsJson() come from tests/Pest.php (Task 5, Step 1a).

it('produces a complete council review', function () {
    Http::fake(function ($req) {
        return $req['model'] === 'judge/strong'
            ? Http::response(chatBody(findingsJson()))
            : Http::response(chatBody("critique {$req['model']}"));
    });

    $result = orchestrator()->review('SPEC', new ReviewRequest(ReviewMode::Council));

    expect($result->status)->toBe('complete')
        ->and($result->mode)->toBe(ReviewMode::Council)
        ->and($result->panelSize)->toBe(3)
        ->and($result->findings)->toHaveCount(1)
        ->and($result->metrics)->toHaveCount(4); // 3 panel + 1 judge
});

it('fails the council review when quorum is not met', function () {
    Http::fake(function ($req) {
        // only a/one ever succeeds; b/two and c/three fail both attempts
        return $req['model'] === 'a/one'
            ? Http::response(chatBody('critique a/one'))
            : Http::response('err', 500);
    });

    orchestrator()->review('SPEC', new ReviewRequest(ReviewMode::Council));
})->throws(QuorumNotMetException::class);

it('produces a baseline review from a single model', function () {
    Http::fake(function ($req) {
        return $req['model'] === 'judge/strong'
            ? Http::response(chatBody(findingsJson()))
            : Http::response(chatBody("critique {$req['model']}"));
    });

    $result = orchestrator()->review('SPEC', new ReviewRequest(ReviewMode::Baseline));

    expect($result->mode)->toBe(ReviewMode::Baseline)
        ->and($result->panelSize)->toBe(1)
        ->and($result->panelModels)->toBe(['a/one']) // first panelist as baseline
        ->and($result->findings)->toHaveCount(1);
});

it('resolves the orchestrator from the container', function () {
    expect(app(\App\Council\CouncilOrchestrator::class))
        ->toBeInstanceOf(\App\Council\CouncilOrchestrator::class);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Council/ReviewTest.php`
Expected: FAIL — `Call to undefined method ...::review()`.

- [ ] **Step 3: Write minimal implementation**

Add `use` lines at the top of `app/Council/CouncilOrchestrator.php`:

```php
use App\Council\Exceptions\QuorumNotMetException;
```

Add to `CouncilOrchestrator`:

```php
public function review(string $spec, ReviewRequest $request): ReviewResult
{
    $panel = $request->mode === ReviewMode::Baseline
        ? $this->baselinePanel($spec)
        : $this->collectPanel($spec);

    $ok = array_values(array_filter($panel, fn (PanelResponse $r) => $r->ok));

    if ($request->mode === ReviewMode::Council && count($ok) < $this->config['quorum']) {
        $dead = array_map(
            fn (PanelResponse $r) => $r->model,
            array_filter($panel, fn (PanelResponse $r) => ! $r->ok),
        );
        throw new QuorumNotMetException(array_values($dead), count($ok), $this->config['quorum']);
    }

    $critiques = array_map(fn (PanelResponse $r) => ['model' => $r->model, 'content' => $r->content], $ok);
    $judge = $this->runJudge($spec, $critiques);

    $metrics = array_map(
        fn (PanelResponse $r) => ['model' => $r->model, 'ms' => $r->ms, 'costUsd' => $r->costUsd],
        $panel,
    );
    $metrics[] = ['model' => $this->config['judge'], 'ms' => $judge['ms'], 'costUsd' => $judge['costUsd']];

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
    $result = $this->client->pool([$model => ['messages' => PanelPrompt::build($spec)]])[$model];

    return [$result['ok']
        ? PanelResponse::ok($model, $result['content'], $result['ms'], $result['costUsd'])
        : PanelResponse::failed($model, $result['error'] ?? 'unknown error')];
}
```

Add binding in `app/Providers/AppServiceProvider.php` `register()`:

```php
$this->app->singleton(\App\Council\CouncilOrchestrator::class, function ($app) {
    return new \App\Council\CouncilOrchestrator(
        $app->make(\App\Council\OpenRouter\OpenRouterClient::class),
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
  - `App\Models\Review` Eloquent model with JSON-cast columns and `Review::fromResult(ReviewResult $result, ?string $specRef, string $specHash): self` (creates and returns the persisted row).

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
        metrics: [['model' => 'a/one', 'ms' => 10, 'costUsd' => 0.1]],
        status: 'complete',
    );

    $review = Review::fromResult($result, 'openapi.yaml', 'abc123');

    expect($review->exists)->toBeTrue();

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

### Task 9: HTTP endpoint `POST /v1/reviews`

**Files:**
- Create: `routes/api.php`
- Create: `app/Http/Requests/StoreReviewRequest.php`
- Create: `app/Http/Controllers/ReviewController.php`
- Modify: `bootstrap/app.php`
- Modify: `tests/Pest.php` (add `fakeCouncilHttp()` helper, reused by Task 10)
- Test: `tests/Feature/ReviewEndpointTest.php`

**Interfaces:**
- Consumes: `CouncilOrchestrator` (resolved from container), `Review::fromResult`, domain exceptions.
- Produces:
  - `POST /v1/reviews` accepting JSON `{ "spec": "<raw spec>", "mode": "council|baseline" }` (mode optional, defaults `council`).
  - Success → `200` JSON `{ mode, dimension, panel_size, findings, metrics, status }`.
  - Domain failure (`QuorumNotMetException`, `InvalidJudgeOutputException`, `OpenRouterException`) → persisted `status = error` row + `422` JSON `{ status: "error", message }`.

- [ ] **Step 1a: Add the `fakeCouncilHttp()` helper to `tests/Pest.php`**

This fakes a full council HTTP flow against the **real** configured panelists/judge (`config('oast.judge')`), reused by Task 10. Append to `tests/Pest.php`:

```php
function fakeCouncilHttp(): void
{
    $findings = findingsJson();

    \Illuminate\Support\Facades\Http::fake(function ($req) use ($findings) {
        $content = $req['model'] === config('oast.judge') ? $findings : 'critique';

        return \Illuminate\Support\Facades\Http::response([
            'choices' => [['message' => ['content' => $content]]],
            'usage' => ['cost' => 0.01],
        ]);
    });
}
```

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Review;
use Illuminate\Support\Facades\Http;

// fakeCouncilHttp() comes from tests/Pest.php (Step 1a).

it('runs a council review over http and persists it', function () {
    fakeCouncilHttp();

    $response = $this->postJson('/v1/reviews', ['spec' => "openapi: 3.1.0", 'mode' => 'council']);

    $response->assertOk()
        ->assertJsonPath('status', 'complete')
        ->assertJsonPath('mode', 'council')
        ->assertJsonCount(1, 'findings');

    expect(Review::where('status', 'complete')->count())->toBe(1);
});

it('requires a spec', function () {
    $this->postJson('/v1/reviews', ['mode' => 'council'])
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('spec');
});

it('persists an error row and returns 422 when quorum is not met', function () {
    Http::fake(fn ($req) => $req['model'] === config('oast.panelists')[0]
        ? Http::response(['choices' => [['message' => ['content' => 'crit']]], 'usage' => ['cost' => 0.01]])
        : Http::response('err', 500));

    $this->postJson('/v1/reviews', ['spec' => 'openapi: 3.1.0', 'mode' => 'council'])
        ->assertStatus(422)
        ->assertJsonPath('status', 'error');

    expect(Review::where('status', 'error')->count())->toBe(1);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/ReviewEndpointTest.php`
Expected: FAIL — 404 (route not registered) / class not found.

- [ ] **Step 3: Write minimal implementation**

Modify `bootstrap/app.php` — update the `withRouting` and `withExceptions` calls:

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
            fn (Request $request) => $request->is('v1/*') || $request->is('api/*'),
        );
    })->create();
```

`routes/api.php`:

```php
<?php

use App\Http\Controllers\ReviewController;
use Illuminate\Support\Facades\Route;

Route::post('/v1/reviews', [ReviewController::class, 'store']);
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

`app/Http/Controllers/ReviewController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Council\CouncilOrchestrator;
use App\Council\Exceptions\InvalidJudgeOutputException;
use App\Council\Exceptions\OpenRouterException;
use App\Council\Exceptions\QuorumNotMetException;
use App\Council\ReviewMode;
use App\Council\ReviewRequest;
use App\Http\Requests\StoreReviewRequest;
use App\Models\Review;
use Illuminate\Http\JsonResponse;

class ReviewController extends Controller
{
    public function store(StoreReviewRequest $request, CouncilOrchestrator $orchestrator): JsonResponse
    {
        $spec = (string) $request->string('spec');
        $mode = ReviewMode::from($request->input('mode', 'council'));
        $hash = hash('sha256', $spec);

        try {
            $result = $orchestrator->review($spec, new ReviewRequest($mode));
        } catch (QuorumNotMetException|InvalidJudgeOutputException|OpenRouterException $e) {
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

        Review::fromResult($result, null, $hash);

        return response()->json([
            'mode' => $result->mode->value,
            'dimension' => $result->dimension,
            'panel_size' => $result->panelSize,
            'findings' => $result->findings,
            'metrics' => $result->metrics,
            'status' => $result->status,
        ]);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Feature/ReviewEndpointTest.php`
Expected: PASS (3 passed).

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint app/Http routes/api.php bootstrap/app.php
git add app/Http routes/api.php bootstrap/app.php tests/Feature/ReviewEndpointTest.php
git commit -m "feat: add POST /v1/reviews endpoint"
```

---

### Task 10: Artisan command `oast:review`

**Files:**
- Create: `app/Console/Commands/ReviewCommand.php`
- Test: `tests/Feature/ReviewCommandTest.php`

**Interfaces:**
- Consumes: `CouncilOrchestrator`, `Review::fromResult`.
- Produces:
  - `php artisan oast:review {spec : path to spec file} {--baseline}`. Reads the file, runs the orchestrator **live**, persists, prints a findings table + total cost. `--baseline` selects baseline mode. Returns `Command::FAILURE` (and persists an error row) on a missing file or domain exception.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Review;

// fakeCouncilHttp() comes from tests/Pest.php (Task 9, Step 1a).

it('runs a baseline review from a spec file and persists it', function () {
    fakeCouncilHttp();
    $path = sys_get_temp_dir().'/oast-spec-'.uniqid().'.yaml';
    file_put_contents($path, "openapi: 3.1.0");

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
use App\Council\Exceptions\OpenRouterException;
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
        } catch (QuorumNotMetException|InvalidJudgeOutputException|OpenRouterException $e) {
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

        $totalCost = collect($result->metrics)->sum('costUsd');
        $this->info(sprintf('Panel size: %d  |  Findings: %d  |  Total cost: $%.4f  |  Review #%d',
            $result->panelSize, count($result->findings), $totalCost, $review->id));

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
- Test: (this task's deliverable is itself a test file; verify it is skipped by default)

**Interfaces:**
- Consumes: the real `oast:review` path and live OpenRouter API.
- Produces: a `live`-grouped test that is excluded from `composer test` and runnable on demand via `vendor/bin/pest --group=live`.

- [ ] **Step 1: Write the live test (no Http::fake)**

```php
<?php

use App\Council\CouncilOrchestrator;
use App\Council\ReviewMode;
use App\Council\ReviewRequest;

it('runs a real council review against OpenRouter', function () {
    if (blank(config('oast.api_key'))) {
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

- [ ] **Step 2: Verify it is excluded from the default suite**

Modify the `test` script in `composer.json` so the default run skips live tests. Change:

```json
"test": [
    "@php artisan config:clear --ansi",
    "@php artisan test"
],
```

to:

```json
"test": [
    "@php artisan config:clear --ansi",
    "@php artisan test --exclude-group=live"
],
```

- [ ] **Step 3: Run the default suite and confirm the live test does not execute**

Run: `composer test`
Expected: PASS; the live test is reported as not run (excluded). All faked tests pass.

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
- [ ] Run Pint across the project: `vendor/bin/pint --test` — no style violations.
- [ ] Confirm the M0 fixture decision (build-spec Decision #4) is settled and set valid OpenRouter model slugs in `config/oast.php` before the first `vendor/bin/pest --group=live` run.
