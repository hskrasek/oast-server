import { describe, expect, it, vi } from "vitest";
import { reviewWorkspace } from "./review-workspace.js";

class FakeEventSource {
    static CONNECTING = 0;
    static OPEN = 1;
    static CLOSED = 2;

    constructor(url) {
        this.url = url;
        this.readyState = FakeEventSource.CONNECTING;
        this.listeners = {};
        this.close = vi.fn();
    }

    addEventListener(name, listener) {
        this.listeners[name] = listener;
    }
    emit(name, data) {
        this.listeners[name]?.({ data: JSON.stringify(data) });
    }
}

function options(overrides = {}) {
    return {
        eventsUrl: "/app/reviews/1/events",
        status: "running",
        findings: [],
        spec: "paths: {}\n",
        eventSourceConstructor: FakeEventSource,
        eventSourceClosedState: FakeEventSource.CLOSED,
        ...overrides,
    };
}

describe("reviewWorkspace", () => {
    it("streams lifecycle events and becomes terminal on completion", () => {
        const workspace = reviewWorkspace(options());
        workspace.init();
        const source = workspace.eventSource;
        source.readyState = FakeEventSource.OPEN;
        source.onopen();
        source.emit("panel.model.start", { model: "openai/gpt-5.5" });
        source.emit("judge.start", { model: "judge/strong" });
        source.emit("review.completed", {
            findings: [{ severity: "blocker", location: "#/paths", title: "Model paths" }],
        });

        expect(workspace.status).toBe("complete");
        expect(workspace.connection).toBe("terminal");
        expect(workspace.events).toHaveLength(2);
        expect(workspace.groupedFindings.blocker).toHaveLength(1);
        expect(source.close).toHaveBeenCalledOnce();
    });

    it("models reconnecting and disconnected without a Node EventSource global", () => {
        const workspace = reviewWorkspace(options());
        workspace.init();
        const source = workspace.eventSource;

        source.readyState = FakeEventSource.CONNECTING;
        source.onerror();
        expect(workspace.connection).toBe("reconnecting");

        source.readyState = FakeEventSource.CLOSED;
        source.onerror();
        expect(workspace.connection).toBe("disconnected");
    });

    it("does not connect for persisted complete or error states", () => {
        const constructor = vi.fn();
        for (const status of ["complete", "error"]) {
            const workspace = reviewWorkspace(
                options({ status, eventSourceConstructor: constructor }),
            );
            workspace.init();
            expect(workspace.connection).toBe("terminal");
        }
        expect(constructor).not.toHaveBeenCalled();
    });

    it("maps queued judging and failure lifecycle states", () => {
        const workspace = reviewWorkspace(options({ status: "queued" }));
        workspace.init();
        workspace.eventSource.emit("review.queued", {});
        expect(workspace.status).toBe("running");
        workspace.eventSource.emit("judge.start", { model: "judge" });
        expect(workspace.status).toBe("judging");
        workspace.eventSource.emit("review.failed", { problem: { title: "Failed" } });
        expect(workspace.status).toBe("error");
        expect(workspace.connection).toBe("terminal");
    });

    it("selects exact ranges and supplies missing and parse fallbacks", () => {
        const workspace = reviewWorkspace(
            options({
                status: "complete",
                spec: "paths:\n  /orders: {}\n",
            }),
        );
        workspace.selectFinding({ location: "#/paths/~1orders" });
        expect(workspace.selectedRange).toEqual({ startLine: 2, endLine: 2 });
        workspace.selectFinding({ location: "#/missing" });
        expect(workspace.selectedRange).toBeNull();
        expect(workspace.sourceMessage).toContain("could not be located");

        const invalid = reviewWorkspace(options({ status: "complete", spec: "paths: [" }));
        invalid.selectFinding({ location: "#/paths" });
        expect(invalid.sourceMessage).toContain("Exact highlighting is unavailable");
    });
});
