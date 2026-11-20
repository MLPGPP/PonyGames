<?php
/** Admin: list, add, edit and delete games (writes data/games.json, with backups). */

declare(strict_types=1);
require __DIR__ . '/../inc/screenshots.php';
require __DIR__ . '/../inc/admin_ui.php';

$csrf = admin_require();

/* ---------------------------------------------------------------- download */
if (isset($_GET['download'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="games.json"');
    readfile(content_path('games'));
    exit;
}

/* ---------------------------------------------------------------- save / delete */
$errors = [];
$posted = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!admin_csrf_ok()) {
        $errors[] = 'Your session expired, please try again.';
    } else {
        try {
            if (($_POST['do'] ?? '') === 'sheet-sync') {
                $st = sheet_sync_now();
                $_SESSION['admin_flash'] = $st['ok'] ? 'Sent ' . $st['rows'] . ' games to the Google Sheet.' : 'Google Sheet sync failed: ' . $st['error'];
                header('Location: games.php'); exit;
            }
            if (($_POST['do'] ?? '') === 'rollback') {
                $_SESSION['admin_flash'] = rollback_content('games', (int)($_POST['log'] ?? 0)) . '. A backup of the version before the rollback was kept, so you can undo it again.';
                header('Location: games.php'); exit;
            }
            $data = load_content('games');
            $list = &$data['games'];
            $id = (string)($_POST['id'] ?? '');
            $index = null;
            foreach ($list as $i => $g) if ((string)$g['id'] === $id) { $index = $i; break; }
            $do = (string)($_POST['do'] ?? '');

            if ($do === 'delete') {
                if ($index === null) throw new RuntimeException('That game doesn\'t exist anymore.');
                if (empty($_POST['confirm'])) throw new RuntimeException('Tick the box to confirm deleting.');
                $title = $list[$index]['title'];
                array_splice($list, $index, 1);
                save_content('games', $data, "Deleted game #$id $title");
                $_SESSION['admin_flash'] = "Deleted “{$title}”. A backup of the old games.json was saved.";
                header('Location: games.php'); exit;
            }

            $isNew = $index === null;
            if ($isNew) $id = (string)next_game_id($list);
            $old = $isNew ? ['id' => (int)$id] : $list[$index];

            if ($do === 'save-json') {
                $g = json_decode((string)($_POST['json'] ?? ''), true);
                if (!is_array($g)) throw new RuntimeException('That isn\'t valid JSON: ' . json_last_error_msg());
                $g['id'] = $old['id']; // the id can't change here
            } else {
                $g = game_from_post($_POST, $old);
            }
            $posted = $g;

            // the screenshot editor: new order, crops, removed and added pictures
            $shotItems = null;
            $shotOrder = json_decode((string)($_POST['shots_order'] ?? ''), true);
            if ($do === 'save' && is_array($shotOrder)) {
                $current = game_shots($old);
                $files = $_FILES['shot_files'] ?? null;
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $shotItems = [];
                $keptOnly = [];
                foreach ($shotOrder as $it) {
                    if (isset($it['keep']) && in_array($it['keep'], $current, true)) {
                        $shotItems[] = ['keep' => $it['keep']];
                        $keptOnly[] = $it['keep'];
                    } elseif (isset($it['file']) && $files && isset($files['tmp_name'][(int)$it['file']])) {
                        $i = (int)$it['file'];
                        if ($files['error'][$i] !== UPLOAD_ERR_OK) throw new RuntimeException('A picture didn\'t upload (too big?). Try again.');
                        $ext = SHOT_EXTS[$finfo->file($files['tmp_name'][$i])] ?? null;
                        if (!$ext || @getimagesize($files['tmp_name'][$i]) === false) throw new RuntimeException('Pictures have to be JPG, PNG, WebP or GIF.');
                        $shotItems[] = ['file' => $files['tmp_name'][$i], 'ext' => $ext];
                        $keptOnly = null;
                    }
                }
                if (count($shotItems) > 60) throw new RuntimeException('That are a lot of pictures, please keep it under 60.');
                // nothing moved, cropped or added: leave the files alone
                if ($keptOnly !== null && $keptOnly === $current) $shotItems = null;
            } elseif (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
                throw new RuntimeException('Too much to upload at once (server limit). Save fewer new pictures at a time.');
            }

            if ($problems = game_problems($g)) {
                $errors = $problems;
            } else {
                $shotBackup = [];
                if ($shotItems !== null) {
                    $g['id'] = $isNew ? (int)$id : $g['id'];
                    [$g['thumbnail'], $g['screenshots'], $sb] = save_game_shots($g, $shotItems, shot_folder_users(all_games()));
                    $shotBackup = [$sb]; // the old pictures go with this save, so a rollback puts them back
                }
                if ($isNew) $list[] = $g; else $list[$index] = $g;
                save_content('games', $data, ($isNew ? 'Added' : 'Edited') . " game #$id " . $g['title'] . ($shotItems !== null ? ' (pictures)' : ''), $shotBackup);
                $_SESSION['admin_flash'] = ($isNew ? 'Added' : 'Saved') . " “{$g['title']}”.";
                $goto = $id;
                if (!empty($_POST['next'])) {
                    $ids = array_map(fn($x) => (string)$x['id'], $list);
                    $pos = array_search((string)$id, $ids, true);
                    if ($pos !== false && isset($ids[$pos + 1])) $goto = $ids[$pos + 1];
                }
                header('Location: games.php?id=' . urlencode($goto)); exit;
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

/* ---------------------------------------------------------------- page */
$all = all_games();
$editing = null;
$isNew = isset($_GET['new']);
if (isset($_GET['id'])) $editing = $all[(string)$_GET['id']] ?? null;
if ($posted) $editing = $posted;
if ($isNew && !$editing) {
    $editing = ['id' => null, 'title' => '', 'author' => [], 'thumbnail' => '', 'shortDescription' => '', 'fullDescription' => '', 'tags' => [],
        'characters' => [], 'playtime' => 'Unknown', 'status' => 'Released', 'engine' => 'Unknown', 'platforms' => [], 'url' => '',
        'releaseDate' => '', 'dateAdded' => date('Y-m-d'), 'downloads' => null, 'screenshots' => []];
}

admin_head($editing ? ($editing['title'] ?: 'New game') : 'Games');
admin_nav('games');
echo '<main class="wide">';
if (!empty($_SESSION['admin_flash'])) { echo '<p class="flash">' . h($_SESSION['admin_flash']) . '</p>'; unset($_SESSION['admin_flash']); }
if ($errors) { echo '<div class="flash flash--error">'; foreach ($errors as $e) echo '<p>' . h($e) . '</p>'; echo '</div>'; }

if ($editing):
    $g = $editing;
    $ids = array_map('strval', array_keys($all));
    $pos = $isNew ? false : array_search((string)$g['id'], $ids, true);
    $prevId = $pos ? $ids[$pos - 1] : null;
    $nextId = ($pos !== false && isset($ids[$pos + 1])) ? $ids[$pos + 1] : null;
    $tagLabels = known_tags();
    $engines = array_unique(array_merge(SUBMIT_ENGINES, array_filter(array_map(fn($x) => (string)($x['engine'] ?? ''), $all))));
    sort($engines);
    $allPlatforms = array_unique(array_merge(SUBMIT_PLATFORMS, ...array_map(fn($x) => (array)($x['platforms'] ?? []), array_values($all))));
    $extraPlatforms = array_diff((array)$g['platforms'], $allPlatforms);
    $extraChars = array_diff((array)$g['characters'], array_merge(SUBMIT_CHARACTERS, ['Unknown']));
    $downloads = (array)($g['downloads'] ?? []);
    $socialLines = implode("\n", array_map(fn($s) => isset($s['label']) && $s['label'] !== '' ? $s['label'] . ' | ' . $s['url'] : $s['url'], (array)($g['socials'] ?? [])));
?>
  <p><a href="games.php">← All games</a><?php if ($g['id'] !== null && !$isNew): ?> · <a href="<?= h(game_path($g)) ?>" target="_blank">View on the site ↗</a><?php endif ?></p>
  <h2><?= $isNew ? 'New game' : 'Edit game #' . h((string)$g['id']) ?></h2>

  <form method="post" enctype="multipart/form-data" class="editor">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="id" value="<?= $isNew ? '' : h((string)$g['id']) ?>">
    <input type="hidden" name="do" value="save">

    <div class="panel">
      <h3>Basics</h3>
      <div class="grid2">
        <label class="span2">Title <input name="title" required value="<?= h($g['title']) ?>"></label>
        <label>Made by <span class="muted">one per line</span><textarea name="author" rows="2"><?= h(implode("\n", (array)$g['author'])) ?></textarea></label>
        <label>Status <select name="status"><?php foreach (GAME_STATUSES as $s): ?><option<?= ($g['status'] === $s || ($g['status'] === 'Abandoned' && $s === 'Cancelled')) ? ' selected' : '' ?>><?= h($s) ?></option><?php endforeach ?></select></label>
        <label>Release date <span class="muted">2014, 2014-05 or 2014-05-21</span><input name="releaseDate" value="<?= h((string)$g['releaseDate']) ?>"></label>
        <label>Date added <input name="dateAdded" value="<?= h((string)$g['dateAdded']) ?>" placeholder="YYYY-MM-DD"></label>
        <label>Engine <input name="engine" list="engines" value="<?= h((string)$g['engine']) ?>"></label>
        <label>Playtime <select name="playtime"><?php foreach (array_unique(array_merge(SUBMIT_PLAYTIMES, [$g['playtime'] ?: 'Unknown'])) as $p): ?><option<?= $g['playtime'] === $p ? ' selected' : '' ?>><?= h($p) ?></option><?php endforeach ?></select></label>
        <label class="span2">Website / author page <input name="url" value="<?= h((string)$g['url']) ?>" placeholder="https://"></label>
      </div>
      <datalist id="engines"><?php foreach ($engines as $e): ?><option value="<?= h($e) ?>"><?php endforeach ?></datalist>
      <fieldset><legend>Platforms</legend>
        <?php foreach ($allPlatforms as $pl): ?><label class="check"><input type="checkbox" name="platforms[]" value="<?= h($pl) ?>"<?= in_array($pl, (array)$g['platforms'], true) ? ' checked' : '' ?>> <?= h($pl) ?></label><?php endforeach ?>
        <input type="hidden" name="platforms_extra" value="<?= h(implode("\n", $extraPlatforms)) ?>">
      </fieldset>
      <fieldset><legend>Characters</legend>
        <?php foreach (SUBMIT_CHARACTERS as $c): ?><label class="check"><input type="checkbox" name="characters[]" value="<?= h($c) ?>"<?= in_array($c, (array)$g['characters'], true) ? ' checked' : '' ?>> <?= h($c) ?></label><?php endforeach ?>
        <label class="inline">Other <input name="characters_extra" value="<?= h(implode(', ', $extraChars)) ?>" placeholder="one per line"></label>
      </fieldset>
    </div>

    <div class="panel">
      <h3>Description</h3>
      <label>Description <textarea name="fullDescription" rows="6"><?= h((string)$g['fullDescription']) ?></textarea></label>
      <label>Short description <span class="muted">for cards; empty = first 50 characters of the description</span><input name="shortDescription" value="<?= h((string)$g['shortDescription']) ?>"></label>
    </div>

    <div class="panel">
      <h3>Tags</h3>
      <div class="rows" data-rows="tag">
        <?php foreach ((array)$g['tags'] as $t): if (!is_array($t)) continue; ?>
        <div class="rowline">
          <input name="tag_label[]" list="taglist" value="<?= h($t['label'] ?? '') ?>" placeholder="Tag">
          <select name="tag_type[]"><?php foreach (GAME_TAG_TYPES as $tt): ?><option<?= ($t['type'] ?? '') === $tt ? ' selected' : '' ?>><?= h($tt) ?></option><?php endforeach ?></select>
          <button type="button" class="small" data-remove-row>remove</button>
        </div>
        <?php endforeach ?>
      </div>
      <button type="button" data-add-row="tag">＋ Tag</button>
      <template data-template="tag"><div class="rowline">
        <input name="tag_label[]" list="taglist" placeholder="Tag">
        <select name="tag_type[]"><?php foreach (GAME_TAG_TYPES as $tt): ?><option><?= h($tt) ?></option><?php endforeach ?></select>
        <button type="button" class="small" data-remove-row>remove</button></div></template>
      <datalist id="taglist"><?php foreach (array_merge($tagLabels['genre'], $tagLabels['other'], SUBMIT_WARNINGS, ['Cash Grab', 'Anthro', 'Human', 'Gen AI']) as $tl): ?><option value="<?= h($tl) ?>"><?php endforeach ?></datalist>
      <p class="muted">Types: <strong>Genre</strong> (shown first), <strong>Other</strong> (2D, G4…), <strong>warning</strong> (NSFW, Gore: blurs pictures), <strong>Black Tags</strong>.</p>
    </div>

    <div class="panel">
      <h3>Downloads &amp; play links</h3>
      <div class="rows" data-rows="dl">
        <?php foreach ($downloads as $d): ?>
        <div class="dlrow">
          <input name="dl_label[]" value="<?= h((string)($d['label'] ?? '')) ?>" placeholder="Label (Windows, Web…)">
          <input name="dl_version[]" value="<?= h((string)($d['version'] ?? '')) ?>" placeholder="Version">
          <input name="dl_url[]" value="<?= h((string)($d['url'] ?? '')) ?>" placeholder="https://" class="url">
          <input name="dl_button[]" value="<?= h((string)($d['buttonText'] ?? '')) ?>" placeholder="Button text">
          <select name="dl_type[]"><?php foreach (['' => 'auto', 'download' => 'download', 'web' => 'play (web)', 'source' => 'author site'] as $k => $l): ?><option value="<?= $k ?>"<?= ($d['type'] ?? '') === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach ?></select>
          <textarea name="dl_mirrors[]" rows="2" placeholder="Mirrors: label | url, one per line"><?= h(implode("\n", array_map(fn($m) => ($m['label'] ?? '') . ' | ' . ($m['url'] ?? ''), (array)($d['mirrors'] ?? [])))) ?></textarea>
          <button type="button" class="small" data-remove-row>remove</button>
        </div>
        <?php endforeach ?>
      </div>
      <button type="button" data-add-row="dl">＋ Download / play link</button>
      <template data-template="dl"><div class="dlrow">
        <input name="dl_label[]" placeholder="Label (Windows, Web…)"><input name="dl_version[]" placeholder="Version">
        <input name="dl_url[]" placeholder="https://" class="url"><input name="dl_button[]" placeholder="Button text">
        <select name="dl_type[]"><option value="">auto</option><option value="download">download</option><option value="web">play (web)</option><option value="source">author site</option></select>
        <textarea name="dl_mirrors[]" rows="2" placeholder="Mirrors: label | url, one per line"></textarea>
        <button type="button" class="small" data-remove-row>remove</button></div></template>
      <label>Socials / Patreon / devlog <span class="muted">for upcoming games; "label | url", one per line</span>
        <textarea name="socials" rows="2"><?= h($socialLines) ?></textarea></label>
    </div>

    <div class="panel" id="pictures">
      <h3>Pictures &amp; videos</h3>
      <?php
        $tiles = array_map(fn($p) => ['path' => $p, 'managed' => shot_parts($p) !== null], game_shots($g));
        $users = shot_folder_users($all);
        $target = $isNew ? null : shot_target_folder($g, $users);
        $own = null;
        foreach (game_shots($g) as $p) if ($parts = shot_parts($p)) { $own = $parts[0]; break; }
      ?>
      <div id="shot-editor" class="shot-editor" data-tiles="<?= h(json_encode($tiles, JSON_UNESCAPED_SLASHES)) ?>">
        <p class="muted shot-help">Drag the pictures into the best order (or use ← →); the first one is the cover. ★ makes a picture the cover,
          ✂ crops, 🗑 removes. When you save, they're renamed to <code><?= $target !== null ? 's/' . h($target) . '/' : '' ?>0, 1, 2…</code>
          <?php if ($own !== null && $target !== null && $own !== $target): ?><br><strong>s/<?= h($own) ?>/ is shared with another game</strong>, so this game gets its own folder when its pictures change.<?php endif ?></p>
        <ol class="shot-grid" id="shot-grid"></ol>
        <label class="drop-add" id="shot-add">
          <input type="file" accept="image/png,image/jpeg,image/webp,image/gif" multiple>
          <span><strong>＋ Add pictures</strong> or drop them here</span>
        </label>
        <input type="hidden" name="shots_order" id="shots-order">
        <input type="file" name="shot_files[]" id="shots-files" multiple hidden>
      </div>
      <label>Videos <span class="muted">YouTube links or https links to a video file (.mp4, .webm, …), one per line: trailers or gameplay, shown after the pictures</span>
        <textarea name="videos" rows="2" placeholder="https://www.youtube.com/watch?v=…"><?= h(implode("\n", array_filter((array)($g['videos'] ?? []), 'is_string'))) ?></textarea></label>
    </div>

    <div class="savebar">
      <button class="primary"><?= $isNew ? 'Add game' : 'Save changes' ?></button>
      <?php if (!$isNew): ?><button class="primary" name="next" value="1">Save &amp; next game →</button><?php endif ?>
      <a href="games.php">Cancel</a>
      <span class="savebar__nav">
        <?php if ($prevId !== null): ?><a href="games.php?id=<?= h(urlencode($prevId)) ?>">← Previous game</a><?php endif ?>
        <?php if ($nextId !== null): ?><a href="games.php?id=<?= h(urlencode($nextId)) ?>">Next game →</a><?php endif ?>
      </span>
    </div>
  </form>

<?php if (!$isNew): ?>
  <details class="panel">
    <summary><strong>Edit as JSON</strong> <span class="muted">for anything the form doesn't cover</span></summary>
    <form method="post" class="stack">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="id" value="<?= h((string)$g['id']) ?>"><input type="hidden" name="do" value="save-json">
      <textarea name="json" class="json" rows="24"><?= h(json_encode($g, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></textarea>
      <div><button>Save JSON</button></div>
    </form>
  </details>
  <details class="panel danger-zone">
    <summary><strong>Delete this game</strong></summary>
    <form method="post" class="stack">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="id" value="<?= h((string)$g['id']) ?>"><input type="hidden" name="do" value="delete">
      <label class="check"><input type="checkbox" name="confirm" value="1" required> Yes, remove “<?= h($g['title']) ?>” from games.json</label>
      <div><button class="danger">Delete game</button></div>
    </form>
  </details>
<?php endif ?>

<?php else:
    /* ---------------------------------------------------------------- list */
    $q = trim((string)($_GET['q'] ?? ''));
    $status = (string)($_GET['status'] ?? '');
    $rows = array_values(array_filter($all, function ($g) use ($q, $status) {
        if ($status !== '' && ($g['status'] ?? '') !== $status && !($status === 'Cancelled' && ($g['status'] ?? '') === 'Abandoned')) return false;
        if ($q === '') return true;
        if (ctype_digit($q) && (string)$g['id'] === $q) return true;
        return mb_stripos(($g['title'] ?? '') . ' ' . implode(' ', (array)($g['author'] ?? [])), $q) !== false;
    }));
    usort($rows, fn($a, $b) => strcmp((string)($b['dateAdded'] ?? ''), (string)($a['dateAdded'] ?? '')) ?: ((int)$b['id'] <=> (int)$a['id']));
    $per = 50;
    $pages = max(1, (int)ceil(count($rows) / $per));
    $page = max(1, min($pages, (int)($_GET['page'] ?? 1)));
    $shown = array_slice($rows, ($page - 1) * $per, $per);
    $qs = fn(array $extra) => '?' . http_build_query(array_filter(['q' => $q, 'status' => $status] + $extra, fn($v) => $v !== '' && $v !== null));
?>
  <?php render_recent_changes('games', $csrf); ?>
  <div class="toolbar">
    <form method="get" class="search">
      <input name="q" value="<?= h($q) ?>" placeholder="Title, maker or id" autofocus>
      <select name="status"><option value="">All statuses</option><?php foreach (GAME_STATUSES as $s): ?><option<?= $status === $s ? ' selected' : '' ?>><?= h($s) ?></option><?php endforeach ?></select>
      <button>Search</button>
      <span class="muted"><?= count($rows) ?> of <?= count($all) ?> games</span>
    </form>
    <div class="toolbar__right">
      <a class="button primary" href="games.php?new">＋ New game</a>
      <a class="button" href="games.php?download" title="The current games.json from the server, to put into git">⬇ games.json</a>
    </div>
  </div>
  <p class="muted note-git">Changes here go live right away and are saved on the server (with a backup of the old file).</p>
<?php if (sheet_sync_enabled()): $sheet = sheet_sync_status(); ?>
  <form method="post" class="sheet-sync">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="do" value="sheet-sync">
    <span class="muted">Google Sheet (tab “<?= h(env('SHEET_TAB', 'site')) ?>”):
<?php if (!$sheet): ?> not synced yet.
<?php elseif ($sheet['ok']): ?> <span class="sheet-sync__ok">✓</span> updated <?= h(date('M j, H:i', (int)$sheet['at'])) ?> (<?= (int)$sheet['rows'] ?> games).
<?php else: ?> <span class="sheet-sync__err">✗ last try <?= h(date('M j, H:i', (int)$sheet['at'])) ?> failed: <?= h((string)$sheet['error']) ?></span>
<?php endif ?></span>
    <button class="small">Sync now</button>
  </form>
<?php endif ?>
  <table class="list">
    <tr><th></th><th>#</th><th>Title</th><th>Made by</th><th>Status</th><th>Added</th><th></th></tr>
    <?php foreach ($shown as $g): ?>
    <tr>
      <td class="thumb"><?php if (!empty($g['thumbnail'])): ?><img src="<?= h($g['thumbnail']) ?>" alt="" loading="lazy"><?php endif ?></td>
      <td class="muted"><?= h((string)$g['id']) ?></td>
      <td><a href="games.php?id=<?= h(urlencode((string)$g['id'])) ?>"><strong><?= h($g['title']) ?></strong></a></td>
      <td><?= h(implode(', ', (array)($g['author'] ?? []))) ?></td>
      <td><?= h(STATUS_LABELS[$g['status']] ?? $g['status']) ?></td>
      <td class="muted"><?= h((string)($g['dateAdded'] ?? '')) ?></td>
      <td class="acts"><a class="button" href="games.php?id=<?= h(urlencode((string)$g['id'])) ?>">Edit</a> <a href="<?= h(game_path($g)) ?>" target="_blank" title="View on the site">↗</a></td>
    </tr>
    <?php endforeach ?>
  </table>
  <?php if ($pages > 1): ?>
  <p class="pager"><?php for ($i = 1; $i <= $pages; $i++): ?><a href="<?= h($qs(['page' => $i])) ?>" class="<?= $i === $page ? 'on' : '' ?>"><?= $i ?></a><?php endfor ?></p>
  <?php endif ?>
<?php endif ?>
</main>
<script src="admin.js?v=3"></script>
<script src="/js/shot-editor.js?v=1"></script>
<script src="screenshots.js?v=3"></script>
</body></html>
