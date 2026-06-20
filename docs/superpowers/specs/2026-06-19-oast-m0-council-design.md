# oast.sh M0 — Prove the Council (Design)

> Scope: **M0 only** — the weekend experiment that answers one question:
> *Is a multi-model panel review sharper than asking one model once?*
> This doc plugs the M0-blocking gaps in `docs/oast-build-spec.md` and
> `docs/judge-rubric.md`. It does not redesign the platform; it makes M0 buildable.

## Goal & success criterion

Prove the Council (multi-model design panel) produces a sharper API-design review than
a single model, on **Dimension 1 — Domain & Resource Modeling**.

M0 produces **two** artifacts for every test spec, through the identical judge/format
path:

1. A **Council** review (3 panelists → judge).
2. A single-model **baseline** review (1 model → judge).

Both are persisted side-by-side so the go/no-go decision rests on directly comparable
output. If the Council isn't visibly sharper than the baseline, M0 has done its job —
stop cheaply.

## Non-goals (explicitly out of scope for M0)

SSE streaming, the Rust CLI, the web client, auth/billing, dimensions 2 & 7, the
Spectral pre-pass, `$ref` bundling / multi-file specs, cost caps, and the cloud
overlay. The word **"digest"** from the build spec's diagram is deferred — M0 sends the
raw spec; "digest" is reserved for a future large-spec token optimization and should be
renamed there to avoid implying summarization (which would break `location` pointers).

## Architecture

One engine, two thin entry points. The engine is a pure-ish function
`(spec, request) → result` that performs **no database writes**; the outer layers own
persistence. This honors Principle 2 of the build spec (stateless engine, state lives
outside) while giving M0 both an experiment driver and the REST-core deliverable.

```
  artisan oast:review  ─┐                              PanelistAgent ×3  ┐
  (experiment driver)   ├─▶  CouncilOrchestrator  ─▶   (Laravel AI SDK)  ├─▶ OpenRouter
  CreateReviewAction   ─┘    (stateless engine)        JudgeAgent        ┘   (Lab::OpenRouter,
  POST api.<domain>/reviews        │                   (HasStructuredOutput)   BYOK 1 key)
        │                          ├─ panel fan-out (3 model overrides, sequential)
        │                          ├─ judge pass (1 dedicated model, structured output)
        │                          └─ returns ReviewResult
        ▼
   reviews table (SQLite)  ← outer layer persists ReviewResult after engine returns
```

### Components

**`CouncilOrchestrator`** — the engine. No DB access. Calls panel/judge through the
**Laravel AI SDK** agents (it does not talk HTTP itself).

- **Council mode:** prompts **3 panelist model slugs** (from `config/oast.php`, hardcoded
  for M0; default one Anthropic + one OpenAI + one Google slug for cross-lab diversity)
  via the `PanelistAgent` with per-prompt `model:` overrides. Calls run **sequentially**
  in M0 (concurrency is an M1 SSE-era concern). A failed panelist is **retried once**.
  After retries, enforce a **quorum floor of ≥2 successful panelists** — fewer than 2
  fails the review with an error naming the dead models. A 1-model "panel" is not a panel.
- **Baseline mode:** a single model, one call, routed through the same judge/format path
  so its output shape matches the Council's.
