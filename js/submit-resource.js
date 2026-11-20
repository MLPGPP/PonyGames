/* submit.php?resource: submit a resource (asset) for the resources page.
   Sent to api/submit.php as kind=resource; previews use js/shot-editor.js. */
(function () {
  "use strict";

  const form = document.getElementById("resource-form");
  if (!form) return;
  const msg = document.getElementById("submit-msg");
  const sendBtn = form.querySelector(".submit-actions__send");
  const licSel = form.elements.license;
  const licOther = form.querySelector("[data-license-other]");

  licSel.addEventListener("change", () => {
    licOther.hidden = licSel.value !== "other";
  });

  /* ---------- previews ---------- */
  const drop = document.getElementById("drop-zone");
  const shotsMsg = document.getElementById("shots-msg");
  const editor = window.PGShotEditor({
    grid: document.getElementById("shot-grid"),
    add: drop,
    tiles: [],
    maxNew: 12, // SUBMIT_MAX_IMAGES in inc/submissions.php
    maxBytes: 5 * 1024 * 1024,
    labels: { managed: () => "", shared: "" },
    onChange: () => {
      shotsMsg.textContent = "";
    },
    onMessage: (text) => {
      shotsMsg.textContent = text || "";
      shotsMsg.className = "post-form__msg" + (text ? " is-error" : "");
    },
  });

  /* ---------- messages ---------- */
  function showMsg(text, kind) {
    msg.textContent = text || "";
    msg.className = "post-form__msg" + (kind === "error" ? " is-error" : kind === "ok" ? " is-ok" : "");
  }
  function markField(name) {
    form.querySelectorAll(".has-error").forEach((el) => el.classList.remove("has-error"));
    if (!name) return;
    const target = name === "tags" ? form.querySelector(".chip-checks") : form.querySelector(`[name="${name}"]`);
    if (!target) return;
    const box = target.closest(".post-form__field") || target.closest(".field-group") || target;
    box.classList.add("has-error");
    target.scrollIntoView({ behavior: "smooth", block: "center" });
    const focusable = target.matches("input,select,textarea") ? target : target.querySelector("input,select,textarea");
    focusable?.focus({ preventScroll: true });
  }

  /* ---------- send ---------- */
  form.addEventListener("submit", (e) => {
    e.preventDefault();
    markField(null);
    const f = form.elements;
    const fail = (text, field) => {
      showMsg(text, "error");
      markField(field);
    };
    if (f.title.value.trim().length < 2) return fail("Please give the resource a name.", "title");
    if (!form.querySelector("[name='tags[]']:checked")) return fail("Please pick at least one type.", "tags");
    const links = f.downloads.value.split(/\s+/).filter(Boolean);
    if (!links.length) return fail("Please add at least one link where the resource can be downloaded.", "downloads");
    if (links.some((u) => !/^https?:\/\/\S+\.\S+$/i.test(u))) return fail("Download links should start with https://", "downloads");

    const data = new FormData(form);
    const r = editor.result();
    r.files.forEach((file) => data.append("images[]", file, file.name));
    sendBtn.disabled = true;
    showMsg(r.files.length ? "Uploading…" : "Sending…");
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
        const done = document.getElementById("submit-done");
        done.querySelector("[data-done-text]").textContent =
          "Your resource is in the queue. A moderator will check it and add it to the resources.";
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
