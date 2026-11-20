/* Admin editor: add/remove rows (tags, downloads), warn before leaving with unsaved changes. */
(function () {
  "use strict";
  document.addEventListener("click", function (e) {
    var add = e.target.closest("[data-add-row]");
    if (add) {
      var kind = add.dataset.addRow;
      var tpl = document.querySelector('template[data-template="' + kind + '"]');
      var box = document.querySelector('[data-rows="' + kind + '"]');
      if (tpl && box) {
        var row = tpl.content.firstElementChild.cloneNode(true);
        box.appendChild(row);
        var first = row.querySelector("input,select,textarea");
        if (first) first.focus();
        dirty = true;
      }
      return;
    }
    var rm = e.target.closest("[data-remove-row]");
    if (rm) {
      rm.parentElement.remove();
      dirty = true;
    }
  });

  var dirty = false;
  var editor = document.querySelector("form.editor");
  if (editor) {
    editor.addEventListener("input", function () { dirty = true; });
    editor.addEventListener("submit", function () { dirty = false; });
    window.addEventListener("beforeunload", function (e) {
      if (dirty) { e.preventDefault(); e.returnValue = ""; }
    });
  }
})();

/* buttons that ask first */
document.addEventListener("click", function (e) {
  var b = e.target.closest("[data-confirm]");
  if (b && !window.confirm(b.dataset.confirm)) e.preventDefault();
});

/* Share links (links.php): copy a link, select it on click, confirm before deleting. */
(function () {
  "use strict";
  document.addEventListener("click", function (e) {
    var btn = e.target.closest("[data-copy]");
    if (btn) {
      var text = btn.getAttribute("data-copy");
      var done = function () { var t = btn.textContent; btn.textContent = "Copied!"; setTimeout(function () { btn.textContent = t; }, 1500); };
      if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(text).then(done, function () {});
      else { var inp = btn.parentElement.querySelector("input"); if (inp) { inp.select(); document.execCommand("copy"); done(); } }
      return;
    }
    var sel = e.target.closest("[data-select-all]");
    if (sel) sel.select();
  });
  document.addEventListener("submit", function (e) {
    var f = e.target.closest("form[data-confirm]");
    if (f && !window.confirm(f.getAttribute("data-confirm"))) e.preventDefault();
  });
})();

