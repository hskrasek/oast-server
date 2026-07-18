# oast CLI Token Authentication Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Require an organization-scoped token for `oast roast`, authenticate review creation and every SSE connection, and render safe, actionable RFC 9457 errors without changing established exit codes.

**Architecture:** Clap acquires the credential from required `--token <TOKEN>` input with `OAST_TOKEN` fallback and passes it directly to `api::Client::new(base, token)`. The API client privately owns the token, applies Reqwest `bearer_auth` to the review POST and every SSE GET/reconnect, and converts non-success responses into sanitized errors using RFC 9457 `title` and `detail`.

**Tech Stack:** Rust 2024, Clap 4 derive/env, blocking Reqwest 0.13, Serde/serde_json, anyhow, and httpmock 0.8.

## Global Constraints

- Execute this plan only in `/Users/hskrasek/Documents/Projects/Rust/oast-cli`.
- `oast roast` requires a token from `--token <TOKEN>` or `OAST_TOKEN`; keep `--token` as the exact flag spelling.
- Preserve `Client::new(base: &str, token: &str)` as the API-client constructor contract.
- Send `Authorization: Bearer <token>` on `POST /reviews` and every `GET /reviews/{id}/events`, including reconnects.
- Parse RFC 9457 `title` and `detail`; for HTTP 401, direct the user to `/app/settings/tokens` and mention both `--token` and `OAST_TOKEN`.
- Never print or interpolate the token in stdout, stderr, errors, or debug output.
- Preserve exit codes: `0` for no blocker findings, `1` for blocker findings or review failure, and `2` for argument, authentication, transport, or server errors.
- Use only dependencies already present in `Cargo.toml`; do not modify `Cargo.toml` or `Cargo.lock`.
- Do not add GitHub Actions files or paths; no Action repository is present.

---

### Task 1: Authenticate API Requests and Parse Problem Details

**Files:**

- Modify: `src/api.rs`
- Test: `src/api.rs`

**Interfaces:**

- Consumes: server base URL `&str`, required plaintext token `&str`, review inputs, and SSE cursor.
- Produces: `Client::new(base: &str, token: &str) -> Client`; unchanged success contracts `create_review(&self, spec: &str, dimension: &str, baseline: bool) -> anyhow::Result<u64>` and `stream_events(&self, review_id: u64, last_event_id: u64) -> anyhow::Result<reqwest::blocking::Response>`.

- [ ] **Step 1: Replace the `src/api.rs` test module with failing authentication and Problem Details coverage**

Keep the production code above `#[cfg(test)]` unchanged for this red step and replace the complete test module with:

