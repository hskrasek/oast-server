# Council test fixtures

Public API specs for exercising the Council. All licenses permit redistribution;
sources and rationale below. Run with `php artisan oast:review fixtures/specs/<file>`.

## specs/

| File | Size | Source / License | Why it's here |
|---|---|---|---|
| `train-travel.yaml` | 43KB | [bump-sh-examples/train-travel-api](https://github.com/bump-sh-examples/train-travel-api) (CC-BY-SA; Phil Sturgeon's modern-exemplar spec) | **Control.** A deliberately well-designed spec — measures the council's false-positive rate. A pile of blockers here means the panel over-flags. |
| `slack.yaml` | 728KB | [APIs.guru directory](https://github.com/APIs-guru/openapi-directory) (CC0) | **Known smell: RPC-as-REST.** Slack's Web API is famously method-style (`chat.postMessage`). A defensible-on-purpose design → best available bait for the still-unobserved **split** finding. |
| `adyen-checkout.yaml` | 778KB | [Adyen/adyen-openapi](https://github.com/Adyen/adyen-openapi) (MIT) | **D7 material.** Payments: idempotency, async webhooks, session lifecycles. Ground truth for workflow findings. |

Size note: the two large specs are ≈20% bigger than the 610KB M0 spec — expect
long panel calls. Anything Stripe/GitHub-sized (multi-MB) needs the deferred
"digest" work first; don't add those yet.

## workflows/ (Arazzo)

From [OAI/Arazzo-Specification examples](https://github.com/OAI/Arazzo-Specification/tree/main/examples/1.0.0)
(Apache-2.0), each with its paired OpenAPI spec:

- `bnpl-*.yaml` — buy-now-pay-later loan flow; the richest example (multi-step, conditional branches).
- `FAPI-PAR.*.yaml` — OAuth pushed-authorization flow.
- `pet-coupons.*.yaml` — minimal example.

These are for D7 work: reviewing a spec *with* its declared workflows, and
eventually reviewing Arazzo docs themselves. The
[jentic/jentic-public-apis](https://github.com/jentic/jentic-public-apis) repo
(CC0) has hundreds more generated Arazzo workflows when a bigger corpus is needed.
