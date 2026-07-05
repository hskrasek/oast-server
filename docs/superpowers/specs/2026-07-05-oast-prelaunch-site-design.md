# oast.sh Pre-Launch Site (Design)

> Scope: the public face of oast.sh before the product is usable — a homepage that
> explains the concept, real published Council reviews as proof, and a newsletter
> signup for launch notification. Runs as the real Laravel app (API gated off) on
> AWS ECS behind Cloudflare, provisioned with OpenTofu, deployed via GitHub Actions.

## Goals

1. A visitor understands in one screen what the Council is and why it beats a linter
   and a single-model prompt.
2. Published reviews are **real product output**, individually linkable, and render
   the split-disagreement artifact as the centerpiece.
3. Email capture with double-opt-in, ready to send a launch announcement from SES.
4. Zero standing exposure: no auth'd surface, no OpenRouter key, no writable DB in prod.
5. Repeatable ops: `tofu apply` builds the AWS estate; merge to main deploys.

## Non-goals

RSS, blog, analytics beyond Cloudflare's built-ins, auth, running reviews in prod,
council-vs-baseline comparison pages (fast-follow), the M3 interactive web client.

## Pages

| Route | Purpose |
|---|---|
| `/` | Hero, problem, how-the-Council-works, split explainer, featured review cards, roadmap teaser, signup form |
| `/reviews` | Index of published reviews (cards: spec name, dimension, finding counts by severity, cost, date) |
| `/reviews/{slug}` | Full review: spec context + editorial intro, panel roster, findings table (severity/confidence badges), splits as two-voice debates, run stats (wall time, tokens, **cost in dollars** — transparency is a feature) |
| `/subscribe/confirm/{email}` (signed) | Double-opt-in confirmation landing |

All server-rendered Blade + Tailwind 4 via the existing Vite Plus toolchain. No SPA.

**Launch content** (generated locally, ~$2 total): Slack × domain-modeling
(regenerate — the views-as-resources split is the flagship), Train Travel ×
domain-modeling, × resource-relationships, × workflows. Work-API review stays private.

## Publishing pipeline — content as code

Reviews are generated **locally only** (OpenRouter key never ships to prod).

- `artisan site:publish {reviewId} {slug} [--headline=] [--commentary=path.md]`
  exports the review (findings, panelists, metrics, dimension, spec_ref, created_at)
  plus editorial fields to `database/publications/{slug}.json`, committed to git.
- A `PublicationRepository` reads/caches `database/publications/*.json` and feeds the
  Blade views. **No DB tables, no import step; prod has no database requirement.**
- Unpublish = delete the JSON, redeploy. Content changes are code-reviewable diffs.

JSON shape (one file per publication):

```jsonc
{
  "slug": "slack-web-api-domain-modeling",
  "headline": "Three frontier models vs. Slack's RPC habit",
  "commentary_md": "Why we picked this spec...",   // rendered above the findings
  "spec_name": "Slack Web API",
  "spec_source_url": "https://github.com/APIs-guru/openapi-directory/...",
  "spec_license": "CC0",
  "dimension": "domain-modeling",
  "panelists": ["~anthropic/claude-sonnet-latest", "openai/gpt-5.5", "z-ai/glm-5.2"],
  "judge": "anthropic/claude-opus-4.8",
  "findings": [ /* verbatim from reviews.findings */ ],
  "metrics": [ /* verbatim from reviews.metrics, incl. total_cost_usd */ ],
  "reviewed_at": "2026-07-05T…",
  "published_at": "2026-07-05T…"
}
```

## Newsletter — SES v2 contact list

- `POST /subscribe` (rate-limited, honeypot field): validate email →
  `SesV2::createContact` on contact list `oast-launch` (attribute `confirmed=false`)
  → send confirmation email via SES with a **signed** confirm URL → redirect back
  with a flash ("check your inbox").
- `GET /subscribe/confirm/{email}` (signed middleware): flip contact attribute
  `confirmed=true`, render a small thanks page.
- A `NewsletterContacts` interface wraps the SDK (`SesV2Client`); the container binds
  the real client in prod and a fake in tests — the 100% line+type coverage gate
  applies to all of this.
- Duplicate subscribes are idempotent (catch `AlreadyExistsException`, re-send
  confirmation if unconfirmed). New dependency: `aws/aws-sdk-php`.
- Launch day: send to `confirmed=true` contacts from SES (or import the list into a
  campaign tool — nothing here forecloses that).

## API gating

