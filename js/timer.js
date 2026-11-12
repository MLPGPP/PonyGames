/* Jam countdown. Config comes from data/jam-config.json; if that fails to
   load, fall back to the plain "no jam" state instead of counting down to
   a made-up date. */

const JAM_CONFIG_FALLBACK = {
  state: "none",
  messages: {
    none: "No jam running right now. Check back soon!",
    upcoming: "Next jam starts in",
    active: "Jam ends in",
    complete: "Jam is live!",
    ended: "Jam has ended!",
  },
};

const UNIT_CONFIG = [
  { key: "days", label: "Days", max: 365 },
  { key: "hours", label: "Hours", max: 24 },
  { key: "minutes", label: "Min", max: 60 },
  { key: "seconds", label: "Sec", max: 60 },
];

function toUTCTimestamp(dateStr) {
  if (!dateStr) return null;
  const normalized = /[+-]\d{2}:\d{2}$/.test(dateStr) || /Z$/.test(dateStr)
    ? dateStr
    : dateStr + 'Z';
  return new Date(normalized).getTime();
}

async function loadJamConfig() {
  try {
    const response = await fetch("data/jam-config.json");
    if (!response.ok) throw new Error("bad response");
    const data = await response.json();
    const processed = {
      ...JAM_CONFIG_FALLBACK,
      ...data,
      messages: {
        ...JAM_CONFIG_FALLBACK.messages,
        ...(data?.messages || {}),
      },
    };
    if (data.startDate) processed.startDate = toUTCTimestamp(data.startDate);
    if (data.endDate) processed.endDate = toUTCTimestamp(data.endDate);
    return processed;
  } catch (err) {
    console.warn("jam-config.json failed to load, using fallback");
    return JAM_CONFIG_FALLBACK;
  }
}

