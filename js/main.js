const POSTS_PER_PAGE = 10;
const TAG_PRIORITY = { warning: 0, black: 1, genre: 2, other: 3 };

// Data has mixed casing ("Genre", "Black Tags"); keep the keys lowercase
// to match TAG_PRIORITY and the .tag--* classes.
function normalizeTagType(type) {
  const t = String(type || "").toLowerCase().trim();
  if (t.startsWith("black")) return "black";
  if (t === "warning" || t === "genre") return t;
  return "other";
}

// URL of a game's own page. Same rules as slugify() in inc/bootstrap.php.
function gameSlug(title) {
  let s = String(title || "").normalize("NFKD").replace(/\p{Mn}+/gu, "").toLowerCase();
  s = s.replace(/[^a-z0-9]+/g, "-").replace(/^-+|-+$/g, "");
  if (s.length > 60) s = s.slice(0, 60).replace(/-+$/, "");
  return s;
}
function gamePath(game) {
  const slug = gameSlug(game.title);
  return `/game/${encodeURIComponent(game.id)}${slug ? "-" + slug : ""}`;
}

// Bookmarks belong to the account; this is the copy for the logged-in member
// (filled by initAccount, set on the game pages). Guests have none.
const BOOKMARK_KEY = "pg-bookmarks";
// bookmarks made before they needed an account, added to it at the next log in
const BOOKMARK_GUEST_KEY = "pg-bookmarks-guest";
function readBookmarks() {
  try {
    const v = JSON.parse(localStorage.getItem(BOOKMARK_KEY) || "[]");
    return Array.isArray(v) ? v.map(String) : [];
  } catch {
    return [];
  }
}

// Sites whose game player is shown inside our game page (same list as game.php)
const EMBED_PLAY_HOSTS = ["flash.mare.by"];
function embeddablePlayUrl(url) {
  try {
    const u = new URL(url);
    return u.protocol === "https:" && EMBED_PLAY_HOSTS.includes(u.hostname.toLowerCase());
  } catch {
    return false;
  }
}
// phones and tablets open Flash games in their own tab instead
function canEmbedPlayer() {
  return !(window.matchMedia && matchMedia("(pointer: coarse)").matches && !matchMedia("(any-pointer: fine)").matches);
}

// Download/source entry classification (see openPanel downloads rendering).
const DOWNLOAD_BUTTON_TEXT = { source: "Author Site", web: "Play", download: "Download" };
const DOWNLOAD_TYPE_ORDER = { source: 0, web: 1, download: 2 };

function classifyDownloadEntry(d) {
  if (d.type && DOWNLOAD_BUTTON_TEXT[d.type]) return d.type;
  const text = `${d.label || ""} ${d.url || ""}`.toLowerCase();
  const looksLikeFile = /\.(zip|exe|apk|rar|7z|dmg|tar\.gz)(\?|$)/.test(text);
  if (!looksLikeFile && /steam|itch\.io\/?(\s|$)|author|website|homepage|source/.test(text)) return "source";
  if (/web|browser|html5/.test(text)) return "web";
  return "download";
}

/* ==========================================================================
   Follow links for upcoming games (dev page, Patreon, socials)
   ========================================================================== */
const ICON_PATHS = {
  patreon: "M0 .48v23.04h4.22V.48zm15.385 0c-4.764 0-8.641 3.88-8.641 8.65 0 4.755 3.877 8.623 8.641 8.623 4.75 0 8.615-3.868 8.615-8.623C24 4.36 20.136.48 15.385.48z",
  twitter: "M23.953 4.57a10 10 0 01-2.825.775 4.958 4.958 0 002.163-2.723c-.951.555-2.005.959-3.127 1.184a4.92 4.92 0 00-8.384 4.482C7.69 8.095 4.067 6.13 1.64 3.162a4.822 4.822 0 00-.666 2.475c0 1.71.87 3.213 2.188 4.096a4.904 4.904 0 01-2.228-.616v.06a4.923 4.923 0 003.946 4.827 4.996 4.996 0 01-2.212.085 4.936 4.936 0 004.604 3.417 9.867 9.867 0 01-6.102 2.105c-.39 0-.779-.023-1.17-.067a13.995 13.995 0 007.557 2.209c9.053 0 13.998-7.496 13.998-13.985 0-.21 0-.42-.015-.63A9.935 9.935 0 0024 4.59z",
  youtube: "M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z",
};

// Which site a link points to: label for the button + optional brand icon
const LINK_SITES = [
  [/patreon\.com/, "Patreon", "patreon"],
  [/(^|\.)(twitter|x)\.com/, "Twitter", "twitter"],
  [/youtube\.com|youtu\.be/, "YouTube", "youtube"],
  [/discord\.(gg|com)/, "Discord", null],
  [/store\.steampowered\.com/, "Steam", null],
  [/itch\.io/, "itch.io", null],
  [/gamejolt\.com/, "Game Jolt", null],
  [/ko-fi\.com/, "Ko-fi", null],
  [/subscribestar/, "SubscribeStar", null],
  [/bsky\.app/, "Bluesky", null],
  [/pone\.social|mastodon/, "Mastodon", null],
  [/deviantart\.com/, "DeviantArt", null],
  [/tumblr\.com/, "Tumblr", null],
  [/equestriadaily\.com/, "Equestria Daily", null],
  [/roblox\.com/, "Roblox", null],
  [/web\.archive\.org/, "Archived page", null],
];

function describeLink(url) {
  let host = "";
  try {
    host = new URL(url).hostname.replace(/^www\./, "");
  } catch (e) {
    return { name: "Website", icon: null };
  }
  const hit = LINK_SITES.find(([re]) => re.test(host));
  return hit ? { name: hit[1], icon: hit[2] } : { name: host, icon: null };
}

function linkIcon(icon) {
  if (icon && ICON_PATHS[icon]) {
    return `<svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="${ICON_PATHS[icon]}"/></svg>`;
  }
  return `<svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 14L21 3M15 3h6v6M21 14v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5"/></svg>`;
}

// Panel block for an upcoming game: no downloads, but where to follow it.
// item.url = the dev page; optional item.socials = [{ url, label? }, ...]
function renderFollowLinks(item) {
  const links = [];
  if (item.url) {
    const site = describeLink(item.url);
    const text =
      site.name === "Steam" ? "Wishlist on Steam" : site.icon ? `Follow on ${site.name}` : `Dev page · ${site.name}`;
    links.push({ url: item.url, text, icon: site.icon, main: true });
  }
  (item.socials || []).forEach((sc) => {
    if (!sc || !sc.url || sc.url === item.url) return;
    const site = describeLink(sc.url);
    links.push({ url: sc.url, text: sc.label || site.name, icon: site.icon, main: false });
  });
  if (!links.length) return "";
  return `
    <div class="follow-links">
      <p class="follow-links__title">Not out yet. Follow the development:</p>
      <div class="follow-links__list">
        ${links
          .map(
            (l) => `<a class="follow-link${l.main ? " follow-link--main" : ""}" href="${escapeHtml(l.url)}" target="_blank" rel="noopener noreferrer">${linkIcon(l.icon)}<span>${escapeHtml(l.text)}</span></a>`
          )
          .join("")}
      </div>
    </div>`;
}

/* ==========================================================================
   Utility
   ========================================================================== */
async function fetchJSON(url) {
  const response = await fetch(url);
  if (!response.ok) throw new Error(`Failed to load ${url}`);
  return response.json();
}

function normalizeListData(data, key) {
  if (Array.isArray(data)) return data;
  if (data && Array.isArray(data[key])) return data[key];
  return [];
}

function formatDate(dateString) {
  // timeZone: UTC matters. "YYYY-MM-DD" parses as UTC midnight, so local
  // rendering shows the previous day for anyone west of Greenwich.
  return new Date(dateString).toLocaleDateString("en-US", {
    year: "numeric",
    month: "long",
    day: "numeric",
    timeZone: "UTC",
  });
}

// releaseDate comes in three precisions: "YYYY-MM-DD", bare "YYYY", or
// empty/missing when unknown. Returns null when there's nothing to show.
function formatReleaseDate(releaseDate) {
  if (!releaseDate) return null;
  const value = String(releaseDate).trim();
  if (!value) return null;

  if (/^\d{4}$/.test(value)) return value;

  if (/^\d{4}-\d{2}-\d{2}$/.test(value)) {
    const date = new Date(value);
    if (!Number.isNaN(date.getTime())) return formatDate(value);
  }

  console.warn(`releaseDate "${releaseDate}" is not YYYY-MM-DD or YYYY, hiding it`);
  return null;
}

// Comparable timestamp for sorting: full dates use their exact time,
// year-only uses Jan 1 of that year, unknown returns null so callers can
// sort those to the end instead of treating them as the oldest/newest.
function releaseDateValue(releaseDate) {
  const value = String(releaseDate || "").trim();
  if (/^\d{4}-\d{2}-\d{2}$/.test(value)) {
    const t = Date.parse(`${value}T00:00:00Z`);
    return Number.isNaN(t) ? null : t;
  }
  if (/^\d{4}$/.test(value)) return Date.UTC(Number(value), 0, 1);
  return null;
}

// year bucket for the "Published" filter chips, "Unknown" if unset/unparseable
function releaseYearOf(item) {
  const value = String(item.releaseDate || "").trim();
  if (/^\d{4}-\d{2}-\d{2}$/.test(value)) return value.slice(0, 4);
  if (/^\d{4}$/.test(value)) return value;
  return "Unknown";
}

// direction 1 = oldest first, -1 = newest first. Unknown release dates
// always sort last regardless of direction, so reversing for "newest"
// can't be done by just swapping arguments (that would also flip which
// end the unknowns land on) — direction only flips the known-vs-known
// comparison.
function compareReleaseDates(a, b, direction) {
  const av = releaseDateValue(a.releaseDate);
  const bv = releaseDateValue(b.releaseDate);
  if (av === null && bv === null) return 0;
  if (av === null) return 1;
  if (bv === null) return -1;
  return (av - bv) * direction;
}

function formatRelativeDate(dateString) {
  const date = new Date(dateString);
  const now = new Date();
  const diffMs = now - date;
  const diffSec = Math.floor(diffMs / 1000);
  const diffMin = Math.floor(diffSec / 60);
  const diffHour = Math.floor(diffMin / 60);
  const diffDay = Math.floor(diffHour / 24);
  const diffWeek = Math.floor(diffDay / 7);
  const diffMonth = Math.floor(diffDay / 30);
  const diffYear = Math.floor(diffDay / 365);

  if (diffSec < 60) return "just now";
  if (diffMin < 60) return `${diffMin} minute${diffMin === 1 ? "" : "s"} ago`;
  if (diffHour < 24) return `${diffHour} hour${diffHour === 1 ? "" : "s"} ago`;
  if (diffDay < 7) return `${diffDay} day${diffDay === 1 ? "" : "s"} ago`;
  if (diffWeek < 5) return `${diffWeek} week${diffWeek === 1 ? "" : "s"} ago`;
  if (diffMonth < 12) return `${diffMonth} month${diffMonth === 1 ? "" : "s"} ago`;
  if (diffYear >= 1) return `${diffYear} year${diffYear === 1 ? "" : "s"} ago`;
  return formatDate(dateString);
}

function sortTagsByPriority(tags) {
  return [...tags].sort((a, b) => {
    const typeA = typeof a === "string" ? "other" : normalizeTagType(a.type);
    const typeB = typeof b === "string" ? "other" : normalizeTagType(b.type);
    return (TAG_PRIORITY[typeA] ?? 99) - (TAG_PRIORITY[typeB] ?? 99);
  });
}

function renderGameTag(tag) {
  if (typeof tag === "string") {
    return `<span class="tag">${tag}</span>`;
  }
  return `<span class="tag tag--${normalizeTagType(tag.type)}">${tag.label}</span>`;
}

function createTagElements(tags) {
  return sortTagsByPriority(tags).map(renderGameTag).join("");
}

function pickRandomItems(array, count) {
  const pool = [...array];
  const picked = [];
  while (pool.length > 0 && picked.length < count) {
    const index = Math.floor(Math.random() * pool.length);
    picked.push(pool.splice(index, 1)[0]);
  }
  return picked;
}

// author is a plain string in old entries, an array in newer ones
function getAuthors(item) {
  if (Array.isArray(item.author)) return item.author.filter(Boolean);
  if (item.author) return [item.author];
  return [];
}

function renderAuthorLinks(item, className) {
  return getAuthors(item)
    .map((a) => `<button type="button" class="${className}" data-author="${a}">${a}</button>`)
    .join(", ");
}

function getLicenses(item) {
  if (Array.isArray(item.license)) return item.license.filter(Boolean);
  return [];
}

function renderLicenseLinks(item, className) {
  return getLicenses(item)
    .map((l) => `<a class="${className}" href="${l.url}">${l.name}</a>`)
    .join(", ");
}

function isNsfwItem(item) {
  return (item.tags || []).some(
    (t) => t && typeof t === "object" && normalizeTagType(t.type) === "warning"
  );
}

// lowercase labels of every warning-type tag on the item, e.g. ["nsfw", "gore"]
function warningLabelsOf(item) {
  return (item.tags || [])
    .filter((t) => t && typeof t === "object" && normalizeTagType(t.type) === "warning")
    .map((t) => String(t.label || "").toLowerCase());
}

// true only if every warning label the item carries is toggled visible.
// A game tagged both nsfw and gore needs both toggles on. Unrecognized
// warning labels default to gated-by-showNsfw, the safer side.
function warningsSatisfied(item, showNsfw, showGore) {
  return warningLabelsOf(item).every((label) => (label === "gore" ? showGore : showNsfw));
}

function getPlaytimeLevel(playtime) {
  if (!playtime || playtime === "N/A") return 0;
  const lower = playtime.toLowerCase();
  const hoursMatch = lower.match(/(\d+)/);
  if (lower.includes("hour")) {
    const h = hoursMatch ? parseInt(hoursMatch[1], 10) : 1;
    return h >= 3 ? 4 : 3;
  }
  if (lower.includes("90") || lower.includes("60") || lower.includes("45")) return 2;
  return 1;
}

function animateCount(el, target, noun = "game") {
  if (!el) return;
  const start = parseInt(el.dataset.count, 10) || 0;
  if (start === target) {
    el.textContent = `${target} ${noun}${target === 1 ? "" : "s"}`;
    return;
  }

  const duration = 350;
  const startTime = performance.now();

  function frame(now) {
    const t = Math.min(1, (now - startTime) / duration);
    const eased = 1 - (1 - t) ** 3;
    const current = Math.round(start + (target - start) * eased);
    el.textContent = `${current} ${noun}${current === 1 ? "" : "s"}`;
    if (t < 1) {
      requestAnimationFrame(frame);
    } else {
      el.dataset.count = String(target);
      el.textContent = `${target} ${noun}${target === 1 ? "" : "s"}`;
    }
  }

  requestAnimationFrame(frame);
}

function renderSkeletonCards(count = 6) {
  return Array.from({ length: count }, () => `
    <div class="skeleton-card" aria-hidden="true">
      <div class="skeleton skeleton--thumb"></div>
      <div class="skeleton skeleton--line skeleton--title"></div>
      <div class="skeleton skeleton--line skeleton--short"></div>
      <div class="skeleton skeleton--line skeleton--medium"></div>
    </div>
  `).join("");
}

function renderSkeletonPosts(count = 3) {
  return Array.from({ length: count }, () => `
    <div class="skeleton-post" aria-hidden="true">
      <div class="skeleton skeleton--line skeleton--short"></div>
      <div class="skeleton skeleton--line skeleton--title"></div>
      <div class="skeleton skeleton--line"></div>
      <div class="skeleton skeleton--line skeleton--medium"></div>
    </div>
  `).join("");
}

function renderSkeletonJamHistory(count = 4) {
  return Array.from({ length: count }, () => `
    <div class="skeleton-jam" aria-hidden="true">
      <div class="skeleton skeleton--jam-img"></div>
      <div class="skeleton skeleton--line skeleton--short"></div>
    </div>
  `).join("");
}

function renderSkeletonSidebar(count = 3) {
  return Array.from({ length: count }, () => `
    <div class="skeleton-sidebar-item" aria-hidden="true">
      <div class="skeleton skeleton--avatar"></div>
      <div class="skeleton-sidebar-item__lines">
        <div class="skeleton skeleton--line skeleton--short"></div>
        <div class="skeleton skeleton--line skeleton--medium"></div>
      </div>
    </div>
  `).join("");
}

function staggerCards(grid) {
  const cards = grid.querySelectorAll(".card");
  // cap the stagger so big grids don't spend seconds animating in
  const MAX_STAGGER_MS = 450;
  cards.forEach((card, i) => {
    card.classList.add("card--enter");
    card.style.animationDelay = `${Math.min(i * 45, MAX_STAGGER_MS)}ms`;
  });
}

