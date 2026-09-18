# Design Mode (Server) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `POST /design/turns`, a synchronous, stateless endpoint that takes the current spec, a prose message and a client-held history, and returns a rewritten spec plus a short reply.

**Architecture:** One `Designer` agent (same shape as `Judge`: `Promptable`, structured output, prompt from `resources/prompts/`), one action that builds the prompt, calls the agent, parses the returned YAML and retries once, one FormRequest, one invokable controller, one new Problem Details type. No queue, no migration, no model.

**Tech Stack:** PHP 8.5, Laravel 13, `laravel/ai` 0.8 (OpenRouter via `Lab::OpenRouter`), Pest 4, PHPStan max, Pint (PER), `symfony/yaml` (new).

**Spec:** `docs/superpowers/specs/2026-09-16-oast-design-mode-design.md`

## Global Constraints

- `declare(strict_types=1)` in every PHP file; classes `final`, agents and actions `final readonly`.
- 100% line and type coverage is enforced by `composer test`; every branch you add needs a test.
- API errors are RFC 9457 via `App\Http\Problems\ProblemResponse`, rendered in `bootstrap/app.php`, only on the `oast.api_domain` host.
- Routes live in `routes/api.php` inside the existing `Route::domain(...)->middleware(EnsureApiEnabled::class)` group.
- Run `composer lint` before each commit; `composer test:types` must pass.
- History truncates to the last 20 messages server-side. Spec limit 1 MB (1048576 bytes), message limit 16 KB (16384 bytes).
- Response is `200` (synchronous), not the reviews' `202`.

---

### Task 1: YAML dependency and `designer` config key

**Files:**
- Modify: `composer.json` (via `composer require`)
- Modify: `config/oast.php`
- Modify: `.env.example`
- Test: `tests/Unit/DesignConfigTest.php`

**Interfaces:**
- Produces: `config('oast.designer')` (string|null). Null means "use `config('oast.judge')`". Resolution happens in Task 3's action, not here.

- [ ] **Step 1: Add symfony/yaml**

```bash
composer require symfony/yaml
```

Expected: `composer.json` gains `"symfony/yaml": "^7.x"` under `require`.

- [ ] **Step 2: Add the config key**

In `config/oast.php`, after the `'judge' => ...` line:

```php
    // Single strong drafting model for design mode; null => the judge slug.
    'designer' => env('OAST_DESIGNER'),
```

In `.env.example`, after `OAST_API_DOMAIN=api.oast.test`:

```
OAST_DESIGNER=
```

- [ ] **Step 3: Write the config test**

`tests/Unit/DesignConfigTest.php`:

```php
<?php

declare(strict_types=1);

it('leaves the designer model unset by default so the action falls back to the judge', function (): void {
    expect(config('oast.designer'))->toBeNull()
        ->and(config('oast.judge'))->toBeString();
});
```

- [ ] **Step 4: Run it**

Run: `vendor/bin/pest tests/Unit/DesignConfigTest.php`
Expected: PASS (1 test).

- [ ] **Step 5: Commit**

```bash
composer lint
git add composer.json composer.lock config/oast.php .env.example tests/Unit/DesignConfigTest.php
git commit -m "config: Add designer model key and symfony/yaml"
```

---

### Task 2: `Designer` agent and prompt

**Files:**
- Create: `app/Ai/Agents/Designer.php`
- Create: `resources/prompts/designer.md`
- Create: `app/Council/Prompts/DesignerPrompt.php`
- Test: `tests/Unit/Ai/DesignerAgentTest.php`

**Interfaces:**
- Produces: `App\Ai\Agents\Designer` implementing `Agent, HasStructuredOutput, HasTools`; `schema()` returns `['spec' => string required, 'reply' => string required]`.
- Produces: `DesignerPrompt::userPrompt(array<int, array{role: string, content: string}> $history, string $spec, string $message): string`.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Ai/DesignerAgentTest.php`:

```php
<?php

declare(strict_types=1);

use App\Ai\Agents\Designer;
use App\Council\Prompts\DesignerPrompt;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;

it('loads the designer instructions with the output contract', function (): void {
    $instructions = mb_strtolower(new Designer()->instructions());

    expect($instructions)->toContain('complete spec')
        ->and($instructions)->toContain('openapi 3.1')
        ->and($instructions)->toContain('tbd: confirm with owner');
});