- **Judge pass:** the `JudgeAgent` (`implements HasStructuredOutput`) runs one **dedicated
  strong model**, never a panelist. Receives the spec + all panel critiques + the
  Dimension 1 rubric (the rubric lives in the agent's `instructions()`). The SDK's
  **structured output (`schema()`)** forces the `{findings: [...]}` shape at the provider
  layer; the `FindingValidator` adds defense-in-depth and owns the conditional rule
  (`disagreement` required when `confidence = split`, which the schema marks optional),
  with **one re-prompt retry** on validation failure. Second failure → error.
- **Returns** a `ReviewResult`: `findings[]`, per-model `{ms}`, `panelSize`,
  `mode` (council | baseline), `status`. (Per-model token/cost is a fast follow-up once
  the SDK's per-response usage accessor is confirmed — see Persistence.)

**Models reach OpenRouter via the Laravel AI SDK's built-in `openrouter` provider**
(`Lab::OpenRouter`, configured in `config/ai.php`). **BYOK** stays single-key via
`OPENROUTER_API_KEY`. Native multi-provider (Anthropic/OpenAI/Gemini with separate keys)
is an M1 option. The M0 endpoint is single-user with no auth layer.

**Finding validator** — validates judge output against the per-finding schema before it
leaves the engine. Required fields and enums:

```jsonc
{
  "dimension": "domain-modeling",
  "title": "string",
  "severity": "blocker | should-fix | consider",
  "confidence": "consensus | majority | split | lone-flag",
  "location": "#/json/pointer",
  "finding": "string",
  "why_it_matters": "string",
  "disagreement": "string (required only when confidence = split)",
  "suggested_change": "string"
}
```

**API endpoint** `POST /reviews`, served on the **`api.*` subdomain**
(`config('oast.api_domain')`, e.g. `api.oast.test` locally), not an `/api/` path prefix.
Endpoints are **unversioned by path** — the API evolves backwards-compatibly rather than
cutting `/v1`, `/v2` URL versions.
Implemented with the **ADR pattern**: an invokable single-action controller
(`app/Actions/Reviews/CreateReviewAction`) is the Action; an API Resource
(`ReviewResource`) is the Responder. Accepts a spec + mode, calls the orchestrator,
persists, returns JSON. Plain JSON, **no SSE** (SSE arrives in M1). This is the M0
deliverable that establishes the REST-core shape.

**Artisan command** `oast:review {spec} {--baseline}` — the experiment driver. Runs the
orchestrator **live**, persists the result, prints findings + per-model latency to the
terminal. `--baseline` switches to single-model mode. This is what you run for the
weekend experiment.

## Prompts (the actual IP)

- **Panel prompt:** ask each panelist to critique an API specification's domain and
  resource modeling **freely, in its own words**. The panel is **NOT given the rubric** —
  this keeps disagreement genuine (per `judge-rubric.md`). The rubric lives only in the
  judge.
- **Judge prompt:** given the spec + all panel critiques + the Dimension 1 rubric,
  **organize** the critiques (do not merge or re-critique) into findings, assigning each a
  **severity** (blocker / should-fix / consider) and a **confidence** derived from
  cross-panel agreement (consensus / majority / split / lone-flag), and emit the
  structured JSON array. A *split / blocker* is the most valuable output and must surface
  both positions in `disagreement`.

## Persistence

A single `reviews` table (SQLite — already wired; in-memory for tests, file for dev).
The engine returns a `ReviewResult`; the outer layer (controller or command) writes it.

| Column | Notes |
|---|---|
| `id` | PK |
| `spec_ref` | path/name of the input spec |
| `spec_hash` | hash of spec content (dedupe / compare runs) |
| `mode` | `council` \| `baseline` |
| `dimension` | `domain-modeling` for M0 |
| `panel_models` | JSON — model IDs actually used |
| `panel_size` | count of successful panelists |
| `raw_panel_responses` | JSON — each panelist's verbatim critique |
| `findings` | JSON — validated judge output |
| `metrics` | JSON — per-model `{ms}` (token/cost added once the SDK usage accessor is confirmed) |
| `status` | `complete` \| `error` |
| `created_at` / `updated_at` | timestamps |

This seeds the M1+ persistence layer rather than being throwaway.

> **Metrics scope (SDK consequence):** M0 records measured **latency** per call. The
> Laravel AI SDK doesn't document a per-response token/cost accessor for non-streaming
> prompts; capturing per-model cost (via `$response->usage` or the SDK's invocation
> logging) is a fast follow-up once confirmed against the installed version.

## Config

- `config/ai.php` (Laravel AI SDK): the built-in `openrouter` provider — `driver`,
  `key` (`OPENROUTER_API_KEY`), `url` (`OPENROUTER_URL`).
- `config/oast.php`:
  - `panelists` — array of 3 hardcoded model slugs (config-driven roster in M1).
  - `judge` — judge model slug.
  - `baseline` — baseline model slug (null → first panelist).
  - `quorum` — minimum successful panelists (default 2).
  - `timeout` — per-call timeout.
  - `api_domain` — the `api.*` subdomain the REST API is served on.

## Error handling

| Failure | Behavior |
|---|---|
| Panelist call fails | Retry once; if still failing, count as lost. |
| < 2 panelists succeed | Fail review (`QuorumNotMetException`) naming dead models; persist `status = error`. |
| Judge output fails validation | One re-prompt with the validation error appended. |
| Judge still invalid | Fail review (`InvalidJudgeOutputException`); persist `status = error`. |

## Testing

Pest 4, functional style, using the **Laravel AI SDK's native fakes**
(`PanelistAgent::fake()`, `JudgeAgent::fake()`) — no live HTTP in the default suite. Three
layers:

1. **Orchestration tests** — SDK fakes (deterministic, free, CI-safe). Cover: happy-path
   Council; one panelist fails → retry → proceeds; quorum not met → review errors; judge
   invalid then retry succeeds; judge invalid twice → review errors.
2. **Schema contract tests** — the finding validator accepts well-formed findings and
   rejects: empty payload, an invalid `severity`/`confidence` enum, a missing `location`,
   and a `split` finding missing `disagreement`.
3. **Live smoke tests** — behind a Pest `->group('live')`, **excluded from the default
   `composer test` run** (`--exclude-group=live`), executed on demand to sanity-check the
   real model integration.

## Open item (requires user input, not a design decision)

**M0 test fixture** — build-spec Decision #4: *which real OpenAPI spec do you know well
enough to grade the review's quality against?* The experiment is only as trustworthy as
the spec you can judge the output against. Required before the first live run; pick one
spec you know cold (ideally one with at least one known domain-modeling smell, so there's
a right answer to compare against).

## Decisions locked in this session

| Gap | Decision |
|---|---|
| Panel composition | 3 models hardcoded for M0 (diverse frontier default), config-driven in M1. |
| Judge model | Dedicated strong model, never a panelist. |
| Judge validation | SDK structured output (`HasStructuredOutput`) + `FindingValidator` for the conditional rule + one re-prompt retry. |
| Panel partial failure | Retry each failed model once, then ≥2 quorum floor. |
| Spec input | Raw spec as-is; "digest" deferred. |
| M0 persistence | Persist run artifacts to a `reviews` table. |
| Testing | SDK native fakes + schema contract tests in CI; live tests isolated by Pest group. |
| Baseline comparison | `--baseline` mode in the same command/engine. |
| M0 dimension | Dimension 1 — Domain & Resource Modeling. |
| Runtime | PHP 8.5. |
| Model access | Laravel AI SDK (`laravel/ai`); OpenRouter via the built-in `openrouter` provider (`Lab::OpenRouter`), single-key BYOK. Native multi-provider deferred to M1. |
| Concurrency | Panel calls sequential in M0; concurrent fan-out is an M1 SSE-era concern. |
| API surface | Served on the `api.*` subdomain (not `/api/` path). |
| Entry-point pattern | ADR — invokable single-action controllers in `app/Actions`, API Resource as Responder. |
| Code style | Pint PER preset via committed `pint.json`. |
