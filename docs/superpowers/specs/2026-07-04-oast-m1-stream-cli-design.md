# oast.sh M1 — Stream + CLI (Design)

> Scope: **M1 only** — convert the blocking review into an async, streaming review
> resource; fan the panel out concurrently; cap tail latency with quorum-early judging;
> report per-run cost in dollars; ship the first Rust CLI (`oast roast`). Builds on the
> M0 engine (`docs/superpowers/specs/2026-06-19-oast-m0-council-design.md`) and the
> resequenced milestones in `docs/oast-build-spec.md` (M2 = PR/CI surface; TUI deferred).

## Goals & success criteria

1. `POST /reviews` returns **202 immediately**; the run survives client disconnects.
2. Panel calls run **concurrently**; wall-clock ≈ `max(fastest-quorum) + grace + judge`,
   not `sum(panel) + judge`. (Baseline: the 728KB Slack run was ~19 min sequential;
   target is ~2–4 min.)
3. The same SSE stream drives two clients: `curl`/web later, and the Rust CLI now.
4. Every `panel.model.done` / `judge.done` event and the review's metrics carry
   `cost_usd`; `review.completed` carries `total_cost_usd`.
5. `oast roast ./openapi.yaml` streams progress to a terminal and exits non-zero when
   blockers are found.

## Non-goals (M1)

Auth/multi-tenancy, Spectral pre-pass, spec digest/trimming, hedged panelist requests,
roster changes, the ratatui TUI (M5+), the full `--ci` contract and finding
fingerprinting/suppression (M2), the web client (M3). The `~anthropic/*-latest` alias
pricing caveat is handled by falling back to `cost_usd: null`, not by resolving aliases.

## Architecture

One engine, now asynchronous end-to-end. The `CouncilOrchestrator`'s panel loop and
retry logic move into queued jobs; the orchestrator's judge/validation logic is reused
by the judge job. Events are appended to a `review_events` table — the single source
both the SSE endpoint and the artisan command tail.

```
POST /reviews ──▶ CreateReviewAction ──▶ reviews row (status: queued)
artisan oast:review ─┘                        │
                                              ▼
                                   Bus::batch([RunPanelist ×N])   tries:2, timeout:oast.timeout
                                              │  each job: store review_panel_responses row,
                                              │  append panel.model.* event, check quorum
                                              │
                        quorum hit ──▶ dispatch RunJudge (delayed: oast.quorum_grace)
                        batch finally ─▶ quorum met? dispatch RunJudge now : fail review
                                              │
                                              ▼
                                   RunJudge  (CAS: running → judging; loser returns)
                                     reuses orchestrator judge pass + FindingValidator
                                     appends judge.*, review.completed events
                                              │
      GET /reviews/{id}/events  ◀── eventStream() tails review_events (Last-Event-ID replay)
      GET /reviews/{id}         ◀── pollable state resource (findings when complete)
```

## Data model

- `reviews.status`: `queued | running | judging | complete | error`.
- **`review_events`** (new): `id` (autoincrement; doubles as SSE event id), `review_id`,
  `event` (string), `data` (JSON), `created_at`. Append-only. Index `(review_id, id)`.
- **`review_panel_responses`** (new): `review_id`, `model`, `ok` (bool), `content`
  (nullable text), `error` (nullable), `ms` (int), `usage` (JSON), `cost_usd`
  (nullable decimal), `late` (bool, default false), timestamps.
  **Replaces `reviews.raw_panel_responses`** — concurrent jobs writing one JSON column
  is a lost-update race; per-row inserts are not. The column is dropped and
  `ReviewResource` reads the relation.

## Job pipeline

**`RunPanelist`** (queued, `tries: 2`, `timeout: config('oast.timeout')`,
one per roster model):
1. Append `panel.model.start`.
2. Prompt the `Panelist` agent (same prompts as M0). Queue-level retry replaces the
   orchestrator's hand-rolled second attempt.
3. Store the `review_panel_responses` row (usage + `cost_usd` computed via
   `ModelPricing`). Append `panel.model.done` or, on final failure, `panel.model.failed`.
