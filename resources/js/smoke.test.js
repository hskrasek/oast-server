import { describe, expect, it } from "vitest";
import { LineCounter, parseDocument } from "yaml";

describe("M3B browser dependencies", () => {
    it("retains source positions", () => {
        const lineCounter = new LineCounter();
        const document = parseDocument("openapi: 3.1.0\n", { lineCounter });

        expect(document.get("openapi")).toBe("3.1.0");
        expect(lineCounter.linePos(0)).toEqual({ line: 1, col: 1 });
    });
});