```rust
#[cfg(test)]
mod tests {
    use super::*;
    use httpmock::prelude::*;

    const TOKEN: &str = "test-token";

    #[test]
    fn creates_a_review_with_bearer_auth_and_returns_its_id() {
        let server = MockServer::start();
        let mock = server.mock(|when, then| {
            when.method(POST)
                .path("/reviews")
                .header("Authorization", "Bearer test-token");
            then.status(202)
                .json_body(serde_json::json!({"data": {"id": 42, "status": "running"}}));
        });

        let id = Client::new(&server.base_url(), TOKEN)
            .create_review("openapi: 3.1.0", "domain-modeling", false)
            .unwrap();

        mock.assert();
        assert_eq!(id, 42);
    }

    #[test]
    fn reports_problem_details_on_non_202() {
        let server = MockServer::start();
        server.mock(|when, then| {
            when.method(POST).path("/reviews");
            then.status(422)
                .header("Content-Type", "application/problem+json")
                .json_body(serde_json::json!({
                    "type": "https://oast.sh/problems/invalid-spec",
                    "title": "Invalid specification",
                    "status": 422,
                    "detail": "The submitted document is not OpenAPI."
                }));
        });

        let error = Client::new(&server.base_url(), TOKEN)
            .create_review("nope", "domain-modeling", false)
            .unwrap_err();
        let message = error.to_string();

        assert!(message.contains("422"));
        assert!(message.contains("Invalid specification"));
        assert!(message.contains("The submitted document is not OpenAPI."));
    }

    #[test]
    fn guides_create_401_without_exposing_the_token() {
        let server = MockServer::start();
        server.mock(|when, then| {
            when.method(POST)
                .path("/reviews")
                .header("Authorization", "Bearer test-token");
            then.status(401)
                .header("Content-Type", "application/problem+json")
                .json_body(serde_json::json!({
                    "type": "https://oast.sh/problems/unauthenticated",
                    "title": "Unauthenticated",
                    "status": 401,
                    "detail": "A valid bearer token is required."
                }));
        });

        let error = Client::new(&server.base_url(), TOKEN)
            .create_review("openapi: 3.1.0", "domain-modeling", false)
            .unwrap_err();
        let message = error.to_string();

        assert!(message.contains("Unauthenticated"));
        assert!(message.contains("A valid bearer token is required."));
        assert!(message.contains("/app/settings/tokens"));
        assert!(message.contains("--token"));
        assert!(message.contains("OAST_TOKEN"));
        assert!(!message.contains(TOKEN));
    }

    #[test]
    fn streams_events_with_bearer_auth_and_last_event_id() {
        let server = MockServer::start();
        let mock = server.mock(|when, then| {
            when.method(GET)
                .path("/reviews/42/events")
                .header("Authorization", "Bearer test-token")
                .header("Accept", "text/event-stream")
                .header("Last-Event-ID", "7");
            then.status(200)
                .header("Content-Type", "text/event-stream")
                .body("id: 8\nevent: review.completed\ndata: {\"findings\":[]}\n\n");
        });

        let response = Client::new(&server.base_url(), TOKEN)
            .stream_events(42, 7)
            .unwrap();

        assert!(response.status().is_success());
        mock.assert();
    }

    #[test]
    fn guides_stream_401_without_exposing_the_token() {
        let server = MockServer::start();
        server.mock(|when, then| {
            when.method(GET)
                .path("/reviews/42/events")
                .header("Authorization", "Bearer test-token");
            then.status(401)
                .header("Content-Type", "application/problem+json")
                .json_body(serde_json::json!({
                    "type": "https://oast.sh/problems/unauthenticated",
                    "title": "Unauthenticated",
                    "status": 401,
                    "detail": "The token has expired or been revoked."
                }));
        });

        let error = Client::new(&server.base_url(), TOKEN)
            .stream_events(42, 0)
            .unwrap_err();
        let message = error.to_string();

        assert!(message.contains("Unauthenticated"));
        assert!(message.contains("The token has expired or been revoked."));
        assert!(message.contains("/app/settings/tokens"));
        assert!(message.contains("--token"));
        assert!(message.contains("OAST_TOKEN"));
        assert!(!message.contains(TOKEN));
    }
}
```

- [ ] **Step 2: Run the focused tests and verify the constructor contract fails**

Run:

```bash
cargo test api::tests -- --nocapture
```

Expected: compilation fails with Rust error `E0061` because the tests pass a token while the current `Client::new` accepts only `base`.

- [ ] **Step 3: Replace `src/api.rs` with the complete authenticated implementation**

