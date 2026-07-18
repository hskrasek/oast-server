import { LineCounter, isAlias, isMap, isScalar, isSeq, parseDocument } from "yaml";

function decodePointer(pointer) {
    if (pointer === "#") return [];
    if (!pointer.startsWith("#/")) return null;

    try {
        return pointer
            .slice(2)
            .split("/")
            .map((segment) =>
                decodeURIComponent(segment).replaceAll("~1", "/").replaceAll("~0", "~"),
            );
    } catch {
        return null;
    }
}

function resolveNode(node, document) {
    if (!node || !isAlias(node)) return node ?? null;

    try {
        return node.resolve(document) ?? null;
    } catch {
        return null;
    }
}

function keyText(node) {
    if (!isScalar(node)) return null;
    if (typeof node.value === "symbol") return node.value.description ?? null;

    return String(node.value);
}

function isMergePair(pair) {
    return keyText(pair.key) === "<<";
}

function mergeMaps(node, document) {
    const value = resolveNode(node, document);
    if (isMap(value)) return [value];
    if (!isSeq(value)) return [];

    return value.items.map((item) => resolveNode(item, document)).filter((item) => isMap(item));
}

function findMapChild(map, segment, document, seen) {
    for (let index = map.items.length - 1; index >= 0; index -= 1) {
        const pair = map.items[index];
        if (!isMergePair(pair) && keyText(pair.key) === segment) {
            return { node: resolveNode(pair.value, document), pair };
        }
    }

    if (seen.has(map)) return null;
    seen.add(map);

    for (let index = map.items.length - 1; index >= 0; index -= 1) {
        const mergePair = map.items[index];
        if (!isMergePair(mergePair)) continue;

        // YAML merge sequences give earlier sources precedence over later sources.
        for (const source of mergeMaps(mergePair.value, document)) {
            const selection = findMapChild(source, segment, document, seen);
            if (selection !== null) return selection;
        }
    }

    return null;
}

function childSelection(selection, segment, document) {
    const node = resolveNode(selection.node, document);
    if (isMap(node)) {
        return findMapChild(node, segment, document, new WeakSet());
    }

    if (!isSeq(node) || !/^(0|[1-9]\d*)$/.test(segment)) return null;
    const item = node.items[Number(segment)];
    if (!item) return null;

    return { node: resolveNode(item, document), pair: null };
}

function finalOffset(range) {
    if (!Array.isArray(range)) return null;

    return range[2] ?? range[1] ?? range[0] ?? null;
}

function selectionRange(selection, lineCounter) {
    const node = selection.node;
    const pair = selection.pair;
    const start = pair?.key?.range?.[0] ?? node?.range?.[0];
    const end = finalOffset(pair?.value?.range) ?? finalOffset(node?.range);
    if (!Number.isInteger(start) || !Number.isInteger(end)) return null;

    return {
        startLine: lineCounter.linePos(start).line,
        endLine: lineCounter.linePos(Math.max(start, end - 1)).line,
    };
}

export function createSpecSourceMap(source) {
    const lines = source.split("\n");
    const lineCounter = new LineCounter();
    let document = null;
    let parseError = null;

    try {
        document = parseDocument(source, {
            lineCounter,
            keepSourceTokens: true,
            merge: true,
            uniqueKeys: false,
        });
        parseError = document.errors[0]?.message ?? null;
    } catch (error) {
        parseError = error instanceof Error ? error.message : "The source could not be parsed.";
    }

    return {
        lines,
        parseError,
        rangeFor(pointer) {
            const segments = decodePointer(pointer);
            if (segments === null || parseError !== null || document === null) return null;
            if (segments.length === 0) {
                return { startLine: 1, endLine: Math.max(1, lines.length) };
            }

            let selection = { node: document.contents, pair: null };
            for (const segment of segments) {
                selection = childSelection(selection, segment, document);
                if (selection === null || selection.node === null) return null;
            }

            return selectionRange(selection, lineCounter);
        },
    };
}
