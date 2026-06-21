# API Design Copilot — Judge Rubric (v0)

The judge receives the spec plus all panel responses. It does **not** merge them. It
organizes their critiques against the dimensions below, assigns each finding a
**severity** and a **confidence**, and emits structured findings.

The panel members are *not* given this rubric — they critique freely, so disagreement
stays genuine. The rubric lives here, in the judge.

---

## Scope boundary: what the panel should NOT spend tokens on

Run a deterministic linter (Spectral / Vacuum / Redocly) as a cheap pre-pass. It owns
the objective layer: spec validity, HTTP method semantics, status-code correctness,
idempotency *rules*, security hygiene (no secrets in URLs), `$ref` reuse, missing
`operationId`s, required fields. The judge folds linter output in as pre-confirmed
consensus findings.

The panel exists for the **judgment layer** — design taste where smart people disagree
and there is no rule to encode. That's the three dimensions below (and the rest of the
list to come).

---

## Severity scale

| Level | Meaning | Test |
|---|---|---|
| **Blocker** | Will force a breaking change, corrupt data, or break clients later | "If we ship this, do we owe a v2 rewrite or an incident?" |
| **Should-fix** | Real design debt — friction, confusion, hard to reverse, but survivable | "Will this make every future client integration a little worse?" |
| **Consider** | Genuine judgment call, context-dependent or stylistic | "Reasonable people would pick differently; flag it for a decision." |

## Confidence scale (maps to panel agreement)

| Level | Signal | How to present |
|---|---|---|
| **Consensus** | All/most models independently raised it | High confidence — treat as near-objective |
| **Majority** | More models than not | Confident, note the dissent |
| **Split** | Genuine disagreement | **This is the product.** Surface both positions, let the user decide |
| **Lone flag** | One model only | Unique insight — could be a sharp blind-spot catch or noise; low confidence |

The two axes are independent. A *Split / Blocker* ("two models say this resource
boundary will force a v2, one strongly disagrees") is the single most valuable thing
this tool can produce — it's exactly the call a human should make consciously.

---

## Per-finding output schema

```json
{
  "dimension": "domain-modeling",
  "title": "Order exposes DB join table as a resource",
  "severity": "blocker",
  "confidence": "consensus",
  "location": "#/paths/~1order_line_items",
  "finding": "What's wrong, in one or two sentences.",
  "why_it_matters": "The downstream cost if left as-is.",
  "disagreement": "Only populated when confidence=split: summarize each position.",
  "suggested_change": "Concrete, spec-level: rename, restructure, or the diff shape."
}
```

---

## Dimension 1 — Domain & Resource Modeling

**The question:** Does the API expose the right *domain* concepts, or does it leak
implementation (DB tables, internal services, UI screens)?

**Panel probes:**
- Are resources business-domain nouns, or DB tables mapped 1:1? (schema leakage)
- RPC-in-disguise endpoints? (`/getUser`, `/processOrder`, `POST /doThing`)
- Do resource names match the domain's ubiquitous language (DDD)?
- Are aggregate boundaries respected — one resource spanning what should be several,
  or one aggregate fragmented across many?
- **Missing resources** — a concept the client clearly needs that isn't modeled
  (can create orders but no way to represent a cart).
- Granularity — a god-resource doing everything, or every attribute as its own endpoint?
- Is real behavior modeled, or is it CRUD-only where the domain has true state
  transitions? (state changes smuggled into `PATCH status=...`)

**Good looks like:** resources a domain expert would name; explainable to a non-engineer
stakeholder; internal refactors don't force API changes.

**Smells:** `/tables`-style naming, exposed join tables, leaking internal status enums,
fields named after columns, verbs in paths.

**Severity guidance:** schema leakage that chains the public contract to your DB →
**blocker**. Missing core resource → **should-fix**. Suboptimal-but-workable granularity
→ **consider**.

---

## Dimension 2 — Resource Relationships

**The question:** Is each relationship modeled with the right mechanism — nest, link,
or embed — and is nesting depth sane?

**Panel probes:**
- Sub-resource (`/orders/{id}/items`) vs. top-level + filter (`/items?order=`) vs.
  link vs. embed — right tool for *this* relationship's access pattern and lifecycle?
- Nesting depth > 2 (`/users/{u}/orders/{o}/items/{i}`) — usually a smell. Can the deep
  resource stand alone with its own stable ID?
- Lifecycle coupling — is a child genuinely parent-dependent (cascade delete is correct),
  or independently addressable and wrongly buried (or vice versa)?
- Navigability — can clients traverse the graph every direction they actually need?
- Expand/include strategy for avoiding client-side N+1 — does one exist, is it
  consistent (`?expand=`, `?include=`)?
- Denormalization across representations — deliberate, or accidental drift?

**Good looks like:** relationships reflect real ownership and lifecycle; clients get what
they need in reasonable round-trips; deep things keep stable standalone identities.

**Smells:** deep mandatory nesting baking parent IDs into every URL; children with no
independent identity treated as if they had one; inconsistent include mechanisms;
one-way navigability where the client needs both.

**Severity guidance:** a model that forces N+1 or can't express a needed traversal →
**should-fix**. Deep nesting that bakes ownership into URLs and breaks when ownership
moves → **blocker**. Expand-syntax inconsistency → **consider**.

---

## Dimension 7 — Workflow / Async & Long-Running Operations

*(The Arazzo angle — likely your sharpest differentiator.)*

**The question:** Are multi-step processes, long-running operations, and async work
modeled with a coherent, discoverable pattern?

**Panel probes:**
- Long-running ops — consistent async pattern (`202` + status/operation resource,
  webhooks, callbacks), or synchronous blocking on slow work?
- Is there a pollable operation resource (`/operations/{id}`) with its own lifecycle?
- Are state machines explicit — can a client discover legal next transitions, or guess?
- Multi-step workflows — sequence documented and machine-readable (Arazzo), or implicit
  tribal knowledge? Are step dependencies and data hand-off between steps clear?
- Idempotency on retryable steps — `Idempotency-Key` support where it matters?
- Compensation / rollback — if step 3 of 5 fails, can the client recover, or is it
  stranded in a partial state?
- Correlation — can a client tie an async result back to the request that started it?
- Events/callbacks (if present) — payload stability, delivery semantics, replay?

**Good looks like:** async ops return an operation resource with a clear lifecycle;
workflows are documented (ideally Arazzo) with explicit steps, inputs, outputs, and
failure paths; transitions are discoverable; retries are safe.

**Smells:** synchronous blocking on genuinely long ops; no way to check async job status;
implicit ordering buried in prose ("call X before Y"); no idempotency on retryable steps;
partial-failure states with no recovery path; workflows that live only in someone's head.

**Severity guidance:** no idempotency on payment-like steps → **blocker**. Synchronous
blocking on long ops, undocumented step ordering, missing compensation → **should-fix**.
Discoverability niceties → **consider**.

---

## Still to draft (down the list, one at a time)

- **3 — Naming coherence** (semantic consistency across the *whole* surface, not token-level)
- **4 — Evolution & versioning** (additive-change discipline, where breaking changes lurk)
- **5 — Collection semantics** (pagination strategy fit, filter/sort coherence)
- **6 — Error modeling** (Problem Details, taxonomy mapped to real failure modes)
