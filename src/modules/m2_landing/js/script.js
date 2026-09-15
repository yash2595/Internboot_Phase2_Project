/* ==========================================================
   InternBoot — Landing Page (M2)
   Pure JavaScript + Bootstrap 5
   
   EDIT ONLY THE CONFIG BELOW when the client confirms the
   pending numbers (fee, batch size, duration, level ranges).
   Nothing is hardcoded anywhere in index.html.
   ========================================================== */

const IB_CONFIG = {
  /* --- money & batch --- */
  fee:        "₹2499",
  feeShort:   "₹2499",
  batchSize:  "100",

  /* --- exam --- */
  duration:   "60 minutes",
  questions:  "100 questions",

  /* --- result --- */
  resultTiming: "within a few minutes of submission",
  retake:       "No, you can only take the test once per drive.",
  placementLevels: "4 and 5",

  /* --- levels: name, score range, what it says about the candidate --- */
  levels: [
    { level: 1, name: "Foundation", range: "0 – 39%",   desc: "Basics are in place. Recommended to revise fundamentals before the next drive." },
    { level: 2, name: "Developing", range: "40 – 54%",  desc: "Working knowledge of core concepts, with room to build speed and depth." },
    { level: 3, name: "Proficient", range: "55 – 69%",  desc: "Solid grasp of fundamentals. Ready for entry-level internship roles." },
    { level: 4, name: "Advanced",   range: "70 – 84%",  desc: "Strong across sections. Shared with the placement team on priority." },
    { level: 5, name: "Expert",     range: "85 – 100%", desc: "Top band. Shortlisted first for interviews and pre-placement offers." }
  ]
};

