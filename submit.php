<?php
/** Submit a new game, correct the info of a game that's already listed, or submit a resource (?resource). */

declare(strict_types=1);
require __DIR__ . '/inc/submissions.php';
require __DIR__ . '/inc/layout.php';

$me = current_user();
$editGame = isset($_GET['edit']) ? find_game($_GET['edit']) : null;
$mode = ($editGame || isset($_GET['fix'])) ? 'fix' : (isset($_GET['resource']) ? 'resource' : 'new');
$known = known_tags();

/** A row of checkbox chips. */
function chips(string $name, array $options, array $labels = []): string
{
    $out = '<div class="chip-checks">';
    foreach ($options as $o) {
        $out .= '<label class="chip-check"><input type="checkbox" name="' . h($name) . '[]" value="' . h($o) . '"><span>' . h($labels[$o] ?? $o) . '</span></label>';
    }
    return $out . '</div>';
}

render_page_start(
    $mode === 'fix' ? ($editGame ? 'Fix info: ' . $editGame['title'] : 'Fix game info') : ($mode === 'resource' ? 'Submit a resource' : 'Submit a game'),
    '',
    $mode === 'resource' ? 'Share free sprites, music, sounds or other assets with every pony making games.'
        : 'Add a pony fan game to Pony Games, or correct the info of a game that is already listed.',
    $mode === 'fix'
);
?>
  <main class="container submit-page">
    <header class="submit-hero">
      <p class="submit-hero__kicker"><?= $mode === 'resource' ? 'Share with the herd' : 'Help the herd' ?></p>
      <h1 class="page-title submit-hero__title"><?= ['fix' => 'Fix game info', 'resource' => 'Submit a resource', 'new' => 'Submit a game'][$mode] ?></h1>
      <p class="submit-hero__lead">
        <?php if ($mode === 'resource'): ?>
          Made sprites, music, sounds or other assets that others may use in their games? Share them here.
          A moderator checks every resource before it shows up under <a href="/resources">Resources</a>.
        <?php elseif ($mode === 'fix'): ?>
          Spotted a wrong date, a dead link or a missing tag? Correct it below. A moderator checks every change before it goes live.
        <?php else: ?>
          Made a pony game, or found one that's missing here? Tell us about it. A moderator checks every submission before it goes live,
          usually within a few days.
        <?php endif ?>
      </p>
<?php if ($mode !== 'resource'): // resources are something else: no tabs there, and no resource tab here ?>
      <nav class="submit-tabs" aria-label="What do you want to do?">
        <a href="/submit" class="submit-tab<?= $mode === 'new' ? ' is-active' : '' ?>"<?= $mode === 'new' ? ' aria-current="page"' : '' ?>>
          <span aria-hidden="true">＋</span> Add a new game</a>
        <a href="/submit?fix" class="submit-tab<?= $mode === 'fix' ? ' is-active' : '' ?>"<?= $mode === 'fix' ? ' aria-current="page"' : '' ?>>
          <span aria-hidden="true">✎</span> Fix game info</a>
      </nav>
<?php endif ?>
<?php if (!$me): ?>
      <p class="submit-hero__login"><a href="/login?next=<?= h(urlencode($_SERVER['REQUEST_URI'] ?? '/submit')) ?>">Log in</a> first if you'd like a gold star when your submission is accepted. Not required.</p>
<?php endif ?>
    </header>

<?php if ($mode === 'resource'):
    $choices = resource_choices(); ?>
    <form id="resource-form" class="submit-form" enctype="multipart/form-data" novalidate>
      <input type="hidden" name="kind" value="resource">
      <section class="submit-card">
        <h2 class="submit-card__title">The resource</h2>
        <div class="field-grid">
          <label class="post-form__field field-grid__wide">Name <span class="req" aria-hidden="true">*</span>
            <input name="title" required maxlength="150" autocomplete="off" placeholder="e.g. Applejack sprite sheet, Night forest loop"></label>
          <label class="post-form__field field-grid__wide">Made by <span class="field-hint">Separate names with commas</span>
            <input name="authors" maxlength="400" autocomplete="off"></label>
          <label class="post-form__field">License <span class="field-hint">What others may do with it</span>
            <select name="license">
              <option value="">Not sure / not listed</option>
