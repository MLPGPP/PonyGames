<?php
/**
 * The bounty board (/bounties). Used to be bounties.html; the old address still works
 * (it redirects here, see deploy/nginx-ponygames.conf). Header, footer and
 * <head> come from inc/layout.php.
 */

declare(strict_types=1);
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/layout.php';

render_page_start('Bounty Board: missing pony games', 'bounties', 'The Pony Games bounty board: pony fan games we know existed but can\'t find. Seen one of these? Help bring it back, dead or alive!', false, [
    'canonical' => '/bounties'
]);
?>

  <main class="container bounty-page">

    <section class="bounty-hero">
      <div class="bounty-hero__text">
        <p class="bounty-hero__kicker">Bounty board</p>
        <h1 class="page-title bounty-hero__title">Looking for these games, <span>dead or alive!</span></h1>
        <p class="bounty-hero__lead">
          These pony games once existed, but every link we know is dead. Maybe a copy is still sitting on
          an old hard drive, a forgotten forum or a web archive. Seen one? Click its poster and tell us where.
        </p>
        <p class="bounty-hero__reward">Reward: the eternal gratitude of the herd. (That's all, sorry.)</p>
      </div>
      <dl class="bounty-hero__stats" id="bounty-stats" aria-live="polite">
        <div><dt>Wanted</dt><dd data-stat="wanted">0</dd></div>
        <div><dt>Found</dt><dd data-stat="found">0</dd></div>
      </dl>
    </section>

    <div class="bounty-toolbar">
      <div class="bounty-tabs" role="tablist" aria-label="Show bounties">
        <button type="button" role="tab" class="bounty-tab is-active" aria-selected="true" data-filter="wanted">Wanted</button>
        <button type="button" role="tab" class="bounty-tab" aria-selected="false" data-filter="found">Found</button>
        <button type="button" role="tab" class="bounty-tab" aria-selected="false" data-filter="all">All</button>
      </div>
      <label class="bounty-search">
        <span class="visually-hidden">Search bounties</span>
        <input type="search" id="bounty-search" placeholder="Search by title or author…" autocomplete="off" />
      </label>
      <button type="button" class="btn btn--ghost" data-bounty-new>Know a missing game? Post a bounty</button>
    </div>

    <div id="bounty-grid" class="bounty-grid" aria-live="polite">
      <p class="bounty-loading">Pinning posters to the board…</p>
    </div>

  </main>

  <!-- ===================== TIP / NEW BOUNTY DIALOG ===================== -->
  <dialog id="bounty-dialog" class="bounty-dialog" aria-labelledby="bounty-dialog-title">
    <button type="button" class="modal-close" data-close aria-label="Close">&times;</button>
    <form class="bounty-form" novalidate>
      <p class="bounty-form__kicker" data-kicker>Wanted</p>
      <h2 id="bounty-dialog-title" class="bounty-form__title" data-title>I've seen this game!</h2>
      <p class="bounty-form__intro" data-intro></p>
      <input type="hidden" name="bounty" />

      <label class="post-form__field" data-new-only hidden>Game title <input name="title" maxlength="120" placeholder="What was the game called?" /></label>
      <label class="post-form__field" data-new-only hidden>Made by <input name="author" maxlength="120" placeholder="Author or team, if you know it" /></label>

      <label class="post-form__field"><span data-links-label>Where can it be found?</span>
        <textarea name="links" rows="3" maxlength="3000" placeholder="Download links, archive.org pages, old forum posts… one per line"></textarea>
      </label>
      <label class="post-form__field">Anything else we should know?
        <textarea name="notes" rows="3" maxlength="2000" placeholder="Version, file name, who might still have it…"></textarea>
      </label>
      <div class="bounty-form__row">
        <label class="post-form__field"><span>Your name <span class="bounty-form__opt">(optional)</span></span>
          <input name="name" maxlength="60" autocomplete="nickname" placeholder="So we can credit you" />
        </label>
        <label class="post-form__field"><span>Contact <span class="bounty-form__opt">(optional)</span></span>
          <input name="contact" maxlength="120" placeholder="Discord, email… if we may ask more" />
        </label>
      </div>
      <label class="hp" aria-hidden="true">Leave empty <input name="website" tabindex="-1" autocomplete="off" /></label>
      <div class="post-form__foot">
        <button type="submit" class="btn btn--accent" data-submit>Send tip</button>
        <p class="post-form__msg" role="status"></p>
      </div>
      <p class="bounty-form__privacy">Only the Pony Games team can read what you send here.</p>
    </form>
  </dialog>
<?php
render_page_end(['/js/bounties.js'], [
    'html' => <<<'HTML'
  <!-- ===================== MASCOT LIGHTBOX ===================== -->
  <div id="mascot-lightbox" class="mascot-lightbox" hidden>
    <button class="mascot-lightbox__close" type="button" aria-label="Close">&times;</button>
    <img class="mascot-lightbox__img" src="assets/mascot.png" alt="Pony Games mascot, enlarged" />
  </div>

HTML
]);