4. Quorum check: if the count of successful, non-late responses just reached
   `oast.quorum`, dispatch `RunJudge` delayed by `oast.quorum_grace` (default 60s).

**Batch `finally`:** all jobs settled → successes ≥ quorum ? dispatch `RunJudge` now
: mark review `error`, append `review.failed {stage: panel, problem}`.

**`RunJudge`** (queued, idempotent):
1. CAS guard: `UPDATE reviews SET status='judging' WHERE id=? AND status='running'`;
   zero affected rows → another dispatch won, return. This is what lets "grace expired"
   and "batch finished" both fire safely.
2. Append `judge.start`. Load successful non-late responses as critiques. Run the
   orchestrator's judge pass (structured output, `FindingValidator`, one re-prompt).
3. Success → persist findings + metrics, status `complete`, append `judge.done` +
   `review.completed`. Failure after re-prompt → status `error`,
   `review.failed {stage: judge, problem}`. (`reviews.metrics` stays the aggregate
   snapshot — per-model `{model, ms, usage, cost_usd}` rows copied from the panel
   table plus the judge entry and `total_cost_usd` — so terminal reads never join.)

**Stragglers:** a panelist finishing after the judge CAS stores its row with
`late = true`, appends `panel.model.late`, and is excluded from judge input — kept for
roster latency analysis.