`config('oast.api_enabled')` from `OAST_API_ENABLED` (default **true** locally,
**false** in prod task definition). When false the `api.*` subdomain routes are not
registered at all. Prod task env contains **no** `OPENROUTER_API_KEY` and runs **no**
queue worker. A feature test asserts the api routes 404 when the flag is off.

## Infrastructure — OpenTofu + GitHub Actions

**Repo layout:** `infra/` (OpenTofu root module) and `.github/workflows/deploy.yml`
live in oast-server. `*.tfvars`/state are not committed (S3 backend + DynamoDB lock,
also provisioned in `infra/bootstrap/`). Nothing account-specific in tracked files —
compatible with the repo eventually going public (AGPL).

**OpenTofu-managed estate (us-east-1 unless noted):**
- ECR repository `oast-server`.
- ECS cluster + Fargate service (1 task, 0.25 vCPU / 512MB): container `app`
  (FrankenPHP image serving the Laravel app) + sidecar `cloudflared` running a
  Cloudflare Tunnel. **No ALB, no public ingress, no inbound security-group rules** —
  the tunnel is the only path in; Cloudflare terminates TLS at the edge.
- SES: domain identity for `oast.sh` (DKIM tokens output for Cloudflare DNS), contact
  list `oast-launch`, from-address `hello@oast.sh`. (Sandbox exit is a manual one-time
  console request — documented, not automated.)
- IAM: task execution role; task role scoped to exactly
  `sesv2:CreateContact/UpdateContact/GetContact/SendEmail` on the list/identity.
- Secrets Manager: the tunnel token (+ `APP_KEY`), injected into the task definition.
- Cloudflare provider: DNS records for the tunnel CNAME and SES DKIM, tunnel +
  tunnel-route resources (token into Secrets Manager).

**GitHub Actions (`deploy.yml`):** on push to `main` —
1. `composer test` (the full 100/100 gate) — deploy blocks on red.
2. `docker build` (multi-stage: composer install --no-dev, `vp build` assets,
   publications JSON baked in) → push to ECR (OIDC role, no long-lived keys).
3. `aws ecs update-service --force-new-deployment`.
A second workflow runs `tofu plan` on PRs touching `infra/` (apply stays manual:
`tofu apply` locally or a manually-dispatched workflow).

**Image contract:** stateless; config via env; `/up` health endpoint for the ECS
health check; publications and compiled assets baked at build time.

## Visual direction (for the design pass)

Between developer-dark and warm-kiln: charcoal base surfaces; ember/copper as the
single warm accent doing severity work; monospace accents where product output
appears (findings render like the CLI table). Severity semantics: blocker = ember
red-orange, should-fix = amber, consider = muted copper/neutral. Confidence rendered
as texture/weight, not color (consensus solid → lone-flag outlined). The split is
ALWAYS a two-voice layout, never a merged paragraph. Prototyping happens in Claude
design using **Appendix A verbatim**; the winning prototype feeds implementation.

## Testing

- `PublicationRepository`: loads/caches/sorts JSON fixtures; malformed file → skipped
  with a log line, never a 500.
- Subscribe flow: happy path (contact created + mail queued), duplicate (idempotent),
  honeypot filled → silent 200 no-op, rate-limit 429, signed-confirm flips attribute,
  tampered signature → 403.
- Pages: `/`, `/reviews`, `/reviews/{slug}` render with a fixture publication; unknown
  slug → 404; api routes 404 when `oast.api_enabled=false`.
- CI runs the same `composer test` gate as always; coverage stays 100/100.

## Decisions locked in this session

| Question | Decision |
|---|---|
| Scope | Homepage + real per-review pages (seed of M3), reviews index, confirm page |
| Hosting | Real Laravel app, API gated off, on AWS ECS Fargate behind Cloudflare |
| Ingress | cloudflared sidecar tunnel; no ALB, no public ingress |
| Persistence | None in prod — publications as committed JSON; SES holds subscribers |
| Newsletter | SES v2 contact list + signed double-opt-in (Mailcoach rejected: paid + needs DB) |
| Publishing | `site:publish` artisan → `database/publications/*.json` → baked into image |
| Launch content | Slack×D1 (regen), Train Travel×D1/D2/D7 |
| Provisioning | OpenTofu (`infra/`), S3 state backend, Cloudflare + AWS providers |
| CI/CD | GitHub Actions: test gate → ECR (OIDC) → ECS deploy; tofu plan on infra PRs |
| Brand | Dark×kiln hybrid; Claude-design prototypes from Appendix A |

---

## Appendix A — Design-prototyping brief (copy/paste into Claude design)

> Everything below is self-contained on purpose — paste it whole.