```rust
use anyhow::{Context, anyhow};
use serde::Deserialize;

#[derive(Debug, Deserialize)]
pub struct Finding {
    pub severity: String,
    pub confidence: String,
    pub title: String,
    pub location: String,
}

#[derive(Debug, Deserialize)]
pub struct Completed {
    #[serde(default)]
    pub findings: Vec<Finding>,
    pub total_cost_usd: Option<f64>,
}

#[derive(Deserialize)]
struct CreatedEnvelope {
    data: Created,
}

#[derive(Deserialize)]
struct Created {
    id: u64,
}

#[derive(Deserialize)]
struct ProblemDetails {
    title: Option<String>,
    detail: Option<String>,
}

pub struct Client {
    base: String,
    token: String,
    http: reqwest::blocking::Client,
}

impl Client {
    pub fn new(base: &str, token: &str) -> Self {
        Self {
            base: base.trim_end_matches('/').to_string(),
            token: token.to_string(),
            http: reqwest::blocking::Client::builder()
                .timeout(None) // SSE streams are long-lived
                .build()
                .expect("reqwest client"),
        }
    }

    pub fn create_review(
        &self,
        spec: &str,
        dimension: &str,
        baseline: bool,
    ) -> anyhow::Result<u64> {
        let response = self
            .http
            .post(format!("{}/reviews", self.base))
            .bearer_auth(&self.token)
            .json(&serde_json::json!({
                "spec": spec,
                "dimension": dimension,
                "mode": if baseline { "baseline" } else { "council" },
            }))
            .send()
            .context("POST /reviews failed")?;

        if response.status().as_u16() != 202 {
            return Err(response_error(response, "creating the review"));
        }

        Ok(response.json::<CreatedEnvelope>()?.data.id)
    }

    pub fn stream_events(
        &self,
        review_id: u64,
        last_event_id: u64,
    ) -> anyhow::Result<reqwest::blocking::Response> {
        let response = self
            .http
            .get(format!("{}/reviews/{review_id}/events", self.base))
            .bearer_auth(&self.token)
            .header("Accept", "text/event-stream")
            .header("Last-Event-ID", last_event_id.to_string())
            .send()
            .context("GET /reviews/{id}/events failed")?;

        if !response.status().is_success() {
            return Err(response_error(response, "streaming review events"));
        }

        Ok(response)
    }
}

fn response_error(response: reqwest::blocking::Response, action: &str) -> anyhow::Error {
    let status = response.status();
    let summary = response
        .json::<ProblemDetails>()
        .ok()
        .map(problem_summary)
        .unwrap_or_else(|| status.to_string());

    if status == reqwest::StatusCode::UNAUTHORIZED {
        return anyhow!(
            "authentication required while {action} ({status}): {summary}\nCreate a token in /app/settings/tokens, then pass it with --token or set OAST_TOKEN"
        );
    }

    anyhow!("server returned {status} while {action}: {summary}")
}

fn problem_summary(problem: ProblemDetails) -> String {
    match (problem.title, problem.detail) {
        (Some(title), Some(detail)) => format!("{title}: {detail}"),
        (Some(title), None) => title,
        (None, Some(detail)) => detail,
        (None, None) => "The server returned an API error without details.".to_string(),
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use httpmock::prelude::*;

    const TOKEN: &str = "test-token";

    #[test]
    fn creates_a_review_with_bearer_auth_and_returns_its_id() {
        let server = MockServer::start();
        let mock = server.mock(|when, then| {
            when.method(POST)
                .path("/reviews")
                .header("Authorization", "Bearer test-token");
            then.status(202)
                .json_body(serde_json::json!({"data": {"id": 42, "status": "running"}}));
        });

        let id = Client::new(&server.base_url(), TOKEN)
            .create_review("openapi: 3.1.0", "domain-modeling", false)
            .unwrap();

        mock.assert();
        assert_eq!(id, 42);
    }

    #[test]
    fn reports_problem_details_on_non_202() {
        let server = MockServer::start();
        server.mock(|when, then| {
            when.method(POST).path("/reviews");
            then.status(422)
                .header("Content-Type", "application/problem+json")
                .json_body(serde_json::json!({
                    "type": "https://oast.sh/problems/invalid-spec",
                    "title": "Invalid specification",
                    "status": 422,
                    "detail": "The submitted document is not OpenAPI."
                }));
        });

        let error = Client::new(&server.base_url(), TOKEN)
            .create_review("nope", "domain-modeling", false)
            .unwrap_err();
        let message = error.to_string();

        assert!(message.contains("422"));
        assert!(message.contains("Invalid specification"));
        assert!(message.contains("The submitted document is not OpenAPI."));
    }

    #[test]
    fn guides_create_401_without_exposing_the_token() {
        let server = MockServer::start();
        server.mock(|when, then| {
            when.method(POST)
                .path("/reviews")
                .header("Authorization", "Bearer test-token");
            then.status(401)
                .header("Content-Type", "application/problem+json")
                .json_body(serde_json::json!({
                    "type": "https://oast.sh/problems/unauthenticated",
                    "title": "Unauthenticated",
                    "status": 401,
                    "detail": "A valid bearer token is required."
                }));
        });

        let error = Client::new(&server.base_url(), TOKEN)
            .create_review("openapi: 3.1.0", "domain-modeling", false)
            .unwrap_err();
        let message = error.to_string();

        assert!(message.contains("Unauthenticated"));
        assert!(message.contains("A valid bearer token is required."));
        assert!(message.contains("/app/settings/tokens"));
        assert!(message.contains("--token"));
        assert!(message.contains("OAST_TOKEN"));
        assert!(!message.contains(TOKEN));
    }

    #[test]
    fn streams_events_with_bearer_auth_and_last_event_id() {
        let server = MockServer::start();
        let mock = server.mock(|when, then| {
            when.method(GET)
                .path("/reviews/42/events")
                .header("Authorization", "Bearer test-token")
                .header("Accept", "text/event-stream")
                .header("Last-Event-ID", "7");
            then.status(200)
                .header("Content-Type", "text/event-stream")
                .body("id: 8\nevent: review.completed\ndata: {\"findings\":[]}\n\n");
        });

        let response = Client::new(&server.base_url(), TOKEN)
            .stream_events(42, 7)
            .unwrap();

        assert!(response.status().is_success());
        mock.assert();
    }

    #[test]
    fn guides_stream_401_without_exposing_the_token() {
        let server = MockServer::start();
        server.mock(|when, then| {
            when.method(GET)
                .path("/reviews/42/events")
                .header("Authorization", "Bearer test-token");
            then.status(401)
                .header("Content-Type", "application/problem+json")
                .json_body(serde_json::json!({
                    "type": "https://oast.sh/problems/unauthenticated",
                    "title": "Unauthenticated",
                    "status": 401,
                    "detail": "The token has expired or been revoked."
                }));
        });

        let error = Client::new(&server.base_url(), TOKEN)
            .stream_events(42, 0)
            .unwrap_err();
        let message = error.to_string();

        assert!(message.contains("Unauthenticated"));
        assert!(message.contains("The token has expired or been revoked."));
        assert!(message.contains("/app/settings/tokens"));
        assert!(message.contains("--token"));
        assert!(message.contains("OAST_TOKEN"));
        assert!(!message.contains(TOKEN));
    }
}
```

