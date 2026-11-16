/* Asset requests page (/requests): the "Request an asset" form opens as a
   popup (also for /requests#ask links, and again with the message when a
   request couldn't be sent), and some buttons ask before they do something. */
(function () {
  "use strict";

  document.addEventListener("submit", (e) => {
    const btn = e.submitter || e.target.querySelector("[data-confirm]");
    const text = btn && btn.dataset ? btn.dataset.confirm : "";
    if (text && !window.confirm(text)) e.preventDefault();
  });

  const dialog = document.getElementById("ask");
  if (!dialog) return;
  let opener = null;

  function open(from) {
    opener = from || null;
    if (typeof dialog.showModal === "function") {
      if (!dialog.open) dialog.showModal();
    } else dialog.setAttribute("open", "");
    const first = dialog.querySelector(".form-alert--error") ? null : dialog.querySelector("input[name=title]");
    first?.focus();
  }
  function close() {
    if (typeof dialog.close === "function") dialog.close();
    else dialog.removeAttribute("open");
  }

  dialog.addEventListener("close", () => {
    if (location.hash === "#ask") history.replaceState(null, "", location.pathname + location.search);
    opener?.focus();
  });
  dialog.addEventListener("click", (e) => {
    if (e.target === dialog) close(); // click on the dark backdrop
  });
  dialog.querySelector("[data-close]")?.addEventListener("click", close);

  document.addEventListener("click", (e) => {
    const btn = e.target.closest("[data-ask], a[href='#ask'], a[href='/requests#ask']");
    if (!btn) return;
    e.preventDefault();
    open(btn);
  });
  window.addEventListener("hashchange", () => {
    if (location.hash === "#ask") open();
  });
  if (location.hash === "#ask" || dialog.hasAttribute("data-open")) open();
})();
