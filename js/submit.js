/* submit.php: submit a new game or correct a listed one.
   Uses escapeHtml() and gamePath() from main.js. */
(function () {
  "use strict";

  const form = document.getElementById("submit-form");
  if (!form) return;
  const mode = form.dataset.mode; // "new" or "fix"
  const msg = document.getElementById("submit-msg");
  const sendBtn = form.querySelector(".submit-actions__send");
  const rowsEl = document.getElementById("link-rows");
  const rowTpl = document.getElementById("link-row");
  const engineSel = form.elements.engine;
  const engineOther = form.querySelector("[data-engine-other]");

  /* ---------- engine "something else" ---------- */
  function syncEngine() {
    engineOther.hidden = engineSel.value !== "other";
  }
  engineSel.addEventListener("change", syncEngine);

  /* ---------- links ---------- */
  function addLink(link) {
    const row = rowTpl.content.firstElementChild.cloneNode(true);
    if (link) {
      row.querySelector("select").value = link.type || "download";
      row.querySelector("[name='link_url[]']").value = link.url || "";
      row.querySelector("[name='link_label[]']").value = link.label || "";
    }
    rowsEl.appendChild(row);
    return row;
  }
  document.getElementById("add-link").addEventListener("click", () => {
    addLink().querySelector("[name='link_url[]']").focus();
  });
  rowsEl.addEventListener("click", (e) => {
    const rm = e.target.closest(".link-row__remove");
    if (!rm) return;
    rm.closest(".link-row").remove();
    if (!rowsEl.children.length) addLink();
  });

  // upcoming games don't need a link yet
  const linksHint = form.querySelector("[data-links-required]");
  function syncStatus() {
    if (linksHint) linksHint.hidden = form.elements.status.value === "Upcoming" || mode === "fix";
  }
  form.elements.status.addEventListener("change", syncStatus);

  /* ---------- pictures (js/shot-editor.js) ---------- */
  const MAX_NEW = 12; // SUBMIT_MAX_IMAGES in inc/submissions.php
  const drop = document.getElementById("drop-zone");
  const shotsMsg = document.getElementById("shots-msg");
  let editor = null;
  function startEditor(tiles) {
    editor = window.PGShotEditor({
      grid: document.getElementById("shot-grid"),
      add: drop,
      tiles,
      maxNew: MAX_NEW,
      maxBytes: 5 * 1024 * 1024,
      labels: { managed: () => "", shared: "" },
      onChange: () => {
        shotsMsg.textContent = "";
        drop.closest(".submit-card").classList.remove("has-error");
      },
      onMessage: (text) => {
        shotsMsg.textContent = text || "";
        shotsMsg.className = "post-form__msg" + (text ? " is-error" : "");
      },
    });
  }

  /* ---------- messages ---------- */
  function showMsg(text, kind) {
    msg.textContent = text || "";
    msg.className = "post-form__msg" + (kind === "error" ? " is-error" : kind === "ok" ? " is-ok" : "");
  }
  function markField(name) {
    form.querySelectorAll(".has-error").forEach((el) => el.classList.remove("has-error"));
    if (!name) return;
    const target =
      name === "links" ? rowsEl : name === "images" ? document.getElementById("shot-grid") : form.querySelector(`[name="${name}"]`);
    if (!target) return;
    const box = target.closest(".post-form__field") || (name === "images" ? target.closest(".submit-card") : target);
    box.classList.add("has-error");
    target.scrollIntoView({ behavior: "smooth", block: "center" });
    const focusable = target.matches("input,select,textarea") ? target : target.querySelector("input,select,textarea");
    focusable?.focus({ preventScroll: true });
  }

  /* ---------- fix mode: pick a game and fill in its current info ---------- */
  let games = [];
  function setChecks(name, values) {
    const set = new Set(values || []);
    form.querySelectorAll(`[name="${name}[]"]`).forEach((cb) => (cb.checked = set.has(cb.value)));
  }
  function tagsOf(g, type) {
    return (g.tags || []).filter((t) => t && typeof t === "object" && String(t.type || "").toLowerCase() === type).map((t) => t.label);
  }
  // same mapping as game_form_values() in inc/submissions.php
  function linksOf(g) {
    const out = [];
    if (g.url) out.push({ type: "page", label: "", url: g.url });
    (g.downloads || []).forEach((d) => {
      if (!d.url || d.url === g.url) return;
      const kind = classifyDownloadEntry(d);
      const isCode = /source\s*code/i.test(`${d.label || ""} ${d.buttonText || ""}`);
      out.push({
        type: isCode ? "source" : kind === "web" ? "play" : kind === "source" ? "page" : "download",
        label: `${d.label || ""}${d.version ? " " + d.version : ""}`.trim(),
        url: d.url,
      });
    });
    (g.socials || []).forEach((s) => s.url && out.push({ type: "social", label: s.label || "", url: s.url }));
    return out;
  }
  function fillFrom(g) {
    form.elements.game.value = g.id;
    form.elements.title.value = g.title || "";
    form.elements.authors.value = (Array.isArray(g.author) ? g.author : [g.author]).filter(Boolean).join(", ");
    form.elements.status.value = g.status === "Abandoned" ? "Cancelled" : g.status || "";
    form.elements.releaseDate.value = g.releaseDate || "";
    const engines = Array.from(engineSel.options).map((o) => o.value);
    if (!g.engine || engines.includes(g.engine)) {
      engineSel.value = g.engine || "Unknown";
      form.elements.engine_other.value = "";
    } else {
      engineSel.value = "other";
      form.elements.engine_other.value = g.engine;
    }
    syncEngine();
    form.elements.playtime.value = g.playtime && g.playtime !== "N/A" ? g.playtime : "Unknown";
    setChecks("platforms", g.platforms);
    setChecks("genres", tagsOf(g, "genre"));
    setChecks("tags", tagsOf(g, "other"));
    setChecks("warnings", tagsOf(g, "warning"));
    setChecks("characters", (g.characters || []).filter((c) => c !== "Unknown"));
    form.elements.shortDescription.value = g.shortDescription || "";
    form.elements.fullDescription.value = g.fullDescription || "";
    if (form.elements.videos) form.elements.videos.value = (g.videos || []).join("\n");
    rowsEl.innerHTML = "";
    const links = linksOf(g);
    (links.length ? links : [null]).forEach(addLink);
    const authors = (Array.isArray(g.author) ? g.author : [g.author]).filter(Boolean).join(" & ");
    document.getElementById("picked-game").innerHTML = `
      <img class="picked-game__thumb" src="${escapeHtml(g.thumbnail || "")}" alt="" />
      <div class="picked-game__text">
        <p class="picked-game__kicker">You're fixing</p>
        <p class="picked-game__title">${escapeHtml(g.title)}</p>
        ${authors ? `<p class="picked-game__by">by ${escapeHtml(authors)}</p>` : ""}
        <p class="picked-game__links"><a href="${gamePath(g)}" target="_blank" rel="noopener">View its page</a> · <a href="/submit?fix" data-change-game>Pick another game</a></p>
      </div>`;
    syncStatus();
    // the game's pictures as they are now, cover first
    const shots = [g.thumbnail, ...(g.screenshots || [])].filter((p, i, all) => p && all.indexOf(p) === i);
    startEditor(shots.map((p) => ({ path: p, managed: /^\/?s\/\d+\//.test(p) })));
    // is a change for this game already waiting for review?
    if (window.pgMe) {
      pgMe().then((d) => {
        const note = document.getElementById("pending-note");
        if (note) note.hidden = !(d.pending || []).includes(String(g.id));
      });
    }
  }

  function initPicker() {
    const picker = document.getElementById("game-picker");
    const search = document.getElementById("game-picker-search");
    const results = document.getElementById("game-picker-results");
    fetch("/data/games.json")
      .then((r) => r.json())
      .then((data) => {
        games = data.games || data;
        const pre = form.dataset.edit;
        const g = pre && games.find((x) => String(x.id) === pre);
        if (g) {
          fillFrom(g);
          form.hidden = false;
        } else {
          picker.hidden = false;
          search?.focus();
        }
      })
      .catch(() => showMsg("Couldn't load the list of games. Please refresh the page.", "error"));

    function pick(g) {
      fillFrom(g);
      picker.hidden = true;
      form.hidden = false;
      history.replaceState(null, "", `/submit?edit=${encodeURIComponent(g.id)}`);
      form.querySelector("[name=note]").focus();
    }
    search?.addEventListener("input", () => {
      const q = search.value.trim().toLowerCase();
      if (q.length < 2) {
        results.innerHTML = "";
        return;
      }
      const hits = games
        .filter((g) => `${g.title} ${(Array.isArray(g.author) ? g.author : [g.author]).join(" ")}`.toLowerCase().includes(q))
        .slice(0, 12);
      results.innerHTML = hits.length
        ? hits
            .map(
              (g) => `<li role="option"><button type="button" class="game-picker__hit" data-id="${escapeHtml(String(g.id))}">
                <img src="${escapeHtml(g.thumbnail || "")}" alt="" loading="lazy" />
                <span><strong>${escapeHtml(g.title)}</strong>
                <small>${escapeHtml((Array.isArray(g.author) ? g.author : [g.author]).filter(Boolean).join(" & "))}${g.releaseDate ? " · " + escapeHtml(String(g.releaseDate).slice(0, 4)) : ""}</small></span>
              </button></li>`
            )
            .join("")
        : `<li class="game-picker__none">No games found. Is it missing from the site? <a href="/submit">Submit it as a new game</a>.</li>`;
    });
    results?.addEventListener("click", (e) => {
      const b = e.target.closest("[data-id]");
      if (!b) return;
      const g = games.find((x) => String(x.id) === b.dataset.id);
      if (g) pick(g);
    });
  }

  if (mode === "fix") initPicker();
  else {
    addLink();
    startEditor([]);
  }
  syncEngine();
  syncStatus();

  /* ---------- send ---------- */
  form.addEventListener("submit", (e) => {
    e.preventDefault();
    markField(null);
    const f = form.elements;
    const hasLink = Array.from(form.querySelectorAll("[name='link_url[]']")).some((i) => i.value.trim());
    const fail = (text, field) => {
      showMsg(text, "error");
      markField(field);
    };
    if (!f.title.value.trim()) return fail("Please fill in the title of the game.", "title");
    if (!f.status.value) return fail("Please choose a status.", "status");
    if (f.releaseDate.value && !/^\d{4}(-\d{2}(-\d{2})?)?$/.test(f.releaseDate.value.trim())) {
      return fail("Please write the release date like 2014, 2014-05 or 2014-05-21.", "releaseDate");
    }
    if (mode === "new") {
      if (!f.fullDescription.value.trim() && !f.shortDescription.value.trim()) return fail("Please tell us a bit about the game.", "fullDescription");
      if (f.status.value !== "Upcoming" && !hasLink) return fail("Please add at least one link where the game can be found.", "links");
    }
    const bad = Array.from(form.querySelectorAll("[name='link_url[]']")).find((i) => i.value.trim() && !/^https?:\/\/\S+\.\S+$/i.test(i.value.trim()));
    if (bad) {
      showMsg("This link should start with https://", "error");
      bad.closest(".post-form__field").classList.add("has-error");
      bad.focus();
      return;
    }

    const data = new FormData(form);
    let uploading = false;
    if (editor && (mode === "new" || editor.isChanged())) {
      if (mode === "fix" && !editor.count()) return fail("Please keep at least one picture.", "images");
      const r = editor.result();
      // new games: the pictures in order; corrections: also the new order of the pictures there are now
      r.files.forEach((f) => data.append("images[]", f, f.name));
      if (mode === "fix") data.set("shots_order", JSON.stringify(r.order));
      uploading = r.files.length > 0;
    }
    sendBtn.disabled = true;
    showMsg(uploading ? "Uploading…" : "Sending…");
    fetch("/api/submit.php", {
      method: "POST",
      credentials: "same-origin",
      headers: { "X-Requested-With": "pony-games" },
      body: data,
    })
      .then((res) =>
        res
          .json()
          .catch(() => ({ error: res.status === 413 ? "That was too much to upload at once. Try fewer or smaller pictures." : "" }))
          .then((d) => {
            if (!res.ok) {
              const err = new Error(d.error || "Something went wrong. Please try again.");
              err.field = d.field;
              throw err;
            }
          })
      )
      .then(() => {
        form.hidden = true;
        document.getElementById("game-picker")?.setAttribute("hidden", "");
        const done = document.getElementById("submit-done");
        done.querySelector("[data-done-text]").textContent =
          mode === "fix"
            ? "Your correction is in. A moderator will check it and update the game."
            : "Your game is in the queue. A moderator will check it and add it to the herd.";
        done.hidden = false;
        done.focus();
        window.scrollTo({ top: 0, behavior: "smooth" });
      })
      .catch((err) => {
        fail(err.message, err.field);
        sendBtn.disabled = false;
      });
  });
})();
