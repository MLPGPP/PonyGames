/* Pictures part of the game form (admin/games.php). The editor itself is
   /js/shot-editor.js (shared with the public submit form). On save the order
   goes along with the form, and the server renames everything to 0 (cover), 1, 2... */
(function () {
  "use strict";
  var root = document.getElementById("shot-editor");
  if (!root || !window.PGShotEditor) return;

  var form = root.closest("form");
  var orderInput = document.getElementById("shots-order");
  var filesInput = document.getElementById("shots-files");

  var editor = window.PGShotEditor({
    grid: document.getElementById("shot-grid"),
    add: document.getElementById("shot-add"),
    tiles: JSON.parse(root.dataset.tiles || "[]"),
    onChange: function () {
      orderInput.value = "changed"; // replaced by the real order on submit, see below
      form.dispatchEvent(new Event("input", { bubbles: true })); // "unsaved changes" warning
    },
    onMessage: function (text) { if (text) window.alert(text); },
  });

  form.addEventListener("submit", function () {
    if (!editor.isChanged()) return; // pictures untouched: the server leaves them alone
    var r = editor.result();
    var dt = new DataTransfer();
    r.files.forEach(function (f) { dt.items.add(f); });
    filesInput.files = dt.files;
    orderInput.value = JSON.stringify(r.order.map(function (o) { return o.keep ? { keep: o.keep } : { file: o.file }; }));
  });
})();