/* ==========================================================================
   Global UI polish
   ========================================================================== */
function initAuthorSearch() {
  document.addEventListener("click", (e) => {
    const btn = e.target.closest("[data-author]");
    if (!btn) return;
    e.stopPropagation();
    const author = btn.dataset.author;
    const searchInput = document.getElementById("filter-search") || document.getElementById("resources-search");
    if (searchInput) {
      // We're already on the games page
      searchInput.value = author;
      searchInput.dispatchEvent(new Event("input", { bubbles: true }));
      searchInput.scrollIntoView({ behavior: "smooth", block: "center" });
    } else {
      // Navigate to games page with search param
      window.location.href = `/games?search=${encodeURIComponent(author)}`;
    }
  });
}

// background and accent of each theme, for the little swatches in the theme menu
const THEME_SWATCHES = {
  midnight: ["#1a1a2e", "#e94560"],
  light: ["#f5f2fa", "#d6336c"],
  trixie: ["#1a1a3a", "#4fc3f7"],
  twilight: ["#2a1a3a", "#ffd54f"],
  applejack: ["#3a2a1a", "#ffb74d"],
  pinkie: ["#f8e0e0", "#e91e63"],
  rarity: ["#f5f0f0", "#7e57c2"],
  rainbow: ["#1a2a4a", "#ffeb3b"],
  fluttershy: ["#fef9e0", "#f48fb1"],
};

/* The theme <select> in the header becomes a small palette button with a menu.
   The select stays (hidden) as the source of the theme names, and works on its own without JS. */
function initThemeSwitcher() {
  const select = document.getElementById("theme-switcher");
  if (!select) return;

  // the inline head script already applied a saved theme before paint
  const current = () => document.documentElement.dataset.theme || "midnight";
  select.value = current();

  function apply(theme) {
    if (theme === "midnight") delete document.documentElement.dataset.theme;
    else document.documentElement.dataset.theme = theme;
    select.value = theme;
    try {
      localStorage.setItem("pg-theme", theme);
    } catch (e) {}
  }
  select.addEventListener("change", () => apply(select.value));

  const options = Array.from(select.options);
  const nameOf = (v) => options.find((o) => o.value === v)?.textContent.trim() || v;
  const wrap = document.createElement("div");
  wrap.className = "theme-picker";
  wrap.innerHTML = `
    <button type="button" class="theme-picker__btn" aria-haspopup="true" aria-expanded="false" aria-controls="theme-picker-menu">
      <svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22a10 10 0 1 1 10-10c0 2.8-2.2 4-4 4h-2.2a1.8 1.8 0 0 0-1.3 3.1c.6.6.5 2.9-2.5 2.9z"/><circle cx="7.5" cy="10.5" r="1.3" fill="currentColor"/><circle cx="11" cy="6.8" r="1.3" fill="currentColor"/><circle cx="16" cy="8" r="1.3" fill="currentColor"/></svg>
    </button>
    <ul class="theme-picker__menu" id="theme-picker-menu" role="menu" aria-label="Site theme" hidden>
      ${options
        .map((o) => {
          const [bg, accent] = THEME_SWATCHES[o.value] || ["#888", "#ccc"];
          return `<li role="none"><button type="button" role="menuitemradio" class="theme-picker__item" data-theme-value="${o.value}" aria-checked="false">
            <span class="theme-picker__swatch" style="background:${bg}" aria-hidden="true"><span style="background:${accent}"></span></span>
            <span class="theme-picker__name">${escapeHtml(o.textContent.trim())}</span>
            <svg class="theme-picker__check" aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M5 12l5 5L20 7"/></svg>
          </button></li>`;
        })
        .join("")}
    </ul>`;
  select.parentNode.insertBefore(wrap, select);
  wrap.appendChild(select); // kept for the theme names and as fallback, hidden by CSS

  const btn = wrap.querySelector(".theme-picker__btn");
  const menu = wrap.querySelector(".theme-picker__menu");
  const items = Array.from(menu.querySelectorAll(".theme-picker__item"));

  function sync() {
    const t = current();
    btn.title = `Theme: ${nameOf(t)}`;
    btn.setAttribute("aria-label", `Site theme: ${nameOf(t)}`);
    items.forEach((it) => it.setAttribute("aria-checked", String(it.dataset.themeValue === t)));
  }
  function open() {
    menu.hidden = false;
    btn.setAttribute("aria-expanded", "true");
    (items.find((it) => it.getAttribute("aria-checked") === "true") || items[0]).focus();
  }
  function close(focusBtn) {
    if (menu.hidden) return;
    menu.hidden = true;
    btn.setAttribute("aria-expanded", "false");
    if (focusBtn) btn.focus();
  }

  btn.addEventListener("click", () => (menu.hidden ? open() : close()));
  btn.addEventListener("keydown", (e) => {
    if (e.key === "ArrowDown" || e.key === "ArrowUp") {
      e.preventDefault();
      open();
    }
  });
  menu.addEventListener("click", (e) => {
    const it = e.target.closest(".theme-picker__item");
    if (!it) return;
    apply(it.dataset.themeValue);
    sync();
    close(true);
  });
  menu.addEventListener("keydown", (e) => {
    const i = items.indexOf(document.activeElement);
    if (e.key === "ArrowDown" || e.key === "ArrowUp") {
      e.preventDefault();
      const n = (i + (e.key === "ArrowDown" ? 1 : -1) + items.length) % items.length;
      items[n].focus();
    } else if (e.key === "Home" || e.key === "End") {
      e.preventDefault();
      items[e.key === "Home" ? 0 : items.length - 1].focus();
    } else if (e.key === "Escape") {
      e.preventDefault();
      close(true);
    } else if (e.key === "Tab") {
      close(false);
    }
  });
  document.addEventListener("click", (e) => {
    if (!wrap.contains(e.target)) close(false);
  });
  sync();
}

function initGlobalJuice() {
  const header = document.querySelector(".site-header");
  const logo = document.querySelector(".site-logo");

  if (header) {
    const onScroll = () => {
      header.classList.toggle("is-scrolled", window.scrollY > 8);
    };
    onScroll();
    window.addEventListener("scroll", onScroll, { passive: true });
  }

  if (logo) {
    logo.addEventListener("click", (e) => {
      if (sessionStorage.getItem("logo-sparkle")) return;
      sessionStorage.setItem("logo-sparkle", "1");
      logo.classList.add("site-logo--sparkle");
      setTimeout(() => logo.classList.remove("site-logo--sparkle"), 1200);
    });
  }

  document.querySelectorAll(".game-sidebar__column-title").forEach((title, i) => {
    title.style.animationDelay = `${i * 120}ms`;
    title.classList.add("game-sidebar__column-title--enter");
  });
}

function initSearchShortcut() {
  const searchInput = document.getElementById("filter-search");
  if (!searchInput) return;

  document.addEventListener("keydown", (e) => {
    if (e.key !== "/" || e.ctrlKey || e.metaKey || e.altKey) return;
    const tag = document.activeElement?.tagName;
    if (tag === "INPUT" || tag === "TEXTAREA" || tag === "SELECT") return;
    e.preventDefault();
    searchInput.focus();
    searchInput.classList.add("input--focus-flash");
    setTimeout(() => searchInput.classList.remove("input--focus-flash"), 600);
  });
}

/* Videos of a game (games.json "videos"), shown after the screenshots: YouTube
   links (only load from YouTube when someone presses play) or https links to a
   video file (.mp4, .webm, ...), played by the browser itself. */
const VIDEO_FILE_RE = /\.(mp4|webm|ogv|ogg|m4v|mov)$/i;
function videoFileUrl(url) {
  try {
    const u = new URL(url);
    return u.protocol === "https:" && VIDEO_FILE_RE.test(u.pathname) ? u.href : null;
  } catch {
    return null;
  }
}
const PLAY_BADGE_SVG = `<svg aria-hidden="true" viewBox="0 0 68 48" width="68" height="48"><path d="M66.5 7.7a8.5 8.5 0 0 0-6-6C55.3.3 34 .3 34 .3s-21.3 0-26.5 1.4a8.5 8.5 0 0 0-6 6C.1 13 .1 24 .1 24s0 11 1.4 16.3a8.5 8.5 0 0 0 6 6C12.7 47.7 34 47.7 34 47.7s21.3 0 26.5-1.4a8.5 8.5 0 0 0 6-6C67.9 35 67.9 24 67.9 24s0-11-1.4-16.3z" fill="#e00"/><path d="M45 24 27 14v20z" fill="#fff"/></svg>`;
function youtubeId(url) {
  try {
    const u = new URL(/^https?:\/\//i.test(url) ? url : `https://${url}`);
    const host = u.hostname.toLowerCase().replace(/^(www\.|m\.|music\.)/, "");
    let id = null;
    if (host === "youtu.be") id = u.pathname.split("/")[1];
    else if (host === "youtube.com" || host === "youtube-nocookie.com") {
      const m = u.pathname.match(/^\/(?:embed|shorts|live|v)\/([^/?]+)/);
      id = m ? m[1] : u.searchParams.get("v");
    }
    return id && /^[A-Za-z0-9_-]{11}$/.test(id) ? id : null;
  } catch {
    return null;
  }
}
// [{ yt: id } | { src: url }], duplicates dropped
function gameVideos(item) {
  const seen = new Set();
  return (Array.isArray(item?.videos) ? item.videos : [])
    .map((u) => (typeof u !== "string" ? null : youtubeId(u) ? { yt: youtubeId(u) } : videoFileUrl(u) ? { src: videoFileUrl(u) } : null))
    .filter((v) => v && !seen.has(v.yt || v.src) && seen.add(v.yt || v.src));
}
function youtubePoster(id) {
  return `https://i.ytimg.com/vi/${id}/hqdefault.jpg`;
}
// the "press play" cover of a video slide
function videoCoverHtml(id, label) {
  return `<button type="button" class="video-cover" data-yt-start="${id}" aria-label="${escapeHtml(label)}">
    <img src="${youtubePoster(id)}" alt="" loading="lazy" referrerpolicy="no-referrer" /><span class="video-cover__play">${PLAY_BADGE_SVG}</span></button>`;
}
// swaps the cover of a video slide for the YouTube player
function startVideo(slide, title) {
  const id = slide.dataset.yt;
  if (!id) return;
  const frame = document.createElement("iframe");
  frame.className = "video-frame";
  frame.src = `https://www.youtube-nocookie.com/embed/${id}?autoplay=1&rel=0&playsinline=1`;
  frame.title = title || "YouTube video";
  frame.setAttribute("allow", "autoplay; encrypted-media; picture-in-picture; fullscreen");
  frame.setAttribute("allowfullscreen", "");
  // the site sends no referrer by default, YouTube's player needs one
  frame.setAttribute("referrerpolicy", "strict-origin-when-cross-origin");
  slide.innerHTML = "";
  slide.appendChild(frame);
}
// back to the cover (stops the video and its sound); a video file just pauses
function stopVideo(slide) {
  if (!slide) return;
  const file = slide.querySelector("video");
  if (file) file.pause();
  if (slide.dataset.yt && slide.querySelector("iframe")) slide.innerHTML = videoCoverHtml(slide.dataset.yt, slide.dataset.ytLabel || "Play video");
}
window.pgVideo = { start: startVideo, stop: stopVideo };

/* ==========================================================================
   Detail Panels
   ========================================================================== */
function initDetailPanelCommon(panel) {

  const carousel = panel.querySelector(".detail-panel__carousel");
  const track = panel.querySelector(".carousel__track");
  const dotsEl = panel.querySelector(".carousel__dots");
  const prevBtn = panel.querySelector(".carousel__btn--prev");
  const nextBtn = panel.querySelector(".carousel__btn--next");
  const closeBtn = panel.querySelector(".detail-panel__close");
  const content = panel.querySelector(".detail-panel__content");

  function closePanel() {
    track?.querySelectorAll(".carousel__video").forEach(stopVideo);
    panel.classList.remove("is-open");
    content.classList.remove("detail-panel__content--visible");
    document.body.style.overflow = "";
    const url = new URL(window.location);
    url.searchParams.delete("game");
    history.replaceState(null, "", url);
  }

  function openPanel(allImages, isNsfw, skipNsfwBlur, title, tags, description, authors, videos = []) {
    if (carousel) {
      const total = allImages.length + videos.length;
      const hasMultiple = total > 1;
      carousel.hidden = false;
      let current = 0;

      // pictures first, then the videos
      track.innerHTML = allImages.map((src, i) => `
      <img class="carousel__img${i === 0 ? " carousel__img--active" : ""}" src="${src}" alt="${title}${i === 0 ? "" : ` screenshot ${i}`}" loading="lazy" />
      `).join("") + videos.map((v, i) => {
        const active = !allImages.length && i === 0 ? " carousel__img--active" : "";
        const label = `Play video ${i + 1}${videos.length > 1 ? ` of ${videos.length}` : ""}: ${title}`;
        if (v.src) return `<div class="carousel__img carousel__video carousel__video--file${active}"><video class="video-file" src="${escapeHtml(v.src)}" controls playsinline preload="none" aria-label="${escapeHtml(`Video ${i + 1}: ${title}`)}"></video></div>`;
        return `<div class="carousel__img carousel__video${active}" data-yt="${v.yt}" data-yt-label="${escapeHtml(label)}">${videoCoverHtml(v.yt, label)}</div>`;
      }).join("");

      if (prevBtn) prevBtn.hidden = !hasMultiple;
      if (nextBtn) nextBtn.hidden = !hasMultiple;

      dotsEl.innerHTML = hasMultiple ? Array.from({ length: total }, (_, i) => `
      <button type="button" class="carousel__dot${i >= allImages.length ? " carousel__dot--video" : ""}${i === 0 ? " carousel__dot--active" : ""}" data-index="${i}" aria-label="${i === 0 && allImages.length ? "Cover" : i < allImages.length ? `Screenshot ${i}` : `Video ${i - allImages.length + 1}`}"></button>
      `).join("") : "";

      track.querySelectorAll(".carousel__video").forEach((slide) => {
        slide.addEventListener("click", (e) => {
          if (e.target.closest("[data-yt-start]")) startVideo(slide, title);
        });
      });

      function goTo(n) {
        const imgs = track.querySelectorAll(".carousel__img");
        const dots = dotsEl.querySelectorAll(".carousel__dot");
        stopVideo(imgs[current]);
        imgs[current].classList.remove("carousel__img--active");
        if (dots[current]) dots[current].classList.remove("carousel__dot--active");
        current = (n + total) % total;
        imgs[current].classList.add("carousel__img--active");
        if (dots[current]) dots[current].classList.add("carousel__dot--active");
      }

      if (prevBtn) prevBtn.onclick = () => goTo(current - 1);
      if (nextBtn) nextBtn.onclick = () => goTo(current + 1);
      dotsEl.querySelectorAll(".carousel__dot").forEach((dot) => {
        dot.onclick = () => goTo(Number(dot.dataset.index));
      });

      // the panel is reused between opens, clear any leftover NSFW overlay
      const oldOverlay = carousel.querySelector(".detail-panel__nsfw-overlay");
      if (oldOverlay) oldOverlay.remove();
      carousel.classList.remove("detail-panel__carousel--nsfw-blurred");
      if (isNsfw && !skipNsfwBlur) {
        carousel.classList.add("detail-panel__carousel--nsfw-blurred");
        const overlayBtn = document.createElement("button");
        overlayBtn.type = "button";
        overlayBtn.className = "detail-panel__nsfw-overlay";
        overlayBtn.textContent = "🔞 NSFW, click to reveal";
        overlayBtn.addEventListener("click", () => {
          carousel.classList.remove("detail-panel__carousel--nsfw-blurred");
          overlayBtn.remove();
        });
        carousel.appendChild(overlayBtn);
      }
    }

    panel.querySelector(".detail-panel__title").textContent = title;
    panel.querySelector(".detail-panel__tags").innerHTML = createTagElements(tags);
    panel.querySelector(".detail-panel__description").textContent = description;

    const authorEl = panel.querySelector(".detail-panel__author");

    if (authorEl) {
      if (authors) {
        authorEl.innerHTML = `By ${authors}`;
        authorEl.hidden = false;
      } else {
        authorEl.hidden = true;
      }
    }

    panel.classList.add("is-open");
    document.body.style.overflow = "hidden";
    requestAnimationFrame(() => {
      content.classList.add("detail-panel__content--visible");
      closeBtn.focus();
    });

  }

  panel.querySelector(".detail-panel__close").addEventListener("click", closePanel);
  panel.addEventListener("click", (e) => {
    if (e.target === panel) closePanel();
  });
  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && panel.classList.contains("is-open")) closePanel();
  });


  return { openPanel, closePanel };
}

