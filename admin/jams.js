/* admin/jams.php: jam history rows (add, move, remove, picture preview) and
   the time zone hint under the timer dates. */
(function () {
  "use strict";
  var rows = document.getElementById("jam-rows");
  if (rows) {
    var blank = rows.querySelector(".jam-row--new");
    var template = blank ? blank.cloneNode(true) : null;
    function changed() { rows.closest("form").dispatchEvent(new Event("input", { bubbles: true })); }
    rows.addEventListener("click", function (e) {
      var b = e.target.closest("button");
      var row = b && b.closest(".jam-row");
      if (!row) return;
      if (b.hasAttribute("data-remove")) {
        if (rows.children.length > 1) row.remove();
        else row.querySelectorAll("input").forEach(function (i) { i.value = ""; });
        changed();
      } else if (b.dataset.move) {
        var other = b.dataset.move === "-1" ? row.previousElementSibling : row.nextElementSibling;
        if (!other) return;
        if (b.dataset.move === "-1") rows.insertBefore(row, other); else rows.insertBefore(other, row);
        b.focus();
        changed();
      }
    });
    rows.addEventListener("input", function (e) {
      if (!e.target.matches("[data-preview]")) return;
      var img = e.target.closest(".jam-row").querySelector(".jam-row__img");
      var v = e.target.value.trim();
      img.hidden = !v;
      if (v) img.src = v;
    });
    var add = document.getElementById("jam-add");
    if (add && template) {
      add.addEventListener("click", function () {
        var row = template.cloneNode(true);
        row.querySelectorAll("input").forEach(function (i) { i.value = ""; });
        row.querySelector("[name='jam_name[]']").placeholder = "Name";
        rows.insertBefore(row, rows.firstElementChild);
        row.querySelector("input").focus();
      });
    }
  }

  // "2026-11-14T02:00" in UTC -> the same moment in the admin's own time zone
  document.querySelectorAll("input[data-utc]").forEach(function (input) {
    var hint = document.createElement("span");
    hint.className = "muted utc-hint";
    input.insertAdjacentElement("afterend", hint);
    function show() {
      if (!input.value) { hint.textContent = ""; return; }
      var d = new Date(input.value + ":00Z");
      hint.textContent = isNaN(d) ? "" : "= " + d.toLocaleString(undefined, { weekday: "short", day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" }) + " your time";
    }
    input.addEventListener("input", show);
    show();
  });
})();
