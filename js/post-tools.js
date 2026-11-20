/* Edit and delete your own reviews and discussion messages, used on the game
   page (js/game.js) and the account page. Each post is an element with
     data-post="<id>" data-kind="review|comment" data-body="<full text>" [data-rating="1-5"]
   and buttons [data-post-edit] / [data-post-delete] inside it.

   PGPostTools(root, {
     bodySelector: ".post__body",            // the text that's replaced by the editor
     onSaved:   function (el, post) {},      // update the post after an edit
     onDeleted: function (el, reply) {},     // after a delete (el is removed afterwards)
     onEditReview: function (el) {}          // optional: edit reviews somewhere else (return true)
   }); */
(function () {
  "use strict";

  function api(payload) {
    return fetch("/api/community.php", {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/json", "X-Requested-With": "pony-games" },
      body: JSON.stringify(payload),
    }).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (data) {
        if (!res.ok) throw new Error(data.error || "Something went wrong. Please try again.");
        return data;
      });
    });
  }

  window.PGPostTools = function (root, opts) {
    opts = opts || {};
    var bodySel = opts.bodySelector || ".post__body";

    function openEditor(el) {
      if (el.querySelector(".post-edit")) return;
      var isReview = el.dataset.kind === "review";
      var body = el.querySelector(bodySel);
      var form = document.createElement("form");
      form.className = "post-edit";
      var rating = Number(el.dataset.rating) || 0;
      form.innerHTML =
        (isReview
          ? '<fieldset class="post-edit__rating"><legend class="visually-hidden">Your rating</legend>' +
            [1, 2, 3, 4, 5].map(function (n) {
              return '<label title="' + n + " star" + (n > 1 ? "s" : "") + '"><input type="radio" name="rating" value="' + n + '"' + (n === rating ? " checked" : "") + '><span aria-hidden="true">★</span><span class="visually-hidden">' + n + " stars</span></label>";
            }).join("") + "</fieldset>"
          : "") +
        '<label><span class="visually-hidden">' + (isReview ? "Your review" : "Your message") + '</span>' +
        '<textarea name="body" rows="' + (isReview ? 4 : 3) + '" maxlength="' + (isReview ? 2000 : 1500) + '" required></textarea></label>' +
        '<div class="post-edit__foot"><button type="submit" class="btn btn--accent btn--small">Save</button>' +
        '<button type="button" class="btn btn--ghost btn--small" data-edit-cancel>Cancel</button>' +
        '<p class="post-form__msg" role="status"></p></div>';
      form.querySelector("textarea").value = el.dataset.body || (body ? body.innerText : "");
      // show the stars up to the picked one
      function paint() {
        var v = Number((form.querySelector("[name=rating]:checked") || {}).value) || 0;
        Array.prototype.forEach.call(form.querySelectorAll(".post-edit__rating label"), function (l, i) { l.classList.toggle("is-on", i < v); });
      }
      if (isReview) { form.addEventListener("change", paint); paint(); }
      if (body) { body.hidden = true; body.insertAdjacentElement("afterend", form); }
      else el.appendChild(form);
      var ta = form.querySelector("textarea");
      ta.focus();
      ta.setSelectionRange(ta.value.length, ta.value.length);

      function close() {
        form.remove();
        if (body) body.hidden = false;
      }
      form.querySelector("[data-edit-cancel]").addEventListener("click", close);
      form.addEventListener("keydown", function (e) { if (e.key === "Escape") close(); });
      form.addEventListener("submit", function (e) {
        e.preventDefault();
        var msg = form.querySelector(".post-form__msg");
        var btn = form.querySelector("[type=submit]");
        var payload = { action: "edit", post: Number(el.dataset.post), body: ta.value.trim() };
        if (isReview) payload.rating = Number((form.querySelector("[name=rating]:checked") || {}).value) || 0;
        btn.disabled = true;
        msg.className = "post-form__msg";
        msg.textContent = "Saving…";
        api(payload).then(function (d) {
          el.dataset.body = d.post.body;
          if (d.post.rating) el.dataset.rating = d.post.rating;
          close();
          if (opts.onSaved) opts.onSaved(el, d.post, d);
        }).catch(function (err) {
          msg.className = "post-form__msg is-error";
          msg.textContent = err.message;
          btn.disabled = false;
        });
      });
    }

    root.addEventListener("click", function (e) {
      var edit = e.target.closest("[data-post-edit]");
      var del = e.target.closest("[data-post-delete]");
      var el = (edit || del) && (edit || del).closest("[data-post]");
      if (!el) return;
      if (edit) {
        if (el.dataset.kind === "review" && opts.onEditReview && opts.onEditReview(el)) return;
        openEditor(el);
        return;
      }
      var what = el.dataset.kind === "review" ? "your review" : "this message";
      if (!window.confirm("Delete " + what + "? This can't be undone.")) return;
      del.disabled = true;
      api({ action: "delete", post: Number(el.dataset.post) }).then(function (d) {
        if (opts.onDeleted) opts.onDeleted(el, d);
        el.classList.add("is-removing");
        setTimeout(function () { el.remove(); }, 250);
      }).catch(function (err) {
        del.disabled = false;
        window.alert(err.message);
      });
    });
  };
})();
