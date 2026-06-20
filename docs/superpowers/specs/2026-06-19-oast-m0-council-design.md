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
  artisan oast:review  ─┐
  (experiment driver)   ├─▶  CouncilOrchestrator  ─▶  OpenRouterClient ─▶ OpenRouter
  POST /v1/reviews     ─┘    (stateless engine)          (BYOK)
        │                          │
        │                          ├─ panel fan-out (Http::pool, 3 models)
        │                          ├─ judge pass (1 dedicated model, structured output)
        │                          └─ returns ReviewResult
        ▼
   reviews table (SQLite)  ← outer layer persists ReviewResult after engine returns
```

### Components

**`CouncilOrchestrator`** — the engine. No DB access.

- **Council mode:** fans out to **3 hardcoded panelist models** via `Http::pool()`
  (concurrent). Default roster: one Anthropic + one OpenAI + one Google model (exact IDs
  set in config; chosen for cross-lab diversity so disagreement is real signal). A failed
  panelist (timeout, rate limit, 5xx) is **retried once**. After retries, enforce a
  **quorum floor of ≥2 successful panelists** — fewer than 2 fails the review with an
  error naming the dead models. A 1-model "panel" is not a panel.
- **Baseline mode:** a single model, one call, routed through the same judge/format path
  so its output shape matches the Council's.
- **Judge pass:** one **dedicated strong model**, never a panelist (synthesis +
  structured output is a genuinely different job from critique). Receives the spec + all
  panel critiques + the Dimension 1 rubric. Uses the model's **native structured-output /
  tool mode** to force schema adherence, plus **one re-prompt retry** feeding back the
  validation error if the first attempt still fails validation. Second failure → error.
- **Returns** a `ReviewResult`: `findings[]`, per-model `{ms, costUsd}`, `panelSize`,
  `mode` (council | baseline), `status`.

**`OpenRouterClient`** — thin HTTP wrapper over OpenRouter. **BYOK** via
`OPENROUTER_API_KEY` (the M0 HTTP endpoint is single-user with no auth layer). Supports
the `response_format`/tool schema needed for the judge's structured output.

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

**HTTP endpoint** `POST /v1/reviews` — thin controller. Accepts a spec + mode, calls the
orchestrator, persists the result, returns JSON (findings + metrics). Plain JSON, **no
SSE** (SSE arrives in M1). This is the M0 deliverable that establishes the REST-core
shape.

**Artisan command** `oast:review {spec} {--baseline}` — the experiment driver. Runs the
orchestrator **live**, persists the result, prints findings + per-model cost/latency to
the terminal. `--baseline` switches to single-model mode. This is what you run for the
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
| `metrics` | JSON — per-model `{ms, costUsd}` |
| `status` | `complete` \| `error` |
| `created_at` / `updated_at` | timestamps |

This seeds the M1+ persistence layer rather than being throwaway.

## Config

`config/oast.php`:

- `panelists` — array of 3 hardcoded model IDs (env-overridable; becomes the
  config-driven roster in M1).
- `judge` — judge model ID.
- `retries` — per-model retry count (default 1).
- `quorum` — minimum successful panelists (default 2).
- `timeouts` — per-call timeout.

## Error handling

| Failure | Behavior |
|---|---|
| Panelist call fails | Retry once; if still failing, count as lost. |
| < 2 panelists succeed | Fail review with error naming dead models; persist `status = error`. |
| Judge returns invalid JSON | Structured-output mode + one re-prompt with the validation error. |
| Judge still invalid | Fail review with error; persist `status = error`. |
| OpenRouter auth/network error | Surfaced as an error result, not an unhandled exception. |

## Testing

Pest 4, functional style. Three layers:

1. **Orchestration tests** — `Http::fake()` with recorded fixture responses (no live
   calls, deterministic, free, CI-safe). Cover: happy-path Council; one panelist fails →
   proceeds with 2; two panelists fail → review errors; judge invalid then retry
   succeeds; judge invalid twice → review errors.
2. **Schema contract tests** — the finding validator accepts well-formed findings and
   rejects: truncated JSON, an invalid `severity`/`confidence` enum, a missing
   `location`, and a `split` finding missing `disagreement`.
3. **Live smoke tests** — behind a Pest `->group('live')`, **excluded from the default
   `composer test` run**, executed on demand to sanity-check the real model integration.

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
| Judge validation | Native structured output + one re-prompt retry. |
| Panel partial failure | Retry each failed model once, then ≥2 quorum floor. |
| Spec input | Raw spec as-is; "digest" deferred. |
| M0 persistence | Persist run artifacts to a `reviews` table. |
| Testing | Faked HTTP + schema contract tests in CI; live tests isolated by Pest group. |
| Baseline comparison | `--baseline` mode in the same command/engine. |
| M0 dimension | Dimension 1 — Domain & Resource Modeling. |
