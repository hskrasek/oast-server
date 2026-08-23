import Alpine from "alpinejs";
import { reviewSubmission } from "./review-submission.js";
import { reviewWorkspace } from "./review-workspace.js";

window.Alpine = Alpine;
Alpine.data("reviewSubmission", reviewSubmission);
Alpine.data("reviewWorkspace", reviewWorkspace);
Alpine.start();

document.addEventListener("click", async (event) => {
    const target = event.target;
    if (!(target instanceof Element)) return;

    const copy = target.closest("[data-copy]");
    if (copy instanceof HTMLElement) {
        await navigator.clipboard.writeText(copy.dataset.copy ?? "");
        copy.textContent = "Copied";
    }

    const confirm = target.closest("[data-confirm]");
    if (
        confirm instanceof HTMLElement &&
        !window.confirm(confirm.dataset.confirm ?? "Are you sure?")
    ) {
        event.preventDefault();
    }
});