function initGameDetailPanel(options = {}) {
  const panel = document.getElementById("detail-panel");
  if (!panel) return null;

  const common = initDetailPanelCommon(panel);

  // On pages with a page-wide "Show NSFW" toggle, that toggle is already
  // explicit consent, so re-blurring and demanding another click per game
  // is redundant. Pages without a toggle (e.g. the homepage sidebar, where
  // NSFW games can surface unannounced in "Random") keep the per-item gate.
  const skipNsfwBlur = typeof options.skipNsfwBlur === "function" ? options.skipNsfwBlur : () => false;
  // skipNsfwBlur receives the item so it can check per-item warning labels
  // against whichever content toggles the page has (not just one flag)


  function openPanel(item) {
    // Carousel
    const screenshots = item.screenshots && item.screenshots.length ? item.screenshots : [];

    // thumbnail is the first slide, screenshots follow
    const allImages = [item.thumbnail, ...(item.screenshots || [])];

    panel.querySelector(".detail-panel__thumb").hidden = true;
    panel.querySelector(".detail-panel__thumb").src = item.thumbnail;
    panel.querySelector(".detail-panel__thumb").alt = item.title;

    const authorArray = getAuthors(item);
    const authors = authorArray.length ? renderAuthorLinks(item, "author-link") : null;

    const downloadsEl = panel.querySelector(".detail-panel__downloads");
    if (downloadsEl && item.status === "Upcoming") {
      // announced games have nothing to download: promote the dev page and socials
      downloadsEl.innerHTML = renderFollowLinks(item);
    } else if (downloadsEl) {
      // item.url is the author/source page, item.downloads are the build
      // files. Both show when both exist, source first.
      const sourceEntry = item.url
      ? [{ label: item.platform || "Source", version: item.version, url: item.url, type: "source" }]
      : [];
      const buildEntries = (item.downloads || []).filter((d) => d.url !== item.url);
      const rawEntries = [...sourceEntry, ...buildEntries];
      // explicit d.type wins, otherwise guess from the label/url
      const entries = rawEntries
      .map((d) => ({ ...d, _type: classifyDownloadEntry(d) }))
      .sort((a, b) => (DOWNLOAD_TYPE_ORDER[a._type] ?? 3) - (DOWNLOAD_TYPE_ORDER[b._type] ?? 3));
      // mirrors = same build hosted elsewhere (telegram, backup server)
      // download / play clicks are counted (api/track.php), the author's site isn't
      // "Play" buttons (browser / Flash games) count as plays, the rest as downloads
      const isPlay = (d) => d._type === "web" || /^\s*play\b/i.test(d.buttonText || "");
      // Flash games on flash.mare.by: "Play" opens the game page, which shows the player
      const playHere = (d) => isPlay(d) && item.id != null && embeddablePlayUrl(d.url) && canEmbedPlayer();
      const trackAttr = (d) => (d._type === "source" || item.id == null ? "" : ` data-track="${isPlay(d) ? "play" : "download"}" data-track-game="${escapeHtml(String(item.id))}"`);
      downloadsEl.innerHTML = entries.map(d => `
      <div class="download-row">
      <div class="download-row__btns">
      ${playHere(d)
        ? `<a class="download-row__btn" href="${gamePath(item)}#play"${trackAttr(d)}>${d.buttonText || DOWNLOAD_BUTTON_TEXT[d._type] || "Play"}</a>`
        : `<a class="download-row__btn" href="${d.url}" target="_blank" rel="noopener noreferrer"${trackAttr(d)}>${d.buttonText || DOWNLOAD_BUTTON_TEXT[d._type] || "Download"}</a>`}
      ${(d.mirrors || []).map((m) => `
        <a class="download-row__btn download-row__btn--mirror" href="${m.url}" target="_blank" rel="noopener noreferrer" title="${m.label}"${trackAttr(d)}>${m.label}</a>
        `).join("")}
        </div>
        <span class="download-row__info">
        <span class="download-row__label">${d.label}</span>
        ${d.version ? `<span class="download-row__version">${d.version}</span>` : ""}
        </span>
        </div>
        `).join("");
    }


    const playtimeEl = panel.querySelector(".detail-panel__playtime");
    const playtimeWrap = panel.querySelector(".detail-panel__playtime-wrap");
    if (playtimeWrap && playtimeEl) {
      if (item.playtime && item.playtime !== "N/A") {
        playtimeEl.textContent = item.playtime;
        playtimeWrap.hidden = false;
      } else {
        playtimeWrap.hidden = true;
      }
    }

    const releaseEl = panel.querySelector(".detail-panel__release");
    if (releaseEl) {
      const releaseText = formatReleaseDate(item.releaseDate);
      if (releaseText) {
        releaseEl.textContent = `Released ${releaseText}`;
        releaseEl.hidden = false;
      } else {
        releaseEl.hidden = true;
      }
    }

    if (item.id) {
      const url = new URL(window.location);
      url.searchParams.set("game", item.id);
      history.replaceState(null, "", url);
    }

    // button to the game's own page (reviews, comments, likes)
    let pageLink = panel.querySelector(".detail-panel__page-link");
    if (!pageLink) {
      pageLink = document.createElement("a");
      pageLink.className = "btn btn--accent detail-panel__page-link";
      panel.querySelector(".detail-panel__title")?.insertAdjacentElement("afterend", pageLink);
    }
    pageLink.href = gamePath(item);
    pageLink.innerHTML = `Open game page <span aria-hidden="true">→</span>`;
    pageLink.title = "Reviews, discussion, likes and more";

    let shareBtn = panel.querySelector(".detail-panel__share");
    if (!shareBtn) {
      shareBtn = document.createElement("button");
      shareBtn.type = "button";
      shareBtn.className = "btn btn--ghost detail-panel__share";
      shareBtn.setAttribute("data-share", "");
      shareBtn.innerHTML = `<svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4"/></svg> Share`;
      pageLink.insertAdjacentElement("afterend", shareBtn);
    }
    shareBtn.dataset.shareUrl = gamePath(item);
    shareBtn.dataset.shareTitle = item.title;

    // average review score, links to the reviews on the game page
    let ratingEl = panel.querySelector(".detail-panel__rating");
    if (!ratingEl) {
      ratingEl = document.createElement("a");
      ratingEl.className = "detail-panel__rating";
      pageLink.insertAdjacentElement("beforebegin", ratingEl);
    }
    ratingEl.hidden = true;
    ratingEl.href = gamePath(item) + "#reviews";
    if (item.id != null) {
      const shownPath = gamePath(item);
      loadRatings().then(() => {
        if (!pageLink.href.endsWith(shownPath) || !gameRatings) return;
        const r = gameRatings[String(item.id)];
        if (r) {
          const [avg, n] = r;
          const full = Math.round(avg);
          ratingEl.innerHTML = `<span class="detail-panel__stars" aria-hidden="true">${"★".repeat(full)}<span class="is-off">${"★".repeat(5 - full)}</span></span>`
            + `<strong>${avg.toFixed(1)}</strong><span class="detail-panel__rating-n">${n} review${n === 1 ? "" : "s"}</span>`;
          ratingEl.setAttribute("aria-label", `${avg.toFixed(1)} out of 5 from ${n} review${n === 1 ? "" : "s"}, read the reviews`);
          ratingEl.classList.remove("is-empty");
        } else {
          ratingEl.innerHTML = `<span class="detail-panel__stars" aria-hidden="true"><span class="is-off">★★★★★</span></span><span class="detail-panel__rating-n">No reviews yet, be the first!</span>`;
          ratingEl.removeAttribute("aria-label");
          ratingEl.classList.add("is-empty");
        }
        const stats = statsHtml(item.id, "detail-panel__stats");
        if (stats) ratingEl.insertAdjacentHTML("beforeend", stats);
        ratingEl.hidden = false;
      });
    }

    // a suggested change for this game is waiting for a moderator
    let pending = panel.querySelector(".detail-panel__pending");
    if (!pending) {
      pending = document.createElement("span");
      pending.className = "pending-badge detail-panel__pending";
      pending.title = "Someone suggested a change to this game. A moderator will check it soon.";
      pending.innerHTML = `<span aria-hidden="true">⏳</span> Change under review`;
      (panel.querySelector(".detail-panel__share") || pageLink).insertAdjacentElement("afterend", pending);
    }
    pending.hidden = true;
    if (item.id != null && typeof pgMe === "function") {
      const shownId = String(item.id);
      pgMe().then((d) => {
        if (pageLink.href.endsWith(gamePath(item))) pending.hidden = !(d.pending || []).includes(shownId);
      });
    }

    common.openPanel(allImages, isNsfwItem(item), skipNsfwBlur(item), item.title, item.tags, item.fullDescription, authors, gameVideos(item));

  }

  return { openPanel, closePanel : common.closePanel };
}

function initResourceDetailPanel() {
  const panel = document.getElementById("detail-panel");
  if (!panel) return null;

  const common = initDetailPanelCommon(panel);

  function openPanel(item) {
    const allImages = item.previews.length ? item.previews : [item.thumbnail];

    const authorArray = getAuthors(item);
    const authors = authorArray.length ? renderAuthorLinks(item, "author-link") : null;

    const licenseArray = getLicenses(item);
    licenseWrap = panel.querySelector(".detail-panel__license-wrap");
    licenseLabel = panel.querySelector(".detail-panel__license-label");
    licenseRow = panel.querySelector(".detail-panel__license");
    if (licenseWrap) {
      if (licenseArray.length) {
        licenseWrap.hidden = false;
        licenseLabel.textContent = licenseArray.length > 1 ? "Licenses" : "License";
        licenseRow.innerHTML = renderLicenseLinks(item, "license-link");
      } else {
        licenseWrap.hidden = true;
      }
    }


    const assetPacksEl = panel.querySelector(".detail-panel__asset-packs");
    if (assetPacksEl && item.assetPacks.length) {
      assetPacksEl.innerHTML = `<p>${item.assetPacks.join(", ")}</p>`;
    }

    const downloadsEl = panel.querySelector(".detail-panel__downloads");
    if (downloadsEl) {

      function makeButton(downloadURL) {
        let buttonText = "Download";
        try {
          const urlObj = new URL(downloadURL);
          buttonText = urlObj.hostname;
        } catch (err) {
          console.error(err);
        }
        return `<a class="download-row__btn" href="${downloadURL}" target="_blank" rel="noopener noreferrer">${buttonText}</a>`;
      }

      downloadsEl.innerHTML = [{buttons: item.downloads.map(makeButton), label: item.name}, {buttons: item.assetPackDownloads.map(makeButton), label: item.primaryAssetPack}].map(args =>
        args.buttons.length ? `<div class="download-row">
          <div class="download-row__btns">${args.buttons.join("")}</div>
          <span class="download-row__info">
          <span class="download-row__label">Download ${args.label}</span>
          </span>
          </div>` : ""
        ).join("");
    }

    common.openPanel(allImages, false, false, item.name, item.tags, item.description, authors);
  }

  return { openPanel, closePanel : common.closePanel };

}

function bindCardInteractions(grid, itemMap, detail) {
  grid.addEventListener("click", (e) => {
    const card = e.target.closest(".card");
    if (!card) return;
    const item = itemMap.get(String(card.dataset.id));
    if (item) detail.openPanel(item);
  });

  grid.addEventListener("keydown", (e) => {
    if (e.key !== "Enter" && e.key !== " ") return;
    const card = e.target.closest(".card");
    if (!card) return;
    e.preventDefault();
    const item = itemMap.get(String(card.dataset.id));
    if (item) detail.openPanel(item);
  });
}

/* ==========================================================================
   Homepage
   ========================================================================== */
const NUMBER_FORMAT = new Intl.NumberFormat("en-US");

