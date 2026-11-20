/* Account page: edit or delete your reviews and discussion messages (js/post-tools.js). */
(function () {
  "use strict";
  var box = document.getElementById("your-posts");
  if (!box || !window.PGPostTools) return;

  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }
  function bump(sel, delta) {
    var el = document.querySelector(sel);
    if (el) { var n = Math.max(0, (Number(el.textContent) || 0) + delta); el.textContent = n || (sel === "[data-my-total]" ? "0" : ""); }
  }

  window.PGPostTools(box, {
    bodySelector: ".my-post__body",
    onSaved: function (el, p) {
      el.querySelector(".my-post__body").innerHTML = esc(p.body).replace(/\n/g, "<br>");
      var stars = el.querySelector(".my-post__stars");
      if (stars && p.rating) {
        stars.setAttribute("aria-label", p.rating + " out of 5 stars");
        stars.innerHTML = "★".repeat(p.rating) + '<span class="is-off">' + "★".repeat(5 - p.rating) + "</span>";
      }
      var meta = el.querySelector(".my-post__meta");
      if (meta && meta.textContent.indexOf("edited") === -1) {
        var hidden = meta.querySelector(".my-post__hidden");
        var edited = document.createTextNode(" · edited");
        if (hidden) meta.insertBefore(edited, hidden.previousSibling || hidden); else meta.appendChild(edited);
      }
    },
    onDeleted: function (el, d) {
      var kind = d.kind === "review" ? "review" : "comment";
      bump('[data-my-count="' + kind + '"]', -1);
      bump("[data-my-total]", -1);
      setTimeout(function () {
        var col = document.querySelector('[data-my-count="' + kind + '"]').closest(".my-posts__col");
        if (!col.querySelector("[data-post]")) {
          var empty = col.querySelector("[data-my-empty]");
          if (empty) empty.hidden = false;
        }
      }, 300);
    },
  });
})();