The private `token` field is not debug-formatted, and neither request errors nor response errors interpolate it.

- [ ] **Step 4: Run the focused API tests**

Run:

```bash
cargo test api::tests -- --nocapture
```

Expected: all five `api::tests` pass; POST and SSE mocks verify exact bearer headers, both 401 paths include actionable guidance, and neither 401 error includes `test-token`.

- [ ] **Step 5: Commit the API-client change**

```bash
git add src/api.rs
git commit -m "feat: authenticate API requests"
```

Expected: one commit containing only `src/api.rs`.

### Task 2: Require the Token in the CLI and Preserve Reconnect Behavior

**Files:**

- Modify: `src/main.rs`
- Modify: `tests/roast.rs`
- Test: `tests/roast.rs`

**Interfaces:**

- Consumes: `api::Client::new(base: &str, token: &str) -> Client` from Task 1.
- Produces: `oast roast <SPEC> --server <URL> --token <TOKEN>` with `OAST_SERVER` and `OAST_TOKEN` environment fallbacks; existing exit-code behavior remains unchanged.

- [ ] **Step 1: Replace `tests/roast.rs` with complete failing CLI authentication coverage**

```rust
use httpmock::prelude::*;

const TOKEN: &str = "test-token";

fn roast_command(
    spec: &std::path::Path,
    server: &MockServer,
    token: &str,
) -> std::process::Command {
    let mut command = std::process::Command::new(env!("CARGO_BIN_EXE_oast"));
    command.args([
        "roast",
        spec.to_str().unwrap(),
        "--server",
        &server.base_url(),
        "--token",
        token,
    ]);
    command
}

#[test]
fn roast_happy_path_exits_zero_and_prints_findings() {
    let server = MockServer::start();
    server.mock(|when, then| {
        when.method(POST)
            .path("/reviews")
            .header("Authorization", "Bearer test-token");
        then.status(202)
            .json_body(serde_json::json!({"data": {"id": 1}}));
    });
    server.mock(|when, then| {
        when.method(GET)
            .path("/reviews/1/events")
            .header("Authorization", "Bearer test-token");
        then.status(200)
            .header("Content-Type", "text/event-stream")
            .body(concat!(
                "id: 1\nevent: panel.model.done\ndata: {\"model\":\"m\",\"ms\":1000}\n\n",
                "id: 2\nevent: review.completed\ndata: {\"findings\":[{\"severity\":\"should-fix\",\"confidence\":\"majority\",\"title\":\"T\",\"location\":\"#/x\"}],\"total_cost_usd\":0.2}\n\n",
            ));
    });

    let spec = std::env::temp_dir().join("roast-spec.yaml");
    std::fs::write(&spec, "openapi: 3.1.0").unwrap();

    let output = roast_command(&spec, &server, TOKEN).output().unwrap();
    let stdout = String::from_utf8_lossy(&output.stdout);

    assert!(
        output.status.success(),
        "stderr: {}",
        String::from_utf8_lossy(&output.stderr)
    );
    assert!(stdout.contains("✓ panel  m"));
    assert!(stdout.contains("should-fix"));
    assert!(stdout.contains("$0.2"));
    assert!(!stdout.contains(TOKEN));
}

#[test]
fn roast_exits_one_on_blockers() {
    let server = MockServer::start();
    server.mock(|when, then| {
        when.method(POST)
            .path("/reviews")
            .header("Authorization", "Bearer test-token");
        then.status(202)
            .json_body(serde_json::json!({"data": {"id": 2}}));
    });
    server.mock(|when, then| {
        when.method(GET)
            .path("/reviews/2/events")
            .header("Authorization", "Bearer test-token");
        then.status(200).body("id: 1\nevent: review.completed\ndata: {\"findings\":[{\"severity\":\"blocker\",\"confidence\":\"consensus\",\"title\":\"B\",\"location\":\"#/y\"}]}\n\n");
    });

    let spec = std::env::temp_dir().join("roast-blocker.yaml");
    std::fs::write(&spec, "openapi: 3.1.0").unwrap();

    let status = roast_command(&spec, &server, TOKEN).status().unwrap();

    assert_eq!(status.code(), Some(1));
}

#[test]
fn roast_exits_two_on_stale_stream() {
    let server = MockServer::start();
    server.mock(|when, then| {
        when.method(POST)
            .path("/reviews")
            .header("Authorization", "Bearer test-token");
        then.status(202)
            .json_body(serde_json::json!({"data": {"id": 3}}));
    });
    server.mock(|when, then| {
        when.method(GET)
            .path("/reviews/3/events")
            .header("Authorization", "Bearer test-token");
        then.status(200)
            .header("Content-Type", "text/event-stream")
            .body("");
    });

    let spec = std::env::temp_dir().join("roast-stale.yaml");
    std::fs::write(&spec, "openapi: 3.1.0").unwrap();

    let status = roast_command(&spec, &server, TOKEN).status().unwrap();

    assert_eq!(
        status.code(),
        Some(2),
        "should exit with code 2 on stale streams"
    );
}

#[test]
fn roast_reconnects_with_bearer_auth_after_a_mid_stream_read_error() {
    let server = MockServer::start();
    server.mock(|when, then| {
        when.method(POST)
            .path("/reviews")
            .header("Authorization", "Bearer test-token");
        then.status(202)
            .json_body(serde_json::json!({"data": {"id": 4}}));
    });
    server.mock(|when, then| {
        when.method(GET)
            .path("/reviews/4/events")
            .header("Authorization", "Bearer test-token")
            .header("Last-Event-ID", "0");
        then.status(200)
            .header("Content-Type", "text/event-stream")
            .body(
                [
                    b"id: 1\nevent: panel.model.done\ndata: {\"model\":\"m\",\"ms\":1000}\n\n"
                        .as_slice(),
                    b"id: 2\nevent: panel.model.done\ndata: ",
                    &[0xFF, 0xFE],
                ]
                .concat(),
            );
    });
    server.mock(|when, then| {
        when.method(GET)
            .path("/reviews/4/events")
            .header("Authorization", "Bearer test-token")
            .header("Last-Event-ID", "1");
        then.status(200)
            .header("Content-Type", "text/event-stream")
            .body("id: 3\nevent: review.completed\ndata: {\"findings\":[]}\n\n");
    });

    let spec = std::env::temp_dir().join("roast-mid-stream-error.yaml");
    std::fs::write(&spec, "openapi: 3.1.0").unwrap();

    let output = roast_command(&spec, &server, TOKEN).output().unwrap();

    assert_eq!(
        output.status.code(),
        Some(0),
        "stderr: {}",
        String::from_utf8_lossy(&output.stderr)
    );
    let stdout = String::from_utf8_lossy(&output.stdout);
    assert_eq!(
        stdout.matches("✓ panel  m").count(),
        1,
        "panel.model.done should be printed once across reconnects:\n{stdout}"
    );
    assert!(!stdout.contains(TOKEN));
    assert!(!String::from_utf8_lossy(&output.stderr).contains(TOKEN));
}

#[test]
fn roast_accepts_token_from_environment() {
    let server = MockServer::start();
    let create = server.mock(|when, then| {
        when.method(POST)
            .path("/reviews")
            .header("Authorization", "Bearer environment-token");
        then.status(202)
            .json_body(serde_json::json!({"data": {"id": 5}}));
    });
    let stream = server.mock(|when, then| {
        when.method(GET)
            .path("/reviews/5/events")
            .header("Authorization", "Bearer environment-token");
        then.status(200)
            .header("Content-Type", "text/event-stream")
            .body("id: 1\nevent: review.completed\ndata: {\"findings\":[]}\n\n");
    });

    let spec = std::env::temp_dir().join("roast-environment-token.yaml");
    std::fs::write(&spec, "openapi: 3.1.0").unwrap();

    let output = std::process::Command::new(env!("CARGO_BIN_EXE_oast"))
        .args([
            "roast",
            spec.to_str().unwrap(),
            "--server",
            &server.base_url(),
        ])
        .env("OAST_TOKEN", "environment-token")
        .output()
        .unwrap();

    assert!(
        output.status.success(),
        "stderr: {}",
        String::from_utf8_lossy(&output.stderr)
    );
    assert!(!String::from_utf8_lossy(&output.stdout).contains("environment-token"));
    assert!(!String::from_utf8_lossy(&output.stderr).contains("environment-token"));
    create.assert();
    stream.assert();
}

#[test]
fn roast_requires_a_token() {
    let server = MockServer::start();
    let spec = std::env::temp_dir().join("roast-missing-token.yaml");
    std::fs::write(&spec, "openapi: 3.1.0").unwrap();

    let output = std::process::Command::new(env!("CARGO_BIN_EXE_oast"))
        .args([
            "roast",
            spec.to_str().unwrap(),
            "--server",
            &server.base_url(),
        ])
        .env_remove("OAST_TOKEN")
        .output()
        .unwrap();
    let stderr = String::from_utf8_lossy(&output.stderr);

    assert_eq!(output.status.code(), Some(2));
    assert!(stderr.contains("--token <TOKEN>"), "stderr: {stderr}");
}

#[test]
fn roast_prints_actionable_401_guidance_without_printing_the_token() {
    let server = MockServer::start();
    server.mock(|when, then| {
        when.method(POST)
            .path("/reviews")
            .header("Authorization", "Bearer super-secret-token");
        then.status(401)
            .header("Content-Type", "application/problem+json")
            .json_body(serde_json::json!({
                "type": "https://oast.sh/problems/unauthenticated",
                "title": "Unauthenticated",
                "status": 401,
                "detail": "A valid bearer token is required."
            }));
    });

    let spec = std::env::temp_dir().join("roast-unauthenticated.yaml");
    std::fs::write(&spec, "openapi: 3.1.0").unwrap();

    let output = roast_command(&spec, &server, "super-secret-token")
        .output()
        .unwrap();
    let stdout = String::from_utf8_lossy(&output.stdout);
    let stderr = String::from_utf8_lossy(&output.stderr);

    assert_eq!(output.status.code(), Some(2));
    assert!(stderr.contains("Unauthenticated"));
    assert!(stderr.contains("A valid bearer token is required."));
    assert!(stderr.contains("/app/settings/tokens"));
    assert!(stderr.contains("--token"));
    assert!(stderr.contains("OAST_TOKEN"));
    assert!(!stdout.contains("super-secret-token"));
    assert!(!stderr.contains("super-secret-token"));
}
```

