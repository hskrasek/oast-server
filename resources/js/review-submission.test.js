import { describe, expect, it, vi } from "vitest";
import { reviewSubmission } from "./review-submission.js";

const form = {
    action: "/app/reviews",
    querySelector: () => ({ value: "csrf" }),
};

globalThis.FormData = class {
    constructor(value) {
        this.value = value;
    }
};

describe("reviewSubmission", () => {
    it("navigates only after 202 with Location", async () => {
        const assign = vi.fn();
        const fetch = vi.fn().mockResolvedValue({
            status: 202,
            headers: new Headers({ Location: "/app/reviews/42" }),
        });
        const component = reviewSubmission({ fetch, assign });

        await component.submit(form);

        expect(fetch).toHaveBeenCalledWith("/app/reviews", {
            method: "POST",
            body: expect.any(FormData),
            headers: { Accept: "application/json", "X-CSRF-TOKEN": "csrf" },
        });
        expect(assign).toHaveBeenCalledWith("/app/reviews/42");
        expect(component.submitting).toBe(false);
    });

    it("renders 422 errors and does not navigate", async () => {
        const assign = vi.fn();
        const component = reviewSubmission({
            fetch: vi.fn().mockResolvedValue({
                status: 422,
                headers: new Headers(),
                json: async () => ({ errors: { spec: ["Add a specification."] } }),
            }),
            assign,
        });

        await component.submit(form);

        expect(component.errors.spec).toEqual(["Add a specification."]);
        expect(assign).not.toHaveBeenCalled();
    });

    it("shows a safe failure for transport and malformed success responses", async () => {
        const offline = reviewSubmission({
            fetch: vi.fn().mockRejectedValue(new Error("offline")),
            assign: vi.fn(),
        });
        await offline.submit(form);
        expect(offline.failure).toBe("The review could not be submitted. Try again.");

        const malformed = reviewSubmission({
            fetch: vi.fn().mockResolvedValue({ status: 202, headers: new Headers() }),
            assign: vi.fn(),
        });
        await malformed.submit(form);
        expect(malformed.failure).toBe("The review could not be submitted. Try again.");
    });
});