it('defines a spec and reply structured output schema', function (): void {
    $schema = new Designer()->schema(new JsonSchemaTypeFactory());

    expect($schema)->toHaveKeys(['spec', 'reply']);
});

it('has no tools', function (): void {
    expect(new Designer()->tools())->toBe([]);
});

it('builds a user prompt with history, current spec and message in order', function (): void {
    $prompt = DesignerPrompt::userPrompt(
        [['role' => 'user', 'content' => 'earlier ask'], ['role' => 'assistant', 'content' => 'earlier reply']],
        'openapi: 3.1.0',
        'add loans',
    );

    expect($prompt)->toContain('USER: earlier ask')
        ->and($prompt)->toContain('ASSISTANT: earlier reply')
        ->and($prompt)->toContain('openapi: 3.1.0')
        ->and($prompt)->toContain('add loans')
        ->and(mb_strpos($prompt, 'earlier ask'))->toBeLessThan(mb_strpos($prompt, 'openapi: 3.1.0'))
        ->and(mb_strpos($prompt, 'openapi: 3.1.0'))->toBeLessThan(mb_strpos($prompt, 'add loans'));
});

it('marks an empty spec as a fresh start', function (): void {
    expect(DesignerPrompt::userPrompt([], '', 'library API'))->toContain('(empty: this is a new spec)');
});
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Unit/Ai/DesignerAgentTest.php`
Expected: FAIL, `Class "App\Ai\Agents\Designer" not found`.

- [ ] **Step 3: Write the prompt file**

`resources/prompts/designer.md`:

```markdown
# Role

You are a senior API designer co-authoring an OpenAPI 3.1 document with the user, one turn
at a time. The user describes their domain in prose; you draft and revise the spec. The
user may hand-edit the file between turns, so the spec you receive is always the truth.

# House style

- OpenAPI 3.1 YAML. `openapi: 3.1.0`, `info` with `title`, `version`, `description`.
- Resource names are plural nouns in `kebab-case` paths: `/loans`, `/loans/{loanId}`.
  Path parameters are `camelCase`. Schema names are `PascalCase`. Property names are
  `camelCase`. Never expose database join tables or internal IDs as resources.
- Model the domain, not the storage. Sub-resources only when the child cannot exist
  without the parent.
- Every operation has an `operationId` (`verbResource`, e.g. `listLoans`, `createLoan`),
  a `summary`, and `tags` grouped by resource.
- Collections paginate with `?cursor=` and `?limit=` and return `{ data: [...], nextCursor }`.
  Do not add pagination to a collection until it exists as an operation.
- Errors are RFC 9457 Problem Details: `application/problem+json`, one shared `Problem`
  schema under `components/schemas`, referenced from every non-2xx response. Add the
  `Problem` schema the first time any error response is declared, not before.
- Enums are closed `string` enums with a `description` per value where the meaning is not
  obvious. Polymorphism uses `oneOf` with a `discriminator`.
- Timestamps are `string` with `format: date-time`. Money is `{ amount: integer (minor units), currency: string }`.
- Reuse via `$ref`; never duplicate a schema inline that exists in `components`.
- Security schemes are added only when the user mentions auth, or when a write operation
  exists and the user has not said the API is public.

# Output contract

- Return the COMPLETE spec every turn in `spec`. Never elide with `...`, `# unchanged`
  or similar. Every path present in the input spec must be present in the output unless
  the user asked to remove it.
- Make one coherent change set per turn. `reply` states what changed in three to six
  lines, plain prose, no markdown headings.
- Ask at most one clarifying question per turn, at the end of `reply`, and only when the
  answer changes the resource model. Otherwise pick a sensible default and say so.
- Do not invent business rules the user did not mention. When a detail is unknown, put
  a `description` reading `TBD: confirm with owner` on the field or operation.
- When the input spec is empty, produce `openapi`, `info`, a single placeholder
  `servers` entry (`https://api.example.com`) and only the resources the message named.
  Do not scaffold auth, pagination or error schemas until asked or until a second
  resource makes them necessary.
- If the message contains review findings, address the blockers first, then should-fix,
  and say in `reply` which findings you addressed and which you deliberately did not.
```

- [ ] **Step 4: Write the agent**

`app/Ai/Agents/Designer.php`:

```php
<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\File;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;