function escapeHtml(value) {
  return String(value ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");
}

// genre tags in data order, used for featured/meta lines
function genresOf(item) {
  return (item.tags || [])
    .filter((t) => t && typeof t === "object" && normalizeTagType(t.type) === "genre")
    .map((t) => t.label);
}

function isUpcoming(item) {
  return String(item.status || "").toLowerCase() === "in development";
}

// Plain-text preview of a post: drops images, HTML tags and markdown marks.
function postPreviewText(excerpt) {
  return String(excerpt || "")
    .replace(/!\[[^\]]*\]\([^)]*\)/g, " ")
    .replace(/\[([^\]]*)\]\([^)]*\)/g, "$1")
    .replace(/<[^>]+>/g, " ")
    .replace(/&nbsp;/g, " ")
    .replace(/[*_#>`]+/g, "")
    .replace(/^\s*[-+]\s+/gm, "")
    .replace(/\s+/g, " ")
    .trim();
}

function newsDateParts(dateString) {
  const date = new Date(dateString);
  if (Number.isNaN(date.getTime())) return { day: "", month: "", full: "", ago: "" };
  return {
    day: date.getUTCDate(),
    month: date.toLocaleDateString("en-US", { month: "short", timeZone: "UTC" }),
    full: formatDate(dateString),
    ago: formatRelativeDate(dateString),
  };
}

function renderNewsCard(post, index) {
  const d = newsDateParts(post.date);
  return `
    <article class="news-card" data-post="${index}">
      <div class="news-card__head">
        <time class="news-card__date" datetime="${escapeHtml(post.date)}" title="${d.full}">
          <span class="news-card__day">${d.day}</span>
          <span class="news-card__month">${d.month}</span>
        </time>
        <h3 class="news-card__title">${escapeHtml(post.title)}
          <span class="news-card__ago">${d.ago}</span>
        </h3>
      </div>
      <p class="news-card__preview">${escapeHtml(postPreviewText(post.excerpt))}</p>
      <button type="button" class="news-card__toggle" aria-haspopup="dialog">Read more →</button>
    </article>
  `;
}

// Opens a post in the <dialog> pop-up. Falls back to nothing if the
// browser has no <dialog> support (very old browsers): the preview stays.
function openNewsDialog(post) {
  const dialog = document.getElementById("news-dialog");
  if (!dialog || typeof dialog.showModal !== "function") return;
  const d = newsDateParts(post.date);
  dialog.querySelector(".news-card__day").textContent = d.day;
  dialog.querySelector(".news-card__month").textContent = d.month;
  const time = dialog.querySelector(".news-dialog__date");
  time.setAttribute("datetime", post.date || "");
  time.title = d.full;
  dialog.querySelector(".news-dialog__title").innerHTML =
    `${escapeHtml(post.title)}<span class="news-card__ago">${d.ago}</span>`;
  dialog.querySelector(".news-dialog__body").innerHTML =
    `${post.image ? `<img src="${escapeHtml(post.image)}" alt="" loading="lazy" />` : ""}<md>${post.excerpt || ""}</md>`;
  if (typeof renderMarkdown === "function") renderMarkdown();
  dialog.querySelector(".news-dialog__inner").scrollTop = 0;
  dialog.showModal();
  document.body.classList.add("gf-locked");
}

function initNewsDialog() {
  const dialog = document.getElementById("news-dialog");
  if (!dialog) return;
  dialog.querySelector(".news-dialog__close")?.addEventListener("click", () => dialog.close());
  // click on the dimmed area outside the box closes it
  dialog.addEventListener("click", (e) => {
    if (e.target === dialog) dialog.close();
  });
  dialog.addEventListener("close", () => document.body.classList.remove("gf-locked"));
}

function renderFeatured(game) {
  const genres = genresOf(game);
  // the homepage says "Released" (not "Completed") for finished games
  const statusText = game.status === "Released" ? "Released" : STATUS_LABELS[game.status] || game.status;
  const meta = [statusText, game.engine && game.engine !== "Unknown" ? game.engine : null, releaseYearOf(game) !== "Unknown" ? releaseYearOf(game) : null]
    .filter(Boolean)
    .join(" · ");
  const tags = [
    ...genres.slice(0, 2).map((g) => `<span class="tag tag--genre">${escapeHtml(g)}</span>`),
    game.playtime && game.playtime !== "Unknown" ? `<span class="tag">${escapeHtml(game.playtime)}</span>` : "",
  ].join("");
  const authors = renderAuthorLinks(game, "author-link");

  return `
    <a class="featured__media" href="${gamePath(game)}" data-open-game="${game.id}" aria-label="${escapeHtml(game.title)}">
      <img src="${escapeHtml(game.thumbnail)}" alt="${escapeHtml(game.title)} thumbnail" loading="lazy" />
      <span class="featured__badge">Pony pick</span>
    </a>
    <div class="featured__body">
      <p class="featured__meta">${escapeHtml(meta)}</p>
      <h2 class="featured__title">${escapeHtml(game.title)}</h2>
      ${authors ? `<p class="featured__author">by ${authors}</p>` : ""}
      ${game.shortDescription ? `<p class="featured__desc">${escapeHtml(game.shortDescription)}</p>` : ""}
      <div class="featured__tags">${tags}</div>
      <div class="featured__actions">
        <a class="btn btn--accent" href="${gamePath(game)}" data-open-game="${game.id}">Trot over to it</a>
        <button type="button" class="btn btn--ghost" data-reroll>Reroll the hay bale</button>
      </div>
    </div>
  `;
}

// Counts an element's number up from 0 to target (ease-out). The width is
// reserved for the final number so the heading doesn't jump while counting.
// Users who prefer reduced motion get the final number straight away.
function countUp(el, target, duration = 1200) {
  const final = NUMBER_FORMAT.format(target);
  const reduce = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  if (reduce || target <= 0) {
    el.textContent = final;
    return;
  }
  el.classList.add("count-up");
  el.style.minWidth = `${final.length}ch`;
  el.setAttribute("aria-label", final);
  const start = performance.now();
  function frame(now) {
    const t = Math.min(1, (now - start) / duration);
    const eased = 1 - (1 - t) ** 3;
    el.textContent = NUMBER_FORMAT.format(Math.round(target * eased));
    if (t < 1) {
      requestAnimationFrame(frame);
    } else {
      el.textContent = final;
      el.style.minWidth = "";
      el.removeAttribute("aria-label");
    }
  }
  el.textContent = "0";
  requestAnimationFrame(frame);
}

async function initHome() {
  const hero = document.querySelector(".home-hero");
  if (!hero) return;

  const genresEl = document.getElementById("home-genres");
  const featuredEl = document.getElementById("featured-game");
  const newestGrid = document.getElementById("newest-grid");
  const upcomingGrid = document.getElementById("upcoming-grid");
  const upcomingAll = document.getElementById("upcoming-all");
  const incompleteAll = document.getElementById("incomplete-all");
  const statsEl = document.getElementById("site-stats");
  const newsGrid = document.getElementById("news-grid");
  const newsMore = document.getElementById("news-more");
  const detail = initGameDetailPanel();

  initCarousels();

  if (newestGrid) newestGrid.innerHTML = renderSkeletonCards(5);
  if (upcomingGrid) upcomingGrid.innerHTML = renderSkeletonCards(5);
  if (newsGrid) newsGrid.innerHTML = renderSkeletonPosts(3);

  const [gamesRes, postsRes, jamsRes] = await Promise.allSettled([
    fetchJSON("data/games.json"),
    fetchJSON("data/posts.json"),
    fetchJSON("data/jams.json"),
  ]);

  /* --- News --- */
  if (newsGrid) {
    if (postsRes.status === "fulfilled") {
      const posts = normalizeListData(postsRes.value, "posts").sort((a, b) => new Date(b.date) - new Date(a.date));
      newsGrid.innerHTML = posts.length
        ? posts.map((post, i) => renderNewsCard(post, i)).join("")
        : `<p class="home-empty">No news yet.</p>`;
      // CSS shows one row of posts (as many as fit); the button reveals the rest.
      const updateNewsMore = () => {
        if (!newsMore) return;
        const all = newsGrid.classList.contains("news-grid--all");
        const hidden = [...newsGrid.querySelectorAll(".news-card")].some((c) => getComputedStyle(c).display === "none");
        newsMore.hidden = all || !hidden;
      };
      if (newsMore) {
        newsMore.addEventListener("click", () => {
          newsGrid.classList.add("news-grid--all");
          updateNewsMore();
        });
        window.addEventListener("resize", updateNewsMore, { passive: true });
      }
      updateNewsMore();
      initNewsDialog();
      // the whole card opens the post; the "Read more" button is the keyboard target
      newsGrid.addEventListener("click", (e) => {
        const card = e.target.closest(".news-card");
        if (!card || e.target.closest("a")) return;
        const post = posts[Number(card.dataset.post)];
        if (post) openNewsDialog(post);
      });
    } else {
      newsGrid.innerHTML = `<p class="home-empty">Unable to load news posts.</p>`;
      console.error(postsRes.reason);
    }
  }

  /* --- Games --- */
  if (gamesRes.status !== "fulfilled") {
    console.error(gamesRes.reason);
    if (newestGrid) newestGrid.innerHTML = `<p class="home-empty">Unable to load games.</p>`;
    if (upcomingGrid) upcomingGrid.innerHTML = "";
    if (featuredEl) featuredEl.hidden = true;
    return;
  }

  const games = normalizeListData(gamesRes.value, "games");
  // The homepage only shows games without warning tags; the games page has the toggles.
  const safeGames = games.filter((g) => !isNsfwItem(g));
  const itemMap = new Map(games.map((g) => [String(g.id), g]));
  const byNewest = (a, b) => new Date(b.dateAdded) - new Date(a.dateAdded);

  const makers = new Set(games.flatMap(getAuthors).map((a) => a.toLowerCase()));
  const heroCount = document.getElementById("hero-count");
  const heroMakers = document.getElementById("hero-makers");
  if (heroCount) countUp(heroCount, games.length);
  if (heroMakers) heroMakers.textContent = `, made by ${NUMBER_FORMAT.format(makers.size)} creators for everypony`;

  if (statsEl) {
    const jamCount = jamsRes.status === "fulfilled" ? normalizeListData(jamsRes.value, "jams").length : null;
    statsEl.innerHTML = [
      `<span><strong>${NUMBER_FORMAT.format(games.length)}</strong>games</span>`,
      `<span><strong>${NUMBER_FORMAT.format(makers.size)}</strong>makers</span>`,
      jamCount ? `<a href="#jams"><strong>${jamCount}</strong>jams</a>` : "",
    ].join("");
  }

  if (genresEl) {
    const counts = new Map();
    safeGames.forEach((g) => genresOf(g).forEach((label) => counts.set(label, (counts.get(label) || 0) + 1)));
    genresEl.innerHTML = [...counts.entries()]
      .sort((a, b) => b[1] - a[1] || a[0].localeCompare(b[0]))
      .slice(0, 8)
      .map(([label, n]) => `
        <a class="genre-chip" href="/games?genre=${encodeURIComponent(label)}">
          ${escapeHtml(label)}<span class="genre-chip__count">${n}</span>
        </a>`)
      .join("");
  }

  if (featuredEl) {
    const pool = safeGames.filter(
      (g) => g.thumbnail && g.shortDescription && getAuthors(g).some((name) => name.trim() && name.trim() !== "?")
    );
    let current = null;
    const roll = () => {
      if (!pool.length) {
        featuredEl.hidden = true;
        return;
      }
      let next = pool[Math.floor(Math.random() * pool.length)];
      if (pool.length > 1) while (next === current) next = pool[Math.floor(Math.random() * pool.length)];
      current = next;
      featuredEl.innerHTML = renderFeatured(current);
    };
    featuredEl.addEventListener("click", (e) => {
      if (e.target.closest("[data-reroll]")) {
        roll();
        featuredEl.querySelector("[data-reroll]")?.focus();
        return;
      }
      const opener = e.target.closest("[data-open-game]");
      if (opener && detail && !(e.ctrlKey || e.metaKey || e.shiftKey)) {
        const item = itemMap.get(opener.dataset.openGame);
        if (item) {
          e.preventDefault();
          detail.openPanel(item);
        }
      }
    });
    roll();
  }

  if (newestGrid) {
    newestGrid.innerHTML = [...safeGames].sort(byNewest).slice(0, 15).map((g) => renderGameCard(g)).join("");
    bindGameCards(newestGrid, itemMap, detail);
  }

  if (upcomingGrid) {
    // the workshop: playable-but-unfinished games and announced ones, newest first
    const upcoming = safeGames.filter((g) => isUpcoming(g) || g.status === UPCOMING_STATUS).sort(byNewest);
    const nIncomplete = upcoming.filter(isUpcoming).length;
    const section = upcomingGrid.closest("section");
    if (!upcoming.length) {
      if (section) section.hidden = true;
    } else {
      upcomingGrid.innerHTML = upcoming.slice(0, 15).map((g) => renderGameCard(g, { upcoming: true })).join("");
      bindGameCards(upcomingGrid, itemMap, detail);
      if (incompleteAll) incompleteAll.textContent = `${NUMBER_FORMAT.format(nIncomplete)} unfinished →`;
      if (upcomingAll) upcomingAll.textContent = `${NUMBER_FORMAT.format(upcoming.length - nIncomplete)} upcoming →`;
    }
  }

  initCarousels();
}

/* ==========================================================================
   Carousels: arrow buttons page through a sideways-scrolling row
   ========================================================================== */
function initCarousels() {
  const reduceMotion = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;

  document.querySelectorAll(".carousel-nav[data-carousel]").forEach((nav) => {
    const track = document.getElementById(nav.dataset.carousel);
    if (!track || nav.dataset.bound) return;
    nav.dataset.bound = "1";
    const [prev, next] = nav.querySelectorAll(".carousel-btn");

    const update = () => {
      const max = track.scrollWidth - track.clientWidth;
      nav.hidden = max <= 2; // everything fits: no arrows needed
      prev.disabled = track.scrollLeft <= 2;
      next.disabled = track.scrollLeft >= max - 2;
    };

    nav.addEventListener("click", (e) => {
      const btn = e.target.closest(".carousel-btn");
      if (!btn || btn.disabled) return;
      // one "page" = the visible width, so cards never get skipped
      track.scrollBy({ left: Number(btn.dataset.dir) * track.clientWidth, behavior: reduceMotion ? "auto" : "smooth" });
    });
    track.addEventListener("scroll", update, { passive: true });
    window.addEventListener("resize", update, { passive: true });
    // rows fill in after their data loads: recheck whenever the cards change
    new MutationObserver(update).observe(track, { childList: true });
    update();
  });
}

/* ==========================================================================
   Game Cards
   ========================================================================== */
// opts.upcoming: show the "In development" badge and the short description
// Short plain-text excerpt for the hover card (descriptions can be long).
function excerpt(text, max = 260) {
  const t = String(text || "").replace(/\s+/g, " ").trim();
  if (t.length <= max) return t;
  return t.slice(0, max).replace(/\s+\S*$/, "") + "…";
}

// opts.upcoming: show WIP/Soon badges
// opts.showDescription: show the short description
// opts.pop: add the expanded info card that appears on hover (games page)
function renderGameCard(item, opts = {}) {
  const tags = item.tags || [];
  const warnings = tags.filter((t) => typeof t === "object" && normalizeTagType(t.type) === "warning");
  const genre = tags.find((t) => typeof t === "object" && normalizeTagType(t.type) === "genre");
  const other = sortTagsByPriority(tags).find(
    (t) => typeof t === "object" && !["warning", "genre"].includes(normalizeTagType(t.type))
  );
  const shownTags = [...warnings, genre, other].filter(Boolean).slice(0, 3);

  const nsfw = isNsfwItem(item);
  const year = releaseYearOf(item);
  const authors = renderAuthorLinks(item, "card__author--link");
  const authorLine = [authors, year !== "Unknown" ? year : ""].filter(Boolean).join(" · ");
  const showPlaytime = item.playtime && item.playtime !== "Unknown" && item.playtime !== "N/A";
  const showDesc = opts.showDescription;
  const href = gamePath(item);
  const statusBadge = opts.upcoming
    ? item.status === UPCOMING_STATUS
      ? `<span class="card__status card__status--soon" title="Announced, no playable demo yet">Soon</span>`
      : `<span class="card__status" title="In development, playable">WIP</span>`
    : "";

  let pop = "";
  if (opts.pop) {
    const facts = [
      STATUS_LABELS[item.status] || item.status,
      item.engine && item.engine !== "Unknown" ? item.engine : "",
      showPlaytime ? item.playtime : "",
      formatReleaseDate(item.releaseDate) || "",
      ...(item.platforms || []),
    ].filter(Boolean);
    const factsUnique = [...new Set(facts)];
    const allTags = sortTagsByPriority(tags.filter((t) => t && typeof t === "object")).slice(0, 10);
    const desc = excerpt(item.fullDescription || item.shortDescription);
    pop = `
      <div class="card__pop" aria-hidden="true">
        <div class="card__pop-thumb">
          <img class="card__thumb${nsfw ? " card__thumb--nsfw" : ""}" src="${escapeHtml(item.thumbnail)}" alt="" loading="lazy" />
          ${statusBadge}
        </div>
        <div class="card__pop-body">
          <p class="card__title">${escapeHtml(item.title)}</p>
          ${authorLine ? `<p class="card__author">${authorLine}</p>` : ""}
          ${factsUnique.length ? `<p class="card__facts">${factsUnique.map((f) => `<span>${escapeHtml(f)}</span>`).join("")}</p>` : ""}
          ${desc ? `<p class="card__desc">${escapeHtml(desc)}</p>` : ""}
          ${allTags.length ? `<div class="card__tags">${allTags.map(renderGameTag).join("")}</div>` : ""}
          <span class="card__more">Open game page →</span>
        </div>
      </div>`;
  }

  return `
    <article class="card card--game${opts.pop ? " card--pop" : ""}" data-id="${item.id}" data-href="${href}">
      <div class="card__thumb-wrap">
        <img class="card__thumb${nsfw ? " card__thumb--nsfw" : ""}" src="${escapeHtml(item.thumbnail)}" alt="${escapeHtml(item.title)} thumbnail" loading="lazy" />
        ${statusBadge}
        ${nsfw ? `<span class="card__nsfw-badge" title="NSFW, hover to preview">🔞</span>` : ""}
        ${showPlaytime ? `<span class="card__badge">${escapeHtml(item.playtime)}</span>` : ""}
      </div>
      <div class="card__body">
        <h3 class="card__title"><a class="card__link" href="${href}">${escapeHtml(item.title)}</a></h3>
        ${authorLine ? `<p class="card__author">${authorLine}</p>` : ""}
        ${showDesc && item.shortDescription ? `<p class="card__short-desc">${escapeHtml(item.shortDescription)}</p>` : ""}
        <div class="card__tags">${shownTags.map(renderGameTag).join("")}</div>
      </div>${pop}
    </article>
  `;
}

/* A spinner over pictures while they load: the "Pony pick" on the home
   page, the game cards, the game popup and the gallery on a game's page. It follows the
   picture that's shown, so it also appears when you go to the next one. */
const IMG_LOAD_BOXES = ".featured__media, .detail-panel__carousel, .gallery__main, .card__thumb-wrap";
function initImageLoading() {
  // on screen: not hidden itself or by a parent (video covers sit inside hidden slides)
  const shown = (img) => !img.hidden && img.getClientRects().length > 0;
  function update(box) {
    const img = [...box.querySelectorAll("img")].find(shown);
    // "complete" is also true for a picture that failed: then there's nothing to wait for
    const loading = !!img && img.getAttribute("src") && img.dataset.failedSrc !== img.src && !img.complete;
    box.classList.toggle("is-img-loading", !!loading);
  }
  function scan() {
    document.querySelectorAll(IMG_LOAD_BOXES).forEach((box) => {
      box.querySelectorAll("img").forEach((img) => {
        if (img.dataset.loadWatch) return;
        img.dataset.loadWatch = "1";
        img.addEventListener("load", () => update(box));
        img.addEventListener("error", () => { img.dataset.failedSrc = img.src; update(box); });
      });
      update(box);
    });
  }
  let queued = false;
  const later = () => {
    if (queued) return;
    queued = true;
    requestAnimationFrame(() => { queued = false; scan(); });
  };
  scan();
  // pictures that are added or switched later (popup, gallery arrows, random pick)
  new MutationObserver((records) => {
    if (records.some((r) => r.type === "childList" || (r.target.closest && r.target.closest(IMG_LOAD_BOXES)))) later();
  }).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ["class", "hidden", "src"] });
}

/* Share buttons: [data-share] with data-share-url (a path on this site) and
   data-share-title. Phones get the system share sheet, other browsers copy
   the link. Used on the game page and in the game popup. */
let pgToastEl = null;
let pgToastTimer = null;
function pgToast(message) {
  if (!pgToastEl) {
    pgToastEl = document.createElement("div");
    pgToastEl.className = "toast";
    pgToastEl.setAttribute("role", "status");
    document.body.appendChild(pgToastEl);
  }
  pgToastEl.textContent = message;
  pgToastEl.classList.add("is-visible");
  clearTimeout(pgToastTimer);
  pgToastTimer = setTimeout(() => pgToastEl.classList.remove("is-visible"), 2800);
}
async function shareLink(path, title) {
  const url = new URL(path || location.pathname, location.origin).href;
  const text = title ? `${title} on Pony Games` : "Pony Games";
  // the share sheet is mostly a phone thing; on desktop copying is more useful
  if (navigator.share && matchMedia("(pointer: coarse)").matches) {
    try {
      await navigator.share({ title: text, url });
      return;
    } catch (e) {
      if (e && e.name === "AbortError") return; // closed the share sheet
    }
  }
  try {
    await navigator.clipboard.writeText(url);
    pgToast("Link copied! Share it with your herd 🐴");
  } catch (e) {
    window.prompt("Copy this link:", url);
  }
}
/* Show a click right away: the server counts one per visitor, game, kind and
   day, so the page does the same (remembered in this browser) and updates the
   numbers on the cards, in the popup and on the game page. */
