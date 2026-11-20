<?php
/**
 * Home page (/). Used to be index.html; the old address still works
 * (it redirects here, see deploy/nginx-ponygames.conf). Header, footer and
 * <head> come from inc/layout.php.
 */

declare(strict_types=1);
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/layout.php';

render_page_start('Pony Games', 'home', 'Pony Games: your central directory for pony games, jams, and community resources.', false, [
    'canonical' => '/',
    'full_title' => true,
    'head' => <<<'HTML'
  <script src="https://cdn.jsdelivr.net/gh/MarketingPipeline/Markdown-Tag/markdown-tag.js"></script>
  <link rel="stylesheet" href="https://mlp-ring.neocities.org/ring-js/onionring.css">
HTML
]);
?>

  <main class="container home">

    <!-- ===================== HERO: SEARCH + GENRES ===================== -->
    <section class="home-hero" aria-labelledby="home-title">
      <div class="mascot-hello">
        <img class="mascot-hello__img" src="assets/mascot-head.png" alt="Pony Games mascot" width="76" height="99" />
        <p class="mascot-hello__bubble">Hi everypony! What are we playing today?</p>
      </div>
      <h1 id="home-title" class="home-hero__title">Discover <span id="hero-count">hundreds of</span> pony fan games</h1>
      <p class="home-hero__sub">A community-run directory of pony fan games, jams and dev resources<span id="hero-makers"></span>.</p>
      <form class="home-search" action="/games" method="get" role="search">
        <label for="home-search-input" class="visually-hidden">Search games</label>
        <input id="home-search-input" name="search" type="search" placeholder="Search by title or author…" autocomplete="off" />
        <button type="submit">Giddy up!</button>
      </form>
      <nav id="home-genres" class="home-genres" aria-label="Browse by genre"></nav>
    </section>

    <!-- ===================== FEATURED GAME + JAM TIMER ===================== -->
    <section class="home-top">
      <article id="featured-game" class="featured" aria-label="Random pick"></article>

      <aside id="jam-timer" class="jam-timer" aria-label="Game jam">
        <p class="jam-timer__status"></p>
        <h2 class="jam-timer__title"></h2>
        <p class="jam-timer__message"></p>
        <div class="jam-timer__countdown" hidden></div>
        <a class="jam-timer__link btn btn--accent" hidden></a>
        <div id="site-stats" class="jam-timer__stats"></div>
      </aside>
    </section>

    <!-- ===================== NEWEST ADDITIONS ===================== -->
    <section aria-labelledby="newest-title">
      <div class="section-head">
        <h2 id="newest-title" class="section-title">Fresh from the stable</h2>
        <a class="section-head__link" href="/games">The whole herd →</a>
        <div class="carousel-nav" data-carousel="newest-grid">
          <button type="button" class="carousel-btn" data-dir="-1" aria-controls="newest-grid" aria-label="Previous new games" disabled>
            <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M15 6l-6 6 6 6"/></svg>
          </button>
          <button type="button" class="carousel-btn" data-dir="1" aria-controls="newest-grid" aria-label="Next new games">
            <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M9 6l6 6-6 6"/></svg>
          </button>
        </div>
      </div>
      <div id="newest-grid" class="card-grid carousel" tabindex="0" aria-label="Fresh from the stable, scroll for more"></div>
    </section>

    <!-- ===================== UPCOMING / IN DEVELOPMENT ===================== -->
    <section class="upcoming-section" aria-labelledby="upcoming-title">
      <div class="section-head">
        <h2 id="upcoming-title" class="section-title">Still in the workshop</h2>
        <div class="section-head__links">
          <a id="incomplete-all" class="section-head__link" href="/games?status=In%20Development">Unfinished →</a>
          <a id="upcoming-all" class="section-head__link" href="/upcoming">Upcoming →</a>
        </div>
        <div class="carousel-nav" data-carousel="upcoming-grid">
          <button type="button" class="carousel-btn" data-dir="-1" aria-controls="upcoming-grid" aria-label="Previous games in development" disabled>
            <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M15 6l-6 6 6 6"/></svg>
          </button>
          <button type="button" class="carousel-btn" data-dir="1" aria-controls="upcoming-grid" aria-label="Next games in development">
            <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M9 6l6 6-6 6"/></svg>
          </button>
        </div>
      </div>
      <div id="upcoming-grid" class="card-grid carousel" tabindex="0" aria-label="Still in the workshop, scroll for more"></div>
    </section>

    <!-- ===================== JAM HISTORY ===================== -->
    <section id="jams" aria-labelledby="jams-title">
      <div class="section-head">
        <h2 id="jams-title" class="section-title">Past jams of the herd</h2>
        <div class="carousel-nav" data-carousel="jam-history">
          <button type="button" class="carousel-btn" data-dir="-1" aria-controls="jam-history" aria-label="Previous jams" disabled>
            <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M15 6l-6 6 6 6"/></svg>
          </button>
          <button type="button" class="carousel-btn" data-dir="1" aria-controls="jam-history" aria-label="Next jams">
            <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M9 6l6 6-6 6"/></svg>
          </button>
        </div>
      </div>
      <div id="jam-history" class="jam-history" tabindex="0" aria-label="Past jams, scroll for more"></div>
    </section>

    <!-- ===================== NEWS ===================== -->
    <section aria-labelledby="news-title">
      <div class="section-head">
        <h2 id="news-title" class="section-title">News from the herd</h2>
      </div>
      <div id="news-grid" class="news-grid"></div>
      <div class="news-more">
        <button type="button" id="news-more" class="btn btn--ghost" hidden>Older news from the herd</button>
      </div>
    </section>

  </main>

  <!-- ===================== GAME DETAIL PANEL ===================== -->
  <div id="detail-panel" class="detail-panel" role="dialog" aria-modal="true" aria-label="Game details">
    <div class="detail-panel__content">
      <button class="modal-close detail-panel__close" type="button" aria-label="Close details">&times;</button>
      <div class="detail-panel__carousel" hidden>
        <div class="carousel__track"></div>
        <button class="carousel__btn carousel__btn--prev" type="button" aria-label="Previous screenshot">‹</button>
        <button class="carousel__btn carousel__btn--next" type="button" aria-label="Next screenshot">›</button>
        <div class="carousel__dots"></div>
      </div>
      <img class="detail-panel__thumb" src="" alt="" />
      <h2 class="detail-panel__title"></h2>
      <p class="detail-panel__author" hidden></p>
      <p class="detail-panel__release" hidden></p>
      <div class="detail-panel__playtime-wrap" hidden>
        <div class="detail-panel__playtime-header">
          <span class="detail-panel__playtime-label">Playtime</span>
          <span class="detail-panel__playtime"></span>
        </div>
        <div class="detail-panel__playtime-bar" role="presentation">
          <div class="detail-panel__playtime-fill"></div>
        </div>
      </div>
      <div class="detail-panel__meta">
        <div class="detail-panel__tags"></div>
      </div>
      <p class="detail-panel__description"></p>
      <div class="detail-panel__downloads"></div>
    </div>
  </div>

  <!-- ===================== NEWS POST DIALOG ===================== -->
  <dialog id="news-dialog" class="news-dialog" aria-labelledby="news-dialog-title">
    <button type="button" class="modal-close news-dialog__close" aria-label="Close post">&times;</button>
    <div class="news-dialog__inner">
      <div class="news-card__head news-dialog__head">
        <time class="news-card__date news-dialog__date"><span class="news-card__day"></span><span class="news-card__month"></span></time>
        <h2 id="news-dialog-title" class="news-dialog__title"></h2>
      </div>
      <div class="news-card__body news-dialog__body"></div>
    </div>
  </dialog>
<?php
render_page_end(['https://cdn.jsdelivr.net/gh/MarketingPipeline/Markdown-Tag/markdown-tag.js'], [
    'before_main' => ['/js/timer.js']
]);