This keeps all four pre-existing exit/reconnect scenarios while adding flag auth, environment auth, missing-token, 401 guidance, and output-redaction assertions. Both reconnect mocks require the bearer header.

- [ ] **Step 2: Run the integration test and verify the new flag fails**

Run:

```bash
cargo test --test roast -- --nocapture
```

Expected: FAIL because the current Clap command rejects `--token` as an unexpected argument; the environment-token and required-token tests also fail because the argument has not been defined.

- [ ] **Step 3: Replace `src/main.rs` with the complete required-token implementation**

```rust
mod api;
mod render;
mod sse;

use anyhow::Context;
use clap::{Parser, Subcommand};
use std::io::BufReader;

#[derive(Parser)]
#[command(name = "oast", about = "Convene the oast.sh Council on an API spec")]
struct Cli {
    #[command(subcommand)]
    command: Command,
}

#[derive(Subcommand)]
enum Command {
    /// Review a spec with the multi-model Council
    Roast {
        /// Path to the OpenAPI spec file
        spec: std::path::PathBuf,
        #[arg(long, default_value = "domain-modeling")]
        dimension: String,
        #[arg(long)]
        baseline: bool,
        /// Server base URL (defaults to $OAST_SERVER)
        #[arg(long, env = "OAST_SERVER")]
        server: String,
        /// Organization-scoped personal access token (defaults to $OAST_TOKEN)
        #[arg(long, env = "OAST_TOKEN")]
        token: String,
        /// Print raw findings JSON instead of the table
        #[arg(long)]
        json: bool,
    },
}

fn main() {
    let cli = Cli::parse();

    let code = match cli.command {
        Command::Roast {
            spec,
            dimension,
            baseline,
            server,
            token,
            json,
        } => match roast(&spec, &dimension, baseline, &server, &token, json) {
            Ok(code) => code,
            Err(err) => {
                eprintln!("error: {err:#}");
                2
            }
        },
    };

    std::process::exit(code);
}

fn roast(
    spec: &std::path::Path,
    dimension: &str,
    baseline: bool,
    server: &str,
    token: &str,
    json: bool,
) -> anyhow::Result<i32> {
    let spec_text = std::fs::read_to_string(spec)
        .with_context(|| format!("cannot read spec file {}", spec.display()))?;

    let client = api::Client::new(server, token);
    let review_id = client.create_review(&spec_text, dimension, baseline)?;
    println!(
        "▸ review {review_id} started ({})",
        if baseline { "baseline" } else { "council" }
    );

    let mut last_event_id: u64 = 0;
    let mut attempts = 0;
    let mut stale_streams = 0;

    loop {
        let response = match client.stream_events(review_id, last_event_id) {
            Ok(response) => {
                attempts = 0;
                response
            }
            Err(err) if attempts < 5 => {
                attempts += 1;
                eprintln!("stream dropped ({err:#}); reconnecting ({attempts}/5)");
                std::thread::sleep(std::time::Duration::from_secs(2));
                continue;
            }
            Err(err) => return Err(err),
        };

        let event_id_at_start = last_event_id;
        for event in sse::SseReader::new(BufReader::new(response)) {
            let event = match event {
                Ok(event) => event,
                Err(err) => {
                    eprintln!("stream read error ({err:#}); reconnecting");
                    break;
                }
            };
            if let Some(id) = event.id {
                last_event_id = id;
            }

            let data: serde_json::Value = serde_json::from_str(&event.data).unwrap_or_default();

            match event.event.as_str() {
                "review.completed" => {
                    let completed: api::Completed = serde_json::from_str(&event.data)?;

                    if json {
                        println!("{}", event.data);
                    } else {
                        print!("\n{}", render::findings_table(&completed.findings));
                        if let Some(cost) = completed.total_cost_usd {
                            println!("\ntotal cost: ${cost}");
                        }
                    }

                    return Ok(render::exit_code(&completed.findings));
                }
                "review.failed" => {
                    if let Some(line) = render::event_line(&event.event, &data) {
                        eprintln!("{line}");
                    }
                    return Ok(1);
                }
                _ => {
                    if let Some(line) = render::event_line(&event.event, &data) {
                        println!("{line}");
                    }
                }
            }
        }

        if last_event_id > event_id_at_start {
            stale_streams = 0;
        } else {
            stale_streams += 1;
            if stale_streams >= 5 {
                anyhow::bail!(
                    "stream ended {stale_streams} times with no progress and no terminal event"
                );
            }
        }
    }
}
```

