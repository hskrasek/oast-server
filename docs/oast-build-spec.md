# oast.sh — Build Prep Spec (v1)

> An oast is a kiln that dries and refines raw hops into something brewable.
> Raw spec in → refined spec out. Contains **OAS**; `.sh` reads CLI-native.

## What oast is

A platform for designing, reviewing, and iterating on API specifications
(OpenAPI + Arazzo), spec-first. The **multi-model design panel** ("the Council") is the
first marquee feature, not the whole product — the platform grows rings around it over
time (review → diffs → editor → git-native collaboration).

**Business model: open-core.**
- **OSS, self-hostable** instance — inherently bring-your-own-key (BYOK).
- **Hosted SaaS** at oast.sh — managed keys, auth, billing. BYOK-in-cloud is a *future*
  option to cut user cost.

Same codebase serves both. Hosting is a deploy target + an auth/billing layer, not a fork.

---

## The architecture shift (vs. the single-language draft)

You can't share an in-process library across a **PHP** runtime and a **Rust** binary —
there's no common linkage. So the network boundary *is* the sharing mechanism, and that
turns the earlier "server is a later ring" into **the REST API is the core, from day one.**
This is the correct pattern the moment you commit to two client languages; your constraint
made the decision for you.

```
                       ┌─────────────────────────────┐
                       │   oast core  (Laravel)       │
                       │   the IP lives here          │
                       │                              │
   Rust CLI  ───HTTP──▶│  spec digest → panel fan-out │
   (TUI + CI)  SSE◀────│  → judge → structured        │◀──HTTP/SSE── Web client
                       │  Findings.  REST + SSE.      │              (PHP/Blade or SPA)
                       │                              │
                       │  OpenRouter ◀── Http::pool() │
                       │  Spectral pre-pass (seam ↓)  │
                       └─────────────────────────────┘
```

Both clients stay genuinely thin: read spec → POST it → render the event stream. Laravel's
`Http::pool()` fires the N panel calls concurrently, so the orchestration lives comfortably
in PHP — no perf reason to push it elsewhere.

---

## Three durable principles (unchanged in spirit, network-boundary now)

**1. The core streams; it does not block-and-return.**
`POST /v1/reviews` responds with `text/event-stream`. Same `ReviewEvent` shapes as before:

```
event: lint.done          data: {"findings":[...]}
event: panel.model.start  data: {"model":"..."}
event: panel.model.done   data: {"model":"...","ms":1820,"costUsd":0.012}
event: judge.start        data: {}
event: judge.done         data: {"findings":[...]}
event: error              data: {"stage":"...","message":"..."}
```

- **Web** consumes with `EventSource` → live progress, findings stream in.
- **Rust CLI** consumes the same chunked stream with `reqwest` → TUI progress, or CI log lines.

**2. The core is stateless per review.** `(spec, request) → events`. Session/conversation
state, applied/dismissed findings, chat history → **client + persistence layer**, never the
review engine. Keeps it testable and identical across both clients and both hosting modes.

**3. `location` JSON pointers from day one.** Every `Finding` points into the spec. Unused
by the CLI today; it's what later powers findings → diffs → inline editor. Cheap to carry,
painful to retrofit.

### Finding shape (unchanged — the rubric output contract)

```jsonc
{
  "dimension": "domain-modeling",
  "title": "...",
  "severity": "blocker | should-fix | consider",
  "confidence": "consensus | majority | split | lone-flag",
  "location": "#/paths/~1orders/post",
  "finding": "...",
  "whyItMatters": "...",
  "disagreement": "only when confidence=split",
  "suggestedChange": "..."
}
```

---

## The Spectral seam — decide this before M0

Spectral (your deterministic objective pre-pass) is Node-native, but the core is now PHP.
Three options, pick one:

1. **Node sidecar** — Laravel shells out to Spectral's CLI / runs it as a small service.
   Keeps the best linter; adds a Node dependency to the deploy (a real consideration for
   self-hosters — document it, or containerize it).
2. **PHP-native OpenAPI linter** — fewer/weaker rules, but single-runtime deploy. Simpler
   self-host story.
3. **Drop the pre-pass for M0** — let the panel cover the objective layer too. Costs more
   tokens, muddies the "linters check rules, we check taste" pitch, but unblocks fastest.

Recommendation: **(3) for M0** to prove the core, then **(1) sidecar, containerized** once
it's worth the deploy complexity — so self-hosters get one image, not a Node install guide.

---

## Licensing strategy (open-core — decide before repos go public)

The classic, defensible open-core split:

- **Core server (Laravel): AGPL-3.0.** Network-use copyleft — anyone hosting a modified oast
  must share changes. As sole copyright holder you can still dual-license the SaaS. This is
  the standard move to keep someone from trivially reselling your hosted product.