<?php foreach ($choices['licenses'] as $l => $u): ?>
              <option value="<?= h($l) ?>"><?= h($l) ?></option>
<?php endforeach ?>
              <option value="other">Something else…</option>
            </select></label>
          <label class="post-form__field" data-license-other hidden>Which license?
            <input name="license_other" maxlength="60" autocomplete="off"></label>
          <label class="post-form__field">Part of an asset pack? <span class="field-hint">optional</span>
            <input name="assetPack" maxlength="80" list="pack-list" autocomplete="off"></label>
          <datalist id="pack-list"><?php foreach ($choices['packs'] as $p): ?><option value="<?= h($p) ?>"><?php endforeach ?></datalist>
        </div>
        <fieldset class="field-group">
          <legend>Type <span class="req" aria-hidden="true">*</span> <span class="field-hint">Pick all that fit</span></legend>
          <?= chips('tags', $choices['tags']) ?>
        </fieldset>
        <label class="post-form__field">Description <span class="field-hint">What's in it, sizes, formats</span>
          <textarea name="fullDescription" rows="4" maxlength="3000" placeholder="e.g. 12 frames walk cycle, 64×64 PNG, transparent background."></textarea></label>
        <label class="post-form__field">How should people credit you? <span class="field-hint">optional</span>
          <input name="attribution" maxlength="300" autocomplete="off" placeholder="e.g. Music by SylrePony (CC-BY 4.0)"></label>
      </section>

      <section class="submit-card">
        <h2 class="submit-card__title">Downloads <span class="req" aria-hidden="true">*</span></h2>
        <label class="post-form__field">Where can it be downloaded? <span class="field-hint">One link per line: itch.io, Google Drive, Mega, GitHub…</span>
          <textarea name="downloads" rows="3" maxlength="3000" placeholder="https://…"></textarea></label>
      </section>

      <section class="submit-card" id="pictures-card">
        <h2 class="submit-card__title">Previews</h2>
        <p class="submit-card__text">A few pictures that show what's in it. The first one is the thumbnail: drag to reorder, ★ makes a picture the first, ✂ crops.
          Up to <?= SUBMIT_MAX_IMAGES ?> pictures, 5 MB each. For music or sounds you can skip this.</p>
        <ol class="shot-grid" id="shot-grid" aria-label="Previews, in order"></ol>
        <label class="drop-zone" id="drop-zone">
          <input type="file" id="image-input" accept="image/png,image/jpeg,image/webp,image/gif" multiple>
          <span class="drop-zone__icon" aria-hidden="true">🖼️</span>
          <span class="drop-zone__text"><strong>Choose pictures</strong> or drop them here</span>
        </label>
        <p class="post-form__msg" role="status" id="shots-msg"></p>
      </section>

      <section class="submit-card">
        <h2 class="submit-card__title">About you</h2>
        <label class="settings-check submit-dev"><input type="checkbox" name="is_dev" value="1"> I made this resource (or I'm part of the team)</label>
        <div class="field-grid">
          <label class="post-form__field"><span>Your name <span class="field-hint">optional</span></span>
            <input name="name" maxlength="60" autocomplete="nickname" value="<?= $me ? h($me['display_name']) : '' ?>" placeholder="So we can credit you"></label>
          <label class="post-form__field"><span>Contact <span class="field-hint">optional</span></span>
            <input name="contact" maxlength="120" placeholder="Discord, email… if we may ask questions"></label>
        </div>
        <label class="post-form__field">Anything else for the moderators? <span class="field-hint">optional</span>
          <textarea name="note" rows="2" maxlength="3000"></textarea></label>
        <label class="hp" aria-hidden="true">Leave empty <input name="website" tabindex="-1" autocomplete="off"></label>
      </section>

      <div class="submit-actions">
        <button type="submit" class="btn btn--accent submit-actions__send">Submit resource</button>
        <p class="post-form__msg" role="status" id="submit-msg"></p>
      </div>
      <p class="submit-fine">Only the Pony Games team sees your name and contact info. Only share things you made yourself or that are free to share.
        By submitting you're okay with us showing the info and pictures on the site.</p>
    </form>
<?php else: ?>
<?php if ($mode === 'fix'): ?>
    <section class="submit-card game-picker" id="game-picker"<?= $editGame ? ' hidden' : '' ?>>
      <h2 class="submit-card__title">Which game?</h2>
      <label class="post-form__field"><span class="visually-hidden">Search for a game</span>
        <input type="search" id="game-picker-search" placeholder="Type a title or maker…" autocomplete="off" aria-controls="game-picker-results">
      </label>
      <ul id="game-picker-results" class="game-picker__results" role="listbox" aria-label="Games"></ul>
    </section>
<?php endif ?>

    <form id="submit-form" class="submit-form" data-mode="<?= $mode ?>" data-edit="<?= $editGame ? h((string)$editGame['id']) : '' ?>"
          enctype="multipart/form-data" novalidate<?= $mode === 'fix' && !$editGame ? ' hidden' : '' ?>>
      <input type="hidden" name="kind" value="<?= $mode === 'fix' ? 'edit' : 'game' ?>">
      <input type="hidden" name="game" value="<?= $editGame ? h((string)$editGame['id']) : '' ?>">

<?php if ($mode === 'fix'): ?>
      <section class="submit-card submit-card--picked">
        <div class="picked-game" id="picked-game"></div>
        <p class="pending-note" id="pending-note" hidden><span aria-hidden="true">⏳</span>
          Someone already suggested a change to this game and it's waiting for a moderator. You can still send yours.</p>
        <label class="post-form__field">What's wrong? <span class="field-hint">Optional if you fix it in the form below</span>
          <textarea name="note" rows="3" maxlength="3000" placeholder="e.g. The download link is dead, here's the new one. Or: this game came out in 2015, not 2013."></textarea>
        </label>
      </section>
<?php endif ?>

      <section class="submit-card">
        <h2 class="submit-card__title">The basics</h2>
        <div class="field-grid">
          <label class="post-form__field field-grid__wide">Title <span class="req" aria-hidden="true">*</span>
            <input name="title" required maxlength="150" autocomplete="off"></label>
          <label class="post-form__field field-grid__wide">Made by <span class="field-hint">Separate names with commas</span>
            <input name="authors" maxlength="400" placeholder="e.g. SylrePony, StudioWhy" autocomplete="off"></label>
          <label class="post-form__field">Status <span class="req" aria-hidden="true">*</span>
            <select name="status" required>
              <option value="">Choose…</option>
<?php foreach (SUBMIT_STATUSES as $val => $label): ?>
              <option value="<?= h($val) ?>"><?= h($label) ?></option>
<?php endforeach ?>
            </select></label>
          <label class="post-form__field">Release date <span class="field-hint">2014, 2014-05 or 2014-05-21</span>
            <input name="releaseDate" maxlength="10" pattern="\d{4}(-\d{2}(-\d{2})?)?" placeholder="YYYY-MM-DD" inputmode="numeric" autocomplete="off"></label>
          <label class="post-form__field">Engine
            <select name="engine">
<?php foreach (SUBMIT_ENGINES as $e): ?>
              <option value="<?= h($e) ?>"><?= h($e === 'UE' ? 'Unreal Engine' : $e) ?></option>
<?php endforeach ?>
              <option value="other">Something else…</option>
            </select></label>
          <label class="post-form__field" data-engine-other hidden>Which engine?
            <input name="engine_other" maxlength="40" autocomplete="off"></label>
          <label class="post-form__field">How long to play?
            <select name="playtime">
<?php foreach (SUBMIT_PLAYTIMES as $p): ?>
              <option value="<?= h($p) ?>"><?= h($p === 'Unknown' ? 'Not sure' : $p) ?></option>
<?php endforeach ?>
            </select></label>
        </div>
        <fieldset class="field-group">
          <legend>Platforms</legend>
          <?= chips('platforms', SUBMIT_PLATFORMS, ['OSX' => 'macOS', 'Web' => 'Web browser']) ?>
        </fieldset>
      </section>

      <section class="submit-card">
        <h2 class="submit-card__title">About the game</h2>
        <label class="post-form__field">Description <span class="req" aria-hidden="true">*</span>
          <textarea name="fullDescription" rows="6" maxlength="5000" placeholder="What is it about? What do you do in the game? The maker's own description is perfect."></textarea>
        </label>
        <label class="post-form__field">Short description <span class="field-hint">One line for the game cards. We'll make one if you leave it empty</span>
          <input name="shortDescription" maxlength="200" autocomplete="off"></label>
      </section>

      <section class="submit-card">
        <h2 class="submit-card__title">Tags</h2>
        <fieldset class="field-group">
          <legend>Genres <span class="field-hint">Pick a few that fit best</span></legend>
          <?= chips('genres', $known['genre']) ?>
        </fieldset>
        <fieldset class="field-group">
          <legend>Style</legend>
          <?= chips('tags', $known['other'], ['G4' => 'G4 (FiM)', 'G5' => 'G5', 'G1-G3' => 'G1 to G3']) ?>
        </fieldset>
        <fieldset class="field-group">
          <legend>Characters</legend>
          <?= chips('characters', SUBMIT_CHARACTERS, ['M6' => 'Mane 6', 'CMC' => 'Cutie Mark Crusaders', 'OC' => 'Original characters']) ?>
        </fieldset>
        <fieldset class="field-group field-group--warn">
          <legend>Content warnings <span class="field-hint">Please tick these when they apply, they hide the pictures until people choose to see them</span></legend>
          <?= chips('warnings', SUBMIT_WARNINGS, ['NSFW' => '🔞 NSFW (sexual content)', 'Gore' => '🩸 Gore']) ?>
        </fieldset>
      </section>

      <section class="submit-card" id="links-card">
        <h2 class="submit-card__title">Links</h2>
        <p class="submit-card__text">Where can people play, download or follow the game? <span data-links-required>At least one link, please.</span></p>
        <div class="link-rows" id="link-rows"></div>
        <button type="button" class="btn btn--ghost link-rows__add" id="add-link">＋ Add a link</button>
        <template id="link-row">
          <div class="link-row">
            <label class="post-form__field link-row__type"><span class="visually-hidden">Kind of link</span>
              <select name="link_type[]">
<?php foreach (SUBMIT_LINK_TYPES as $val => $label): ?>
                <option value="<?= h($val) ?>"><?= h($label) ?></option>
<?php endforeach ?>
              </select></label>
            <label class="post-form__field link-row__url"><span class="visually-hidden">Web address</span>
              <input type="url" name="link_url[]" maxlength="500" placeholder="https://…" autocomplete="off"></label>
            <label class="post-form__field link-row__label"><span class="visually-hidden">Label</span>
              <input name="link_label[]" maxlength="40" placeholder="Label, e.g. Windows v1.2" autocomplete="off"></label>
            <button type="button" class="link-row__remove" aria-label="Remove this link" title="Remove">×</button>
          </div>
        </template>
      </section>

      <section class="submit-card" id="pictures-card">
        <h2 class="submit-card__title">Pictures &amp; videos</h2>
        <p class="submit-card__text">
<?php if ($mode === 'fix'): ?>
          Put the pictures in the best order, add better ones or remove bad ones. The first picture is the cover.
          Drag them around or use <kbd>←</kbd> <kbd>→</kbd>; ★ makes a picture the cover, ✂ crops, 🗑 removes.
          Nothing changes on the site until a moderator has checked it.
<?php else: ?>
          A cover picture and a few screenshots. The first picture becomes the cover: drag them into the best order,
          or use ★ to make one the cover. ✂ crops a picture.
<?php endif ?>
          Up to <?= SUBMIT_MAX_IMAGES ?> new pictures, 5 MB each.</p>
        <ol class="shot-grid" id="shot-grid" aria-label="Pictures, in order"></ol>
        <label class="drop-zone" id="drop-zone">
          <input type="file" id="image-input" accept="image/png,image/jpeg,image/webp,image/gif" multiple>
          <span class="drop-zone__icon" aria-hidden="true">🖼️</span>
          <span class="drop-zone__text"><strong>Choose pictures</strong> or drop them here</span>
        </label>
        <p class="post-form__msg" role="status" id="shots-msg"></p>
        <label class="post-form__field">Or links to pictures <span class="field-hint">One per line</span>
          <textarea name="image_urls" rows="2" maxlength="3000" placeholder="https://…/screenshot1.png"></textarea>
        </label>
        <label class="post-form__field">Videos <span class="field-hint">A trailer or gameplay: YouTube links or links to a video file (.mp4, .webm), one per line. They're shown after the pictures.</span>
          <textarea name="videos" rows="2" maxlength="3000" placeholder="https://www.youtube.com/watch?v=…"></textarea>
        </label>
      </section>

      <section class="submit-card">
        <h2 class="submit-card__title">About you</h2>
        <label class="settings-check submit-dev"><input type="checkbox" name="is_dev" value="1"> I made this game (or I'm part of the team)</label>
        <div class="field-grid">
          <label class="post-form__field"><span>Your name <span class="field-hint">optional</span></span>
            <input name="name" maxlength="60" autocomplete="nickname" value="<?= $me ? h($me['display_name']) : '' ?>" placeholder="So we can credit you"></label>
          <label class="post-form__field"><span>Contact <span class="field-hint">optional</span></span>
            <input name="contact" maxlength="120" placeholder="Discord, email… if we may ask questions"></label>
        </div>
<?php if ($mode === 'new'): ?>
        <label class="post-form__field">Anything else for the moderators? <span class="field-hint">optional</span>
          <textarea name="note" rows="2" maxlength="3000"></textarea></label>
<?php endif ?>
        <label class="hp" aria-hidden="true">Leave empty <input name="website" tabindex="-1" autocomplete="off"></label>
      </section>

      <div class="submit-actions">
        <button type="submit" class="btn btn--accent submit-actions__send"><?= $mode === 'fix' ? 'Send correction' : 'Submit game' ?></button>
        <p class="post-form__msg" role="status" id="submit-msg"></p>
      </div>
      <p class="submit-fine">Only the Pony Games team sees your name and contact info. By submitting you're okay with us showing the game info and pictures on the site.</p>
    </form>
<?php endif ?>

    <section class="submit-done" id="submit-done" hidden tabindex="-1">
      <img src="/assets/mascot-head.png" alt="" width="96" height="126">
      <h2>Thank you, you're a star!</h2>
      <p data-done-text>A moderator will look at it soon.</p>
      <div class="submit-done__actions">
        <a class="btn btn--accent" href="<?= ['fix' => '/submit?fix', 'resource' => '/submit?resource', 'new' => '/submit'][$mode] ?>"><?= ['fix' => 'Fix another game', 'resource' => 'Submit another resource', 'new' => 'Submit another game'][$mode] ?></a>
        <a class="btn btn--ghost" href="<?= $mode === 'resource' ? '/resources' : '/games' ?>"><?= $mode === 'resource' ? 'Back to the resources' : 'Back to the games' ?></a>
      </div>
    </section>
  </main>
<?php
render_page_end(['/js/shot-editor.js', $mode === 'resource' ? '/js/submit-resource.js' : '/js/submit.js']);
