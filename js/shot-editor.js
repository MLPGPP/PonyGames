/* Picture editor used by the admin game form (admin/screenshots.js) and the
   public submit form (js/submit.js): drag to reorder, make cover, remove,
   crop and add pictures. It only keeps the list; the page decides what to
   send. Plain ES5 so it also runs on the admin pages without main.js.

   var ed = PGShotEditor({
     grid: <ol>,                 // where the tiles go
     add: <label> with <input type=file>,   // "add pictures" drop zone
     tiles: [{ path, managed }], // the pictures there are now (first = cover)
     maxNew: 12,                 // how many new/cropped pictures are allowed
     maxBytes: 5 * 1024 * 1024,  // per picked file
     onChange: function () {},
     onMessage: function (text) {},  // "too big", "too many"…
     labels: { shared: "shared picture" }
   });
   ed.isChanged()  -> true once something was moved, removed, cropped or added
   ed.result()     -> { order: [{keep: path} | {file: n, cropOf?: path}], files: [File] }
   ed.count()      -> pictures that will be kept
*/
(function () {
  "use strict";

  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }
  function each(list, fn) {
    Array.prototype.forEach.call(list, fn);
  }

  /* ---------- one crop dialog for the whole page ---------- */
  var crop = null;
  function cropDialog() {
    if (crop) return crop;
    var overlay = document.createElement("div");
    overlay.className = "crop-overlay";
    overlay.hidden = true;
    overlay.innerHTML =
      '<div class="crop-box" role="dialog" aria-modal="true" aria-label="Crop picture">' +
      '<div class="crop-tools"><strong>Crop</strong>' +
      '<span class="crop-ratios">' +
      '<button type="button" data-ratio="free" class="on">Free</button>' +
      '<button type="button" data-ratio="1.7778">16:9</button>' +
      '<button type="button" data-ratio="1.6">16:10</button>' +
      '<button type="button" data-ratio="1.3333">4:3</button>' +
      '<button type="button" data-ratio="1">1:1</button></span>' +
      '<span class="crop-size" data-size></span>' +
      '<span class="crop-actions"><button type="button" data-crop-cancel>Cancel</button> ' +
      '<button type="button" class="primary" data-crop-ok>Apply crop</button></span></div>' +
      '<div class="crop-stage"><div class="crop-inner"><img alt="">' +
      '<div class="crop-rect">' +
      '<span class="h nw" data-h="nw"></span><span class="h ne" data-h="ne"></span><span class="h sw" data-h="sw"></span><span class="h se" data-h="se"></span>' +
      '<span class="h n" data-h="n"></span><span class="h s" data-h="s"></span><span class="h w" data-h="w"></span><span class="h e" data-h="e"></span>' +
      "</div></div></div>" +
      '<p class="crop-help">Drag to move the frame, drag the corners to resize. The crop is saved as a JPG.</p></div>';
    document.body.appendChild(overlay);

    var img = overlay.querySelector("img");
    var rectEl = overlay.querySelector(".crop-rect");
    var sizeEl = overlay.querySelector("[data-size]");
    var ratio = null; // width / height, or null for free
    var box = { x: 0, y: 0, w: 0, h: 0 }; // in displayed pixels
    var scale = 1; // natural / displayed
    var done = null; // callback(blob)
    var lastFocus = null;

    function drawRect() {
      rectEl.style.left = box.x + "px";
      rectEl.style.top = box.y + "px";
      rectEl.style.width = box.w + "px";
      rectEl.style.height = box.h + "px";
      sizeEl.textContent = Math.round(box.w * scale) + " × " + Math.round(box.h * scale) + " px";
    }
    function fitRatio() {
      var W = img.clientWidth, H = img.clientHeight;
      if (!ratio) { box = { x: 0, y: 0, w: W, h: H }; return; }
      var w = W, h = W / ratio;
      if (h > H) { h = H; w = H * ratio; }
      box = { x: (W - w) / 2, y: (H - h) / 2, w: w, h: h };
    }
    function close() {
      overlay.hidden = true;
      done = null;
      if (lastFocus) lastFocus.focus();
    }
    overlay.querySelector("[data-crop-cancel]").addEventListener("click", close);
    overlay.addEventListener("keydown", function (e) { if (e.key === "Escape") close(); });
    overlay.querySelector(".crop-ratios").addEventListener("click", function (e) {
      var b = e.target.closest("[data-ratio]");
      if (!b) return;
      ratio = b.dataset.ratio === "free" ? null : Number(b.dataset.ratio);
      each(overlay.querySelectorAll("[data-ratio]"), function (x) { x.classList.toggle("on", x === b); });
      fitRatio();
      drawRect();
    });

    var drag = null;
    rectEl.addEventListener("pointerdown", function (e) {
      e.preventDefault();
      rectEl.setPointerCapture(e.pointerId);
      drag = { h: e.target.dataset.h || "move", x: e.clientX, y: e.clientY, start: { x: box.x, y: box.y, w: box.w, h: box.h } };
    });
    rectEl.addEventListener("pointermove", function (e) {
      if (!drag) return;
      var W = img.clientWidth, H = img.clientHeight, MIN = 20;
      var dx = e.clientX - drag.x, dy = e.clientY - drag.y, s = drag.start;
      var b = { x: s.x, y: s.y, w: s.w, h: s.h };
      if (drag.h === "move") {
        b.x = Math.min(Math.max(0, s.x + dx), W - s.w);
        b.y = Math.min(Math.max(0, s.y + dy), H - s.h);
      } else {
        var h = drag.h;
        if (h.indexOf("e") >= 0) b.w = Math.min(Math.max(MIN, s.w + dx), W - s.x);
        if (h.indexOf("s") >= 0) b.h = Math.min(Math.max(MIN, s.h + dy), H - s.y);
        if (h.indexOf("w") >= 0) { var nx = Math.min(Math.max(0, s.x + dx), s.x + s.w - MIN); b.w = s.w + (s.x - nx); b.x = nx; }
        if (h.indexOf("n") >= 0) { var ny = Math.min(Math.max(0, s.y + dy), s.y + s.h - MIN); b.h = s.h + (s.y - ny); b.y = ny; }
        if (ratio) {
          // keep the ratio: follow the width, unless only the height was dragged
          if (h === "n" || h === "s") b.w = b.h * ratio; else b.h = b.w / ratio;
          if (b.x + b.w > W) { b.w = W - b.x; b.h = b.w / ratio; }
          if (b.y + b.h > H) { b.h = H - b.y; b.w = b.h * ratio; }
          if (h.indexOf("n") >= 0) b.y = s.y + s.h - b.h;
          if (h.indexOf("w") >= 0) b.x = s.x + s.w - b.w;
        }
      }
      box = b;
      drawRect();
    });
    rectEl.addEventListener("pointerup", function () { drag = null; });

    overlay.querySelector("[data-crop-ok]").addEventListener("click", function () {
      var sx = Math.round(box.x * scale), sy = Math.round(box.y * scale);
      var sw = Math.round(box.w * scale), sh = Math.round(box.h * scale);
      var canvas = document.createElement("canvas");
      canvas.width = sw;
      canvas.height = sh;
      var ctx = canvas.getContext("2d");
      ctx.fillStyle = "#fff"; // transparent PNGs get a white background in the JPG
      ctx.fillRect(0, 0, sw, sh);
      ctx.drawImage(img, sx, sy, sw, sh, 0, 0, sw, sh);
      var cb = done;
      canvas.toBlob(function (blob) {
        if (!blob) { window.alert("Cropping didn't work for this picture."); return; }
        close();
        if (cb) cb(blob);
      }, "image/jpeg", 0.92);
    });

    crop = {
      open: function (url, cb) {
        lastFocus = document.activeElement;
        done = cb;
        ratio = null;
        each(overlay.querySelectorAll("[data-ratio]"), function (b) { b.classList.toggle("on", b.dataset.ratio === "free"); });
        overlay.hidden = false;
        img.onload = function () {
          scale = img.naturalWidth / img.clientWidth;
          fitRatio();
          drawRect();
        };
        img.src = url;
        if (img.complete && img.naturalWidth) img.onload();
        overlay.querySelector("[data-crop-ok]").focus();
      },
    };
    return crop;
  }

  /* ---------- the editor ---------- */
  window.PGShotEditor = function (opts) {
    var grid = opts.grid;
    var add = opts.add;
    var labels = opts.labels || {};
    var maxNew = opts.maxNew || Infinity;
    var maxBytes = opts.maxBytes || Infinity;
    var onChange = opts.onChange || function () {};
    var say = opts.onMessage || function () {};
    var isChanged = false;
    var tiles = (opts.tiles || []).map(function (t) {
      return { path: t.path, managed: t.managed, url: t.path, blob: null, removed: false, name: t.path.split("/").pop() };
    });

    function newCount() {
      return tiles.filter(function (t) { return t.blob && !t.removed; }).length;
    }
    function changed() {
      isChanged = true;
      render();
      onChange();
    }

    function render() {
      var n = 0;
      grid.innerHTML = tiles
        .map(function (t, i) {
          var label = t.removed ? "removed" : n++ === 0 ? "cover" : String(n - 1);
          var note = t.blob ? (t.path ? "cropped" : "new") : t.managed ? (labels.managed ? labels.managed(t) : t.name) : labels.shared || "shared picture";
          return (
            '<li class="shot' + (t.removed ? " is-removed" : "") + (label === "cover" ? " is-cover" : "") +
            '" draggable="true" tabindex="0" data-i="' + i + '" aria-label="Picture ' + (i + 1) + ", " + label + '">' +
            '<span class="shot__num">' + (label === "cover" ? "★ cover" : label) + "</span>" +
            '<img src="' + esc(t.url) + '" alt="" draggable="false" loading="lazy" data-size>' +
            '<span class="shot__meta"><span>' + esc(note) + '</span><span class="shot__dim"></span></span>' +
            '<span class="shot__acts">' +
            '<button type="button" data-move="-1" title="Move left (←)" aria-label="Move left">←</button>' +
            '<button type="button" data-move="1" title="Move right (→)" aria-label="Move right">→</button>' +
            '<button type="button" data-cover title="Make this the cover" aria-label="Make cover">★</button>' +
            '<button type="button" data-crop title="Crop" aria-label="Crop"' + (t.removed ? " disabled" : "") + ">✂</button>" +
            '<button type="button" data-remove title="' + (t.removed ? "Keep after all" : "Remove") + '" aria-label="' + (t.removed ? "Keep after all" : "Remove") + '">' + (t.removed ? "↺" : "🗑") + "</button>" +
            "</span></li>"
          );
        })
        .join("");
      // show the size of each picture once it's loaded
      each(grid.querySelectorAll("img[data-size]"), function (img) {
        function show() {
          var dim = img.parentElement.querySelector(".shot__dim");
          if (dim && img.naturalWidth) dim.textContent = img.naturalWidth + "×" + img.naturalHeight;
        }
        if (img.complete) show();
        else img.addEventListener("load", show);
      });
    }

    function move(from, to) {
      if (to < 0 || to >= tiles.length || from === to) return;
      var t = tiles.splice(from, 1)[0];
      tiles.splice(to, 0, t);
      changed();
      var el = grid.querySelector('[data-i="' + to + '"]');
      if (el) el.focus();
    }
    function toggleRemove(i) {
      var t = tiles[i];
      if (t.removed && t.blob && newCount() >= maxNew) { say("You can add up to " + maxNew + " new or cropped pictures."); return; }
      t.removed = !t.removed;
      changed();
    }
    function openCrop(i) {
      var t = tiles[i];
      if (!t.blob && newCount() >= maxNew) { say("You can add up to " + maxNew + " new or cropped pictures."); return; }
      cropDialog().open(t.url, function (blob) {
        t.blob = blob;
        t.url = URL.createObjectURL(blob);
        changed();
        var el = grid.querySelector('[data-i="' + i + '"]');
        if (el) el.focus();
      });
    }

    grid.addEventListener("click", function (e) {
      var li = e.target.closest(".shot");
      var b = e.target.closest("button");
      if (!li || !b) return;
      var i = Number(li.dataset.i);
      if (b.dataset.move) move(i, i + Number(b.dataset.move));
      else if (b.hasAttribute("data-cover")) move(i, 0);
      else if (b.hasAttribute("data-remove")) toggleRemove(i);
      else if (b.hasAttribute("data-crop")) openCrop(i);
    });
    grid.addEventListener("keydown", function (e) {
      var li = e.target.closest(".shot");
      if (!li || e.target !== li) return;
      var i = Number(li.dataset.i);
      if (e.key === "ArrowLeft") { e.preventDefault(); move(i, i - 1); }
      else if (e.key === "ArrowRight") { e.preventDefault(); move(i, i + 1); }
      else if (e.key === "Delete" || e.key === "Backspace") { e.preventDefault(); toggleRemove(i); }
    });

    /* drag & drop to reorder */
    var dragFrom = null;
    grid.addEventListener("dragstart", function (e) {
      var li = e.target.closest(".shot");
      if (!li) return;
      dragFrom = Number(li.dataset.i);
      li.classList.add("is-dragging");
      e.dataTransfer.effectAllowed = "move";
      e.dataTransfer.setData("text/plain", String(dragFrom));
    });
    grid.addEventListener("dragend", function () {
      dragFrom = null;
      each(grid.querySelectorAll(".is-dragging,.drop-before,.drop-after"), function (el) {
        el.classList.remove("is-dragging", "drop-before", "drop-after");
      });
    });
    function dropTarget(e) {
      var li = e.target.closest(".shot");
      if (!li) return null;
      var r = li.getBoundingClientRect();
      return { li: li, i: Number(li.dataset.i), after: e.clientX > r.left + r.width / 2 };
    }
    grid.addEventListener("dragover", function (e) {
      if (dragFrom === null) return;
      var t = dropTarget(e);
      if (!t) return;
      e.preventDefault();
      each(grid.querySelectorAll(".drop-before,.drop-after"), function (el) { el.classList.remove("drop-before", "drop-after"); });
      t.li.classList.add(t.after ? "drop-after" : "drop-before");
    });
    grid.addEventListener("drop", function (e) {
      if (dragFrom === null) return;
      var t = dropTarget(e);
      if (!t) return;
      e.preventDefault();
      var to = t.i + (t.after ? 1 : 0);
      if (to > dragFrom) to--;
      move(dragFrom, to);
    });

    /* add pictures */
    var input = add.querySelector("input[type=file]");
    function addFiles(files) {
      var note = "", added = 0;
      each(files, function (f) {
        if (!/^image\/(png|jpeg|webp|gif)$/.test(f.type)) { note = "Pictures have to be JPG, PNG, WebP or GIF."; return; }
        if (f.size > maxBytes) { note = f.name + " is bigger than " + Math.round(maxBytes / 1048576) + " MB."; return; }
        if (newCount() >= maxNew) { note = "You can add up to " + maxNew + " new or cropped pictures."; return; }
        tiles.push({ path: null, managed: true, url: URL.createObjectURL(f), blob: f, removed: false, name: f.name });
        added++;
      });
      if (added) changed();
      say(note);
    }
    input.addEventListener("change", function () {
      addFiles(input.files);
      input.value = "";
    });
    ["dragenter", "dragover"].forEach(function (ev) {
      add.addEventListener(ev, function (e) {
        if (dragFrom !== null) return;
        e.preventDefault();
        add.classList.add("is-over");
      });
    });
    ["dragleave", "drop"].forEach(function (ev) {
      add.addEventListener(ev, function () { add.classList.remove("is-over"); });
    });
    add.addEventListener("drop", function (e) {
      if (dragFrom !== null) return;
      e.preventDefault();
      if (e.dataTransfer.files.length) addFiles(e.dataTransfer.files);
    });

    render();

    return {
      isChanged: function () { return isChanged; },
      count: function () { return tiles.filter(function (t) { return !t.removed; }).length; },
      result: function () {
        var files = [];
        var order = tiles
          .filter(function (t) { return !t.removed; })
          .map(function (t) {
            if (t.blob) {
              var type = t.blob.type || "image/jpeg";
              var ext = type === "image/png" ? ".png" : type === "image/webp" ? ".webp" : type === "image/gif" ? ".gif" : ".jpg";
              files.push(new File([t.blob], (t.name || "picture").replace(/\.[a-z0-9]+$/i, "") + ext, { type: type }));
              var item = { file: files.length - 1 };
              if (t.path) item.cropOf = t.path;
              return item;
            }
            return { keep: t.path };
          });
        return { order: order, files: files };
      },
    };
  };
})();
