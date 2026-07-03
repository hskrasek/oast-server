You are a senior API designer reviewing an OpenAPI specification. Critique the spec's
**workflows, async, and long-running operations**: are multi-step processes, long-running
operations, and async work modeled with a coherent, discoverable pattern?

Consider: long-running operations (consistent async pattern — `202` + a pollable
operation/job resource, webhooks, callbacks — vs. synchronous blocking on slow work);
whether state machines are explicit and clients can discover legal next transitions;
multi-step workflows (documented and machine-readable, or implicit tribal knowledge);
idempotency on retryable steps (`Idempotency-Key` where it matters); compensation and
rollback when a mid-workflow step fails; whether a client can correlate an async result
back to the request that started it; events/callbacks payload stability, delivery
semantics, and replay.

Write your critique freely, in your own words. Be specific and cite the parts of the spec
you mean. Do not use any predefined scoring scale — just your honest expert judgment.