final readonly class Designer implements Agent, HasStructuredOutput, HasTools
{
    use Promptable;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return File::get(resource_path('prompts/designer.md'));
    }

    /**
     * Get the tools available to the agent.
     *
     * @return Tool[]
     */
    public function tools(): iterable
    {
        return [];
    }

    /**
     * Get the agent's structured output schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'spec' => $schema->string()->required(),
            'reply' => $schema->string()->required(),
        ];
    }
}
```

- [ ] **Step 5: Write the prompt builder**

`app/Council/Prompts/DesignerPrompt.php`:

```php
<?php

declare(strict_types=1);

namespace App\Council\Prompts;

final class DesignerPrompt
{
    /**
     * @param  array<int, array{role: string, content: string}>  $history
     */
    public static function userPrompt(array $history, string $spec, string $message): string
    {
        $transcript = implode("\n\n", array_map(
            fn(array $turn): string => mb_strtoupper($turn['role']) . ': ' . $turn['content'],
            $history,
        ));

        $current = $spec === '' ? '(empty: this is a new spec)' : $spec;

        return "Conversation so far:\n\n" . $transcript
            . "\n\nCurrent spec:\n\n```yaml\n" . $current . "\n```"
            . "\n\nUSER: " . $message;
    }
}
```

- [ ] **Step 6: Run the tests**

Run: `vendor/bin/pest tests/Unit/Ai/DesignerAgentTest.php`
Expected: PASS (5 tests).

- [ ] **Step 7: Commit**

```bash
composer lint
git add app/Ai/Agents/Designer.php resources/prompts/designer.md app/Council/Prompts/DesignerPrompt.php tests/Unit/Ai/DesignerAgentTest.php
git commit -m "feat: Add Designer agent and prompt for design mode"
```

---

### Task 3: `RunDesignTurnAction` with YAML check and single retry

**Files:**
- Create: `app/Actions/Design/RunDesignTurnAction.php`
- Create: `app/Council/Exceptions/DesignerException.php`
- Test: `tests/Unit/Actions/Design/RunDesignTurnActionTest.php`

**Interfaces:**
- Consumes: `Designer` (Task 2), `DesignerPrompt::userPrompt(...)` (Task 2), `config('oast.designer')` (Task 1).
- Produces: `RunDesignTurnAction::__invoke(string $spec, string $message, array $history): array{spec: string, reply: string}`.
- Produces: `DesignerException::invalidOutput(array $errors)` with public readonly `array $errors`.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Actions/Design/RunDesignTurnActionTest.php`:

```php
<?php

declare(strict_types=1);

use App\Actions\Design\RunDesignTurnAction;
use App\Ai\Agents\Designer;
use App\Council\Exceptions\DesignerException;

const VALID_SPEC = "openapi: 3.1.0\ninfo:\n  title: Library\n  version: 0.1.0\n";

function history(int $count): array
{
    return array_map(
        fn(int $i): array => ['role' => $i % 2 === 0 ? 'user' : 'assistant', 'content' => "m{$i}"],
        range(1, $count),
    );
}

it('returns the drafted spec and reply', function (): void {
    Designer::fake([['spec' => VALID_SPEC, 'reply' => 'Started the spec.']]);

    $turn = app(RunDesignTurnAction::class)('', 'library API', []);

    expect($turn)->toBe(['spec' => VALID_SPEC, 'reply' => 'Started the spec.']);
});

it('retries once with the parse error when the spec is not YAML', function (): void {
    Designer::fake([
        ['spec' => "openapi: 3.1.0\ninfo: [unclosed", 'reply' => 'oops'],
        ['spec' => VALID_SPEC, 'reply' => 'fixed'],
    ]);

    $turn = app(RunDesignTurnAction::class)(VALID_SPEC, 'add books', []);

    expect($turn['reply'])->toBe('fixed');
    Designer::assertPrompted(fn(object $prompt): bool => str_contains($prompt->prompt, 'Your previous spec did not parse'));
});

it('throws after two invalid specs', function (): void {
    Designer::fake([
        ['spec' => 'info: [unclosed', 'reply' => 'a'],
        ['spec' => "title: no openapi key\n", 'reply' => 'b'],
    ]);

    expect(fn() => app(RunDesignTurnAction::class)(VALID_SPEC, 'add books', []))
        ->toThrow(DesignerException::class, 'The designer produced a spec that does not parse');
});

it('rejects a parseable document without a top-level openapi key', function (): void {
    Designer::fake([
        ['spec' => "title: nope\n", 'reply' => 'a'],
        ['spec' => "title: nope\n", 'reply' => 'b'],
    ]);

    try {
        app(RunDesignTurnAction::class)(VALID_SPEC, 'x', []);
        $this->fail('expected DesignerException');
    } catch (DesignerException $e) {
        expect($e->errors)->toHaveCount(2)
            ->and($e->errors[0])->toContain('openapi');
    }
});

it('truncates history to the last 20 messages before prompting', function (): void {
    Designer::fake([['spec' => VALID_SPEC, 'reply' => 'ok']]);

    app(RunDesignTurnAction::class)(VALID_SPEC, 'x', history(25));

    Designer::assertPrompted(fn(object $prompt): bool => ! str_contains($prompt->prompt, 'USER: m5') && str_contains($prompt->prompt, 'USER: m6'));
});

it('uses the judge model when no designer model is configured', function (): void {
    config(['oast.designer' => null, 'oast.judge' => 'judge/strong']);

    expect(app(RunDesignTurnAction::class)->model())->toBe('judge/strong');
});

it('prefers the configured designer model', function (): void {
    config(['oast.designer' => 'draft/fast']);

    expect(app(RunDesignTurnAction::class)->model())->toBe('draft/fast');
});
```