A non-optional `String` with `env = "OAST_TOKEN"` and no default makes Clap require the flag or environment source before `roast` reads the spec or sends a request. The existing `roast` result mapping continues to produce exit code 2 for argument, authentication, transport, and server failures.

- [ ] **Step 4: Run integration and full tests**

Run:

```bash
cargo test --test roast -- --nocapture
cargo test
```

Expected: all seven `tests/roast.rs` tests pass, followed by the full test suite. The reconnect test proves both SSE connections carry bearer auth; exit-code assertions remain 0, 1, and 2; secret assertions pass.

- [ ] **Step 5: Commit the CLI contract and integration tests**

```bash
git add src/main.rs tests/roast.rs
git commit -m "feat: require token for roast"
```

Expected: one commit containing only `src/main.rs` and `tests/roast.rs`.

### Task 3: Document the Breaking Authentication Contract and Run the Final Gate

**Files:**

- Modify: `README.md`

**Interfaces:**

- Consumes: required `--server`/`OAST_SERVER` and `--token`/`OAST_TOKEN` behavior from Task 2.
- Produces: complete user-facing setup, migration, security, and exit-code documentation for authenticated servers.

- [ ] **Step 1: Replace `README.md` with this complete file**

````markdown
# oast-cli

A thin Rust client for the OAST review server, providing automated API specification reviews through the Council engine.

