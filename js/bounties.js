/* Bounty board: wanted posters for missing games + the tip form.
   Uses escapeHtml() and gamePath() from main.js. */
(function () {
  "use strict";

  const grid = document.getElementById("bounty-grid");
  if (!grid) return;

  const dialog = document.getElementById("bounty-dialog");
  const form = dialog.querySelector("form");
  const msg = form.querySelector(".post-form__msg");
  const submitBtn = form.querySelector("[data-submit]");
  const searchInput = document.getElementById("bounty-search");
  const tabs = Array.from(document.querySelectorAll(".bounty-tab"));

  let bounties = [];
  let filter = "wanted";
  let opener = null;

  // posters without a picture show our mascot
  const MASCOT = `<img class="poster__mascot" src="assets/mascot-head.png" alt="" width="120" height="157" loading="lazy" />`;

  function authorsText(b) {
    const a = Array.isArray(b.authors) ? b.authors.filter(Boolean) : b.author ? [b.author] : [];
    return a.join(" & ");
  }

  function renderPoster(b, i) {
    const found = b.status === "found";
    const authors = authorsText(b);
    const facts = [b.year, b.platform].filter(Boolean).map(escapeHtml).join(" · ");
    const lastSeen = b.lastSeen
      ? `<p class="poster__seen"><span>Last seen:</span> ${
          b.lastSeenUrl
            ? `<a href="${escapeHtml(b.lastSeenUrl)}" target="_blank" rel="noopener nofollow">${escapeHtml(b.lastSeen)}</a>`
            : escapeHtml(b.lastSeen)
        }</p>`
      : "";
    const picture = b.image
      ? `<img src="${escapeHtml(b.image)}" alt="" loading="lazy" />`
      : MASCOT;
    const action = found
      ? b.foundGameId
        ? `<a class="poster__btn poster__btn--found" href="${gamePath({ id: b.foundGameId, title: b.title })}">Play it now →</a>`
        : `<span class="poster__btn poster__btn--found">Found!</span>`
      : `<button type="button" class="poster__btn" data-tip="${escapeHtml(b.id)}">I've seen it!</button>`;
    return `
      <article class="poster${found ? " poster--found" : ""}" style="--tilt:${[-1.6, 1.1, -0.6, 1.8, -1.2, 0.7][i % 6]}deg">
        <span class="poster__pin" aria-hidden="true"></span>
        <p class="poster__wanted">${found ? "Found" : "Wanted"}</p>
        <p class="poster__doa">${found ? "and brought home" : "dead or alive"}</p>
        <div class="poster__picture">${picture}</div>
        <h2 class="poster__title">${escapeHtml(b.title)}</h2>
        ${authors || facts ? `<p class="poster__by">${authors ? `by ${escapeHtml(authors)}` : ""}${authors && facts ? " · " : ""}${facts}</p>` : ""}
        ${b.description ? `<p class="poster__desc">${escapeHtml(b.description)}</p>` : ""}
        ${lastSeen}
        ${found && b.foundBy ? `<p class="poster__seen"><span>Found by:</span> ${escapeHtml(b.foundBy)}</p>` : ""}
        <div class="poster__foot">
          <span class="poster__reward">Reward: eternal gratitude</span>
          ${action}
        </div>
        ${found ? `<span class="poster__stamp" aria-hidden="true">Found</span>` : ""}
      </article>`;
  }

  function render() {
    const q = (searchInput?.value || "").trim().toLowerCase();
    const list = bounties.filter((b) => {
      if (filter === "wanted" && b.status === "found") return false;
      if (filter === "found" && b.status !== "found") return false;
      if (!q) return true;
      return `${b.title} ${authorsText(b)} ${b.description || ""}`.toLowerCase().includes(q);
    });
    if (!list.length) {
      const text = q
        ? "No posters match that search."
        : filter === "found"
          ? "Nothing found yet. Help us bring the first one home!"
          : "No wanted games right now. Know one that's missing?";
      grid.innerHTML = `
        <div class="games-empty games-empty--friendly bounty-empty">
          <img class="games-empty__mascot" src="/assets/mascot-head.png" alt="" width="120" height="157" />
          <p class="games-empty__title">The board is empty!</p>
          <p class="games-empty__text">${text}</p>
          <div class="games-empty__actions"><button type="button" class="btn btn--accent" data-bounty-new>Post a bounty</button></div>
        </div>`;
      return;
    }
    grid.innerHTML = list.map(renderPoster).join("");
  }

  function updateStats() {
    const found = bounties.filter((b) => b.status === "found").length;
    const set = (k, v) => {
      const el = document.querySelector(`[data-stat="${k}"]`);
      if (el) el.textContent = v;
    };
    set("wanted", bounties.length - found);
    set("found", found);
  }

  /* ---------- dialog ---------- */
  function openForm(bounty, trigger) {
    opener = trigger || null;
    form.reset();
    msg.textContent = "";
    msg.className = "post-form__msg";
    submitBtn.disabled = false;
    submitBtn.hidden = false;
    const isNew = !bounty;
    form.elements.bounty.value = isNew ? "new" : bounty.id;
    form.querySelectorAll("[data-new-only]").forEach((el) => (el.hidden = !isNew));
    form.querySelector("[data-kicker]").textContent = isNew ? "New bounty" : "Wanted";
    form.querySelector("[data-title]").textContent = isNew ? "Post a bounty" : bounty.title;
    form.querySelector("[data-intro]").textContent = isNew
      ? "Know a pony game that existed but isn't on Pony Games, and can't be found anymore? Tell us about it and we'll pin it to the board."
      : "Seen this game somewhere? Share any link that might still have it: a download, an archive.org snapshot, an old forum post. Every lead helps!";
    form.querySelector("[data-links-label]").textContent = isNew
      ? "Where did you last see it? (optional)"
      : "Where can it be found?";
    submitBtn.textContent = isNew ? "Post bounty" : "Send tip";
    try {
      const saved = localStorage.getItem("pg-post-name");
      if (saved) form.elements.name.value = saved;
    } catch (e) {}
    if (typeof dialog.showModal === "function") dialog.showModal();
    else dialog.setAttribute("open", "");
    (isNew ? form.elements.title : form.elements.links).focus();
  }
  function closeForm() {
    if (typeof dialog.close === "function") dialog.close();
    else dialog.removeAttribute("open");
  }
  dialog.addEventListener("close", () => opener?.focus());
  dialog.addEventListener("click", (e) => {
    if (e.target === dialog) closeForm();
  });
  dialog.querySelector("[data-close]").addEventListener("click", closeForm);

  document.addEventListener("click", (e) => {
    const tipBtn = e.target.closest("[data-tip]");
    if (tipBtn) {
      const b = bounties.find((x) => String(x.id) === tipBtn.dataset.tip);
      if (b) openForm(b, tipBtn);
      return;
    }
    const newBtn = e.target.closest("[data-bounty-new]");
    if (newBtn) openForm(null, newBtn);
  });

  form.addEventListener("submit", (e) => {
    e.preventDefault();
    const fd = new FormData(form);
    const payload = {};
    fd.forEach((v, k) => (payload[k] = String(v).trim()));
    const isNew = payload.bounty === "new";
    const fail = (t) => {
      msg.className = "post-form__msg is-error";
      msg.textContent = t;
    };
    if (isNew && payload.title.length < 2) return fail("Please fill in the title of the missing game.");
    if (!isNew && !payload.links && payload.notes.length < 10) {
      return fail("Please share a link, or tell us a bit more about where you saw it.");
    }
    submitBtn.disabled = true;
    msg.className = "post-form__msg";
    msg.textContent = "Sending…";
    fetch("/api/bounty.php", {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/json", "X-Requested-With": "pony-games" },
      body: JSON.stringify(payload),
    })
      .then((res) =>
        res
          .json()
          .catch(() => ({}))
          .then((data) => {
            if (!res.ok) throw new Error(data.error || "Something went wrong. Please try again.");
          })
      )
      .then(() => {
        try {
          if (payload.name) localStorage.setItem("pg-post-name", payload.name);
        } catch (err) {}
        msg.className = "post-form__msg is-ok";
        msg.textContent = isNew
          ? "Thanks! The team will check it and pin it to the board."
          : "Thank you, bounty hunter! The team will follow your lead.";
        submitBtn.hidden = true;
        setTimeout(closeForm, 2400);
      })
      .catch((err) => {
        fail(err.message);
        submitBtn.disabled = false;
      });
  });

  /* ---------- filters ---------- */
  tabs.forEach((tab) =>
    tab.addEventListener("click", () => {
      filter = tab.dataset.filter;
      tabs.forEach((t) => {
        const on = t === tab;
        t.classList.toggle("is-active", on);
        t.setAttribute("aria-selected", String(on));
      });
      render();
    })
  );
  searchInput?.addEventListener("input", render);

  fetch("data/bounties.json")
    .then((r) => r.json())
    .then((data) => {
      bounties = Array.isArray(data.bounties) ? data.bounties : [];
      updateStats();
      render();
      // bounties.html#bounty-3 opens that poster's tip form
      const m = location.hash.match(/^#bounty-(.+)$/);
      if (m) {
        const b = bounties.find((x) => String(x.id) === decodeURIComponent(m[1]));
        if (b && b.status !== "found") openForm(b);
      }
    })
    .catch(() => {
      grid.innerHTML = `<p class="bounty-loading">Couldn't load the bounty board. Please refresh the page.</p>`;
    });
})();