function bumpClickCount(id, kind) {
  id = String(id);
  const day = Math.floor(Date.now() / 86400000);
  const key = `pg-click-${kind}-${id}-${day}`;
  try {
    if (localStorage.getItem(key)) return;
    localStorage.setItem(key, "1");
  } catch (e) {}
  const map = kind === "play" ? gamePlays : gameDownloads;
  map[id] = (map[id] || 0) + 1;
  // cards and popup
  document.querySelectorAll(`.card--game[data-id="${CSS.escape(id)}"] .card__stats`).forEach((el) => el.remove());
  document.querySelectorAll(`.card--game[data-id="${CSS.escape(id)}"] .card__body .card__tags`).forEach((tags) => {
    const html = statsHtml(id, "card__stats");
    if (html) tags.insertAdjacentHTML("beforeend", html);
  });
  const panel = document.getElementById("detail-panel");
  const ratingEl = panel && panel.querySelector(".detail-panel__rating");
  if (ratingEl && panel.querySelector(`a[data-track-game="${CSS.escape(id)}"]`)) {
    ratingEl.querySelector(".detail-panel__stats")?.remove();
    ratingEl.insertAdjacentHTML("beforeend", statsHtml(id, "detail-panel__stats"));
  }
  // game page
  const counter = document.querySelector(`[data-click-count="${kind}"]`);
  if (counter) {
    const n = (Number(counter.textContent.replace(/\D/g, "")) || 0) + 1;
    counter.textContent = n.toLocaleString("en-US");
    if (counter.nextSibling) counter.nextSibling.textContent = ` ${kind}${n === 1 ? "" : "s"}`;
    counter.parentElement.hidden = false;
    counter.closest(".game__counters").hidden = false;
  }
}

/* Count clicks on download / play links ([data-track], see api/track.php).
   The link itself opens normally; the count is sent alongside. */
// one page view for the admin stats. Anonymous: no cookie, nothing personal is kept,
// so it also counts browsers that send Do Not Track / Global Privacy Control.
function trackPageView() {
  if (navigator.webdriver) return; // automated browsers
  // a share link made in the admin (pony.games/game/12?s=k3x9qa) says where the visit came from,
  // also from apps like Discord that send no referrer. The server only counts codes it made.
  // The code is taken out of the address bar again, so it isn't copied along when the page is shared.
  let src = "";
  try {
    const url = new URL(location.href);
    const s = url.searchParams.get("s");
    if (s) {
      if (/^[a-z0-9]{4,12}$/.test(s)) src = s;
      url.searchParams.delete("s");
      history.replaceState(history.state, "", url.pathname + url.search + url.hash);
    }
  } catch (err) {}
  try {
    fetch("/api/track.php", {
      method: "POST",
      keepalive: true,
      // same-origin, not omit: a password-protected copy of the site (HTTP auth, like the dev site) needs it
      credentials: "same-origin",
      headers: { "Content-Type": "application/json", "X-Requested-With": "pony-games" },
      body: JSON.stringify({ kind: "view", path: location.pathname, ref: document.referrer || "", src }),
    }).catch(() => {});
  } catch (err) {}
}
function initClickTracking() {
  document.addEventListener("click", (e) => {
    const a = e.target.closest("a[data-track]");
    if (!a) return;
    bumpClickCount(a.dataset.trackGame, a.dataset.track);
    try {
      fetch("/api/track.php", {
        method: "POST",
        keepalive: true,
        credentials: "same-origin",
        headers: { "Content-Type": "application/json", "X-Requested-With": "pony-games" },
        body: JSON.stringify({ game: a.dataset.trackGame, kind: a.dataset.track }),
      }).catch(() => {});
    } catch (err) {}
  });
}
function initShareButtons() {
  document.addEventListener("click", (e) => {
    const btn = e.target.closest("[data-share]");
    if (!btn) return;
    e.preventDefault();
    e.stopPropagation();
    shareLink(btn.dataset.shareUrl, btn.dataset.shareTitle);
  });
}

/* Average review score on the game cards (home page, games list, bookmarks…).
   The cards are drawn from games.json; the scores come from the reviews
   database and are added to every card on the page once they've loaded. */
let gameRatings = null;
let gameLikes = {};     // { id: number of likes }
let gameBookmarks = {}; // { id: number of members who bookmarked it }
let gameDownloads = {}; // { id: download clicks }  (api/track.php)
let gamePlays = {};     // { id: "Play" clicks }
function ratingBadgeHtml(id) {
  const r = gameRatings && gameRatings[String(id)];
  if (!r) return "";
  const [avg, n] = r;
  const label = `${avg.toFixed(1)} out of 5 from ${n} review${n === 1 ? "" : "s"}`;
  return `<span class="card__rating" title="${label}" aria-label="${label}"><span aria-hidden="true">★</span> ${avg.toFixed(1)}<span class="card__rating-n" aria-hidden="true">(${n})</span></span>`;
}
const HEART_SVG = `<svg aria-hidden="true" width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8l1 1.1L12 21l7.8-7.6 1-1.1a5.5 5.5 0 0 0 0-7.7z"/></svg>`;
const DOWNLOAD_SVG = `<svg aria-hidden="true" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12m0 0l-5-5m5 5l5-5M4 21h16"/></svg>`;
const BOOKMARK_SVG = `<svg aria-hidden="true" width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg>`;
const PLAY_SVG = `<svg aria-hidden="true" width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="M7 4.5v15a1 1 0 0 0 1.5.86l12-7.5a1 1 0 0 0 0-1.72l-12-7.5A1 1 0 0 0 7 4.5z"/></svg>`;
// "♥ 12  🔖 3  ⬇ 40  ▶ 7" for a game, or "" when it has none of them
function statsHtml(id, cls) {
  const likes = gameLikes[String(id)] || 0;
  const marks = gameBookmarks[String(id)] || 0;
  const dls = gameDownloads[String(id)] || 0;
  const plays = gamePlays[String(id)] || 0;
  if (!likes && !marks && !dls && !plays) return "";
  const part = (n, svg, kind, one, many) => (n ? `<span class="stat-${kind}" title="${n} ${n === 1 ? one : many}">${svg}${n}</span>` : "");
  return `<span class="${cls}" aria-label="${likes} likes, ${marks} bookmarks, ${dls} downloads, ${plays} plays">${part(likes, HEART_SVG, "likes", "like", "likes")}${part(marks, BOOKMARK_SVG, "bookmarks", "bookmark", "bookmarks")}${part(dls, DOWNLOAD_SVG, "downloads", "download", "downloads")}${part(plays, PLAY_SVG, "plays", "play", "plays")}</span>`;
}
function applyRatings(root = document) {
  if (!gameRatings) return;
  root.querySelectorAll(".card--game[data-id]").forEach((card) => {
    if (card.dataset.rated) return;
    card.dataset.rated = "1";
    // likes and bookmarks at the end of the tag row
    const stats = statsHtml(card.dataset.id, "card__stats");
    if (stats) card.querySelector(".card__body .card__tags")?.insertAdjacentHTML("beforeend", stats);
    const html = ratingBadgeHtml(card.dataset.id);
    if (!html) return;
    card.querySelector(".card__thumb-wrap")?.insertAdjacentHTML("beforeend", html);
    // and in the bigger info card that appears on hover (games page)
    const r = gameRatings[String(card.dataset.id)];
    const facts = card.querySelector(".card__pop .card__facts");
    if (facts) facts.insertAdjacentHTML("afterbegin", `<span class="card__facts-rating">★ ${r[0].toFixed(1)} (${r[1]})</span>`);
  });
}
// loads the scores once; resolves with { id: [average, count] } (or {} when unavailable)
let ratingsPromise = null;
function loadRatings() {
  if (!ratingsPromise) {
    ratingsPromise = fetch("/api/ratings.php")
      .then((r) => (r.ok ? r.json() : null))
      .then((d) => {
        gameLikes = (d && d.likes) || {};
        gameBookmarks = (d && d.bookmarks) || {};
        gameDownloads = (d && d.downloads) || {};
        gamePlays = (d && d.plays) || {};
        return (gameRatings = (d && d.ratings) || {});
      })
      .catch(() => (gameRatings = {}));
  }
  return ratingsPromise;
}
// "Best rated" order: the average, pulled a little towards 3 stars for games
// with only a few reviews, so one 5-star review doesn't beat twenty 4.8s.
function ratingScore(id) {
  const r = gameRatings && gameRatings[String(id)];
  if (!r) return -1;
  const [avg, n] = r;
  return (avg * n + 3 * 2) / (n + 2);
}
function initRatings() {
  if (!document.querySelector(".card-grid, .carousel, [data-type='games'], #card-grid")) return;
  loadRatings()
    .then(() => {
      if (!gameRatings || !(Object.keys(gameRatings).length + Object.keys(gameLikes).length + Object.keys(gameBookmarks).length + Object.keys(gameDownloads).length + Object.keys(gamePlays).length)) return;
      applyRatings();
      // cards drawn later (filters, "load more", carousels) get theirs too
      let queued = false;
      new MutationObserver(() => {
        if (queued) return;
        queued = true;
        requestAnimationFrame(() => {
          queued = false;
          applyRatings();
        });
      }).observe(document.body, { childList: true, subtree: true });
    })
    .catch(() => {});
}

// A click on a game card opens the quick-view popup. The title link still
// covers the card, so Ctrl/Cmd/middle click (and search engines) go to the
// game's own page, and the popup has a button to it as well.
function bindGameCards(grid, itemMap, detail) {
  if (grid.dataset.cardsBound) return;
  grid.dataset.cardsBound = "1";
  grid.addEventListener("click", (e) => {
    const card = e.target.closest(".card--game");
    if (!card || e.target.closest("[data-author], button")) return;
    const newTab = e.ctrlKey || e.metaKey || e.shiftKey;
    const item = itemMap.get(String(card.dataset.id));
    if (newTab) {
      // the hover card isn't a link itself, so open the page by hand there
      if (e.target.closest(".card__pop")) window.open(card.dataset.href, "_blank", "noopener");
      return;
    }
    if (!item || !detail) return; // no popup on this page: the link just works
    e.preventDefault();
    detail.openPanel(item);
  });
  // middle click on the hover card
  grid.addEventListener("auxclick", (e) => {
    const card = e.target.closest(".card--game");
    if (e.button === 1 && card && e.target.closest(".card__pop")) window.open(card.dataset.href, "_blank", "noopener");
  });
}

function renderResourceCard(item) {
  const tags = (item.tags || []).slice(0, 3).map((t) => `<span class="tag">${escapeHtml(t)}</span>`).join("");
  const authors = renderAuthorLinks(item, "card__author--link");
  const pack = item.primaryAssetPack ? `<span class="card__badge">${escapeHtml(item.primaryAssetPack)}</span>` : "";

  return `
    <article class="card card--resource" data-id="${item.id}" tabindex="0" role="button" aria-label="View details for ${escapeHtml(item.name)}">
      <div class="card__thumb-wrap">
        <img class="card__thumb" src="${escapeHtml(item.thumbnail)}" alt="${escapeHtml(item.name)} thumbnail" loading="lazy" />
        ${pack}
      </div>
      <div class="card__body">
        <h3 class="card__title">${escapeHtml(item.name)}</h3>
        ${authors ? `<p class="card__author">${authors}</p>` : ""}
        ${item.description ? `<p class="card__short-desc">${escapeHtml(item.description)}</p>` : ""}
        <div class="card__tags">${tags}</div>
      </div>
    </article>
  `;
}

/* ==========================================================================
   Games Page - search & multi-select filter
   ========================================================================== */

function parseMinutes(playtime) {
  if (!playtime) return 0;
  const s = playtime.toLowerCase();
  // buckets: <5 min, 5m-30m, 30m-2h, 2h-4h, 4h-10h, 10h+
  if (s.startsWith("<5")) return 0;
  if (s.startsWith("5m")) return 5;
  if (s.startsWith("30m")) return 30;
  if (s.startsWith("2h")) return 120;
  if (s.startsWith("4h")) return 240;
  if (s.startsWith("10h")) return 600;
  // Fallback for legacy values
  const n = parseInt(s.match(/\d+/)?.[0] || "0", 10);
  if (s.includes("hour") || s.includes("h")) return n * 60;
  return n;
}

function buildTagFilterChips(containerId, values, activeSet, onToggle, sortFn) {
  const container = document.getElementById(containerId);
  if (!container) return;
  const sorted = sortFn ? [...values].sort(sortFn) : [...values].sort();
  container.innerHTML = sorted.map((val) => `
    <button type="button" class="tag-filter-chip ${activeSet.has(val) ? "tag-filter-chip--active" : ""}" data-value="${val}">
      ${val}
    </button>
  `).join("");
  container.querySelectorAll(".tag-filter-chip").forEach((btn) => {
    btn.addEventListener("click", () => {
      const v = btn.dataset.value;
      if (activeSet.has(v)) activeSet.delete(v);
      else activeSet.add(v);
      btn.classList.toggle("tag-filter-chip--active", activeSet.has(v));
      onToggle();
    });
  });
}

function gameMatchesFilters(game, filters) {
  const { search, genres, tags, releaseYears, playtimes, statuses, engines, platforms, showNsfw, showGore } = filters;

  if (!warningsSatisfied(game, showNsfw, showGore)) return false;

  if (search) {
    const terms = search.toLowerCase().split(/[\s,]+/).filter(Boolean);
    const authorText = getAuthors(game).join(" ").toLowerCase();
    const matchesTerm = (term) =>
      game.title.toLowerCase().includes(term) ||
      authorText.includes(term) ||
      game.tags.some((t) => t.label.toLowerCase().includes(term)) ||
      (game.characters || []).some((c) => c.toLowerCase().includes(term));
    // every word must match somewhere, or multi-word authors match too loosely
    if (!terms.every(matchesTerm)) return false;
  }
  if (genres.size && !game.tags.some((t) => normalizeTagType(t.type) === "genre" && genres.has(t.label))) return false;
  if (tags.size && !game.tags.some((t) => tags.has(t.label))) return false;
  if (releaseYears.size && !releaseYears.has(releaseYearOf(game))) return false;
  if (playtimes.size && !playtimes.has(game.playtime)) return false;
  if (statuses.size && !statuses.has(game.status)) return false;
  if (engines.size && !engines.has(game.engine)) return false;
  if (platforms.size && !(game.platforms || []).some((p) => platforms.has(p))) return false;

  return true;
}

// Value(s) a game has for each filter facet; used for matching counts.
const FACET_VALUES = {
  genres: (g) => (g.tags || []).filter((t) => normalizeTagType(t.type) === "genre").map((t) => t.label),
  tags: (g) => (g.tags || []).filter((t) => normalizeTagType(t.type) !== "genre").map((t) => t.label),
  releaseYears: (g) => [releaseYearOf(g)],
  playtimes: (g) => (g.playtime ? [g.playtime] : []),
  statuses: (g) => (g.status ? [g.status] : []),
  engines: (g) => (g.engine ? [g.engine] : []),
  platforms: (g) => g.platforms || [],
};

// Status values in games.json and how the site names them.
// "Upcoming" games live on their own page (/upcoming), not in the main list.
const STATUS_LABELS = {
  Released: "Released",
  "In Development": "Unfinished",
  Cancelled: "Cancelled",
  Abandoned: "Cancelled",
  Upcoming: "Upcoming",
};
const STATUS_ORDER = ["Released", "In Development", "Cancelled"];
const MOD_TAG = "Mod";
const UPCOMING_STATUS = "Upcoming";

function facetValueLabel(key, value) {
  return key === "statuses" ? STATUS_LABELS[value] || value : value;
}

const FACET_LABELS = {
  genres: "Genre",
  tags: "Tag",
  releaseYears: "Published",
  playtimes: "Playtime",
  statuses: "Status",
  engines: "Engine",
  platforms: "Platform",
};