I'm designing the pre-launch site for **oast.sh** — a developer tool that convenes a
panel of frontier AI models (Claude, GPT, GLM) to review API designs the way a senior
design-review committee would, then has a dedicated judge model organize their
critiques into structured findings. An "oast" is the kiln that dries raw hops into
something brewable: raw spec in, refined spec out. The CLI command is literally
`oast roast ./openapi.yaml`.

**What makes it different (the product's soul):** when panel models genuinely
*disagree*, we don't average them — we surface the disagreement as a "split" finding
showing both positions, because that's exactly the judgment call a human architect
should make consciously. One real example: reviewing Slack's API, Claude argued UI
views should be modeled as resources with lifecycles; GLM pushed back that
event-driven UI commands are a legitimate pattern for a platform API. That debate IS
the product.

**Site purpose (pre-launch):** explain the concept, prove it with real published
reviews, collect emails for launch. No product to use yet. Three page types:

1. **Homepage**: hero → problem → how it works → what a split is → featured review
   cards → roadmap teaser (CLI, CI gate, hosted platform) → email signup.
2. **Review page** (the proof artifact): headline + short editorial intro; a meta
   strip (spec reviewed, dimension, panel of 3 models + judge, wall time, token
   count, **"this review cost $0.62"** — cost transparency is deliberate); findings
   table; splits rendered as a two-voice debate block.
3. **Reviews index**: simple cards.

**Draft copy to work with (rewrite freely):**
- Hero: "Your API design, argued over by a panel that never gets tired." /
  alt: "Linters check the rules. The Council argues the judgment calls."
- Problem: "Spectral tells you an operationId is missing. Nobody tells you your
  resource model leaks the database, your payment flow can't be retried safely, or
  your 'REST' API is RPC in a trench coat — until clients depend on it."
- How it works, 3 steps: "1. Three frontier models critique your spec independently —
  no shared rubric, no groupthink. 2. A judge model organizes their critiques into
  findings — it never adds its own. 3. Every finding carries severity (blocker /
  should-fix / consider) and confidence (consensus / majority / split / lone-flag)."
- Split explainer: "When the panel disagrees, you see both sides. A split on a
  blocker is the most valuable thing we can show you."
- Signup: "We're building in the open. Leave an email, get the launch." Button:
  "Notify me". Confirmation flash: "Check your inbox to confirm."
- Footer: "oast — raw spec in, refined spec out." + GitHub link placeholder.

**Real finding data for realistic mocks** (from an actual run on the public
Train Travel API example; cost $0.62, ~2.3 min, panel: Claude Sonnet, GPT-5.5,
GLM 5.2; judge: Claude Opus):
- BLOCKER · consensus — "Booking lifecycle (held/confirmed/expired/cancelled) is
  described in prose but never modeled as data" — #/components/schemas/Booking
- BLOCKER · consensus — "Payment modeled as a write-only singleton action, not a
  retrievable resource with history" — #/paths/~1bookings~1{bookingId}~1payment/post
- SHOULD-FIX · majority — "Trip conflates timetable, priced offer, and availability"
- CONSIDER · lone-flag — "Optional extras reduced to fixed booleans (has_bicycle/has_dog)"
- SPLIT example (from the Slack review): title "UI-surface concepts (views, dialogs)
  exposed as side-effecting GETs"; Voice A (claude-sonnet): "a view has identity and a
  lifecycle (open→push→update→close) — it's a resource being treated as a
  fire-and-forget GET"; Voice B (glm-5.2): "modeling modal/workflow UI interactions as
  event-driven commands is not necessarily wrong for a platform API — views can be
  transient UI commands, not durable resources."

**Visual direction:** between developer-dark and warm kiln. Charcoal base (not pure
black), one warm ember/copper accent family carrying the heat metaphor and the
severity scale: blocker = ember red-orange, should-fix = amber, consider = muted
copper/neutral gray. Confidence shown by weight/texture, not hue: consensus = solid
filled badge, majority = strong outline, split = the two-voice layout itself,
lone-flag = dashed/ghost outline. Monospace type wherever real product output
appears (findings, locations like `#/paths/~1bookings`, costs, model names) —
findings should feel like a beautiful terminal table. Headlines can be warmer/serif
if it serves the kiln identity. Subtle heat-gradient or grain texture is welcome;
no hop/beer illustration kitsch, no generic SaaS gradient-blob.

**Deliverables I want from prototyping:** 2-3 distinct art directions for the
homepage hero + one full review page in the strongest direction, desktop and a
mobile pass; the findings table and the split debate block are the components that
matter most — design those first.