function formatJamDate(timestamp) {
  return new Date(timestamp).toLocaleString("en-US", {
    weekday: "short",
    month: "short",
    day: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
}

function initJamTimer(config) {
  const container = document.getElementById("jam-timer");
  if (!container) return;

  const statusEl = container.querySelector(".jam-timer__status");
  const titleEl = container.querySelector(".jam-timer__title");
  const messageEl = container.querySelector(".jam-timer__message");
  const countdownEl = container.querySelector(".jam-timer__countdown");
  const linkEl = container.querySelector(".jam-timer__link");

  let intervalId = null;

  function pad(n) {
    return String(n).padStart(2, "0");
  }

  function setTitle(text) {
    if (!titleEl) return;
    titleEl.textContent = text || "";
    titleEl.hidden = !text;
  }

  function buildCountdownShell(showProgress) {
    countdownEl.innerHTML = `
      <div class="jam-timer__units" role="timer" aria-live="off">
        ${UNIT_CONFIG.map(
          (u) => `
          <div class="jam-timer__unit" data-unit="${u.key}">
            <span class="jam-timer__digit">00</span>
            <span class="jam-timer__label">${u.label}</span>
          </div>
        `
        ).join("")}
      </div>
      ${
        showProgress
          ? `<div class="jam-timer__progress" role="progressbar" aria-label="Jam progress" aria-valuemin="0" aria-valuemax="100">
               <div class="jam-timer__progress-fill"></div>
             </div>
             <p class="jam-timer__progress-text"></p>`
          : ""
      }
    `;
  }

  function triggerComplete(state) {
    container.classList.add("jam-timer--complete");
    statusEl.textContent = state === "active" ? "Jam live!" : "Time's up, ponies!";
    messageEl.textContent =
      state === "active" ? config.messages.complete : config.messages.ended;
    countdownEl.classList.add("jam-timer__countdown--finished");

    if (state === "active" && config.currentJamUrl) {
      linkEl.hidden = false;
      linkEl.href = config.currentJamUrl;
      linkEl.textContent = `Hoof it to ${config.currentJamLabel}`;
      linkEl.classList.add("jam-timer__link--pulse");
    }
  }

  function renderNoJam() {
    container.classList.remove("jam-timer--complete");
    countdownEl.hidden = true;
    countdownEl.classList.remove("jam-timer__countdown--finished");
    statusEl.textContent = "The jam stable is quiet";
    setTitle(config.lastJamLabel ? `Last jam: ${config.lastJamLabel}` : "");
    messageEl.textContent = config.messages.none;
    if (config.lastJamUrl) {
      linkEl.hidden = false;
      linkEl.href = config.lastJamUrl;
      linkEl.textContent = `View ${config.lastJamLabel || "last jam"}`;
    } else {
      linkEl.hidden = true;
    }
    linkEl.classList.remove("jam-timer__link--pulse");
  }

  function renderCountdown(targetTimestamp, statusLabel, messageText, linkConfig, completeState, startTimestamp) {
    container.classList.remove("jam-timer--complete");
    countdownEl.hidden = false;
    countdownEl.classList.remove("jam-timer__countdown--finished");
    statusEl.textContent = statusLabel;
    messageEl.textContent = `${messageText} ${formatJamDate(targetTimestamp)}`;

    if (linkConfig && linkConfig.url) {
      linkEl.hidden = false;
      linkEl.href = linkConfig.url;
      linkEl.textContent = linkConfig.label;
    } else {
      linkEl.hidden = true;
    }
    linkEl.classList.remove("jam-timer__link--pulse");

    const end = targetTimestamp;
    const start = startTimestamp;
    const showProgress = start !== null && start !== undefined && start < end;
    buildCountdownShell(showProgress);

    const fill = countdownEl.querySelector(".jam-timer__progress-fill");
    const bar = countdownEl.querySelector(".jam-timer__progress");
    const progressText = countdownEl.querySelector(".jam-timer__progress-text");

    function tick() {
      const now = Date.now();
      const diff = end - now;

      if (diff <= 0) {
        clearInterval(intervalId);
        triggerComplete(completeState);
        return;
      }

      const values = {
        days: Math.floor(diff / (1000 * 60 * 60 * 24)),
        hours: Math.floor((diff / (1000 * 60 * 60)) % 24),
        minutes: Math.floor((diff / (1000 * 60)) % 60),
        seconds: Math.floor((diff / 1000) % 60),
      };

      UNIT_CONFIG.forEach((u) => {
        const digit = countdownEl.querySelector(`[data-unit="${u.key}"] .jam-timer__digit`);
        if (digit) digit.textContent = u.key === "days" ? String(values.days) : pad(values[u.key]);
      });

      if (showProgress && fill) {
        const pct = Math.min(100, Math.max(0, ((now - start) / (end - start)) * 100));
        fill.style.width = `${pct}%`;
        bar.setAttribute("aria-valuenow", String(Math.round(pct)));
        if (progressText) progressText.textContent = `${Math.round(pct)}% of the jam has galloped by`;
      }
    }

    if (intervalId) clearInterval(intervalId);
    tick();
    intervalId = setInterval(tick, 1000);
  }

  switch (config.state) {
    case "none":
      renderNoJam();
      break;
    case "upcoming":
      setTitle(config.currentJamLabel);
      renderCountdown(
        config.startDate,
        "Upcoming jam",
        "Starts",
        config.currentJamUrl ? { url: config.currentJamUrl, label: `View ${config.currentJamLabel}` } : null,
        "upcoming",
        null
      );
      break;
    case "active":
      setTitle(config.currentJamLabel);
      renderCountdown(
        config.endDate,
        "Jam in progress!",
        "Ends",
        { url: config.currentJamUrl, label: "Hoof it to the jam!" },
        "active",
        config.startDate
      );
      break;
    default:
      console.warn(`timer.js: Unknown state "${config.state}". Falling back to "none".`);
      renderNoJam();
  }
}

loadJamConfig().then(initJamTimer);