document.addEventListener("DOMContentLoaded", () => {

  /* ---------- 1. Push config values into the page ---------- */
  document.querySelectorAll("[data-ib]").forEach(el => {
    const key = el.getAttribute("data-ib");
    if (IB_CONFIG[key]) el.textContent = IB_CONFIG[key];
  });

  /* ---------- 2. Hero ladder with enhanced visuals & icons ---------- */
  const ladder = document.getElementById("heroLadder");
  if (ladder) {
    ladder.innerHTML = IB_CONFIG.levels.map(l => `
      <div class="rung" data-level="${l.level}" title="Score ${l.range}">
        <span class="rung-no">${l.level === 5 ? '<i class="bi bi-trophy-fill"></i>' : l.level}</span>
        <span class="rung-name">
          ${l.name}
    
        </span>
        <span class="rung-score">${l.range}</span>
      </div>`).join("");
  }

  /* ---------- 3. Level cards with rank indicators & priority tags ---------- */
  const grid = document.getElementById("levelGrid");
  if (grid) {
    grid.innerHTML = IB_CONFIG.levels.map(l => `
      <div class="col-md-6 col-lg-4">
        <div class="level-card ${l.level === 5 ? "is-top" : ""}" data-level-card="${l.level}">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <span class="level-badge">
              <i class="bi bi-layers-fill"></i> Level ${l.level}
            </span>
            ${l.level >= 4 ? `
              <span class="level-priority-tag">
                <i class="bi bi-lightning-charge-fill"></i>
              </span>` : ''}
          </div>
          <h3>${l.name}</h3>
          <p class="level-score">
            <i class="bi bi-bullseye"></i> Score ${l.range}
          </p>
          <p>${l.desc}</p>
        </div>
      </div>`).join("");
  }

  /* ---------- 4. Interactive Score & Level Estimator ---------- */
  const scoreInput = document.getElementById("scoreRangeInput");
  const scoreDisplay = document.getElementById("scoreValDisplay");
  const levelBadgeDisplay = document.getElementById("levelBadgeDisplay");
  const levelDescDisplay = document.getElementById("levelDescDisplay");

  const updateEstimator = (score) => {
    if (!scoreDisplay) return;
    scoreDisplay.textContent = score + "%";

    let matched = IB_CONFIG.levels[0];
    if (score >= 85) {
      matched = IB_CONFIG.levels[4];
    } else if (score >= 70) {
      matched = IB_CONFIG.levels[3];
    } else if (score >= 55) {
      matched = IB_CONFIG.levels[2];
    } else if (score >= 40) {
      matched = IB_CONFIG.levels[1];
    } else {
      matched = IB_CONFIG.levels[0];
    }

    if (levelBadgeDisplay) {
      const isPriority = matched.level >= 4;
      levelBadgeDisplay.innerHTML = `
        <span class="badge ${isPriority ? 'bg-warning text-dark' : 'bg-primary'} px-3 py-2 rounded-pill">
          Level ${matched.level}: ${matched.name} ${isPriority ? '★ Priority' : ''}
        </span>`;
    }

    if (levelDescDisplay) {
      levelDescDisplay.textContent = matched.desc;
    }

    // Highlight matching level card slightly if visible
    document.querySelectorAll("[data-level-card]").forEach(card => {
      const lvl = parseInt(card.getAttribute("data-level-card"), 10);
      if (lvl === matched.level) {
        card.style.borderColor = "var(--brand)";
        card.style.boxShadow = "0 14px 30px -8px rgba(22, 82, 214, 0.28)";
      } else {
        card.style.borderColor = "";
        card.style.boxShadow = "";
      }
    });
  };

  if (scoreInput) {
    scoreInput.addEventListener("input", (e) => updateEstimator(parseInt(e.target.value, 10)));
    updateEstimator(parseInt(scoreInput.value, 10));
  }

  /* ---------- 5. Navbar scroll, Back-to-top & Mobile Sticky Bar ---------- */
  const nav = document.getElementById("ibNav");
  const toTop = document.getElementById("toTop");
  const mobileStickyBar = document.getElementById("mobileStickyBar");

  const onScroll = () => {
    const y = window.scrollY;
    if (nav) nav.classList.toggle("scrolled", y > 20);
    if (toTop) toTop.classList.toggle("show", y > 450);
    if (mobileStickyBar) mobileStickyBar.classList.toggle("visible", y > 400);
  };
  window.addEventListener("scroll", onScroll, { passive: true });
  onScroll();

  /* ---------- 6. Close mobile menu after clicking a link ---------- */
  const menu = document.getElementById("ibMenu");
  document.querySelectorAll("#ibMenu .nav-link, #ibMenu .btn").forEach(link => {
    link.addEventListener("click", () => {
      if (menu && menu.classList.contains("show")) {
        bootstrap.Collapse.getOrCreateInstance(menu).hide();
      }
    });
  });

  /* ---------- 7. Highlight the section currently in viewport ---------- */
  const sections = document.querySelectorAll("section[id], header[id]");
  const navLinks = document.querySelectorAll('#ibMenu .nav-link[href^="#"]');

  if ("IntersectionObserver" in window && sections.length) {
    const spy = new IntersectionObserver(entries => {
      entries.forEach(entry => {
        if (!entry.isIntersecting) return;
        navLinks.forEach(a => {
          a.classList.toggle("active", a.getAttribute("href") === "#" + entry.target.id);
        });
      });
    }, { rootMargin: "-40% 0px -50% 0px" });
    sections.forEach(s => spy.observe(s));
  }

  /* ---------- 8. Animate stat numbers on scroll once ---------- */
  const formatNum = n => {
    if (n >= 100000) return (n / 100000).toFixed(1).replace(/\.0$/, "") + " Lacs+";
    if (n >= 1000)   return Math.round(n).toLocaleString("en-IN") + "+";
    return Math.round(n) + "+";
  };

  const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

  const runCount = el => {
    const target = parseInt(el.dataset.count, 10);
    const suffix = el.dataset.suffix || "";

    if (reduceMotion) {
      el.textContent = suffix ? target + suffix : formatNum(target);
      return;
    }

    const duration = 1500;
    const start = performance.now();

    const tick = now => {
      const p = Math.min((now - start) / duration, 1);
      const eased = 1 - Math.pow(1 - p, 3);
      const value = target * eased;
      el.textContent = suffix ? Math.round(value) + suffix : formatNum(value);
      if (p < 1) requestAnimationFrame(tick);
    };
    requestAnimationFrame(tick);
  };

  const stats = document.querySelectorAll(".stat-num[data-count]");
  if ("IntersectionObserver" in window && stats.length) {
    const statObserver = new IntersectionObserver((entries, obs) => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          runCount(entry.target);
          obs.unobserve(entry.target);
        }
      });
    }, { threshold: 0.3 });
    stats.forEach(s => statObserver.observe(s));
  } else {
    stats.forEach(runCount);
  }

  /* ---------- 9. Dynamic copyright year in footer ---------- */
  const year = document.getElementById("year");
  if (year) year.textContent = new Date().getFullYear();
});
