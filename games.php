<?php
/**
 * The game list (/games). Used to be games.html; the old address still works
 * (it redirects here, see deploy/nginx-ponygames.conf). Header, footer and
 * <head> come from inc/layout.php.
 */

declare(strict_types=1);
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/layout.php';

render_page_start('Games', 'games', 'Browse pony games: fan games, jam entries, and community projects.', false, [
    'canonical' => '/games'
]);
?>

  <main class="container">

    <form id="games-filter" class="games-page" role="search" aria-label="Search and filter games">

      <!-- ===================== FILTER PANEL ===================== -->
      <aside id="games-filter-panel" class="gf" aria-labelledby="gf-title">
        <div class="gf__head">
          <h2 id="gf-title" class="gf__title">Filters</h2>
          <div class="gf__head-actions">
            <button type="button" id="filter-clear" class="gf__clear" hidden>Clear all</button>
            <button type="button" id="filters-close" class="gf__close" aria-label="Close filters">&times;</button>
          </div>
        </div>

        <div class="gf__toggles">
          <label class="nsfw-toggle">
            <input type="checkbox" id="filter-nsfw" />
            <span class="nsfw-toggle__label">Show NSFW</span>
            <span class="nsfw-toggle__track"></span>
          </label>
          <label class="nsfw-toggle">
            <input type="checkbox" id="filter-gore" />
            <span class="nsfw-toggle__label">Show Gore</span>
            <span class="nsfw-toggle__track"></span>
          </label>
        </div>

        <details class="gf__group" data-facet="genres" open>
          <summary>Genre <span class="gf__group-count" hidden></span></summary>
          <div id="filter-genre" class="gf__options"></div>
        </details>
        <details class="gf__group" data-facet="playtimes" open>
          <summary>Playtime <span class="gf__group-count" hidden></span></summary>
          <div id="filter-playtime" class="gf__options"></div>
        </details>
        <details class="gf__group" data-facet="tags">
          <summary>Tags <span class="gf__group-count" hidden></span></summary>
          <div id="filter-tag" class="gf__options"></div>
        </details>
        <details class="gf__group" data-facet="releaseYears">
          <summary>Published <span class="gf__group-count" hidden></span></summary>
          <div id="filter-releaseyear" class="gf__options"></div>
        </details>
        <details class="gf__group" data-facet="statuses">
          <summary>Status <span class="gf__group-count" hidden></span></summary>
          <div id="filter-status" class="gf__options"></div>
        </details>
        <details class="gf__group" data-facet="engines">
          <summary>Engine <span class="gf__group-count" hidden></span></summary>
          <div id="filter-engine" class="gf__options"></div>
        </details>
        <details class="gf__group" data-facet="platforms">
          <summary>Platform <span class="gf__group-count" hidden></span></summary>
          <div id="filter-platform" class="gf__options"></div>
        </details>

        <div class="gf__apply">
          <button type="button" id="filters-apply" class="btn btn--accent">Show games</button>
        </div>
      </aside>
      <div id="gf-backdrop" class="gf-backdrop" aria-hidden="true"></div>

      <!-- ===================== RESULTS ===================== -->
      <div class="games-main">
        <div class="games-head">
          <h1 class="page-title">Pony Games</h1>
          <p class="games-head__sub"><span id="filter-results">0 games</span><span id="filter-total"></span> · Click a card for details · <a href="/submit">Add your game to the herd</a></p>
        </div>

        <div class="games-toolbar">
          <div class="games-search">
            <svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"></circle><path d="M20 20l-3.5-3.5"></path></svg>
            <label for="filter-search" class="visually-hidden">Search games</label>
            <input id="filter-search" type="search" placeholder="Search by title, author, tag…" autocomplete="off" />
          </div>
          <button type="button" id="filter-bookmarks" class="btn btn--ghost bookmarks-toggle" aria-pressed="false" hidden title="Show only games you bookmarked">
            <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg>
            Bookmarks <span class="bookmarks-toggle__count"></span>
          </button>
          <button type="button" id="filters-open" class="btn btn--accent filters-open" aria-controls="games-filter-panel" aria-expanded="false">Filters <span id="filters-open-count" class="filters-open__count" hidden></span></button>
          <label for="filter-sort" class="visually-hidden">Sort by</label>
          <select id="filter-sort" class="games-select">
            <option value="newest">Newest added</option>
            <option value="oldest">Oldest added</option>
            <option value="az">A → Z</option>
            <option value="za">Z → A</option>
            <option value="release-newest">Release date (newest)</option>
            <option value="release-oldest">Release date (oldest)</option>
            <option value="playtime-asc">Playtime (short → long)</option>
            <option value="playtime-desc">Playtime (long → short)</option>
            <option value="rated">Best rated</option>
            <option value="reviewed">Most reviewed</option>
            <option value="downloaded">Most downloaded</option>
            <option value="played">Most played</option>
            <option value="random">Random</option>
          </select>
          <label for="filter-perpage" class="visually-hidden">Games per page</label>
          <select id="filter-perpage" class="games-select">
            <option value="50">50 per page</option>
            <option value="100">100 per page</option>
            <option value="200">200 per page</option>
            <option value="500">500 per page</option>
            <option value="all">All at once</option>
          </select>
        </div>

        <div id="filter-chips" class="active-chips" aria-label="Active filters"></div>

        <div id="card-grid" class="card-grid" data-type="games"></div>
        <nav id="games-pagination" class="pagination" aria-label="Games pagination" hidden>
          <button id="games-prev" class="pagination__btn" type="button">← Prev</button>
          <span id="games-page-info" class="pagination__info">Page 1 of 1</span>
          <button id="games-next" class="pagination__btn" type="button">Next →</button>
        </nav>
      </div>
    </form>

  </main>

  <!-- ===================== DETAIL PANEL ===================== -->
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
<?php
render_page_end([]);
