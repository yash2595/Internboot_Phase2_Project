// M4 Dashboard - Railway MySQL API Integration
// Values available from the API are dynamic. Static descriptive UI text remains in dashboard.html.

document.addEventListener("DOMContentLoaded", async () => {
    const params = new URLSearchParams(window.location.search);
    // Temporary M4 testing candidate. Replace with the authenticated PHP session in the final M3 integration.
    const candidateId = params.get("candidate_id") || "1";

    try {
        const response = await fetch(
            `api/dashboard.php?candidate_id=${encodeURIComponent(candidateId)}`,
            {
                method: "GET",
                headers: { "Accept": "application/json" }
            }
        );

        const payload = await response.json();

        const isSuccess = payload.status === "success";
        if (!response.ok || !isSuccess || !payload.data) {
            throw new Error(payload.message || "Unable to fetch dashboard data.");
        }

        const source = payload.data;

        fillSection("candidate", source.candidate);
        fillSection("payment", source.payment);
        fillSection("enrollment", source.enrollment);
        fillSection("batch", source.batch);
        fillSection("exam", source.exam);
        fillSection("result", source.result);
        fillSection("certificate", source.certificate);

        updateAvatar(source.candidate?.name);
        updateStatusCards(source);
        updateLearningJourney(source);
    } catch (error) {
        console.error("Dashboard API Error:", error);

        const errorBox = document.querySelector("[data-api-error]");
        if (errorBox) {
            errorBox.textContent = "Unable to load dashboard data. Please try again.";
            errorBox.style.display = "block";
        }
    }
});

function fillSection(attribute, data) {
    if (!data) return;

    document.querySelectorAll(`[data-${attribute}]`).forEach((el) => {
        const key = el.dataset[attribute];

        if (data[key] !== undefined && data[key] !== null) {
            el.textContent = data[key];
        }
    });
}

function updateAvatar(name) {
    const avatar = document.querySelector('[data-candidate="initials"]');
    if (!avatar || !name || name === "—") return;

    const initials = name
        .trim()
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0].toUpperCase())
        .join("");

    avatar.textContent = initials || "--";
}

function updateStatusCards(source) {
    document.querySelectorAll("[data-status]").forEach((el) => {
        const parts = el.dataset.status.split(".");
        if (parts.length === 2 && source[parts[0]] && source[parts[0]][parts[1]] !== undefined) {
            el.textContent = source[parts[0]][parts[1]];
        }
    });
}

function updateLearningJourney(source) {
    const statuses = {
        payment: source.payment?.status,
        enrollment: source.enrollment?.status,
        batch: source.batch?.status,
        exam: source.exam?.status,
        result: source.result?.status,
        certificate: source.certificate?.status
    };

    Object.entries(statuses).forEach(([key, status]) => {
        const badge = document.querySelector(`[data-journey="${key}"]`);
        const step = document.querySelector(`[data-step="${key}"]`);
        if (!badge) return;

        badge.textContent = journeyLabel(key, status);
        badge.className = `badge ${journeyClass(status)}`;

        if (step) {
            step.classList.toggle("done", isCompleted(key, status));
        }
    });
}

function journeyLabel(key, status) {
    if (!status || status === "—") return "—";

    if (key === "payment") return status === "Paid" ? "Completed" : status;
    if (key === "enrollment") return status === "Enrolled" ? "Completed" : status;
    if (key === "batch") return status === "Assigned" ? "Completed" : status;
    if (key === "exam") return status === "Completed" ? "Completed" : status;
    if (key === "result") return status === "Completed" ? "Completed" : status;
    if (key === "certificate") return status === "Issued" ? "Completed" : status;

    return status;
}

function journeyClass(status) {
    if (["Paid", "Enrolled", "Assigned", "Completed", "Issued"].includes(status)) return "green";
    if (["Upcoming", "Scheduled", "In Progress"].includes(status)) return "blue";
    return "gray";
}

function isCompleted(key, status) {
    return (
        (key === "payment" && status === "Paid") ||
        (key === "enrollment" && status === "Enrolled") ||
        (key === "batch" && status === "Assigned") ||
        (key === "exam" && status === "Completed") ||
        (key === "result" && status === "Completed") ||
        (key === "certificate" && status === "Issued")
    );
}