async function initGamesPage() {
  const grid = document.getElementById("card-grid");
  const filterForm = document.getElementById("games-filter");
  if (!grid || !filterForm) return;

  // nsfwToggle/goreToggle are declared further down, but this callback only
  // runs later (on card click), by which point they're assigned — closures.
  const detail = initGameDetailPanel({
    skipNsfwBlur: (item) => warningsSatisfied(item, !!nsfwToggle?.checked, !!goreToggle?.checked),
  });

  grid.innerHTML = renderSkeletonCards(8);

  let everyGame = [];
  try {
    everyGame = normalizeListData(await fetchJSON("data/games.json"), "games");
  } catch (err) {
    grid.innerHTML = `<p>Unable to load games.</p>`;
    console.error(err);
    return;
  }

  // /games lists everything except announced-only games; /upcoming only those
  const upcomingPage = grid.dataset.scope === "upcoming";
  const allGames = everyGame.filter((g) => (g.status === UPCOMING_STATUS) === upcomingPage);
  // the detail panel can open any game (e.g. a shared ?game= link)
  const itemMap = new Map(everyGame.map((g) => [String(g.id), g]));
  const pageTitle = document.querySelector(".games-head .page-title");
  const baseHeading = pageTitle ? pageTitle.textContent : "";
  const baseDocTitle = document.title;

  const PLAYTIME_ORDER = ["<5 min", "5m-30m", "30m-2h", "2h-4h", "4h-10h", "10h+"];
  // values not in PLAYTIME_ORDER ("Unknown" etc) go last
  const playtimeIndex = (v) => {
    const i = PLAYTIME_ORDER.indexOf(v);
    return i === -1 ? PLAYTIME_ORDER.length : i;
  };
  // newest year first, "Unknown" always last
  const yearSort = (a, b) => {
    if (a === "Unknown") return 1;
    if (b === "Unknown") return -1;
    return Number(b) - Number(a);
  };

  // Every value per facet, plus how often it appears in games without
  // warning tags (what most visitors see), to put common options first.
  const totals = {};
  const safeTotals = {};
  Object.keys(FACET_VALUES).forEach((key) => {
    totals[key] = new Map();
    safeTotals[key] = new Map();
    allGames.forEach((g) => {
      const safe = !isNsfwItem(g);
      new Set(FACET_VALUES[key](g)).forEach((v) => {
        totals[key].set(v, (totals[key].get(v) || 0) + 1);
        if (safe) safeTotals[key].set(v, (safeTotals[key].get(v) || 0) + 1);
      });
    });
  });
  if (!upcomingPage) STATUS_ORDER.forEach((st) => totals.statuses.has(st) || totals.statuses.set(st, 0));
  // "Mods" in the Games menu filters on this tag, so keep it even with 0 games
  if (!totals.tags.has(MOD_TAG)) totals.tags.set(MOD_TAG, 0);

  const byTotal = (key) => (a, b) =>
    (safeTotals[key].get(b) || 0) - (safeTotals[key].get(a) || 0) || a.localeCompare(b);

  const FACETS = [
    { key: "genres", id: "filter-genre", sort: byTotal("genres"), limit: 10 },
    { key: "playtimes", id: "filter-playtime", sort: (a, b) => playtimeIndex(a) - playtimeIndex(b), limit: 10 },
    { key: "tags", id: "filter-tag", sort: byTotal("tags"), limit: 10 },
    { key: "releaseYears", id: "filter-releaseyear", sort: yearSort, limit: 8 },
    {
      key: "statuses",
      id: "filter-status",
      sort: (a, b) => (STATUS_ORDER.indexOf(a) + 1 || 99) - (STATUS_ORDER.indexOf(b) + 1 || 99) || a.localeCompare(b),
      limit: 10,
    },
    { key: "engines", id: "filter-engine", sort: byTotal("engines"), limit: 8 },
    { key: "platforms", id: "filter-platform", sort: byTotal("platforms"), limit: 8 },
  ];

  // Active selections (Sets for multi-select)
  const active = {};
  FACETS.forEach((f) => (active[f.key] = new Set()));

  const searchInput = document.getElementById("filter-search");
  const resultsEl = document.getElementById("filter-results");

  // "Bookmarks" toggle in the toolbar (only shown once something is bookmarked)
  const bookmarkBtn = document.getElementById("filter-bookmarks");
  let bookmarksOnly = false;
  function updateBookmarkBtn() {
    if (!bookmarkBtn) return;
    const n = allGames.filter((g) => readBookmarks().includes(String(g.id))).length;
    bookmarkBtn.hidden = n === 0 && !bookmarksOnly;
    bookmarkBtn.setAttribute("aria-pressed", String(bookmarksOnly));
    const countEl = bookmarkBtn.querySelector(".bookmarks-toggle__count");
    if (countEl) countEl.textContent = n;
  }
  function setBookmarksOnly(on) {
    bookmarksOnly = on;
    const url = new URL(window.location.href);
    if (on) url.searchParams.set("bookmarks", "1");
    else url.searchParams.delete("bookmarks");
    history.replaceState(null, "", url);
    updateBookmarkBtn();
    applyFilters();
  }
  bookmarkBtn?.addEventListener("click", () => setBookmarksOnly(!bookmarksOnly));
  // bookmarks can arrive a moment later from the account
  document.addEventListener("pg:bookmarks", () => {
    updateBookmarkBtn();
    if (bookmarksOnly) applyFilters();
  });
  const totalEl = document.getElementById("filter-total");
  const clearBtn = document.getElementById("filter-clear");
  const chipsEl = document.getElementById("filter-chips");
  const nsfwToggle = document.getElementById("filter-nsfw");
  const goreToggle = document.getElementById("filter-gore");
  const sortSelect = document.getElementById("filter-sort");
  // "Random": every game gets a random number once, so the order stays the same
  // while filtering and paging; picking "Random" again shuffles anew
  let randomKeys = null;
  sortSelect?.addEventListener("change", () => { randomKeys = null; });
  const perPageSelect = document.getElementById("filter-perpage");
  const paginationNav = document.getElementById("games-pagination");
  const prevPageBtn = document.getElementById("games-prev");
  const nextPageBtn = document.getElementById("games-next");
  const pageInfoEl = document.getElementById("games-page-info");
  const panel = document.getElementById("games-filter-panel");
  const backdrop = document.getElementById("gf-backdrop");
  const openBtn = document.getElementById("filters-open");
  const openCount = document.getElementById("filters-open-count");
  const closeBtn = document.getElementById("filters-close");
  const applyBtn = document.getElementById("filters-apply");

  let currentPage = 1;

  function getPerPage() {
    const v = perPageSelect ? perPageSelect.value : "50";
    return v === "all" ? Infinity : parseInt(v, 10);
  }

  function getFilters(exceptKey) {
    const f = {
      search: searchInput ? searchInput.value.trim() : "",
      showNsfw: nsfwToggle ? nsfwToggle.checked : false,
      showGore: goreToggle ? goreToggle.checked : false,
    };
    FACETS.forEach(({ key }) => (f[key] = key === exceptKey ? new Set() : active[key]));
    return f;
  }

  function activeFilterCount() {
    return FACETS.reduce((n, { key }) => n + active[key].size, 0);
  }

  function hasActiveFilters() {
    return !!(searchInput && searchInput.value.trim()) || activeFilterCount() > 0;
  }

  /* --- Facet lists (checkboxes with counts) --- */
  FACETS.forEach((facet) => {
    const container = document.getElementById(facet.id);
    if (!container) return;
    facet.values = [...totals[facet.key].keys()].sort(facet.sort);
    facet.container = container;
    facet.group = container.closest(".gf__group");
    facet.expanded = false;

    container.innerHTML =
      facet.values
        .map((v, i) => `
          <label class="gf__option" data-value="${escapeHtml(v)}"${i >= facet.limit ? " data-extra hidden" : ""}>
            <input type="checkbox" value="${escapeHtml(v)}" />
            <span class="gf__option-label">${escapeHtml(facetValueLabel(facet.key, v))}</span>
            <span class="gf__option-count"></span>
          </label>`)
        .join("") +
      (facet.values.length > facet.limit
        ? `<button type="button" class="gf__more">Show all ${facet.values.length}</button>`
        : "");

    // "input" on the container runs before the form's own input listener,
    // so the Set is up to date by the time applyFilters reads it.
    container.addEventListener("input", (e) => {
      const box = e.target.closest('input[type="checkbox"]');
      if (!box) return;
      if (box.checked) active[facet.key].add(box.value);
      else active[facet.key].delete(box.value);
    });

    container.querySelector(".gf__more")?.addEventListener("click", (e) => {
      facet.expanded = !facet.expanded;
      e.currentTarget.textContent = facet.expanded ? "Show fewer" : `Show all ${facet.values.length}`;
      syncFacets();
    });
  });

  function syncFacets() {
    FACETS.forEach((facet) => {
      if (!facet.container) return;
      // counts reflect every other active filter, so they show what you'd get
      const others = getFilters(facet.key);
      const counts = new Map();
      allGames.forEach((g) => {
        if (!gameMatchesFilters(g, others)) return;
        new Set(FACET_VALUES[facet.key](g)).forEach((v) => counts.set(v, (counts.get(v) || 0) + 1));
      });

      facet.container.querySelectorAll(".gf__option").forEach((label) => {
        const value = label.dataset.value;
        const isActive = active[facet.key].has(value);
        const n = counts.get(value) || 0;
        label.querySelector("input").checked = isActive;
        label.querySelector(".gf__option-count").textContent = n;
        label.classList.toggle("gf__option--empty", n === 0 && !isActive);
        if (label.hasAttribute("data-extra")) label.hidden = !(facet.expanded || isActive);
      });

      const badge = facet.group?.querySelector(".gf__group-count");
      if (badge) {
        badge.hidden = active[facet.key].size === 0;
        badge.textContent = active[facet.key].size;
      }
      if (facet.group && active[facet.key].size) facet.group.open = true;
    });
  }

  /* --- Active filter chips --- */
  function renderChips() {
    if (!chipsEl) return;
    const chips = [];
    // only picked filters get a chip, the search text stays in the search box
    FACETS.forEach(({ key }) =>
      active[key].forEach((v) =>
        chips.push({
          key,
          value: v,
          text: key === "genres" || key === "tags" || key === "statuses" ? facetValueLabel(key, v) : `${FACET_LABELS[key]}: ${v}`,
        })
      )
    );
    chipsEl.innerHTML = chips
      .map((c) => `
        <button type="button" class="active-chip" data-key="${c.key}" data-value="${escapeHtml(c.value)}">
          ${escapeHtml(c.text)}<span class="active-chip__x" aria-hidden="true">×</span><span class="visually-hidden">remove filter</span>
        </button>`)
      .join("");
  }

  if (chipsEl) {
    chipsEl.addEventListener("click", (e) => {
      const chip = e.target.closest(".active-chip");
      if (!chip) return;
      if (chip.dataset.key === "search") {
        if (searchInput) searchInput.value = "";
      } else {
        active[chip.dataset.key]?.delete(chip.dataset.value);
      }
      applyFilters();
    });
  }

  function clearAllFilters() {
    if (searchInput) searchInput.value = "";
    Object.values(active).forEach((s) => s.clear());
    applyFilters();
  }

  // "Released games" etc. in the heading when exactly one status is picked
  function updateHeading() {
    if (!pageTitle || upcomingPage) return;
    const only = active.statuses.size === 1 ? [...active.statuses][0] : null;
    const modsOnly = active.tags.size === 1 && active.tags.has(MOD_TAG);
    let heading = only ? `${STATUS_LABELS[only] || only} games` : baseHeading;
    if (modsOnly) heading = only ? `${STATUS_LABELS[only] || only} mods` : "Mods";
    if (bookmarksOnly) heading = "Your bookmarks";
    pageTitle.textContent = heading;
    document.title = heading === baseHeading ? baseDocTitle : `${heading} | Pony Games`;
  }

  function renderGrid(games, animate = false) {
    const onlyFilter = activeFilterCount() === 1 && !searchInput?.value.trim();
    const onlyCancelled = onlyFilter && active.statuses.size === 1 && active.statuses.has("Cancelled");
    const onlyMods = onlyFilter && active.tags.size === 1 && active.tags.has(MOD_TAG);
    const friendlyEmpty = (title, text, primary) => `
        <div class="games-empty games-empty--friendly">
          <img class="games-empty__mascot" src="/assets/mascot-head.png" alt="" width="120" height="157" />
          <p class="games-empty__title">${title}</p>
          <p class="games-empty__text">${text}</p>
          <div class="games-empty__actions">
            ${primary}
            <a class="btn btn--ghost" href="/games">All games</a>
          </div>
        </div>
      `;
    if (games.length === 0 && bookmarksOnly && !hasActiveFilters()) {
      grid.innerHTML = friendlyEmpty(
        "No bookmarks yet!",
        "Open a game and press Bookmark to save it here. Bookmarks are kept in this browser.",
        `<button type="button" class="btn btn--accent" id="empty-bookmarks-off">Show all games</button>`
      );
      document.getElementById("empty-bookmarks-off")?.addEventListener("click", () => setBookmarksOnly(false));
      if (paginationNav) paginationNav.hidden = true;
    } else if (games.length === 0 && (onlyCancelled || onlyMods)) {
      grid.innerHTML = onlyCancelled
        ? friendlyEmpty(
            "No cancelled games yet!",
            "No games are marked as cancelled so far. Know one that was left behind? Let the herd know.",
            `<a class="btn btn--accent" href="/submit?fix">Mark a game as cancelled</a>`
          )
        : friendlyEmpty(
            "No mods yet!",
            "No pony mods are listed so far. Made a mod for a game, or know a good one? Send it in.",
            `<a class="btn btn--accent" href="/submit">Submit a mod</a>`
          );
      if (paginationNav) paginationNav.hidden = true;
    } else if (games.length === 0) {
      grid.innerHTML = `
        <div class="games-empty games-empty--friendly">
          <img class="games-empty__mascot" src="assets/mascot-head.png" alt="" width="120" height="157" />
          <p class="games-empty__title">No ponies found!</p>
          <p class="games-empty__text">Nothing in the stable matches these filters. Try removing one, or tell the herd about a game we're missing.</p>
          <div class="games-empty__actions">
            <button type="button" class="btn btn--accent" id="empty-clear-filters">Clear filters</button>
            <a class="btn btn--ghost" href="/submit">Submit a game</a>
          </div>
        </div>
      `;
      document.getElementById("empty-clear-filters")?.addEventListener("click", clearAllFilters);
      if (paginationNav) paginationNav.hidden = true;
    } else {
      const perPage = getPerPage();
      const totalPages = Math.max(1, Math.ceil(games.length / perPage));
      currentPage = Math.min(currentPage, totalPages);
      const start = (currentPage - 1) * perPage;
      const pageGames = Number.isFinite(perPage) ? games.slice(start, start + perPage) : games;

      grid.innerHTML = pageGames.map((g) => renderGameCard(g, { pop: true, upcoming: upcomingPage })).join("");
      if (animate) staggerCards(grid);

      if (paginationNav) {
        paginationNav.hidden = totalPages <= 1;
        if (prevPageBtn) prevPageBtn.disabled = currentPage <= 1;
        if (nextPageBtn) nextPageBtn.disabled = currentPage >= totalPages;
        if (pageInfoEl) pageInfoEl.textContent = `Page ${currentPage} of ${totalPages}`;
      }
    }
    animateCount(resultsEl, games.length);
    if (totalEl) totalEl.textContent = hasActiveFilters() ? ` of ${NUMBER_FORMAT.format(allGames.length)}` : "";
    if (applyBtn) applyBtn.textContent = `Show ${NUMBER_FORMAT.format(games.length)} game${games.length === 1 ? "" : "s"}`;
    if (clearBtn) clearBtn.hidden = !hasActiveFilters();
    if (openCount) {
      const n = activeFilterCount();
      openCount.hidden = n === 0;
      openCount.textContent = n;
    }
  }

  function sortGames(games) {
    const sort = sortSelect ? sortSelect.value : "newest";
    const sorted = [...games];
    // bad dates parse to NaN and NaN comparisons no-op the sort, so pin
    // them to epoch 0 instead
    const parseDateSafe = (d) => {
      const t = new Date(d).getTime();
      return Number.isNaN(t) ? 0 : t;
    };
    switch (sort) {
      case "newest":  return sorted.sort((a, b) => parseDateSafe(b.dateAdded) - parseDateSafe(a.dateAdded));
      case "oldest":  return sorted.sort((a, b) => parseDateSafe(a.dateAdded) - parseDateSafe(b.dateAdded));
      case "az":      return sorted.sort((a, b) => a.title.localeCompare(b.title));
      case "za":      return sorted.sort((a, b) => b.title.localeCompare(a.title));
      case "release-newest": return sorted.sort((a, b) => compareReleaseDates(a, b, -1));
      case "release-oldest": return sorted.sort((a, b) => compareReleaseDates(a, b, 1));
      case "playtime-asc":  return sorted.sort((a, b) => parseMinutes(a.playtime) - parseMinutes(b.playtime));
      case "playtime-desc": return sorted.sort((a, b) => parseMinutes(b.playtime) - parseMinutes(a.playtime));
      case "random": {
        if (!randomKeys) randomKeys = new Map();
        const key = (g) => {
          const id = String(g.id);
          if (!randomKeys.has(id)) randomKeys.set(id, Math.random());
          return randomKeys.get(id);
        };
        return sorted.sort((a, b) => key(a) - key(b));
      }
      case "rated":
      case "reviewed":
      case "downloaded":
      case "played": {
        if (!gameRatings) {
          // scores not here yet: sort again as soon as they are
          loadRatings().then(() => applyFilters(false, false));
          return sorted.sort((a, b) => parseDateSafe(b.dateAdded) - parseDateSafe(a.dateAdded));
        }
        const count = (g) => (gameRatings[String(g.id)] || [0, 0])[1];
        const newest = (a, b) => parseDateSafe(b.dateAdded) - parseDateSafe(a.dateAdded);
        if (sort === "downloaded" || sort === "played") {
          // ties: the other kind of click, then the newest
          const [main, other] = sort === "played" ? [gamePlays, gameDownloads] : [gameDownloads, gamePlays];
          const n = (map, g) => map[String(g.id)] || 0;
          return sorted.sort((a, b) => n(main, b) - n(main, a) || n(other, b) - n(other, a) || newest(a, b));
        }
        return sort === "rated"
          ? sorted.sort((a, b) => ratingScore(b.id) - ratingScore(a.id) || count(b) - count(a) || newest(a, b))
          : sorted.sort((a, b) => count(b) - count(a) || ratingScore(b.id) - ratingScore(a.id) || newest(a, b));
      }
      default: return sorted;
    }
  }

  function applyFilters(animate = false, resetPage = true) {
    if (resetPage) currentPage = 1;
    const marks = bookmarksOnly ? new Set(readBookmarks()) : null;
    const filtered = sortGames(
      allGames.filter((g) => (!marks || marks.has(String(g.id))) && gameMatchesFilters(g, getFilters()))
    );
    renderGrid(filtered, animate);
    syncFacets();
    renderChips();
    updateHeading();
  }

  function goToPage(page) {
    currentPage = page;
    applyFilters(false, false);
    grid.scrollIntoView({ behavior: "smooth", block: "start" });
  }

  /* --- Mobile filter drawer --- */
  function setPanelOpen(open) {
    if (!panel) return;
    panel.classList.toggle("is-open", open);
    backdrop?.classList.toggle("is-open", open);
    document.body.classList.toggle("gf-locked", open);
    openBtn?.setAttribute("aria-expanded", String(open));
    if (open) closeBtn?.focus();
    else if (document.activeElement && panel.contains(document.activeElement)) openBtn?.focus();
  }

  openBtn?.addEventListener("click", () => setPanelOpen(true));
  closeBtn?.addEventListener("click", () => setPanelOpen(false));
  applyBtn?.addEventListener("click", () => {
    setPanelOpen(false);
    grid.scrollIntoView({ behavior: "smooth", block: "start" });
  });
  backdrop?.addEventListener("click", () => setPanelOpen(false));
  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && panel?.classList.contains("is-open")) setPanelOpen(false);
  });

  filterForm.addEventListener("submit", (e) => e.preventDefault());
  filterForm.addEventListener("input", (e) => {
    // facet checkboxes are handled via their container listener first
    if (e.target.closest(".card")) return;
    applyFilters(false);
  });
  // "change" is needed for the <select>s, they don't always fire "input"
  filterForm.addEventListener("change", (e) => {
    if (e.target.tagName === "SELECT") applyFilters(false);
  });
  if (clearBtn) clearBtn.addEventListener("click", clearAllFilters);

  if (prevPageBtn) prevPageBtn.addEventListener("click", () => goToPage(currentPage - 1));
  if (nextPageBtn) nextPageBtn.addEventListener("click", () => goToPage(currentPage + 1));

  // Pre-fill from URL params: ?search=, and ?genre= / ?status= / ?tag= from the homepage
  const params = new URLSearchParams(window.location.search);
  const urlSearch = params.get("search");
  if (urlSearch && searchInput) searchInput.value = urlSearch;
  [["genre", "genres"], ["status", "statuses"], ["tag", "tags"], ["engine", "engines"], ["platform", "platforms"]].forEach(
    ([param, key]) => params.getAll(param).forEach((v) => {
      if (totals[key].has(v)) active[key].add(v);
    })
  );

  bindGameCards(grid, itemMap, detail);
  if (params.get("bookmarks") === "1" && bookmarkBtn) bookmarksOnly = true;
  updateBookmarkBtn();
  // first render goes through applyFilters so the default sort applies
  applyFilters(true);

  // shared popup links (games.html?game=ID) open the popup again
  const urlGameId = params.get("game");
  if (urlGameId && detail) {
    const game = itemMap.get(String(urlGameId));
    if (game) detail.openPanel(game);
  }
}

