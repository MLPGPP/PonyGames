<?php
/**
 * Resources for game makers (/resources). Used to be resources.html; the old address still works
 * (it redirects here, see deploy/nginx-ponygames.conf). Header, footer and
 * <head> come from inc/layout.php.
 */

declare(strict_types=1);
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/layout.php';

render_page_start('Resources', 'resources', 'Free assets and resources for pony game developers: sprites, tilesets, music, and more.', false, [
    'canonical' => '/resources',
    'body_class' => 'page-resources'
]);
?>

  <main class="container">

    <form id="resources-filter" class="games-page" role="search" aria-label="Search and filter resources">

      <!-- ===================== FILTER PANEL ===================== -->
      <aside id="resources-filter-panel" class="gf" aria-labelledby="rf-title">
        <div class="gf__head">
          <h2 id="rf-title" class="gf__title">Filters</h2>
          <div class="gf__head-actions">
            <button type="button" id="resource-filter-clear" class="gf__clear" hidden>Clear all</button>
            <button type="button" id="res-filters-close" class="gf__close" aria-label="Close filters">&times;</button>
          </div>
        </div>

        <details class="gf__group" data-facet="tags" open>
          <summary>Type <span class="gf__group-count" hidden></span></summary>
          <div id="filter-res-tag" class="gf__options"></div>
        </details>
        <details class="gf__group" data-facet="assetPacks" open>
          <summary>Asset pack <span class="gf__group-count" hidden></span></summary>
          <div id="filter-res-pack" class="gf__options"></div>
        </details>
        <details class="gf__group" data-facet="authors">
          <summary>Author <span class="gf__group-count" hidden></span></summary>
          <div id="filter-res-author" class="gf__options"></div>
        </details>
        <details class="gf__group" data-facet="licenses">
          <summary>License <span class="gf__group-count" hidden></span></summary>
          <div id="filter-res-license" class="gf__options"></div>
        </details>

        <div class="gf__apply">
          <button type="button" id="res-filters-apply" class="btn btn--accent">Show resources</button>
        </div>
      </aside>
      <div id="res-gf-backdrop" class="gf-backdrop" aria-hidden="true"></div>

      <!-- ===================== RESULTS ===================== -->
      <div class="games-main">
        <div class="games-head">
          <h1 class="page-title">Resources</h1>
          <p class="games-head__sub"><span id="resource-filter-results">0 resources</span><span id="resource-filter-total"></span> · Free assets for every pony making games. Click a card for details and downloads. Made something? <a href="/submit?resource">Submit a resource</a>. Missing something? <a href="/requests">Request it</a>.</p>
        </div>

        <div class="games-toolbar">
          <div class="games-search">
            <svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"></circle><path d="M20 20l-3.5-3.5"></path></svg>
            <label for="resources-search" class="visually-hidden">Search resources</label>
            <input id="resources-search" type="search" placeholder="Search by name, tag, asset pack or author…" autocomplete="off" />
          </div>
          <button type="button" id="res-filters-open" class="btn btn--accent filters-open" aria-controls="resources-filter-panel" aria-expanded="false">Filters <span id="res-filters-open-count" class="filters-open__count" hidden></span></button>
          <label for="resource-sort" class="visually-hidden">Sort by</label>
          <select id="resource-sort" class="games-select">
            <option value="az">A → Z</option>
            <option value="za">Z → A</option>
          </select>
          <label for="resource-perpage" class="visually-hidden">Resources per page</label>
          <select id="resource-perpage" class="games-select">
            <option value="48">48 per page</option>
            <option value="96">96 per page</option>
            <option value="all">All at once</option>
          </select>
        </div>

        <div id="resource-filter-chips" class="active-chips" aria-label="Active filters"></div>

        <div id="card-grid" class="card-grid" data-type="resources"></div>
        <nav id="resources-pagination" class="pagination" aria-label="Resources pagination" hidden>
          <button id="res-prev" class="pagination__btn" type="button">← Prev</button>
          <span id="res-page-info" class="pagination__info">Page 1 of 1</span>
          <button id="res-next" class="pagination__btn" type="button">Next →</button>
        </nav>
      </div>
    </form>

  </main>

  <!-- ===================== DETAIL PANEL ===================== -->
  <div id="detail-panel" class="detail-panel" role="dialog" aria-modal="true" aria-label="Resource details">
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
      <div class="detail-panel__license-wrap" hidden>
        <span class="detail-panel__license-label">License</span>
        <span class="detail-panel__license"></span>
      </div>
      <div class="detail-panel__credit" hidden>
        <span class="detail-panel__license-label">How to credit</span>
        <p class="detail-panel__credit-text"></p>
        <button type="button" class="detail-panel__credit-copy">Copy</button>
      </div>
      <div class="detail-panel__meta">
        <div class="detail-panel__tags"></div>
      </div>
      <div class="detail-panel__asset-packs"></div>
      <p class="detail-panel__description"></p>
      <div class="detail-panel__downloads"></div>
    </div>
  </div>
<?php
render_page_end([]);
