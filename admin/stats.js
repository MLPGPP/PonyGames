/* Admin stats: hover a chart to see the numbers of that day (crosshair + tooltip). */
(function () {
  "use strict";
  var tip = document.getElementById("chart-tip");
  if (!tip) return;
  var NS = "http://www.w3.org/2000/svg";

  document.querySelectorAll(".chart__plot[data-chart]").forEach(function (plot) {
    var data = JSON.parse(plot.dataset.chart);
    var svg = plot.querySelector("svg");
    var x0 = +plot.dataset.x0, x1 = +plot.dataset.x1, W = +plot.dataset.w;
    var top = +plot.dataset.t, bottom = +plot.dataset.b, max = +plot.dataset.max;
    var n = data.labels.length;
    var xAt = function (i) { return n > 1 ? x0 + i * (x1 - x0) / (n - 1) : (x0 + x1) / 2; };
    var yAt = function (v) { return top + (bottom - top) * (1 - v / max); };

    var cross = document.createElementNS(NS, "line");
    cross.setAttribute("class", "chart__cross");
    cross.setAttribute("y1", top);
    cross.setAttribute("y2", bottom);
    var dots = data.series.map(function (s) {
      var c = document.createElementNS(NS, "circle");
      c.setAttribute("class", "chart__dot");
      c.setAttribute("r", 4.5);
      c.style.fill = "var(" + s.color + ")";
      return c;
    });
    var layer = document.createElementNS(NS, "g");
    layer.setAttribute("class", "chart__hover");
    layer.appendChild(cross);
    dots.forEach(function (d) { layer.appendChild(d); });
    svg.appendChild(layer);

    function show(e) {
      var box = svg.getBoundingClientRect();
      var sx = (e.clientX - box.left) * (W / box.width);
      var i = Math.round(n > 1 ? (sx - x0) / ((x1 - x0) / (n - 1)) : 0);
      i = Math.max(0, Math.min(n - 1, i));
      current = i;
      var x = xAt(i);
      cross.setAttribute("x1", x);
      cross.setAttribute("x2", x);
      data.series.forEach(function (s, k) {
        dots[k].setAttribute("cx", x);
        dots[k].setAttribute("cy", yAt(s.values[i]));
      });
      layer.classList.add("is-on");
      tip.innerHTML = "";
      var head = document.createElement("strong");
      head.textContent = data.labels[i];
      tip.appendChild(head);
      data.series.forEach(function (s) {
        var row = document.createElement("span");
        row.className = "chart-tip__row";
        var key = document.createElement("i");
        key.style.background = "var(" + s.color + ")";
        row.appendChild(key);
        row.appendChild(document.createTextNode(s.label + " "));
        var b = document.createElement("b");
        b.textContent = s.values[i].toLocaleString();
        row.appendChild(b);
        tip.appendChild(row);
      });
      if (data.days && data.days.length) {
        var hint = document.createElement("small");
        hint.textContent = "Click to see this day";
        tip.appendChild(hint);
      }
      tip.hidden = false;
      var px = box.left + x * (box.width / W);
      var left = px + 14;
      if (left + tip.offsetWidth > window.innerWidth - 8) left = px - 14 - tip.offsetWidth;
      tip.style.left = left + "px";
      tip.style.top = Math.max(8, e.clientY - tip.offsetHeight / 2) + "px";
    }
    function hide() {
      layer.classList.remove("is-on");
      tip.hidden = true;
    }
    // click a day to see just that day
    var current = -1;
    if (data.days && data.days.length) {
      plot.classList.add("is-clickable");
      plot.addEventListener("click", function () {
        if (current < 0 || !data.days[current]) return;
        var q = new URLSearchParams(location.search); // keeps the chosen game
        q.delete("days");
        q.set("day", data.days[current]);
        location.search = q.toString();
      });
    }
    plot.addEventListener("pointermove", show);
    plot.addEventListener("pointerleave", hide);
  });
})();

/* picking a date shows that day right away */
document.querySelectorAll("input[data-autosubmit]").forEach(function (input) {
  input.addEventListener("change", function () {
    if (input.value) input.form.submit();
  });
});

/* the search field above the games table: hides the rows that don't match */
document.querySelectorAll("input[data-table-search]").forEach(function (input) {
  var table = document.getElementById(input.dataset.tableSearch);
  if (!table) return;
  var rows = Array.prototype.slice.call(table.tBodies[0].rows);
  var box = input.closest("section");
  var count = box.querySelector("[data-search-count]");
  var empty = box.querySelector("[data-search-empty]");
  var norm = function (t) { return t.toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, ""); };
  var names = rows.map(function (r) { return norm(r.cells[0].textContent); });
  input.addEventListener("input", function () {
    var q = norm(input.value.trim());
    var shown = 0;
    rows.forEach(function (r, i) {
      var hit = !q || names[i].indexOf(q) !== -1;
      r.hidden = !hit;
      if (hit) shown++;
    });
    count.textContent = q ? shown + " of " + rows.length : "";
    empty.hidden = shown > 0;
    table.hidden = shown === 0;
  });
});