/* ==========================================================================
   Resources Card Grid
   ========================================================================== */
const NO_LICENSE = "No license listed";

// Value(s) a resource has for each filter facet
const RESOURCE_FACET_VALUES = {
  tags: (r) => r.tags || [],
  assetPacks: (r) => r.assetPacks || [],
  authors: (r) => r.author || [],
  licenses: (r) => ((r.license || []).length ? r.license.map((l) => l.name) : [NO_LICENSE]),
};

const RESOURCE_FACET_LABELS = { tags: "Type", assetPacks: "Pack", authors: "Author", licenses: "License" };

async function initCardGrid(jsonPath) {
  const grid = document.getElementById("card-grid");
  const form = document.getElementById("resources-filter");
  if (!grid || !form) return;

  const detail = initResourceDetailPanel();
  if (!detail) return;

  grid.innerHTML = renderSkeletonCards(8);

  let resourcesJSON = null;
  try {
    resourcesJSON = await fetchJSON(jsonPath);
  } catch (err) {
    grid.innerHTML = `<p>Unable to load content.</p>`;
    console.error(err);
    return;
  }
  const items = normalizeListData(resourcesJSON, "assets");
  items.forEach((item) => {
    if (item.primaryAssetPack && resourcesJSON.allAssetPacks && item.primaryAssetPack in resourcesJSON.allAssetPacks) {
      item.assetPackDownloads = resourcesJSON.allAssetPacks[item.primaryAssetPack].downloads;
    }
  });
  const itemMap = new Map(items.map((item) => [String(item.id), item]));

  const $ = (id) => document.getElementById(id);
  const searchInput = $("resources-search");
  const resultsEl = $("resource-filter-results");
  const totalEl = $("resource-filter-total");
  const clearBtn = $("resource-filter-clear");
  const sortSelect = $("resource-sort");
  const perPageSelect = $("resource-perpage");
  const chipsEl = $("resource-filter-chips");
  const paginationNav = $("resources-pagination");
  const prevBtn = $("res-prev");
  const nextBtn = $("res-next");
  const pageInfo = $("res-page-info");
  const panel = $("resources-filter-panel");
  const backdrop = $("res-gf-backdrop");
  const openBtn = $("res-filters-open");
  const openCount = $("res-filters-open-count");
  const closeBtn = $("res-filters-close");
  const applyBtn = $("res-filters-apply");

  // every value per facet with how often it appears, most common first
  const totals = {};
  Object.keys(RESOURCE_FACET_VALUES).forEach((key) => {
    totals[key] = new Map();
    items.forEach((r) => new Set(RESOURCE_FACET_VALUES[key](r)).forEach((v) => totals[key].set(v, (totals[key].get(v) || 0) + 1)));
  });
  const byTotal = (key) => (a, b) => totals[key].get(b) - totals[key].get(a) || a.localeCompare(b);

  const FACETS = [
    { key: "tags", id: "filter-res-tag", limit: 10 },
    { key: "assetPacks", id: "filter-res-pack", limit: 8 },
    { key: "authors", id: "filter-res-author", limit: 8 },
    { key: "licenses", id: "filter-res-license", limit: 8 },
  ];
  const active = {};
  FACETS.forEach((f) => (active[f.key] = new Set()));
  let currentPage = 1;

  function matches(item, exceptKey) {
    const q = searchInput ? searchInput.value.trim().toLowerCase() : "";
    if (q) {
      const terms = q.split(/[\s,]+/).filter(Boolean);
      const matchesTerm = (term) =>
        item.name.toLowerCase().includes(term) ||
        (item.description || "").toLowerCase().includes(term) ||
        (item.tags || []).some((t) => t.toLowerCase().includes(term)) ||
        (item.assetPacks || []).some((t) => t.toLowerCase().includes(term)) ||
        (item.author || []).some((t) => t.toLowerCase().includes(term));
      if (!terms.every(matchesTerm)) return false;
    }
    return FACETS.every(({ key }) => {
      if (key === exceptKey || active[key].size === 0) return true;
      return RESOURCE_FACET_VALUES[key](item).some((v) => active[key].has(v));
    });
  }

  const activeCount = () => FACETS.reduce((n, { key }) => n + active[key].size, 0);
  const hasActive = () => !!(searchInput && searchInput.value.trim()) || activeCount() > 0;

  /* --- facet checkbox lists --- */
  FACETS.forEach((facet) => {
    const container = $(facet.id);
    if (!container) return;
    facet.values = [...totals[facet.key].keys()].sort(byTotal(facet.key));
    facet.container = container;
    facet.group = container.closest(".gf__group");
    facet.expanded = false;
    if (!facet.values.length && facet.group) facet.group.hidden = true;

    container.innerHTML =
      facet.values
        .map((v, i) => `
          <label class="gf__option" data-value="${escapeHtml(v)}"${i >= facet.limit ? " data-extra hidden" : ""}>
            <input type="checkbox" value="${escapeHtml(v)}" />
            <span class="gf__option-label">${escapeHtml(v)}</span>
            <span class="gf__option-count"></span>
          </label>`)
        .join("") +
      (facet.values.length > facet.limit ? `<button type="button" class="gf__more">Show all ${facet.values.length}</button>` : "");

    container.addEventListener("input", (e) => {
      const box = e.target.closest('input[type="checkbox"]');
      if (!box) return;
      if (box.checked) active[facet.key].add(box.value);
      else active[facet.key].delete(box.value);
    });
    container.querySelector(".gf__more")?.addEventListener("click", (e) => {
      facet.expanded = !facet.expanded;
      e.currentTarget.textContent = facet.expanded ? "Show fewer" : `Show all ${facet.values.length}`;
      syncFacets();
    });
  });

  function syncFacets() {
    FACETS.forEach((facet) => {
      if (!facet.container) return;
      const counts = new Map();
      items.forEach((r) => {
        if (!matches(r, facet.key)) return;
        new Set(RESOURCE_FACET_VALUES[facet.key](r)).forEach((v) => counts.set(v, (counts.get(v) || 0) + 1));
      });
      facet.container.querySelectorAll(".gf__option").forEach((label) => {
        const value = label.dataset.value;
        const on = active[facet.key].has(value);
        const n = counts.get(value) || 0;
        label.querySelector("input").checked = on;
        label.querySelector(".gf__option-count").textContent = n;
        label.classList.toggle("gf__option--empty", n === 0 && !on);
        if (label.hasAttribute("data-extra")) label.hidden = !(facet.expanded || on);
      });
      const badge = facet.group?.querySelector(".gf__group-count");
      if (badge) {
        badge.hidden = active[facet.key].size === 0;
        badge.textContent = active[facet.key].size;
      }
      if (facet.group && active[facet.key].size) facet.group.open = true;
    });
  }

  function renderChips() {
    if (!chipsEl) return;
    const chips = [];
    const q = searchInput ? searchInput.value.trim() : "";
    if (q) chips.push({ key: "search", value: q, text: `“${q}”` });
    FACETS.forEach(({ key }) =>
      active[key].forEach((v) => chips.push({ key, value: v, text: key === "tags" ? v : `${RESOURCE_FACET_LABELS[key]}: ${v}` }))
    );
    chipsEl.innerHTML = chips
      .map((c) => `
        <button type="button" class="active-chip" data-key="${c.key}" data-value="${escapeHtml(c.value)}">
          ${escapeHtml(c.text)}<span class="active-chip__x" aria-hidden="true">×</span><span class="visually-hidden">remove filter</span>
        </button>`)
      .join("");
  }

  chipsEl?.addEventListener("click", (e) => {
    const chip = e.target.closest(".active-chip");
    if (!chip) return;
    if (chip.dataset.key === "search") searchInput.value = "";
    else active[chip.dataset.key]?.delete(chip.dataset.value);
    applyFilters();
  });

  function clearAll() {
    if (searchInput) searchInput.value = "";
    Object.values(active).forEach((set) => set.clear());
    applyFilters();
  }

  function sortItems(list) {
    const sorted = [...list];
    return (sortSelect ? sortSelect.value : "az") === "za"
      ? sorted.sort((a, b) => b.name.localeCompare(a.name))
      : sorted.sort((a, b) => a.name.localeCompare(b.name));
  }

  function applyFilters(animate = false, resetPage = true) {
    if (resetPage) currentPage = 1;
    const filtered = sortItems(items.filter((r) => matches(r)));

    if (filtered.length) {
      const per = perPageSelect && perPageSelect.value !== "all" ? parseInt(perPageSelect.value, 10) : Infinity;
      const pages = Math.max(1, Math.ceil(filtered.length / per));
      currentPage = Math.min(currentPage, pages);
      const page = Number.isFinite(per) ? filtered.slice((currentPage - 1) * per, currentPage * per) : filtered;
      grid.innerHTML = page.map(renderResourceCard).join("");
      if (paginationNav) {
        paginationNav.hidden = pages <= 1;
        prevBtn.disabled = currentPage <= 1;
        nextBtn.disabled = currentPage >= pages;
        pageInfo.textContent = `Page ${currentPage} of ${pages}`;
      }
    } else {
      grid.innerHTML = items.length
        ? `
        <div class="games-empty games-empty--friendly">
          <img class="games-empty__mascot" src="assets/mascot-head.png" alt="" width="120" height="157" />
          <p class="games-empty__title">No resources found!</p>
          <p class="games-empty__text">Nothing in the toolbox matches these filters. Try removing one.</p>
          <div class="games-empty__actions"><button type="button" class="btn btn--accent" id="res-empty-clear">Clear filters</button></div>
        </div>`
        : `
        <div class="games-empty games-empty--friendly">
          <img class="games-empty__mascot" src="assets/mascot-head.png" alt="" width="120" height="157" />
          <p class="games-empty__title">Resources are coming soon!</p>
          <p class="games-empty__text">Check back later for free sprites, tilesets, music, and more.</p>
        </div>`;
      $("res-empty-clear")?.addEventListener("click", clearAll);
      if (paginationNav) paginationNav.hidden = true;
    }
    if (animate) staggerCards(grid);
    if (resultsEl) animateCount(resultsEl, filtered.length, "resource");
    if (totalEl) totalEl.textContent = hasActive() ? ` of ${NUMBER_FORMAT.format(items.length)}` : "";
    if (applyBtn) applyBtn.textContent = `Show ${NUMBER_FORMAT.format(filtered.length)} resource${filtered.length === 1 ? "" : "s"}`;
    if (clearBtn) clearBtn.hidden = !hasActive();
    if (openCount) {
      openCount.hidden = activeCount() === 0;
      openCount.textContent = activeCount();
    }
    syncFacets();
    renderChips();
  }

  /* --- mobile filter drawer --- */
  function setPanelOpen(open) {
    if (!panel) return;
    panel.classList.toggle("is-open", open);
    backdrop?.classList.toggle("is-open", open);
    document.body.classList.toggle("gf-locked", open);
    openBtn?.setAttribute("aria-expanded", String(open));
    if (open) closeBtn?.focus();
    else if (panel.contains(document.activeElement)) openBtn?.focus();
  }
  openBtn?.addEventListener("click", () => setPanelOpen(true));
  closeBtn?.addEventListener("click", () => setPanelOpen(false));
  backdrop?.addEventListener("click", () => setPanelOpen(false));
  applyBtn?.addEventListener("click", () => {
    setPanelOpen(false);
    grid.scrollIntoView({ behavior: "smooth", block: "start" });
  });
  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && panel?.classList.contains("is-open")) setPanelOpen(false);
  });

  form.addEventListener("submit", (e) => e.preventDefault());
  form.addEventListener("input", (e) => {
    if (e.target.closest(".card")) return;
    applyFilters();
  });
  form.addEventListener("change", (e) => {
    if (e.target.tagName === "SELECT") applyFilters();
  });
  clearBtn?.addEventListener("click", clearAll);
  const goTo = (page) => {
    currentPage = page;
    applyFilters(false, false);
    grid.scrollIntoView({ behavior: "smooth", block: "start" });
  };
  prevBtn?.addEventListener("click", () => goTo(currentPage - 1));
  nextBtn?.addEventListener("click", () => goTo(currentPage + 1));

  // ?search=, ?tag=, ?pack=, ?author= in the URL
  const params = new URLSearchParams(window.location.search);
  if (params.get("search") && searchInput) searchInput.value = params.get("search");
  [["tag", "tags"], ["pack", "assetPacks"], ["author", "authors"]].forEach(([param, key]) =>
    params.getAll(param).forEach((v) => totals[key].has(v) && active[key].add(v))
  );

  bindCardInteractions(grid, itemMap, detail);
  applyFilters(true);
}