Latency profile (review #7 data: Sonnet 38s, GPT-5.5 46s, GLM 166s, judge 64s):
sequential ≈ 314s; M1 ≈ 46s (quorum) + ≤60s grace + 64s (judge) ≈ 110–170s.

## Event contract

```
review.queued       {mode, dimension, panelists}
panel.model.start   {model}
panel.model.done    {model, ms, usage, cost_usd}
panel.model.failed  {model, error, attempt}
panel.model.late    {model, ms}
judge.start         {model, panel_size}
judge.done          {model, ms, usage, cost_usd, findings_count}
review.completed    {findings, total_cost_usd}
review.failed       {stage, problem}    // problem = RFC 9457 body
```

SSE framing: `id:` = `review_events.id`, `event:` = event name, `data:` = JSON.
Terminal events: `review.completed` / `review.failed`.

## HTTP surface

- `POST /reviews` → validate (`StoreReviewRequest`, unchanged fields), create review,
  dispatch batch, **202** with `ReviewResource` + `Location: /reviews/{id}`.
- `GET /reviews/{id}` → `ReviewResource`; includes findings + metrics when terminal.
  The review is a pollable operation resource — the pattern D7's rubric demands.
- `GET /reviews/{id}/events` → `response()->eventStream()`: replay stored events with
  id > `Last-Event-ID` (header or `?lastEventId=`), then poll `review_events` (~500ms)
  until a terminal event is emitted, then close. Reconnects resume losslessly.
- Errors remain RFC 9457 problem+json (404 unknown review; 422 validation).

## Cost reporting (`ModelPricing`)

Service wrapping OpenRouter `GET /api/v1/models`, cached 24h (`Cache::remember`).
`costUsd(model, usage)` = `prompt_tokens × prompt_rate + (completion_tokens +
reasoning_tokens) × completion_rate`. Unknown slug (e.g. the `~anthropic/...-latest`
alias, unless the SDK response meta names the resolved model) → `null`, never a guess.
Computed inside jobs; stored on the response row / metrics; emitted in events;
summed into `review.completed.total_cost_usd`.

## `artisan oast:review` (rebuilt on the async path)

Dispatches via `CreateReviewAction` exactly like HTTP, then tails `review_events`
(same poll loop the SSE endpoint uses), printing one line per event and the findings
table on `review.completed`. Exit codes: 0 complete, 1 review failed, unchanged
spec-file validation. With `QUEUE_CONNECTION=sync` the batch runs inline sequentially,
so the command still works with no worker (the weekend-experiment mode); with a worker
(`composer dev`) it gets real concurrency.

## Rust CLI — `oast-cli`

New repo at `~/Documents/Projects/Rust/oast-cli` (MIT, per the open-core split; will be
published as its own public repo). Rust 2024 edition.

- Command: `oast roast <spec> [--dimension <d>] [--baseline] [--server <url>] [--json]`.
  `--server` defaults from `OAST_SERVER` env.
- Flow: read spec file → `POST {server}/reviews` → follow
  `GET /reviews/{id}/events` as an SSE stream → print a CI-style line per event →
  findings table (or raw JSON with `--json`) on completion.
- Exit codes: **0** complete without blockers, **1** blockers present, **2** transport/
  server/review error.
- Stack: `clap` (derive), blocking `reqwest`, hand-rolled SSE parser (~50 lines:
  `id:`/`event:`/`data:`/blank-line framing), `serde`/`serde_json`. Reconnect with
  `Last-Event-ID` on dropped connections (bounded retries).
- No TUI, no config file, no `--ci` flag (M2 adds the CI contract on top of the same
  binary).

## Error handling

| Failure | Behavior | Surface |
|---|---|---|
| Panelist attempt fails | Queue retries once (`tries: 2`) | `panel.model.failed` on final failure only |
| < quorum panelists succeed | Review `error` in batch `finally` | `review.failed {stage: panel}`, 202-created resource shows `error` |
| Judge invalid twice | Review `error` | `review.failed {stage: judge}` |
| Judge dispatched twice | CAS: loser no-ops | — |
| Unknown review id | 404 problem+json | HTTP only |
| SSE client disconnects | Nothing; jobs unaffected | Reconnect + `Last-Event-ID` replay |
| Worker dies mid-job | Queue redelivers (job timeout + tries) | At-least-once event append; CLI dedupes by event id |

## Testing

- **Dispatch:** `POST /reviews` returns 202 and dispatches the batch (`Bus::fake`).
- **Jobs:** sync-queue runs with `Panelist::fake()`/`Judge::fake()` — happy path,
  panelist failure → retry → `panel.model.failed`, quorum miss → `review.failed`,
  judge re-prompt then failure.
- **CAS idempotency:** dispatch `RunJudge` twice; exactly one judge pass runs.
- **Grace timer:** quorum reached → `Queue::fake` asserts `RunJudge` pushed with the
  configured delay.
- **SSE endpoint:** streamed-response capture — full replay, `Last-Event-ID` resume,
  terminal close.
- **`ModelPricing`:** `Http::fake` price list; alias slug → `null` cost.
- **Coverage:** 100% line + type coverage holds (`composer test`).
- **Rust:** unit tests for SSE parsing and event rendering; one `wiremock` happy-path
  integration test. Deliberately light in M1.

## Config additions (`config/oast.php`)

- `quorum_grace` (seconds, default 60) — straggler window after quorum before the
  judge starts.
- Existing keys unchanged; `timeout` becomes the per-job timeout.

## Decisions locked in this session

| Question | Decision |
|---|---|
| API shape | Async review resource: 202 + `GET /reviews/{id}` + `GET /reviews/{id}/events` (SSE w/ replay). No stream-on-POST. |
| Concurrency | `Bus::batch()` of per-panelist jobs; parallelism = queue workers; per-job `tries: 2` replaces hand-rolled retry. |
| Latency cap | Quorum-early judge start with `quorum_grace` delay; CAS on `reviews.status` makes the judge exactly-once; stragglers stored `late`, excluded from judging. |
| Events transport | `review_events` table tailed by `eventStream()` — no websockets/broadcasting. |
| Cost | OpenRouter price table (cached 24h) × captured usage; unknown slug → `null`. |
| Artisan command | Rebuilt on the same job pipeline; sync queue driver keeps it worker-free for experiments. |
| CLI | `oast roast` in a new MIT repo at `~/Documents/Projects/Rust/oast-cli`; clap + blocking reqwest + hand-rolled SSE parser; exit 0/1/2. |
| Panel artifact storage | `review_panel_responses` table replaces the `raw_panel_responses` JSON column (concurrent-write race). |