`Designer::assertPrompted` (from `Laravel\Ai\Promptable`) passes each recorded prompt object to the closure; the text is on `->prompt`. It passes if any recorded prompt matches.

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Unit/Actions/Design/RunDesignTurnActionTest.php`
Expected: FAIL, `Class "App\Actions\Design\RunDesignTurnAction" not found`.

- [ ] **Step 3: Write the exception**

`app/Council/Exceptions/DesignerException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Council\Exceptions;

use RuntimeException;

final class DesignerException extends RuntimeException
{
    /**
     * @param  array<int, string>  $errors
     */
    private function __construct(string $message, public readonly array $errors)
    {
        parent::__construct($message);
    }

    /**
     * @param  array<int, string>  $errors
     */
    public static function invalidOutput(array $errors): self
    {
        return new self('The designer produced a spec that does not parse', $errors);
    }
}
```

- [ ] **Step 4: Write the action**

`app/Actions/Design/RunDesignTurnAction.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Design;

use App\Ai\Agents\Designer;
use App\Council\Exceptions\DesignerException;
use App\Council\Prompts\DesignerPrompt;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final readonly class RunDesignTurnAction
{
    private const int HISTORY_LIMIT = 20;

    /**
     * @param  array<int, array{role: string, content: string}>  $history
     * @return array{spec: string, reply: string}
     */
    public function __invoke(string $spec, string $message, array $history): array
    {
        $history = array_slice($history, -self::HISTORY_LIMIT);
        $base = DesignerPrompt::userPrompt($history, $spec, $message);
        $errors = [];

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $prompt = $attempt === 0
                ? $base
                : $base . "\n\nYour previous spec did not parse: " . end($errors)
                . '. Return the complete, valid YAML spec.';

            $response = new Designer()->prompt(
                $prompt,
                provider: Lab::OpenRouter,
                model: $this->model(),
                timeout: config()->integer('oast.timeout'),
            );

            $structured = $response instanceof StructuredAgentResponse ? $response->toArray() : [];
            $draft = is_string($structured['spec'] ?? null) ? $structured['spec'] : '';
            $reply = is_string($structured['reply'] ?? null) ? $structured['reply'] : '';

            $error = $this->parseError($draft);
            if ($error === null) {
                return ['spec' => $draft, 'reply' => $reply];
            }

            $errors[] = $error;
        }

        throw DesignerException::invalidOutput($errors);
    }

    public function model(): string
    {
        $designer = config('oast.designer');

        return is_string($designer) && $designer !== '' ? $designer : config()->string('oast.judge');
    }

    private function parseError(string $draft): ?string
    {
        try {
            $document = Yaml::parse($draft);
        } catch (ParseException $exception) {
            return $exception->getMessage();
        }

        if (! is_array($document) || ! isset($document['openapi'])) {
            return 'document has no top-level openapi key';
        }

        return null;
    }
}
```

Check the exact namespaces for `Lab` and `StructuredAgentResponse` against `app/Council/CouncilOrchestrator.php` imports and copy them verbatim.

- [ ] **Step 5: Run the tests**

Run: `vendor/bin/pest tests/Unit/Actions/Design/RunDesignTurnActionTest.php`
Expected: PASS (7 tests).

- [ ] **Step 6: Static analysis**

Run: `composer test:types`
Expected: no errors. Common fix: PHPStan wants `array<int, string>` on `$errors`; the property docblock in the exception covers it.

- [ ] **Step 7: Commit**

```bash
composer lint
git add app/Actions/Design app/Council/Exceptions/DesignerException.php tests/Unit/Actions/Design
git commit -m "feat: Add RunDesignTurnAction with YAML check and single retry"
```

---

### Task 4: Request, controller, route, and Problem Details rendering

**Files:**
- Create: `app/Http/Requests/DesignTurnRequest.php`
- Create: `app/Http/Controllers/DesignTurnController.php`
- Modify: `app/Http/Problems/ProblemType.php`
- Modify: `bootstrap/app.php` (add one `render` block after the `JudgeException` one)
- Modify: `routes/api.php`
- Test: `tests/Feature/DesignTurnApiTest.php`

**Interfaces:**
- Consumes: `RunDesignTurnAction::__invoke(string, string, array): array{spec, reply}` (Task 3), `DesignerException` (Task 3).
- Produces: `POST https://{oast.api_domain}/design/turns` returning `200 {"spec","reply"}`; `ProblemType::InvalidDesignerOutput = 'https://oast.sh/problems/designer-output-invalid'`.

