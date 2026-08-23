export function reviewSubmission({
    fetch = globalThis.fetch.bind(globalThis),
    assign = globalThis.location.assign.bind(globalThis.location),
} = {}) {
    return {
        source: "paste",
        submitting: false,
        errors: {},
        failure: null,
        async submit(form) {
            this.submitting = true;
            this.errors = {};
            this.failure = null;

            try {
                const response = await fetch(form.action || "/app/reviews", {
                    method: "POST",
                    body: new FormData(form),
                    headers: {
                        Accept: "application/json",
                        "X-CSRF-TOKEN": form.querySelector('[name="_token"]').value,
                    },
                });
                if (response.status === 422) {
                    this.errors = (await response.json()).errors ?? {};
                    return;
                }

                const location = response.headers.get("Location");
                if (response.status !== 202 || location === null) {
                    throw new Error("Unexpected review response");
                }
                assign(location);
            } catch {
                this.failure = "The review could not be submitted. Try again.";
            } finally {
                this.submitting = false;
            }
        },
    };
}
