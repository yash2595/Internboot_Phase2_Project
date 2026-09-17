(() => {
  "use strict";

  const API = new URL("../public/api/admin/evaluate.php", window.location.href)
    .href;
  let csrfToken = null;

  async function getCsrfToken() {
    if (csrfToken) return csrfToken;
    const response = await fetch(`${API}?action=csrf`, {
      credentials: "same-origin",
      headers: { Accept: "application/json" },
    });
    const payload = await response.json();
    if (!response.ok || payload.status === "error")
      throw new Error(payload.message || "Security token could not be loaded.");
    csrfToken = payload.data?.token || null;
    if (!csrfToken) throw new Error("Security token could not be loaded.");
    return csrfToken;
  }

  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) =>
    Array.from(root.querySelectorAll(selector));

  const escapeHtml = (value) =>
    String(value ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");

  const formatDate = (value) => {
    if (!value) return "—";
    const d = new Date(String(value).replace(" ", "T"));
    if (Number.isNaN(d.getTime())) return escapeHtml(value);
    return d.toLocaleDateString("en-IN", {
      day: "2-digit",
      month: "short",
      year: "numeric",
    });
  };

  const label = (value) => {
    const map = {
      "not-started": "Not Started",
      in_progress: "In Progress",
      submitted: "Completed",
      expired: "Expired",
      pending: "Pending",
      success: "Paid",
      failed: "Failed",
      eligible: "Placement Ready",
      shortlisted: "Shortlisted",
      interviewing: "Interview",
      placed: "Placed",
      not_placed: "Not Placed",
      approved: "Approved",
      rejected: "Rejected",
    };
    return (
      map[value] ||
      String(value || "—")
        .replace(/_/g, " ")
        .replace(/\b\w/g, (c) => c.toUpperCase())
    );
  };

  const normalizeFilterValue = (value) => {
    const v = String(value ?? "").trim().toLowerCase().replace(/_/g, "-");
    const aliases = {
      paid: "success",
      enrolled: "eligible",
      "in-progress": "in_progress",
      "placement-ready": "eligible",
      interview: "interviewing",
      generated: "verified",
    };
    return aliases[v] ?? v;
  };

  const sameFilterValue = (actual, selected) => {
    const a = normalizeFilterValue(actual);
    const s = normalizeFilterValue(selected);
    return !s || a === s;
  };

  const refreshIcons = () => {
    if (typeof lucide !== "undefined" && typeof lucide.createIcons === "function") {
      lucide.createIcons();
    }
  };

  const badge = (text, type = "slate") => {
    const styles = {
      green: "bg-green-50 text-green-600",
      blue: "bg-blue-50 text-blue-600",
      amber: "bg-amber-50 text-amber-600",
      red: "bg-red-50 text-red-600",
      slate: "bg-slate-100 text-slate-600",
    };
    return `<span class="inline-flex rounded-full px-3 py-1 text-xs font-medium ${styles[type] || styles.slate}">${escapeHtml(text)}</span>`;
  };

  const typeForStatus = (value) => {
    if (
      ["success", "completed", "approved", "placed", "verified"].includes(value)
    )
      return "green";
    if (["eligible", "shortlisted", "interviewing", "pending"].includes(value))
      return "amber";
    if (["failed", "rejected", "not_placed", "expired"].includes(value))
      return "red";
    return "blue";
  };

  async function api(action, options = {}) {
    const url = `${API}?action=${encodeURIComponent(action)}`;
    const config = {
      credentials: "same-origin",
      headers: { Accept: "application/json" },
      ...options,
    };
    config.headers = { Accept: "application/json", ...(options.headers || {}) };
    if ((config.method || "GET").toUpperCase() === "POST") {
      config.headers["X-CSRF-Token"] = await getCsrfToken();
    }
    if (config.body && typeof config.body !== "string" && !(config.body instanceof FormData)) {
      config.headers["Content-Type"] = "application/json";
      config.body = JSON.stringify(config.body);
    }
    const response = await fetch(url, config);
    const text = await response.text();
    let payload;
    try {
      payload = JSON.parse(text);
    } catch {
      throw new Error(text || `Request failed (${response.status})`);
    }
    if (!response.ok || payload.status === "error") {
      throw new Error(payload.message || `Request failed (${response.status})`);
    }
    return payload.data;
  }

  function notify(message, isError = false) {
    let box = $("#m7Toast");
    if (!box) {
      box = document.createElement("div");
      box.id = "m7Toast";
      box.className =
        "fixed right-5 top-5 z-[100] max-w-sm rounded-xl px-4 py-3 text-sm font-medium shadow-lg transition";
      document.body.appendChild(box);
    }
    box.textContent = message;
    box.className = `fixed right-5 top-5 z-[100] max-w-sm rounded-xl px-4 py-3 text-sm font-medium shadow-lg transition ${isError ? "bg-red-600 text-white" : "bg-slate-900 text-white"}`;
    clearTimeout(box._timer);
    box._timer = setTimeout(() => box.remove(), 3200);
  }

  function modal(title, html) {
    let root = $("#m7Modal");
    if (root) root.remove();
    root = document.createElement("div");
    root.id = "m7Modal";
    root.className =
      "fixed inset-0 z-[90] flex items-center justify-center bg-slate-900/50 p-4";
    root.innerHTML = `
      <div class="max-h-[90vh] w-full max-w-2xl overflow-auto rounded-2xl bg-white shadow-2xl">
        <div class="flex items-center justify-between border-b border-slate-100 px-6 py-4">
          <h3 class="text-lg font-semibold text-slate-900">${escapeHtml(title)}</h3>
          <button type="button" id="m7ModalClose" class="rounded-lg p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label="Close">✕</button>
        </div>
        <div class="p-6">${html}</div>
      </div>`;
    document.body.appendChild(root);
    $("#m7ModalClose", root).onclick = () => root.remove();
    root.addEventListener("click", (e) => {
      if (e.target === root) root.remove();
    });
    return root;
  }

  async function loadAdminIdentity() {
    try {
      const data = await api("settings");
      const profile = data.profile || {};
      const name = profile.full_name || "Admin";
      const email = profile.email || "";
      const initial = name.trim().charAt(0).toUpperCase() || "A";

      $$("#adminHeaderName").forEach((el) => (el.textContent = name));
      $$("#adminAvatarInitial").forEach((el) => (el.textContent = initial));
      $$("#settingsAvatarInitial").forEach((el) => (el.textContent = initial));

      const avatar = profile.avatar_url || data.avatar_url || "";
      if (avatar) {
        $$("#adminAvatarImage, #settingsAvatarImage").forEach((img) => {
          img.src = `${avatar}${avatar.includes("?") ? "&" : "?"}v=${Date.now()}`;
          img.classList.remove("hidden");
        });
        $$("#adminAvatarInitial, #settingsAvatarInitial").forEach((el) => el.classList.add("hidden"));
      }

      $$("#adminAvatar, #changePhotoButton").forEach((el) => {
        if (el.dataset.avatarBound) return;
        el.dataset.avatarBound = "1";
        el.addEventListener("click", (e) => {
          if (el.id === "changePhotoButton") {
            e.preventDefault();
            $("#avatarUpload")?.click();
          }
        });
      });

      const upload = $("#avatarUpload");
      if (upload && !upload.dataset.bound) {
        upload.dataset.bound = "1";
        upload.addEventListener("change", async () => {
          const file = upload.files?.[0];
          if (!file) return;
          if (!/^image\/(png|jpe?g|webp)$/.test(file.type) || file.size > 2 * 1024 * 1024) {
            notify("Choose a PNG, JPG or WEBP image up to 2 MB.", true);
            upload.value = "";
            return;
          }

          const previewUrl = URL.createObjectURL(file);
          $$("#adminAvatarImage, #settingsAvatarImage").forEach((img) => {
            img.src = previewUrl;
            img.classList.remove("hidden");
          });
          $$("#adminAvatarInitial, #settingsAvatarInitial").forEach((el) => el.classList.add("hidden"));

          try {
            const form = new FormData();
            form.append("avatar", file);
            const result = await api("avatar-upload", { method: "POST", body: form });
            const saved = result?.avatar_url || "";
            if (saved) {
              $$("#adminAvatarImage, #settingsAvatarImage").forEach((img) => {
                img.src = `${saved}${saved.includes("?") ? "&" : "?"}v=${Date.now()}`;
              });
            }
            notify("Profile photo updated successfully.");
          } catch (e) {
            notify(e.message || "Profile photo upload failed.", true);
            if (avatar) {
              $$("#adminAvatarImage, #settingsAvatarImage").forEach((img) => (img.src = avatar));
            }
          } finally {
            URL.revokeObjectURL(previewUrl);
            upload.value = "";
          }
        });
      }
      void email;
    } catch (e) {
      console.warn("Admin identity could not be loaded:", e.message);
    }
  }

  function initResponsiveShell() {
    if (!document.getElementById("m7ResponsiveStyles")) {
      const style = document.createElement("style");
      style.id = "m7ResponsiveStyles";
      style.textContent = `
        html, body { max-width: 100%; overflow-x: hidden; }
        main { min-width: 0 !important; max-width: 100vw; overflow-x: hidden; }
        main > section { min-width: 0; }
        main .overflow-x-auto { max-width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
        main table { max-width: 100%; }
        main table th, main table td { white-space: nowrap; }
        #m7MobileMenuButton { display:none; }
        #m7MobileOverlay { display:none; }
        @media (max-width: 1023px) {
          #m7MobileMenuButton { display:flex; }
          aside { transform: translateX(-100%); transition: transform .2s ease; }
          aside.m7-open { transform: translateX(0); }
          #m7MobileOverlay.m7-visible { display:block; }
          main { margin-left: 0 !important; width: 100% !important; }
          main > header { height: auto !important; min-height: 80px; padding: 16px 20px !important; gap: 12px; }
          main > header > div:first-child { min-width: 0; }
          main > header h1 { font-size: 1.35rem !important; line-height: 1.3; }
          main > header p { font-size: .8rem !important; }
          main > section { padding: 20px !important; }
          main .grid { min-width: 0; }
          main .rounded-2xl { max-width: 100%; }
        }
        @media (max-width: 639px) {
          main > header { padding: 14px 16px !important; }
          main > section { padding: 16px !important; }
          #m7MobileMenuButton { width: 40px; height: 40px; flex: 0 0 40px; }
          #adminAvatar { flex: 0 0 auto; }
          main > header > div:last-child { min-width: 0; }
          #adminHeaderName { max-width: 110px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
          main .overflow-x-auto table { min-width: 760px; }
        }
      `;
      document.head.appendChild(style);
    }

    const sidebar = $("#sidebar") || document.querySelector("aside");
    if (!sidebar || document.getElementById("m7MobileMenuButton")) return;

    const button = document.createElement("button");
    button.id = "m7MobileMenuButton";
    button.type = "button";
    button.className = "items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-700 shadow-sm";
    button.setAttribute("aria-label", "Open navigation");
    button.innerHTML = '<i data-lucide="menu" class="h-5 w-5"></i>';

    const overlay = document.createElement("button");
    overlay.id = "m7MobileOverlay";
    overlay.type = "button";
    overlay.className = "fixed inset-0 z-30 hidden bg-slate-900/40";
    overlay.setAttribute("aria-label", "Close navigation");
    document.body.appendChild(overlay);

    const header = document.querySelector("main > header");
    if (header) header.insertBefore(button, header.firstElementChild);

    const close = () => {
      sidebar.classList.remove("m7-open");
      overlay.classList.remove("m7-visible");
    };
    button.addEventListener("click", () => {
      sidebar.classList.toggle("m7-open");
      overlay.classList.toggle("m7-visible");
    });
    overlay.addEventListener("click", close);
    sidebar.querySelectorAll("a").forEach((a) => a.addEventListener("click", close));
    refreshIcons();
  }

  async function logoutAdmin() {
    try {
      await api("logout", { method: "POST", body: {} });
      notify("Logged out successfully.");
    } catch (e) {
      notify(e.message, true);
    }
  }

  function initCommon() {
    initResponsiveShell();
    const currentPage = window.location.pathname.split("/").pop();
    $$("aside nav a").forEach((item) => {
      const link = item.getAttribute("href");
      item.classList.remove("bg-intern-blue", "font-medium", "text-white");
      item.classList.add("text-slate-600");
      if (link === currentPage) {
        item.classList.remove("text-slate-600");
        item.classList.add("bg-intern-blue", "font-medium", "text-white");
      }
    });

    const currentDate = $("#currentDate");
    if (currentDate)
      currentDate.textContent = new Date().toLocaleDateString("en-IN", {
        day: "numeric",
        month: "long",
        year: "numeric",
      });

    const menuButton = $("#menuButton");
    const sidebar = $("#sidebar");
    const overlay = $("#sidebarOverlay");
    if (menuButton && sidebar)
      menuButton.addEventListener("click", () => {
        sidebar.classList.toggle("-translate-x-full");
        overlay?.classList.toggle("hidden");
      });
    overlay?.addEventListener("click", () => {
      sidebar.classList.add("-translate-x-full");
      overlay.classList.add("hidden");
    });

    const userButton = $("#userButton"),
      userDropdown = $("#userDropdown");
    if (userButton && userDropdown) {
      userButton.addEventListener("click", (e) => {
        e.stopPropagation();
        userDropdown.classList.toggle("hidden");
      });
      document.addEventListener("click", () =>
        userDropdown.classList.add("hidden"),
      );
    }

    const notificationButton = $("#notificationButton"),
      notificationDropdown = $("#notificationDropdown");
    if (notificationButton && notificationDropdown) {
      notificationButton.addEventListener("click", (e) => {
        e.stopPropagation();
        notificationDropdown.classList.toggle("hidden");
      });
      document.addEventListener("click", () =>
        notificationDropdown.classList.add("hidden"),
      );
    }

    $$("a[href='#']").forEach((link) => {
      if (
        link.textContent.trim().toLowerCase() === "logout" &&
        !link.dataset.logoutBound
      ) {
        link.dataset.logoutBound = "1";
        link.addEventListener("click", (e) => {
          e.preventDefault();
          void logoutAdmin();
        });
      }
    });
    void loadAdminIdentity();
    if (typeof lucide !== "undefined") lucide.createIcons();
  }

  async function loadDashboard() {
    const data = await api("dashboard");
    const set = (id, value) => {
      const el = $(`#${id}`);
      if (el) el.textContent = value ?? 0;
    };

    set("totalRegistrations", data.total_registrations);
    set("paidCandidates", data.paid_candidates);
    set("eligibleCandidates", data.eligible_candidates);
    set("upcomingBatches", data.upcoming_batches);
    set("availableSlots", data.available_slots);
    set("completedAssessments", data.completed_assessments);
    set("certificates", data.certificates);
    set("completedAssessmentCount", data.completed_assessments);
    set("pendingAssessmentCount", data.pending_attempt_count || 0);

    const totalAssessments =
      (data.completed_assessments || 0) + (data.pending_attempt_count || 0);
    const completedPct = totalAssessments
      ? Math.round((data.completed_assessments / totalAssessments) * 100)
      : 0;
    const completedBar = $("#completedAssessmentBar");
    const pendingBar = $("#pendingAssessmentBar");
    if (completedBar) completedBar.style.width = `${completedPct}%`;
    if (pendingBar) pendingBar.style.width = `${100 - completedPct}%`;

    for (let i = 1; i <= 5; i++) {
      const count = Number(
        data.level_counts?.[String(i)] || data.level_counts?.[i] || 0,
      );
      set(`level${i}Count`, count);
      const bar = $(`#level${i}Bar`);
      if (bar) {
        const total = Object.values(data.level_counts || {}).reduce(
          (a, b) => a + Number(b),
          0,
        );
        bar.style.width = `${total ? Math.round((count / total) * 100) : 0}%`;
      }
    }

    const recent = $("#recentCandidates");
    if (recent) {
      recent.innerHTML = data.recent_candidates?.length
        ? data.recent_candidates
            .map(
              (c) => `
        <tr class="hover:bg-slate-50">
          <td class="px-6 py-4 font-medium">${escapeHtml(c.full_name)}</td>
          <td class="px-6 py-4 text-slate-500">${escapeHtml(c.email)}</td>
          <td class="px-6 py-4">${badge(label(c.payment_status), typeForStatus(c.payment_status === "success" ? "success" : c.payment_status))}</td>
          <td class="px-6 py-4">${badge(c.enrollment_status === "eligible" ? "Enrolled" : "Pending", c.enrollment_status === "eligible" ? "green" : "amber")}</td>
          <td class="px-6 py-4">${badge(label(c.assessment_status), typeForStatus(c.assessment_status === "completed" ? "completed" : c.assessment_status))}</td>
        </tr>`,
            )
            .join("")
        : `<tr><td class="px-6 py-8 text-center text-sm text-slate-500" colspan="5">No candidate data available</td></tr>`;
    }

    const batches = $("#upcomingBatchesTable");
    if (batches) {
      batches.innerHTML = data.upcoming_batch_rows?.length
        ? data.upcoming_batch_rows
            .map(
              (b) => `
        <tr class="hover:bg-slate-50">
          <td class="px-6 py-4 font-medium">${escapeHtml(b.batch_number)}</td>
          <td class="px-6 py-4 text-slate-500">${formatDate(b.exam_date)}</td>
          <td class="px-6 py-4 text-slate-500">${b.exam_date ? new Date(`${b.exam_date}T00:00:00`).toLocaleDateString("en-IN", { weekday: "long" }) : "—"}</td>
          <td class="px-6 py-4">${Number(b.candidate_count || 0)}</td>
          <td class="px-6 py-4">${Number(b.available_slots || 0)}</td>
          <td class="px-6 py-4">${badge(label(b.schedule_status), typeForStatus(b.schedule_status))}</td>
        </tr>`,
            )
            .join("")
        : `<tr><td class="px-6 py-8 text-center text-sm text-slate-500" colspan="6">No upcoming batches available</td></tr>`;
    }
  }

  function bindAddCandidateButton() {
    const buttons = $$("button").filter((b) =>
      b.textContent.trim().toLowerCase().includes("add candidate"),
    );
    buttons.forEach((button) => {
      if (button.dataset.bound) return;
      button.dataset.bound = "1";
      button.addEventListener("click", () => {
        const root = modal(
          "Add Candidate",
          `<form id="addCandidateForm" class="space-y-4">
            <label class="block text-sm font-medium text-slate-700">Full Name
              <input id="newCandidateName" required maxlength="150" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2" placeholder="Candidate name">
            </label>
            <label class="block text-sm font-medium text-slate-700">Email
              <input id="newCandidateEmail" required type="email" maxlength="255" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2" placeholder="candidate@example.com">
            </label>
            <label class="block text-sm font-medium text-slate-700">Phone
              <input id="newCandidatePhone" required maxlength="20" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2" placeholder="Phone number">
            </label>
            <button class="w-full rounded-lg bg-intern-blue px-4 py-2.5 text-sm font-medium text-white">Create Candidate</button>
          </form>`,
        );
        $("#addCandidateForm", root).onsubmit = async (e) => {
          e.preventDefault();
          try {
            const data = await api("candidate-create", {
              method: "POST",
              body: {
                full_name: $("#newCandidateName", root).value.trim(),
                email: $("#newCandidateEmail", root).value.trim(),
                phone: $("#newCandidatePhone", root).value.trim(),
              },
            });
            root.remove();
            notify(`Candidate created. Temporary password: ${data.temporary_password}`);
            await loadCandidates();
          } catch (err) {
            notify(err.message, true);
          }
        };
      });
    });
  }

  async function loadCandidates() {
    const data = await api("candidates");
    const tbody = $("#candidatesTableBody");
    if (!tbody) return;
    tbody.innerHTML = data.candidates.length
      ? data.candidates
          .map(
            (c) => `
      <tr class="hover:bg-slate-50" data-candidate="true" data-payment="${escapeHtml(c.payment_status)}" data-enrollment="${escapeHtml(c.enrollment_status)}" data-assessment="${escapeHtml(c.assessment_status)}">
        <td class="px-6 py-4 font-medium">${escapeHtml(c.full_name)}</td>
        <td class="px-6 py-4 text-slate-500">${escapeHtml(c.email)}</td>
        <td class="px-6 py-4">${badge(label(c.payment_status), typeForStatus(c.payment_status === "success" ? "success" : c.payment_status))}</td>
        <td class="px-6 py-4">${badge(c.enrollment_status === "eligible" ? "Enrolled" : "Pending", c.enrollment_status === "eligible" ? "green" : "amber")}</td>
        <td class="px-6 py-4">${badge(label(c.assessment_status), typeForStatus(c.assessment_status === "completed" ? "completed" : c.assessment_status))}</td>
        <td class="px-6 py-4">${c.level_assigned ? `Level ${escapeHtml(c.level_assigned)}` : "—"}</td>
        <td class="px-6 py-4"><button class="font-medium text-intern-blue hover:underline view-candidate-btn" type="button" data-id="${c.id}">View</button></td>
      </tr>`,
          )
          .join("")
      : `<tr><td class="px-6 py-8 text-center text-sm text-slate-500" colspan="7">No candidates found</td></tr>`;

    $$(".view-candidate-btn").forEach((btn) =>
      btn.addEventListener("click", async () => {
        try {
          const response = await fetch(
            `${API}?action=candidate&id=${encodeURIComponent(btn.dataset.id)}`,
          );
          const payload = await response.json();
          if (payload.status === "error") throw new Error(payload.message);
          const c = payload.data;
          modal(
            "Candidate Details",
            `
          <div class="grid gap-4 sm:grid-cols-2 text-sm">
            <div><p class="text-slate-400">Name</p><p class="font-medium text-slate-900">${escapeHtml(c.full_name)}</p></div>
            <div><p class="text-slate-400">Email</p><p class="font-medium text-slate-900">${escapeHtml(c.email)}</p></div>
            <div><p class="text-slate-400">Phone</p><p class="font-medium text-slate-900">${escapeHtml(c.phone)}</p></div>
            <div><p class="text-slate-400">Payment</p><p>${badge(label(c.payment_status), typeForStatus(c.payment_status === "success" ? "success" : c.payment_status))}</p></div>
            <div><p class="text-slate-400">Enrollment</p><p>${badge(c.enrollment_status === "eligible" ? "Enrolled" : "Pending", c.enrollment_status === "eligible" ? "green" : "amber")}</p></div>
            <div><p class="text-slate-400">Assessment</p><p class="font-medium text-slate-900">${escapeHtml(c.assessment_title || "—")}</p></div>
          </div>
          <div class="mt-5 rounded-xl bg-slate-50 p-4 text-sm"><p class="font-medium text-slate-700">Profile</p><pre class="mt-2 whitespace-pre-wrap text-xs text-slate-500">${escapeHtml(c.profile_details || "No profile details available.")}</pre></div>`,
          );
        } catch (e) {
          notify(e.message, true);
        }
      }),
    );
    wireCandidateFilters();
  }

  function wireCandidateFilters() {
    const search = $("#candidateSearch"),
      payment = $("#paymentFilter"),
      enrollment = $("#enrollmentFilter"),
      assessment = $("#assessmentFilter"),
      count = $("#candidateCount");
    const run = () => {
      const q = (search?.value || "").toLowerCase().trim();
      const p = (payment?.value || "").toLowerCase();
      const en = (enrollment?.value || "").toLowerCase();
      const a = (assessment?.value || "").toLowerCase();
      let visible = 0;
      $$("#candidatesTableBody tr[data-candidate]").forEach((row) => {
        const ok =
          row.textContent.toLowerCase().includes(q) &&
          sameFilterValue(row.dataset.payment, p) &&
          sameFilterValue(row.dataset.enrollment, en) &&
          sameFilterValue(row.dataset.assessment, a);
        row.style.display = ok ? "" : "none";
        if (ok) visible++;
      });
      if (count)
        count.textContent = `${visible} Candidate${visible !== 1 ? "s" : ""}`;
    };
    [search, payment, enrollment, assessment].forEach((el) =>
      el?.addEventListener("input", run),
    );
    [payment, enrollment, assessment].forEach((el) =>
      el?.addEventListener("change", run),
    );
    run();
  }

  async function loadResults() {
    const [resultData, pendingData] = await Promise.all([
      api("results"),
      api("pending-attempts"),
    ]);
    const results = resultData.results || [];
    const pending = pendingData.attempts || [];
    const tbody = $("#resultsTableBody");
    const completed = $("#completedResults"),
      pendingEl = $("#pendingResults"),
      total = $("#totalResults"),
      average = $("#averageScore");
    if (total) total.textContent = results.length + pending.length;
    if (completed) completed.textContent = results.length;
    if (pendingEl) pendingEl.textContent = pending.length;
    if (average)
      average.textContent = results.length
        ? `${(results.reduce((s, r) => s + Number(r.percentage || 0), 0) / results.length).toFixed(1)}%`
        : "0%";

    if (!tbody) return;
    const rows = results.map(
      (r) => `
      <tr data-result="true" data-level="level ${r.level_assigned}" data-status="completed">
        <td class="px-6 py-4"><div><p class="font-medium text-slate-900">${escapeHtml(r.full_name)}</p><p class="text-xs text-slate-500">${escapeHtml(r.email)}</p></div></td>
        <td class="px-6 py-4"><span class="result-score font-semibold text-slate-900">${escapeHtml(r.percentage)}%</span></td>
        <td class="px-6 py-4">${badge(`Level ${r.level_assigned}`, "blue")}</td>
        <td class="px-6 py-4">${badge("Completed", "green")}</td>
        <td class="px-6 py-4 text-slate-500">${formatDate(r.created_at)}</td>
        <td class="px-6 py-4"><button class="view-result-btn inline-flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-xs font-medium text-slate-600 transition hover:border-intern-blue hover:text-intern-blue" type="button" data-attempt="${r.attempt_id}">View</button></td>
      </tr>`,
    );

    const pendingRows = pending.map(
      (a) => `
      <tr data-result="true" data-level="" data-status="pending">
        <td class="px-6 py-4"><div><p class="font-medium text-slate-900">${escapeHtml(a.full_name)}</p><p class="text-xs text-slate-500">${escapeHtml(a.email)}</p></div></td>
        <td class="px-6 py-4 font-semibold text-slate-500">Pending</td>
        <td class="px-6 py-4">—</td>
        <td class="px-6 py-4">${badge(label(a.status), "amber")}</td>
        <td class="px-6 py-4 text-slate-500">${formatDate(a.created_at)}</td>
        <td class="px-6 py-4"><button class="evaluate-attempt-btn rounded-lg bg-intern-blue px-3 py-2 text-xs font-medium text-white" type="button" data-attempt="${a.attempt_id}">Evaluate</button></td>
      </tr>`,
    );

    tbody.innerHTML =
      [...rows, ...pendingRows].join("") ||
      `<tr id="emptyResults"><td class="px-6 py-12 text-center text-sm text-slate-500" colspan="6">No results found</td></tr>`;
    refreshIcons();
    $$(".view-result-btn").forEach((btn) =>
      btn.addEventListener("click", () => showAttempt(btn.dataset.attempt)),
    );
    $$(".evaluate-attempt-btn").forEach((btn) =>
      btn.addEventListener("click", () => evaluateAttempt(btn.dataset.attempt)),
    );
    wireResultFilters();
  }

  async function showAttempt(attemptId) {
    try {
      const response = await fetch(
        `${API}?action=attempt&id=${encodeURIComponent(attemptId)}`,
      );
      const payload = await response.json();
      if (payload.status === "error") throw new Error(payload.message);
      const a = payload.data;
      modal(
        "Assessment Attempt",
        `
        <div class="grid gap-4 sm:grid-cols-2 text-sm">
          <div><p class="text-slate-400">Candidate</p><p class="font-medium">${escapeHtml(a.full_name)}</p></div>
          <div><p class="text-slate-400">Email</p><p class="font-medium">${escapeHtml(a.email)}</p></div>
          <div><p class="text-slate-400">Assessment</p><p class="font-medium">${escapeHtml(a.assessment_title)}</p></div>
          <div><p class="text-slate-400">Status</p>${badge(label(a.status), typeForStatus(a.status))}</div>
        </div>
        <div class="mt-5 space-y-3">
          ${(a.answers || []).map((x, i) => `<div class="rounded-xl border border-slate-100 p-4"><p class="text-sm font-medium text-slate-800">${i + 1}. ${escapeHtml(x.question_text)}</p><p class="mt-2 text-xs text-slate-500">Selected: ${escapeHtml(x.selected_option_text || "Not answered")}</p></div>`).join("") || '<p class="text-sm text-slate-500">No answers recorded.</p>'}
        </div>`,
      );
    } catch (e) {
      notify(e.message, true);
    }
  }

  async function evaluateAttempt(id) {
    if (!confirm("Evaluate this attempt now?")) return;
    try {
      const data = await api("evaluate", {
        method: "POST",
        body: { attempt_id: Number(id), generate_certificate: false },
      });
      notify(
        `Evaluated: ${data.score}/${data.total_questions} (${data.percentage}%), Level ${data.level}`,
      );
      await loadResults();
    } catch (e) {
      notify(e.message, true);
    }
  }

  function wireResultFilters() {
    const search = $("#resultSearch"),
      level = $("#resultLevelFilter"),
      status = $("#resultStatusFilter");
    const run = () => {
      const q = (search?.value || "").toLowerCase(),
        l = (level?.value || "").toLowerCase(),
        s = (status?.value || "").toLowerCase();
      $$("#resultsTableBody tr[data-result]").forEach((row) => {
        const ok =
          row.textContent.toLowerCase().includes(q) &&
          sameFilterValue(row.dataset.level, l) &&
          sameFilterValue(row.dataset.status, s);
        row.style.display = ok ? "" : "none";
      });
    };
    [search, level, status].forEach((el) => el?.addEventListener("input", run));
    [level, status].forEach((el) => el?.addEventListener("change", run));
  }

  async function loadCertificates() {
    const data = await api("certificates");
    const rows = data.certificates || [];
    const set = (id, v) => {
      const el = $("#" + id);
      if (el) el.textContent = v;
    };
    set("totalCertificates", rows.length);
    set("generatedCertificates", rows.length);
    set("verifiedCertificates", rows.length);
    set("pendingCertificates", 0);
    const tbody = $("#certificatesTableBody");
    if (!tbody) return;
    tbody.innerHTML = rows.length
      ? rows
          .map(
            (c) => `
      <tr class="border-b border-slate-100 last:border-0" data-certificate="true" data-level="level ${c.level}" data-status="verified">
        <td class="px-6 py-5 align-middle"><div><p class="font-medium text-slate-900">${escapeHtml(c.full_name)}</p><p class="mt-1 text-xs text-slate-500">${escapeHtml(c.email)}</p></div></td>
        <td class="px-6 py-5 align-middle">${badge(`Level ${c.level}`, "blue")}</td>
        <td class="px-6 py-5 align-middle font-medium text-slate-700">${escapeHtml(c.certificate_number)}</td>
        <td class="px-6 py-5 align-middle text-slate-500">${formatDate(c.issue_date)}</td>
        <td class="px-6 py-5 align-middle">${badge("Verified", "green")}</td>
        <td class="px-4 py-5 align-middle"><div class="flex items-center gap-1.5 whitespace-nowrap">
          <button class="view-certificate-btn inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 text-slate-600 hover:border-intern-blue hover:text-intern-blue" title="View certificate" type="button" data-id="${c.result_id}"><i data-lucide="eye" class="h-4 w-4"></i></button>
          <button class="download-certificate-btn inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 text-slate-600 hover:border-intern-blue hover:text-intern-blue" title="Download certificate" type="button" data-id="${c.result_id}"><i data-lucide="download" class="h-4 w-4"></i></button>
          <button class="verify-certificate-btn inline-flex h-9 w-9 items-center justify-center rounded-lg bg-green-50 text-green-600 hover:bg-green-100" type="button" data-number="${escapeHtml(c.certificate_number)}"><i data-lucide="badge-check" class="h-4 w-4"></i></button>
        </div></td>
      </tr>`,
          )
          .join("")
      : `<tr><td class="px-6 py-12 text-center text-sm text-slate-500" colspan="6">No certificates generated yet.</td></tr>`;
    refreshIcons();

    $$(".download-certificate-btn").forEach((btn) =>
      btn.addEventListener("click", () =>
        window.open(
          `../public/api/admin/certificate_pdf.php?result_id=${encodeURIComponent(btn.dataset.id)}`,
          "_blank",
        ),
      ),
    );
    $$(".view-certificate-btn").forEach((btn) =>
      btn.addEventListener("click", () =>
        window.open(
          `../public/api/admin/certificate_pdf.php?result_id=${encodeURIComponent(btn.dataset.id)}`,
          "_blank",
        ),
      ),
    );
    $$(".verify-certificate-btn").forEach((btn) =>
      btn.addEventListener("click", async () => {
        try {
          const response = await fetch(
            `${API}?action=certificate-verify&certificate_number=${encodeURIComponent(btn.dataset.number)}`,
            {
              credentials: "same-origin",
              headers: { Accept: "application/json" },
            },
          );
          const payload = await response.json();
          if (!response.ok || payload.status === "error")
            throw new Error(payload.message || "Verification failed.");
          const data = payload.data;
          if (data.verified)
            notify(
              `Verified: ${data.certificate.full_name} · Level ${data.certificate.level}`,
            );
          else notify("Certificate not found.", true);
        } catch (e) {
          notify(e.message, true);
        }
      }),
    );
    wireCertificateFilters();
  }

  function wireCertificateFilters() {
    const search = $("#certificateSearch"),
      level = $("#certificateLevelFilter"),
      status = $("#certificateStatusFilter");
    const run = () => {
      const q = (search?.value || "").toLowerCase(),
        l = (level?.value || "").toLowerCase(),
        s = (status?.value || "").toLowerCase();
      $$("#certificatesTableBody tr[data-certificate]").forEach((row) => {
        row.style.display =
          row.textContent.toLowerCase().includes(q) &&
          sameFilterValue(row.dataset.level, l) &&
          sameFilterValue(row.dataset.status, s)
            ? ""
            : "none";
      });
    };
    [search, level, status].forEach((el) => el?.addEventListener("input", run));
    [level, status].forEach((el) => el?.addEventListener("change", run));
  }

  async function loadQuestions() {
    const data = await api("questions");
    const rows = data.questions || [];
    const set = (id, v) => {
      const el = $("#" + id);
      if (el) el.textContent = v;
    };
    set("totalQuestions", rows.length);
    set(
      "pendingQuestions",
      rows.filter((q) => q.approval_status === "pending").length,
    );
    set(
      "approvedQuestions",
      rows.filter((q) => q.approval_status === "approved").length,
    );
    set(
      "rejectedQuestions",
      rows.filter((q) => q.approval_status === "rejected").length,
    );
    const tbody = $("#questionsTableBody");
    if (!tbody) return;
    tbody.innerHTML = rows.length
      ? rows
          .map((q) => {
            const m = String(q.question_bank || "").match(/level\s*([1-5])/i);
            const level = m ? m[1] : "—";
            return `<tr data-question="true" data-level="${m ? `level ${m[1]}` : ""}" data-status="${escapeHtml(q.approval_status)}">
        <td class="px-6 py-4"><p class="max-w-xl font-medium text-slate-800">${escapeHtml(q.question_text)}</p><p class="mt-1 text-xs text-slate-400">${escapeHtml(q.question_bank)} · ${Number(q.option_count || 0)} options</p></td>
        <td class="px-6 py-4">${level === "—" ? "—" : badge(`Level ${level}`, "blue")}</td>
        <td class="px-6 py-4 text-slate-500">${escapeHtml(q.type || "MCQ")}</td>
        <td class="px-6 py-4">${badge(label(q.approval_status), typeForStatus(q.approval_status))}</td>
        <td class="px-6 py-4"><div class="flex gap-2">
          ${q.approval_status !== "approved" ? `<button class="approve-question rounded-lg bg-green-50 px-3 py-2 text-xs font-medium text-green-600" data-id="${q.id}">Approve</button>` : ""}
          ${q.approval_status !== "rejected" ? `<button class="reject-question rounded-lg bg-red-50 px-3 py-2 text-xs font-medium text-red-600" data-id="${q.id}">Reject</button>` : ""}
        </div></td>
      </tr>`;
          })
          .join("")
      : `<tr id="emptyQuestions"><td class="px-6 py-12 text-center text-sm text-slate-500" colspan="5">No questions available</td></tr>`;
    refreshIcons();

    $$(".approve-question").forEach(
      (b) => (b.onclick = () => setQuestionStatus(b.dataset.id, "approved")),
    );
    $$(".reject-question").forEach(
      (b) => (b.onclick = () => setQuestionStatus(b.dataset.id, "rejected")),
    );
    wireQuestionFilters();
  }

  async function setQuestionStatus(id, status) {
    try {
      await api("question-status", {
        method: "POST",
        body: { question_id: Number(id), status },
      });
      notify(`Question ${status}.`);
      await loadQuestions();
    } catch (e) {
      notify(e.message, true);
    }
  }

  function wireQuestionFilters() {
    const search = $("#questionSearch"),
      level = $("#questionLevelFilter"),
      status = $("#questionStatusFilter");
    const run = () => {
      const q = (search?.value || "").toLowerCase(),
        l = (level?.value || "").toLowerCase(),
        s = (status?.value || "").toLowerCase();
      $$("#questionsTableBody tr[data-question]").forEach(
        (row) =>
          (row.style.display =
            row.textContent.toLowerCase().includes(q) &&
            sameFilterValue(row.dataset.level, l) &&
            sameFilterValue(row.dataset.status, s)
              ? ""
              : "none"),
      );
    };
    [search, level, status].forEach((el) => el?.addEventListener("input", run));
    [level, status].forEach((el) => el?.addEventListener("change", run));
  }

  async function loadBatches() {
    const data = await api("batches");
    const eligible = data.eligible_candidates || [],
      slots = data.slots || [],
      batches = data.batches || [];
    const set = (id, v) => {
      const el = $("#" + id);
      if (el) el.textContent = v;
    };
    set("eligibleCandidateCount", eligible.filter((c) => !c.batch_id).length);
    set(
      "availableSlotCount",
      slots.reduce((s, x) => s + Number(x.seats_remaining || 0), 0),
    );
    const slotBody = $("#slotsTableBody");
    if (slotBody)
      slotBody.innerHTML = slots.length
        ? slots
            .map(
              (s) => `
      <tr class="hover:bg-slate-50">
        <td class="px-6 py-4 font-medium">${escapeHtml(s.batch_number)}</td>
        <td class="px-6 py-4 text-slate-500">${escapeHtml(s.start_time?.slice(0, 5) || "")} - ${escapeHtml(s.end_time?.slice(0, 5) || "")}</td>
        <td class="px-6 py-4">${s.capacity}</td><td class="px-6 py-4">${s.allocated}</td><td class="px-6 py-4">${s.seats_remaining}</td>
        <td class="px-6 py-4">${badge(s.seats_remaining > 0 ? "Available" : "Full", s.seats_remaining > 0 ? "green" : "red")}</td>
      </tr>`,
            )
            .join("")
        : `<tr><td class="px-6 py-10 text-center text-sm text-slate-500" colspan="6">No slots created yet.</td></tr>`;
    refreshIcons();

    const allocBody = $("#allocationTableBody");
    const unallocated = eligible.filter((c) => !c.batch_id);
    if (allocBody)
      allocBody.innerHTML = unallocated.length
        ? unallocated
            .map((c) => {
              const options = batches.filter(
                (b) => Number(b.assessment_id) === Number(c.assessment_id),
              );
              const matchingSlots = slots.filter(
                (s) =>
                  options.some((b) => Number(b.id) === Number(s.batch_id)) &&
                  Number(s.seats_remaining) > 0,
              );
              return `<tr class="hover:bg-slate-50">
        <td class="px-6 py-4 font-medium">${escapeHtml(c.full_name)}</td>
        <td class="px-6 py-4 text-slate-500">${c.level_assigned ? `Level ${escapeHtml(c.level_assigned)}` : "—"}</td>
        <td class="px-6 py-4">${badge("Eligible", "green")}</td>
        <td class="px-6 py-4">
          <select class="allocation-slot rounded-lg border border-slate-200 px-2 py-2 text-xs" data-enrollment="${c.enrollment_id}">
            <option value="">Select slot</option>
            ${matchingSlots.map((s) => `<option value="${s.slot_id}">${escapeHtml(s.batch_number)} · ${escapeHtml(s.start_time.slice(0, 5))} (${s.seats_remaining} left)</option>`).join("")}
          </select>
        </td>
        <td class="px-6 py-4"><button class="allocate-candidate rounded-lg bg-intern-blue px-3 py-2 text-xs font-medium text-white" data-enrollment="${c.enrollment_id}">Assign</button></td>
      </tr>`;
            })
            .join("")
        : `<tr><td class="px-6 py-10 text-center text-sm text-slate-500" colspan="5">No eligible candidates available for allocation.</td></tr>`;

    $$(".allocate-candidate").forEach(
      (btn) =>
        (btn.onclick = async () => {
          const select = $(
            `.allocation-slot[data-enrollment="${btn.dataset.enrollment}"]`,
          );
          const slotId = Number(select?.value || 0);
          if (!slotId) {
            notify("Select a slot first.", true);
            return;
          }
          const slot = slots.find((s) => Number(s.slot_id) === slotId);
          try {
            await api("allocate", {
              method: "POST",
              body: {
                enrollment_id: Number(btn.dataset.enrollment),
                batch_id: Number(slot.batch_id),
                slot_id: slotId,
              },
            });
            notify("Candidate allocated.");
            await loadBatches();
          } catch (e) {
            notify(e.message, true);
          }
        }),
    );

    const batchDate = $("#batchDate");
    const batchDateMessage = $("#batchDateMessage");
    if (batchDate && !batchDate.dataset.bound) {
      batchDate.dataset.bound = "1";
      const validateBatchDate = () => {
        if (!batchDate.value) return false;
        const d = new Date(`${batchDate.value}T00:00:00`);
        const valid = !Number.isNaN(d.getTime()) && (d.getDay() === 0 || d.getDay() === 6);
        if (batchDateMessage) {
          batchDateMessage.textContent = valid
            ? "Weekend date selected."
            : "Assessment can be scheduled only on Saturday or Sunday.";
        }
        return valid;
      };
      batchDate.addEventListener("change", validateBatchDate);
      batchDate.addEventListener("input", validateBatchDate);
    }

    const form = $("#createBatchForm");
    if (form && !form.dataset.bound) {
      form.dataset.bound = "1";
      form.addEventListener("submit", async (e) => {
        e.preventDefault();
        const name = $("#batchName")?.value.trim(),
          date = $("#batchDate")?.value,
          capacity = Number($("#batchCapacity")?.value || 0);
        const selectedDate = date ? new Date(`${date}T00:00:00`) : null;
        const isWeekend =
          selectedDate &&
          !Number.isNaN(selectedDate.getTime()) &&
          (selectedDate.getDay() === 0 || selectedDate.getDay() === 6);
        if (!name || !date || !isWeekend || capacity < 100) {
          notify(
            date && !isWeekend
              ? "Assessment can be scheduled only on Saturday or Sunday."
              : "Enter a batch name, weekend date and capacity of at least 100.",
            true,
          );
          return;
        }
        try {
          await api("batch", {
            method: "POST",
            body: { batch_number: name, exam_date: date, capacity },
          });
          notify("Batch created successfully.");
          form.reset();
          $("#batchCapacity").value = 100;
          await loadBatches();
        } catch (err) {
          notify(err.message, true);
        }
      });
    }

    const createSlot = $("#createSlotButton");
    if (createSlot && !createSlot.dataset.bound) {
      createSlot.dataset.bound = "1";
      createSlot.addEventListener("click", async () => {
        if (!batches.length) {
          notify("Create a batch first.", true);
          return;
        }
        let batch =
          batches.find((b) => b.schedule_status === "scheduled") || batches[0];
        if (batches.length > 1) {
          const choice = prompt(
            `Batch name:\n${batches.map((b) => b.batch_number).join("\n")}`,
            batch.batch_number,
          );
          if (choice === null) return;
          batch =
            batches.find(
              (b) =>
                b.batch_number.toLowerCase() === choice.trim().toLowerCase(),
            ) || batch;
        }
        const start = prompt("Start time (HH:MM):", "10:00");
        if (start === null) return;
        const end = prompt("End time (HH:MM):", "11:00");
        if (end === null) return;
        const cap = Number(prompt("Slot capacity:", "50"));
        if (!cap) return;
        try {
          await api("slot", {
            method: "POST",
            body: {
              batch_id: Number(batch.id),
              start_time: start,
              end_time: end,
              capacity: cap,
            },
          });
          notify("Slot created.");
          await loadBatches();
        } catch (err) {
          notify(err.message, true);
        }
      });
    }
  }

  async function loadPlacements() {
    const data = await api("placements"),
      rows = data.placements || [];
    const set = (id, v) => {
      const el = $("#" + id);
      if (el) el.textContent = v;
    };
    set(
      "placementReadyCount",
      rows.filter((p) => p.placement_status === "eligible").length,
    );
    set(
      "placementLevel1Count",
      rows.filter((p) => Number(p.level_assigned) === 1).length,
    );
    set(
      "placementLevel2Count",
      rows.filter((p) => Number(p.level_assigned) === 2).length,
    );
    set(
      "placedCount",
      rows.filter((p) => p.placement_status === "placed").length,
    );
    const tbody =
      $("#emptyPlacement")?.parentElement ||
      document
        .querySelector("#placementSearch")
        ?.closest("div.mt-8")
        ?.querySelector("tbody");
    if (!tbody) return;
    tbody.innerHTML = rows.length
      ? rows
          .map(
            (p) => `
      <tr data-level="${p.level_assigned ? `level ${p.level_assigned}` : ""}" data-placement-candidate="true" data-status="${escapeHtml(p.placement_status === "eligible" ? "placement ready" : p.placement_status === "interviewing" ? "interview" : p.placement_status)}">
        <td class="px-6 py-5 font-medium">${escapeHtml(p.full_name)}</td>
        <td class="px-6 py-5">${p.level_assigned ? badge(`Level ${p.level_assigned}`, "blue") : "—"}</td>
        <td class="px-6 py-5">${p.percentage !== null ? `${escapeHtml(p.percentage)} / 100` : "—"}</td>
        <td class="px-6 py-5">${badge(label(p.placement_status), typeForStatus(p.placement_status))}</td>
        <td class="px-6 py-5">${escapeHtml(p.company_name || "—")}</td>
        <td class="px-6 py-5"><button class="view-placement-btn text-intern-blue" type="button" data-id="${p.id}" data-name="${escapeHtml(p.full_name)}" data-status="${p.placement_status}" data-company="${escapeHtml(p.company_name || "")}" data-notes="${escapeHtml(p.notes || "")}">View</button></td>
      </tr>`,
          )
          .join("")
      : `<tr id="emptyPlacement"><td class="px-6 py-12 text-center text-sm text-slate-600" colspan="6">No candidates found</td></tr>`;
    refreshIcons();

    $$(".view-placement-btn").forEach(
      (btn) => (btn.onclick = () => openPlacementEditor(btn)),
    );
    wirePlacementFilters();
  }

  function openPlacementEditor(btn) {
    const root = modal(
      "Update Placement",
      `
      <form id="placementEditForm" class="space-y-4">
        <div><p class="text-sm font-medium text-slate-800">${escapeHtml(btn.dataset.name)}</p></div>
        <label class="block text-sm text-slate-600">Status
          <select id="placementStatusEdit" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2">
            ${["eligible", "shortlisted", "interviewing", "placed", "not_placed"].map((x) => `<option value="${x}" ${x === btn.dataset.status ? "selected" : ""}>${label(x)}</option>`).join("")}
          </select>
        </label>
        <label class="block text-sm text-slate-600">Company
          <input id="placementCompanyEdit" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2" value="${btn.dataset.company || ""}">
        </label>
        <label class="block text-sm text-slate-600">Notes
          <textarea id="placementNotesEdit" class="mt-1 min-h-24 w-full rounded-lg border border-slate-200 px-3 py-2">${btn.dataset.notes || ""}</textarea>
        </label>
        <button class="w-full rounded-lg bg-intern-blue px-4 py-2.5 text-sm font-medium text-white">Save</button>
      </form>`,
    );
    $("#placementEditForm", root).onsubmit = async (e) => {
      e.preventDefault();
      try {
        await api("placement", {
          method: "POST",
          body: {
            id: Number(btn.dataset.id),
            status: $("#placementStatusEdit", root).value,
            company_name: $("#placementCompanyEdit", root).value,
            notes: $("#placementNotesEdit", root).value,
          },
        });
        root.remove();
        notify("Placement updated.");
        await loadPlacements();
      } catch (err) {
        notify(err.message, true);
      }
    };
  }

  function wirePlacementFilters() {
    const search = $("#placementSearch"),
      level = $("#placementLevelFilter"),
      status = $("#placementStatusFilter"),
      tbody = document
        .querySelector("#placementSearch")
        ?.closest("div.mt-8")
        ?.querySelector("tbody");
    if (!tbody) return;
    const run = () => {
      const q = (search?.value || "").toLowerCase(),
        l = (level?.value || "").toLowerCase(),
        s = (status?.value || "").toLowerCase();
      tbody
        .querySelectorAll("tr[data-placement-candidate]")
        .forEach(
          (row) =>
            (row.style.display =
              row.textContent.toLowerCase().includes(q) &&
              sameFilterValue(row.dataset.level, l) &&
              sameFilterValue(row.dataset.status, s)
                ? ""
                : "none"),
        );
    };
    [search, level, status].forEach((el) => el?.addEventListener("input", run));
    [level, status].forEach((el) => el?.addEventListener("change", run));
  }

  async function loadSettings() {
    const data = await api("settings"),
      profile = data.profile,
      settings = data.settings || [];
    const get = (k) => settings.find((s) => s.setting_key === k)?.setting_value;
    if (profile) {
      if ($("#fullName")) $("#fullName").value = profile.full_name || "Admin";
      if ($("#email")) $("#email").value = profile.email || "";
      if ($("#phone")) $("#phone").value = profile.phone || "";
      if ($("#adminHeaderName"))
        $("#adminHeaderName").textContent = profile.full_name || "Admin";
    }
    [
      "batchNotifications",
      "assessmentNotifications",
      "placementNotifications",
      "certificateNotifications",
    ].forEach((id) => {
      const key = id.replace("Notifications", "_notifications");
      const el = $("#" + id);
      if (el && get(key) !== undefined) el.checked = get(key) === "1";
    });
    const refresh = $("#refreshPreference");
    if (refresh && get("refresh_preference"))
      refresh.value = get("refresh_preference");

    const save = $("#saveChangesButton");
    if (save && !save.dataset.bound) {
      save.dataset.bound = "1";
      save.onclick = async () => {
        try {
          await api("profile", {
            method: "POST",
            body: {
              full_name: $("#fullName").value,
              email: $("#email").value,
              phone: $("#phone").value,
            },
          });
          notify("Profile saved.");
        } catch (e) {
          notify(e.message, true);
        }
      };
    }

    const prefs = $("#savePreferences");
    if (prefs && !prefs.dataset.bound) {
      prefs.dataset.bound = "1";
      prefs.onclick = async () => {
        try {
          for (const id of [
            "batchNotifications",
            "assessmentNotifications",
            "placementNotifications",
            "certificateNotifications",
          ]) {
            await api("setting", {
              method: "POST",
              body: {
                key: id.replace("Notifications", "_notifications"),
                value: $("#" + id).checked ? "1" : "0",
              },
            });
          }
          await api("setting", {
            method: "POST",
            body: {
              key: "refresh_preference",
              value: $("#refreshPreference").value,
            },
          });
          notify("Preferences saved.");
        } catch (e) {
          notify(e.message, true);
        }
      };
    }
  }

  function bindGenerateButtons() {
    const qBtn = $("#generateQuestionsButton");
    if (qBtn && !qBtn.dataset.bound) {
      qBtn.dataset.bound = "1";
      qBtn.onclick = async () => {
        try {
          const data = await api("questions");
          notify(
            `Question bank loaded: ${(data.questions || []).length} questions. AI generation remains owned by M1.`,
          );
          await loadQuestions();
        } catch (e) {
          notify(e.message, true);
        }
      };
    }

    const cBtn = $("#generateCertificateButton");
    if (cBtn && !cBtn.dataset.bound) {
      cBtn.dataset.bound = "1";
      cBtn.onclick = async () => {
        if (
          !confirm("Generate a certificate for the oldest result without one?")
        )
          return;
        try {
          const data = await api("certificate-next", {
            method: "POST",
            body: {},
          });
          notify(`Certificate ${data.certificate_number} generated.`);
          await loadCertificates();
        } catch (e) {
          notify(e.message, true);
        }
      };
    }
  }

  async function initPage() {
    initCommon();
    bindGenerateButtons();
    bindAddCandidateButton();
    const path = window.location.pathname.toLowerCase();
    try {
      if (path.endsWith("index.html") || path.endsWith("/admin/"))
        await loadDashboard();
      else if (path.endsWith("candidates.html")) await loadCandidates();
      else if (path.endsWith("results.html")) await loadResults();
      else if (path.endsWith("certificates.html")) await loadCertificates();
      else if (path.endsWith("questions.html")) await loadQuestions();
      else if (path.endsWith("batches.html")) await loadBatches();
      else if (path.endsWith("placement.html")) await loadPlacements();
      else if (path.endsWith("setting.html")) await loadSettings();
    } catch (e) {
      console.error(e);
      notify(e.message || "Unable to load data.", true);
    }

    const refresh = $("#refreshDashboard");
    if (refresh && !refresh.dataset.bound) {
      refresh.dataset.bound = "1";
      refresh.onclick = async () => {
        refresh.classList.add("animate-spin");
        try {
          await loadDashboard();
          notify("Dashboard refreshed.");
        } catch (e) {
          notify(e.message, true);
        } finally {
          setTimeout(() => refresh.classList.remove("animate-spin"), 700);
        }
      };
    }

    if (typeof lucide !== "undefined") lucide.createIcons();
  }

  document.addEventListener("DOMContentLoaded", initPage);
})();