/* ==========================================================================
   Jam History
   ========================================================================== */
async function initJamHistory() {
  const container = document.getElementById("jam-history");
  if (!container) return;

  container.innerHTML = renderSkeletonJamHistory(5);

  try {
    const jams = await fetchJSON("data/jams.json");

    container.innerHTML = jams
      .map(
        (jam, i) => `
        <a href="${escapeHtml(jam.url)}" class="jam-history__item jam-history__item--enter" style="animation-delay: ${i * 70}ms" target="_blank" rel="noopener noreferrer">
          <img src="${escapeHtml(jam.image)}" alt="" loading="lazy" />
          <span class="jam-history__name">${escapeHtml(jam.name)}</span>
        </a>
      `
      )
      .join("");
  } catch (err) {
    container.innerHTML = `<p>Unable to load jam history.</p>`;
    console.error(err);
  }
}

/* ==========================================================================
   Mascot Lightbox (about.html)
   ========================================================================== */
function initMascotLightbox() {
  const trigger = document.querySelector(".about-mascot");
  const lightbox = document.getElementById("mascot-lightbox");
  if (!trigger || !lightbox) return;

  const closeBtn = lightbox.querySelector(".mascot-lightbox__close");

  function open() {
    lightbox.hidden = false;
    document.body.style.overflow = "hidden";
    requestAnimationFrame(() => lightbox.classList.add("is-open"));
  }

  function close() {
    lightbox.classList.remove("is-open");
    document.body.style.overflow = "";
    setTimeout(() => { lightbox.hidden = true; }, 250);
  }

  trigger.addEventListener("click", open);
  trigger.addEventListener("keydown", (e) => {
    if (e.key === "Enter" || e.key === " ") {
      e.preventDefault();
      open();
    }
  });
  if (closeBtn) closeBtn.addEventListener("click", close);
  lightbox.addEventListener("click", (e) => {
    if (e.target === lightbox) close();
  });
  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && lightbox.classList.contains("is-open")) close();
  });
}

/* ==========================================================================
   Games dropdown in the header
   Opens on hover (desktop) and with the small arrow button (touch/keyboard).
   Clicking "Games" itself still goes to the games page.
   ========================================================================== */
function initNavDropdown() {
  document.querySelectorAll(".nav-dropdown").forEach((dd) => {
    const toggle = dd.querySelector(".nav-dropdown__toggle");
    if (!toggle) return;
    const setOpen = (open) => {
      dd.classList.toggle("is-open", open);
      toggle.setAttribute("aria-expanded", String(open));
    };
    toggle.addEventListener("click", (e) => {
      e.stopPropagation();
      setOpen(!dd.classList.contains("is-open"));
    });
    document.addEventListener("click", (e) => {
      if (!dd.contains(e.target)) setOpen(false);
    });
    dd.addEventListener("keydown", (e) => {
      if (e.key === "Escape" && dd.classList.contains("is-open")) {
        setOpen(false);
        toggle.focus();
      }
    });
    // leaving the menu with Tab closes it
    dd.addEventListener("focusout", (e) => {
      if (!dd.contains(e.relatedTarget)) setOpen(false);
    });
  });
}

/* ==========================================================================
   Page Initialization
   ========================================================================== */
/* ==========================================================================
   Accounts (optional): header button, bookmark sync, account page carousel.
   Everything here quietly does nothing when the PHP backend isn't there.
   ========================================================================== */
let mePromise = null;
function pgMe() {
  if (!mePromise) {
    mePromise = fetch("/api/me.php", { credentials: "same-origin", headers: { Accept: "application/json" } })
      .then((r) => (r.ok ? r.json() : { user: null, failed: true }))
      .then((d) => (d && typeof d === "object" ? d : { user: null }))
      .catch(() => ({ user: null, failed: true }));
  }
  return mePromise;
}
window.pgMe = pgMe;

function accountApi(payload) {
  return fetch("/api/account.php", {
    method: "POST",
    credentials: "same-origin",
    headers: { "Content-Type": "application/json", "X-Requested-With": "pony-games" },
    body: JSON.stringify(payload),
  }).then((r) => r.json().then((d) => (r.ok ? d : Promise.reject(new Error(d.error || "Something went wrong.")))));
}
window.pgAccountApi = accountApi;

const BOOKMARK_OWNER_KEY = "pg-bookmarks-owner";
function storeBookmarks(list) {
  try {
    localStorage.setItem(BOOKMARK_KEY, JSON.stringify(list));
  } catch {}
  document.dispatchEvent(new CustomEvent("pg:bookmarks"));
}

/* The server renders the account button (inc/layout.php). What we knew on the
   last visit (localStorage "pg-me": name, avatar, unread count) lets the
   notification bell show straight away while /api/me.php is on its way; the
   answer then corrects it. paintAccountLink() only changes a button that the
   server didn't render (data-server). */
const ME_CACHE_KEY = "pg-me";
let accountLinkOriginal = null;
function readMeCache() {
  try {
    const c = JSON.parse(localStorage.getItem(ME_CACHE_KEY) || "null");
    return c && typeof c === "object" && c.name ? c : null;
  } catch {
    return null;
  }
}
function writeMeCache(c) {
  try {
    if (c) localStorage.setItem(ME_CACHE_KEY, JSON.stringify(c));
    else localStorage.removeItem(ME_CACHE_KEY);
  } catch {}
}
function paintAccountLink(link, u) {
  if (!link || link.dataset.server === "1") return; // PHP pages render it themselves
  if (accountLinkOriginal === null && !link.classList.contains("is-logged-in")) accountLinkOriginal = link.innerHTML;
  if (!u) {
    if (link.classList.contains("is-logged-in") && accountLinkOriginal !== null) {
      link.innerHTML = accountLinkOriginal;
      link.href = "/login";
      link.removeAttribute("title");
      link.classList.remove("is-logged-in");
    }
    return;
  }
  const avatar = u.avatar
    ? `<img class="account-link__avatar" src="${escapeHtml(u.avatar)}" alt="" width="40" height="40">`
    : u.avatar_color
    ? `<span class="account-link__avatar pony-avatar" style="--avatar-bg: ${escapeHtml(u.avatar_color)}" aria-hidden="true"></span>`
    : `<span class="account-link__avatar account-link__avatar--letter" aria-hidden="true">${escapeHtml((u.name || "?").trim().charAt(0).toUpperCase())}</span>`;
  const html = `${avatar}<span class="account-link__name">${escapeHtml(u.name)}</span>`;
  link.href = "/account";
  link.title = "Your account";
  link.classList.add("is-logged-in");
  if (link.innerHTML !== html) link.innerHTML = html;
}
function paintFromCache() {
  const link = document.querySelector("[data-account-link]");
  if (!link) return;
  const c = readMeCache();
  // PHP pages already know who is logged in; only show the bell early when someone is
  if (c && (link.dataset.server !== "1" || link.classList.contains("is-logged-in"))) {
    paintAccountLink(link, c);
    if (c.bell) initNotificationBell(link, c.unread || 0);
  }
  // logging out: forget the cached state right away
  document.addEventListener("click", (e) => {
    if (e.target.closest('a[href*="/logout"], form[action*="/logout"] button')) writeMeCache(null);
  });
}

async function initAccount() {
  const me = await pgMe();
  const headerLink = document.querySelector("[data-account-link]");
  if (!me.failed) {
    writeMeCache(me.user ? { name: me.user.name, avatar: me.user.avatar || "", avatar_color: me.user.avatar_color || "", bell: !!me.notifications?.on, unread: me.notifications?.unread || 0 } : null);
    if (!me.user) {
      paintAccountLink(headerLink, null);
      document.querySelector("[data-notif]")?.remove();
    } else if (!me.notifications?.on) {
      document.querySelector("[data-notif]")?.remove();
    }
  }
  let owner = null;
  try {
    owner = localStorage.getItem(BOOKMARK_OWNER_KEY);
  } catch {}

  if (!me.user) {
    // logged out: no bookmarks here. Ones made as a guest (before bookmarks
    // needed an account) wait in their own key for the next log in.
    const list = readBookmarks();
    try {
      if (!owner && list.length) {
        const kept = JSON.parse(localStorage.getItem(BOOKMARK_GUEST_KEY) || "[]");
        localStorage.setItem(BOOKMARK_GUEST_KEY, JSON.stringify([...new Set([...(Array.isArray(kept) ? kept : []), ...list])]));
      }
      localStorage.removeItem(BOOKMARK_OWNER_KEY);
    } catch {}
    if (owner || list.length) storeBookmarks([]);
    return;
  }

  // header button (the server already renders it; this only fills in one it didn't)
  const link = headerLink;
  paintAccountLink(link, me.user);

  // the notification bell, next to the account button
  if (link && me.notifications?.on) initNotificationBell(link, me.notifications.unread || 0);

  // bookmarks: the first time this account is used in this browser, add the
  // bookmarks made before logging in; after that the account is the source
  let bookmarks = me.bookmarks || [];
  if (owner !== String(me.user.id)) {
    let guest = [];
    try {
      const v = JSON.parse(localStorage.getItem(BOOKMARK_GUEST_KEY) || "[]");
      if (Array.isArray(v)) guest = v.map(String);
    } catch {}
    const extra = [...new Set([...(owner ? [] : readBookmarks()), ...guest])].filter((id) => !bookmarks.includes(id));
    if (extra.length) {
      try {
        bookmarks = (await accountApi({ action: "merge-bookmarks", games: extra })).bookmarks;
      } catch {}
    }
    try {
      localStorage.setItem(BOOKMARK_OWNER_KEY, String(me.user.id));
      localStorage.removeItem(BOOKMARK_GUEST_KEY);
    } catch {}
  }
  storeBookmarks(bookmarks);
}

/* Notification bell: updates to bookmarked games (api/notifications.php).
   The list loads when the bell is opened; opening a notification marks it read. */
const BELL_SVG = `<svg aria-hidden="true" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>`;
function timeAgo(ts) {
  const s = Math.max(0, Date.now() / 1000 - ts);
  if (s < 60) return "just now";
  const steps = [[60, "minute"], [3600, "hour"], [86400, "day"], [604800, "week"], [2629800, "month"], [31557600, "year"]];
  let [div, word] = steps[0];
  for (const st of steps) if (s >= st[0]) [div, word] = st;
  const n = Math.floor(s / div);
  return `${n} ${word}${n === 1 ? "" : "s"} ago`;
}
let setBellCount = null;
function initNotificationBell(accountLink, unread) {
  if (document.querySelector("[data-notif]")) {
    if (setBellCount) setBellCount(unread);
    return;
  }
  const box = document.createElement("div");
  box.className = "notif";
  box.dataset.notif = "";
  box.innerHTML = `
    <button type="button" class="notif__btn" aria-haspopup="true" aria-expanded="false" aria-controls="notif-menu">
      ${BELL_SVG}<span class="notif__count" hidden></span><span class="visually-hidden" data-notif-label>Notifications</span>
    </button>
    <div class="notif__menu" id="notif-menu" role="dialog" aria-label="Notifications" hidden>
      <div class="notif__head"><strong>Notifications</strong><button type="button" class="notif__all" hidden>Mark all as read</button></div>
      <ul class="notif__list"><li class="notif__empty">Loading…</li></ul>
      <a class="notif__foot" href="/account?tab=settings#notifications">Notification settings</a>
    </div>`;
  accountLink.before(box);
  const btn = box.querySelector(".notif__btn");
  const menu = box.querySelector(".notif__menu");
  const list = box.querySelector(".notif__list");
  const allBtn = box.querySelector(".notif__all");
  const countEl = box.querySelector(".notif__count");
  const label = box.querySelector("[data-notif-label]");
  const setCount = (n) => {
    countEl.hidden = !n;
    countEl.textContent = n > 9 ? "9+" : String(n);
    label.textContent = n ? `Notifications, ${n} unread` : "Notifications";
    btn.title = n ? `${n} unread notification${n === 1 ? "" : "s"}` : "Notifications";
    allBtn.hidden = !n;
    const c = readMeCache();
    if (c && c.unread !== n) writeMeCache({ ...c, unread: n });
  };
  setCount(unread);
  setBellCount = setCount;
  const api = (body) =>
    fetch("/api/notifications.php", {
      method: body ? "POST" : "GET",
      credentials: "same-origin",
      keepalive: !!body,
      headers: body ? { "Content-Type": "application/json", "X-Requested-With": "pony-games" } : {},
      body: body ? JSON.stringify(body) : undefined,
    }).then((r) => r.json());

  async function load() {
    try {
      const d = await api();
      setCount(d.unread || 0);
      list.innerHTML = (d.items || []).length
        ? d.items
            .map(
              (n) => `<li class="notif__item${n.read ? "" : " is-unread"}">
          <a href="${escapeHtml(n.link || "/account")}" data-notif-id="${n.id}">
            ${n.avatar_color
              ? `<span class="notif__thumb notif__thumb--avatar pony-avatar" style="--avatar-bg: ${escapeHtml(n.avatar_color)}"></span>`
              : n.thumb ? `<img class="notif__thumb" src="${escapeHtml(n.thumb)}" alt="" loading="lazy" width="44" height="44">` : `<span class="notif__thumb"></span>`}
            <span class="notif__text"><strong>${escapeHtml(n.title)}</strong><span>${escapeHtml(n.body)}</span><time>${timeAgo(n.at)}</time></span>
          </a></li>`
            )
            .join("")
        : `<li class="notif__empty">Nothing yet. When a game you bookmarked gets an update, you'll see it here.</li>`;
    } catch {
      list.innerHTML = `<li class="notif__empty">Couldn't load your notifications.</li>`;
    }
  }
  const close = () => {
    menu.hidden = true;
    btn.setAttribute("aria-expanded", "false");
  };
  btn.addEventListener("click", () => {
    const open = menu.hidden;
    menu.hidden = !open;
    btn.setAttribute("aria-expanded", String(open));
    if (open) load();
  });
  document.addEventListener("click", (e) => {
    if (!menu.hidden && !box.contains(e.target)) close();
  });
  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && !menu.hidden) {
      close();
      btn.focus();
    }
  });
  allBtn.addEventListener("click", async () => {
    try {
      const d = await api({ action: "read-all" });
      setCount(d.unread || 0);
      list.querySelectorAll(".is-unread").forEach((li) => li.classList.remove("is-unread"));
    } catch {}
  });
  list.addEventListener("click", (e) => {
    const a = e.target.closest("[data-notif-id]");
    if (!a || !a.closest(".is-unread")) return;
    // mark read on the way to the game page (keepalive survives the page change)
    api({ action: "read", id: Number(a.dataset.notifId) }).catch(() => {});
  });
}

// account.php: carousel with the bookmarked games
async function initAccountBookmarks() {
  const grid = document.getElementById("account-bookmarks");
  if (!grid) return;
  let ids = [];
  try {
    ids = JSON.parse(grid.dataset.bookmarks || "[]").map(String);
  } catch {}
  try {
    const data = await fetch("/data/games.json").then((r) => r.json());
    const map = new Map((data.games || data).map((g) => [String(g.id), g]));
    const games = ids.map((id) => map.get(id)).filter(Boolean);
    grid.innerHTML = games.map((g) => renderGameCard(g, { upcoming: g.status === "In Development" || g.status === UPCOMING_STATUS })).join("");
    bindGameCards(grid, map, null);
    initCarousels();
  } catch {
    grid.innerHTML = `<p class="account-empty">Couldn't load your bookmarks. Please refresh the page.</p>`;
  }
}

document.addEventListener("DOMContentLoaded", () => {
  // first, so an error in one of the page parts below can't stop the count
  trackPageView();
  // the page you're on, as the menu links it: "/games" also for the old "/games.html", "/" for the home page
  const currentPage = location.pathname.replace(/\/index\.html$/, "/").replace(/\.html$/, "") || "/";
  document.querySelectorAll(".site-nav a").forEach((link) => {
    if (link.getAttribute("href") === currentPage) link.classList.add("active");
  });
  // the upcoming page sits under "Games" in the menu
  if (currentPage === "/upcoming") document.querySelector(".nav-dropdown__trigger")?.classList.add("active");
  initNavDropdown();

  initThemeSwitcher();
  initGlobalJuice();
  initAuthorSearch();
  initSearchShortcut();
  initHome();
  initJamHistory();
  initMascotLightbox();
  initAccount();
  initAccountBookmarks();
  initRatings();
  initShareButtons();
  initClickTracking();
  initImageLoading();

  if (document.getElementById("card-grid")?.dataset.type === "games") {
    initGamesPage();
  }

  if (document.getElementById("card-grid")?.dataset.type === "resources") {
    initCardGrid("data/resources.json");
  }
});

// at the very end, when everything above exists: the header from the last visit, before /api/me.php answers
paintFromCache();
