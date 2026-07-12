import { describe, expect, it } from "vitest";
import { createSpecSourceMap } from "./spec-source-map.js";

const mapped = (source, pointer) => createSpecSourceMap(source).rangeFor(pointer);

describe("createSpecSourceMap", () => {
    it("maps a selected mapping pair from key start through node end", () => {
        const source = ["paths:", "  /orders:", "    get:", "      summary: List orders"].join(
            "\n",
        );

        expect(mapped(source, "#/paths/~1orders/get")).toEqual({
            startLine: 3,
            endLine: 4,
        });
    });

    it("splits delimiters before percent-decoding each segment", () => {
        const source = ["components:", "  a/b:", "    value: retained"].join("\n");

        expect(mapped(source, "#/components/a%2Fb")).toEqual({
            startLine: 2,
            endLine: 3,
        });
    });

    it("decodes RFC 6901 escapes after percent decoding", () => {
        expect(mapped('{"a b":{"~key/value":1}}', "#/a%20b/~0key~1value")).toEqual({
            startLine: 1,
            endLine: 1,
        });
    });

    it("uses the last syntactic duplicate key", () => {
        const source = ["operation:", "  summary: first", "  summary:", "    nested: last"].join(
            "\n",
        );

        expect(mapped(source, "#/operation/summary")).toEqual({
            startLine: 3,
            endLine: 4,
        });
    });

    it("resolves aliases to source pairs", () => {
        const source = [
            "defaults: &defaults",
            "  responses:",
            "    '200':",
            "      description: shared",
            "operation: *defaults",
        ].join("\n");

        expect(mapped(source, "#/operation/responses/200")).toEqual({
            startLine: 3,
            endLine: 4,
        });
    });

    it("resolves direct map merge sources and lets explicit keys win", () => {
        const source = [
            "operation:",
            "  <<:",
            "    summary: merged",
            "    responses: {}",
            "  summary: explicit",
        ].join("\n");

        expect(mapped(source, "#/operation/responses")).toEqual({
            startLine: 4,
            endLine: 4,
        });
        expect(mapped(source, "#/operation/summary")).toEqual({
            startLine: 5,
            endLine: 5,
        });
    });

    it("uses YAML merge-sequence precedence and returns the winning source pair", () => {
        const source = [
            "first: &first",
            "  summary: first wins",
            "second: &second",
            "  summary: second loses",
            "operation:",
            "  <<: [*first, *second]",
        ].join("\n");

        expect(mapped(source, "#/operation/summary")).toEqual({
            startLine: 2,
            endLine: 2,
        });
    });

    it("covers root and returns null for missing, malformed pointers, and invalid YAML", () => {
        const valid = createSpecSourceMap("openapi: 3.1.0\ninfo:\n  title: Demo\n");
        expect(valid.rangeFor("#")).toEqual({ startLine: 1, endLine: 4 });
        expect(valid.rangeFor("#/missing")).toBeNull();
        expect(valid.rangeFor("not-a-fragment")).toBeNull();
        expect(valid.rangeFor("#/%E0%A4%A")).toBeNull();

        const invalid = createSpecSourceMap("paths: [unterminated");
        expect(invalid.parseError).toBeTruthy();
        expect(invalid.lines).toEqual(["paths: [unterminated"]);
        expect(invalid.rangeFor("#")).toBeNull();
        expect(invalid.rangeFor("#/paths")).toBeNull();
    });
});
