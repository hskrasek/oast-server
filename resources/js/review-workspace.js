import { createSpecSourceMap } from "./spec-source-map.js";

const STREAM_EVENTS = [
    "review.queued",
    "panel.model.start",
    "panel.model.done",
    "panel.model.failed",
    "panel.model.late",
    "judge.start",
    "judge.done",
    "review.completed",
    "review.failed",
];
const TERMINAL = new Set(["complete", "error"]);

export function reviewWorkspace({
    eventsUrl,
    status,
    findings = [],
    spec,
    eventSourceConstructor = globalThis.EventSource,
    eventSourceClosedState = 2,
}) {
    const sourceMap = createSpecSourceMap(spec);

    return {
        status,
        connection: "loading",
        events: [],
        findings,
        lines: sourceMap.lines,
        selectedRange: null,
        sourceMessage: sourceMap.parseError
            ? "Exact highlighting is unavailable; showing the retained raw specification."
            : null,
        eventSource: null,
        get groupedFindings() {
            return {
                blocker: this.findings.filter((finding) => finding.severity === "blocker"),
                "should-fix": this.findings.filter((finding) => finding.severity === "should-fix"),
                consider: this.findings.filter((finding) => finding.severity === "consider"),
            };
        },
        init() {
            if (TERMINAL.has(this.status)) {
                this.connection = "terminal";
                return;
            }
            if (typeof eventSourceConstructor !== "function") {
                this.connection = "disconnected";
                return;
            }

            this.eventSource = new eventSourceConstructor(eventsUrl);
            this.eventSource.onopen = () => {
                this.connection = "connected";
            };
            this.eventSource.onerror = () => {
                this.connection =
                    this.eventSource.readyState === eventSourceClosedState
                        ? "disconnected"
                        : "reconnecting";
            };
            for (const name of STREAM_EVENTS) {
                this.eventSource.addEventListener(name, (event) => {
                    this.consume(name, JSON.parse(event.data));
                });
            }
        },
        consume(name, data) {
            if (name === "review.queued") this.status = "running";
            if (name === "judge.start") this.status = "judging";

            if (name === "review.completed") {
                this.status = "complete";
                this.findings = data.findings ?? [];
                this.connection = "terminal";
                this.destroy();
                return;
            }
            if (name === "review.failed") {
                this.status = "error";
                this.connection = "terminal";
                this.destroy();
                return;
            }

            this.events.push({ name, data });
        },
        selectFinding(finding) {
            this.selectedRange = sourceMap.rangeFor(finding.location);
            this.sourceMessage = this.selectedRange
                ? null
                : sourceMap.parseError
                  ? "Exact highlighting is unavailable; showing the retained raw specification."
                  : `The source for ${finding.location} could not be located; showing the retained raw specification.`;
        },
        isHighlighted(line) {
            return (
                this.selectedRange !== null &&
                line >= this.selectedRange.startLine &&
                line <= this.selectedRange.endLine
            );
        },
        destroy() {
            this.eventSource?.close();
        },
    };
}