- [ ] **Step 1: Write the failing feature tests**

`tests/Feature/DesignTurnApiTest.php`:

```php
<?php

declare(strict_types=1);

use App\Ai\Agents\Designer;
use App\Http\Problems\ProblemType;

const DRAFT = "openapi: 3.1.0\ninfo:\n  title: Library\n  version: 0.1.0\n";

beforeEach(function (): void {
    config(['oast.api_domain' => 'api.oast.test']);
});

it('returns the drafted spec and reply', function (): void {
    Designer::fake([['spec' => DRAFT, 'reply' => 'Started the spec.']]);

    $this->postJson("https://{$this->apiHost()}/design/turns", [
        'spec' => '',
        'message' => 'Library lending API. Books, members, loans.',
        'history' => [],
    ])
        ->assertOk()
        ->assertExactJson(['spec' => DRAFT, 'reply' => 'Started the spec.']);
});

it('accepts a request without history', function (): void {
    Designer::fake([['spec' => DRAFT, 'reply' => 'ok']]);

    $this->postJson("https://{$this->apiHost()}/design/turns", ['spec' => DRAFT, 'message' => 'add books'])
        ->assertOk();
});

it('returns problem+json when the designer output stays invalid', function (): void {
    Designer::fake([
        ['spec' => 'info: [unclosed', 'reply' => 'a'],
        ['spec' => 'info: [unclosed', 'reply' => 'b'],
    ]);

    $this->postJson("https://{$this->apiHost()}/design/turns", ['spec' => DRAFT, 'message' => 'x'])
        ->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', ProblemType::InvalidDesignerOutput->value)
        ->assertJsonPath('title', 'Designer produced an invalid spec')
        ->assertJsonCount(2, 'errors');
});

it('rejects a missing message', function (): void {
    $this->postJson("https://{$this->apiHost()}/design/turns", ['spec' => DRAFT])
        ->assertStatus(422)
        ->assertJsonPath('type', ProblemType::Validation->value)
        ->assertJsonPath('errors.message.0', fn($msg) => filled($msg));
});

it('rejects a spec over 1 MB', function (): void {
    $this->postJson("https://{$this->apiHost()}/design/turns", [
        'spec' => str_repeat('a', 1048577),
        'message' => 'x',
    ])
        ->assertStatus(422)
        ->assertJsonPath('errors.spec.0', fn($msg) => filled($msg));
});

it('rejects a history entry with an unknown role', function (): void {
    $this->postJson("https://{$this->apiHost()}/design/turns", [
        'spec' => DRAFT,
        'message' => 'x',
        'history' => [['role' => 'system', 'content' => 'nope']],
    ])
        ->assertStatus(422)
        ->assertJsonPath('errors.history\.0\.role.0', fn($msg) => filled($msg));
});

```