- **Rust CLI: MIT or Apache-2.0 (permissive).** You *want* max adoption and frictionless
  embedding in other people's CI pipelines — copyleft there would scare off exactly the
  users you want.
- **SaaS-only code (billing, multi-tenancy, managed-key vault): private, unlicensed.**
  Lives outside the OSS repo(s).

> Not legal advice — worth a fee-only IP lawyer pass before launch given there's a commercial
> arm. But this split is the well-trodden open-core path.

---

## Repo structure — the immediate unblock

Open-core wants the public/OSS surface cleanly separable from the proprietary SaaS bits.
Two viable shapes:

**Option A — polyrepo (recommended for open-core clarity):**
```
oast-server   (AGPL)   Laravel: REST + SSE core, web client, self-host Docker
oast-cli      (MIT)    Rust: TUI + CI binary
oast-cloud    (private) SaaS overlay: billing, tenancy, managed keys
oast-docs     (CC/MIT) docs site, eventually oast.sh content
```
Clean license boundaries per repo; the private repo never risks leaking into OSS.

**Option B — monorepo + private overlay:**
```
oast/  (public)  /server  /cli  /docs        ← one OSS repo
oast-cloud/  (private)  consumes oast/server as a dependency
```
Simpler local dev, but you must be disciplined about what's public.

Recommendation: **A.** For a solo open-core project, hard repo boundaries beat discipline —
the license line is physical, not a `.gitignore` you might fat-finger.

---

## Naming within oast

| Thing | Name | Why |
|---|---|---|
| The platform | **oast** (oast.sh) | umbrella |
| The multi-model panel feature | **Council** | matches the "convene the panel" UX |
| The throw-spec-to-panel command | `oast roast` | playful, and literally contains **OAS** (r-**oas**-t) |
| A single review run | a "review" | keep the noun boring; it's an API object |

So: `oast roast ./openapi.yaml` convenes the Council. On-brand and fun without being cute in
the API surface itself.

---

## Milestones (re-sequenced for REST-core)

**M0 — Prove the Council (a weekend or two).** Laravel: one endpoint, no SSE yet (plain JSON
response is fine), one dimension, 3 models via `Http::pool()`, judge with v0 rubric, Zod-
equivalent validation (Laravel form-request / a schema validator) on the judge JSON. Test
against one real spec you know cold. *Is the panel review sharper than asking one model once?*
If no, stop — cheap lesson.

**M1 — Stream + CLI.** Convert the endpoint to SSE (`ReviewEvent`). Build the Rust CLI as a
thin `reqwest` client that renders the stream — start with plain CI-style log output (simpler
than the TUI). All three v0 dimensions (1, 2, 7). Per-run cost reporting.

**M2 — PR + CI surface (resequenced 2026-07-03; TUI deferred).** Meet users where design
review actually happens: a GitHub Action that comments Council findings on spec PRs, plus
**diff-scoped review** — review the spec *change*, not the whole spec (the recurring use
case; whole-spec review is a one-shot audit). CLI `--ci` flag for non-interactive pipeline
mode (exit codes on blocker findings). **Hard prerequisite for any CI gate: finding
fingerprinting + a suppression/baseline file** (the `location` pointers carried since M0
are the fingerprint input) — without it, non-deterministic reruns flake pipelines and the
tool gets uninstalled. Spectral sidecar lands here. The ratatui TUI moves to the deferred
rings (M5+); the M1 plain CLI output carries interactive use until then.

**M3 — Web client.** Laravel-served frontend consuming the same SSE stream. The `location`
pointers you've carried since M0 power an inline spec view. Self-host Docker image ships.

**M4 — Cloud.** `oast-cloud` overlay: auth, billing, managed keys. Hosted oast.sh goes live.
BYOK-in-cloud as a follow-on.

**M5+ — The deferred rings.** The ratatui TUI (interactive apply/dismiss/re-review loop),
greenfield/conversational mode, findings → spec diffs, domain profiles, then the git-native
editor/collaboration platform that was the original vision.

---

## Decisions to lock before the first commit

1. **Spectral seam** — recommend (3) drop for M0, (1) containerized sidecar later.
2. **Licensing** — AGPL server / permissive CLI / private cloud. Lawyer pass pre-launch.
3. **Repo shape** — recommend polyrepo (Option A) for physical license boundaries.
4. **M0 test fixture** — which real spec do you know well enough to judge the review's quality?
5. **Rust TUI lib** — ratatui is the default; worth confirming you're good building the TUI in
   Rust vs. shipping a plain CLI first and adding the TUI at M2 (recommended: plain first).