## Authentication

`oast roast` requires an organization-scoped personal access token. Create one in the server management UI at `/app/settings/tokens`.

Prefer environment variables so the token is not saved in shell history:

```sh
export OAST_SERVER="https://api.example.com"
export OAST_TOKEN="your-token"
oast roast ./openapi.yaml
```

The equivalent explicit flags are:

```sh
oast roast ./openapi.yaml \
  --server "https://api.example.com" \
  --token "your-token"
```

`--server` falls back to `OAST_SERVER`, and `--token` falls back to `OAST_TOKEN`. Both values are required from either source. This token requirement is a breaking change for clients upgrading to an authenticated M3A server.

The token is sent only in the HTTP `Authorization: Bearer` header for review creation and event streaming. The CLI never prints the token. If the server returns HTTP 401, create or replace the token at `/app/settings/tokens`, then pass it with `--token` or set `OAST_TOKEN`.

## Exit codes

- `0`: no BLOCKER findings
- `1`: BLOCKER findings are present or the review failed
- `2`: argument, authentication, transport, or server error

Licensed under MIT — see [LICENSE](LICENSE) for details.
````

- [ ] **Step 2: Check the documentation diff and commit it**

Run:

```bash
git diff --check
git diff -- README.md
```

Expected: `git diff --check` exits 0 with no output; the README diff contains one `sh` example fence for environment usage and one balanced `sh` example fence for explicit flags, with no stray backticks.

Commit:

```bash
git add README.md
git commit -m "docs: explain CLI bearer authentication"
```

Expected: one commit containing only `README.md`.

- [ ] **Step 3: Run the final formatting, lint, and test gate**

Run in this exact order:

```bash
cargo fmt --check
cargo clippy --all-targets --all-features -- -D warnings
cargo test
git diff --check
git status --short
git diff --cached --name-only
```

Expected:

- `cargo fmt --check` exits 0 with no formatting diff.
- Clippy exits 0 with no warnings.
- All unit and integration tests pass.
- `git diff --check`, `git status --short`, and `git diff --cached --name-only` produce no output.
- `Cargo.toml` and `Cargo.lock` remain unchanged.
- No GitHub Actions file or path has been added.