The `errors.history\.0\.role.0` path: Laravel's validator keys nested errors as `history.0.role`; the escaped dots tell `assertJsonPath` to treat them literally. If that assertion fails on key shape, dump `->json('errors')` once and match the actual key.

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Feature/DesignTurnApiTest.php`
Expected: FAIL, first test gets 404 (route missing).

- [ ] **Step 3: Add the problem type**

In `app/Http/Problems/ProblemType.php`, keep cases alphabetical:

```php
    case InvalidDesignerOutput = 'https://oast.sh/problems/designer-output-invalid';
```

- [ ] **Step 4: Write the request**

`app/Http/Requests/DesignTurnRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class DesignTurnRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'spec' => ['present', 'string', 'max:1048576'],
            'message' => ['required', 'string', 'max:16384'],
            'history' => ['sometimes', 'array'],
            'history.*.role' => ['required', Rule::in(['user', 'assistant'])],
            'history.*.content' => ['required', 'string'],
        ];
    }

    /**
     * @return array<int, array{role: string, content: string}>
     */
    public function history(): array
    {
        /** @var array<int, array{role: string, content: string}> $history */
        $history = $this->validated('history', []);

        return array_values($history);
    }
}
```

`present` rather than `required` on `spec` so an empty string (turn one) passes.

- [ ] **Step 5: Write the controller**

`app/Http/Controllers/DesignTurnController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Design\RunDesignTurnAction;
use App\Http\Requests\DesignTurnRequest;
use Illuminate\Http\JsonResponse;

final class DesignTurnController
{
    public function __invoke(DesignTurnRequest $request, RunDesignTurnAction $action): JsonResponse
    {
        return new JsonResponse($action(
            $request->string('spec')->value(),
            $request->string('message')->value(),
            $request->history(),
        ));
    }
}
```

- [ ] **Step 6: Register the route**

In `routes/api.php`, inside the domain group after the reviews routes:

```php
    Route::post('/design/turns', DesignTurnController::class)->name('api.design.turns');
```

Add `use App\Http\Controllers\DesignTurnController;` to the imports.

- [ ] **Step 7: Render the exception**

In `bootstrap/app.php`, add `use App\Council\Exceptions\DesignerException;` to the imports and, after the `JudgeException` render block:

```php
        $exceptions->render(function (DesignerException $e, Request $request) use ($onApi): ?Response {
            if (! $onApi($request)) {
                return null;
            }

            $problem = new ApiProblem('Designer produced an invalid spec', ProblemType::InvalidDesignerOutput->value)
                ->setDetail($e->getMessage());
            $problem['errors'] = $e->errors;

            return ProblemResponse::from($problem, 422);
        });
```

- [ ] **Step 8: Run the feature tests**

Run: `vendor/bin/pest tests/Feature/DesignTurnApiTest.php`
Expected: PASS (6 tests).

- [ ] **Step 9: Run the full gate**

Run: `composer test`
Expected: PASS, including 100% line and type coverage. `bootstrap/app.php` is outside the coverage `<include>` (only `app/` is measured), so the `! $onApi($request)` guard in the new render block needs no test.

- [ ] **Step 10: Commit**

```bash
composer lint
git add app/Http/Requests/DesignTurnRequest.php app/Http/Controllers/DesignTurnController.php app/Http/Problems/ProblemType.php bootstrap/app.php routes/api.php tests/Feature/DesignTurnApiTest.php
git commit -m "feat: Add POST /design/turns endpoint"
```

---

### Task 5: Docs

**Files:**
- Modify: `AGENTS.md` (Architecture notes)

- [ ] **Step 1: Add one bullet**

Under "Architecture notes" after the queued-jobs bullet:

```markdown
- **Design mode (`POST /design/turns`)** is synchronous and stateless: `RunDesignTurnAction` prompts the single `Designer` agent (`config('oast.designer')`, falling back to the judge) with client-supplied history, parses the returned YAML, retries once, and answers `{spec, reply}`. The CLI owns the session file; nothing is persisted server-side.
```

- [ ] **Step 2: Commit**

```bash
git add AGENTS.md
git commit -m "docs: Note design mode endpoint in AGENTS.md"
```

---

## Manual acceptance (not automated)

With `OPENROUTER_API_KEY` set and `composer dev` running:

```bash
curl -s https://api.oast.test/design/turns -H 'Content-Type: application/json' \
  -d '{"spec":"","message":"Library lending API. Books, members, loans.","history":[]}' | jq -r .reply
```

Expected: a three-to-six line reply and a parseable spec with `/books`, `/members`, `/loans` and nothing else scaffolded.
