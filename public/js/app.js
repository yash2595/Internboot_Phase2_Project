// M4 Dashboard - Railway MySQL API Integration
// Values available from the API are dynamic. Static descriptive UI text remains in dashboard.html.

document.addEventListener("DOMContentLoaded", async () => {
    try {
        const response = await fetch("api/dashboard.php", {
            method: "GET",
            headers: { "Accept": "application/json" }
        });

        if (response.status === 401) {
            handleUnauthenticated("Session expired or authentication required. Please <a href='login.php' style='color:#1652d6;text-decoration:underline;font-weight:700;'>log in as candidate</a> to access your dashboard.");
            return;
        }

        const payload = await response.json();

        const isSuccess = payload.status === "success";
        if (!response.ok || !isSuccess || !payload.data) {
            throw new Error(payload.message || "Unable to load dashboard data.");
        }

        const source = payload.data;

        fillSection("candidate", source.candidate);
        fillSection("payment", source.payment);
        fillSection("enrollment", source.enrollment);
        fillSection("batch", source.batch);
        fillSection("exam", source.exam);
        fillSection("result", source.result);
        fillSection("certificate", source.certificate);
        fillSection("placement", source.placement);

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

function handleUnauthenticated(message) {
    updateAvatar("—");
    document.querySelectorAll('[data-candidate="name"]').forEach((el) => {
        el.textContent = "Unauthenticated";
    });

    let alertBox = document.getElementById("dashboard-alert");
    if (!alertBox) {
        const content = document.querySelector(".content");
        if (content) {
            alertBox = document.createElement("div");
            alertBox.id = "dashboard-alert";
            alertBox.style.cssText = "margin-bottom: 20px; padding: 14px 18px; border: 1px solid #ef4444; background: #fef2f2; color: #991b1b; border-radius: 8px; font-size: 14px; line-height: 1.5;";
            content.insertBefore(alertBox, content.firstChild);
        }
    }
    if (alertBox) {
        alertBox.innerHTML = message || "Session expired or authentication required. Please <a href='login.php' style='color:#1652d6;text-decoration:underline;font-weight:700;'>log in as candidate</a> to access your dashboard.";
        alertBox.style.display = "block";
    }
}

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
        certificate: source.certificate?.status,
        placement: source.placement?.applicable
            ? (source.placement.statusLabel || source.placement.status || 'Eligible')
            : 'Not Applicable'
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
    if (key === "placement") return status;

    return status;
}

function journeyClass(status) {
    if (["Paid", "Enrolled", "Assigned", "Completed", "Issued", "Placed"].includes(status)) return "green";
    if (["Upcoming", "Scheduled", "In Progress", "Eligible", "Shortlisted", "Interviewing"].includes(status)) return "blue";
    if (["Not Applicable"].includes(status)) return "gray";
    return "gray";
}

function isCompleted(key, status) {
    return (
        (key === "payment" && status === "Paid") ||
        (key === "enrollment" && status === "Enrolled") ||
        (key === "batch" && status === "Assigned") ||
        (key === "exam" && status === "Completed") ||
        (key === "result" && status === "Completed") ||
        (key === "certificate" && status === "Issued") ||
        (key === "placement" && status === "Placed")
    );
}

function bindCandidateLogout() {
    document.querySelectorAll("a.logout, a[href*='logout.php']").forEach((link) => {
        if (link.dataset.logoutBound) return;
        link.dataset.logoutBound = "1";
        link.addEventListener("click", async (e) => {
            e.preventDefault();
            try {
                let token = null;
                try {
                    const csrfRes = await fetch("api/auth/csrf.php", {
                        credentials: "same-origin",
                        headers: { Accept: "application/json" }
                    });
                    const csrfData = await csrfRes.json();
                    token = csrfData.data?.token || null;
                } catch {}
                if (!token) {
                    const m7Res = await fetch("/api/admin/evaluate.php?action=csrf", {
                        credentials: "same-origin",
                        headers: { Accept: "application/json" }
                    });
                    const m7Data = await m7Res.json();
                    token = m7Data.data?.token || null;
                }
                await fetch("api/auth/logout.php", {
                    method: "POST",
                    headers: {
                        "Accept": "application/json",
                        "X-CSRF-Token": token || ""
                    }
                });
            } catch (err) {
                console.error("Logout failed:", err);
            } finally {
                window.location.href = "/login.php";
            }
        });
    });
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", bindCandidateLogout);
} else {
    bindCandidateLogout();
}

