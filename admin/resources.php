<?php
/** Admin: list, add, edit and delete resources, and the download links of asset packs (data/resources.json). */

declare(strict_types=1);
require __DIR__ . '/../inc/content.php';
require __DIR__ . '/../inc/admin_ui.php';
require __DIR__ . '/../inc/screenshots.php';

$csrf = admin_require();

if (isset($_GET['download'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="resources.json"');
    readfile(content_path('resources'));
    exit;
}

$errors = [];
$posted = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!admin_csrf_ok()) {
        $errors[] = 'Your session expired, please try again.';
    } else {
        try {
            if (($_POST['do'] ?? '') === 'rollback') {
                $_SESSION['admin_flash'] = rollback_content('resources', (int)($_POST['log'] ?? 0)) . '. A backup of the version before the rollback was kept, so you can undo it again.';
                header('Location: resources.php'); exit;
            }
            $data = load_content('resources');
            $do = (string)($_POST['do'] ?? '');

            if ($do === 'packs') {
                foreach ((array)($_POST['pack_downloads'] ?? []) as $pack => $text) {
                    if (!array_key_exists($pack, $data['allAssetPacks'])) continue;
                    $urls = lines((string)$text);
                    $data['allAssetPacks'][$pack] = $urls ? ['downloads' => $urls] : new stdClass();
                }
                save_content('resources', $data, 'Edited asset pack downloads');
                $_SESSION['admin_flash'] = 'Asset pack downloads saved.';
                header('Location: resources.php?packs'); exit;
            }

            $id = (string)($_POST['id'] ?? '');
            $index = null;
            foreach ($data['assets'] as $i => $a) if ((string)$a['id'] === $id) { $index = $i; break; }

            if ($do === 'delete') {
                if ($index === null) throw new RuntimeException('That resource doesn\'t exist anymore.');
                if (empty($_POST['confirm'])) throw new RuntimeException('Tick the box to confirm deleting.');
                $name = $data['assets'][$index]['name'];
                array_splice($data['assets'], $index, 1);
                save_content('resources', $data, "Deleted resource #$id $name");
                $_SESSION['admin_flash'] = "Deleted “{$name}”.";
                header('Location: resources.php'); exit;
            }

            $isNew = $index === null;
            if ($isNew) {
                $max = -1;
                foreach ($data['assets'] as $a) $max = max($max, (int)$a['id']);
                $id = (string)($max + 1);
            }
            $old = $isNew ? ['id' => (int)$id, 'name' => '', 'description' => '', 'attribution' => '', 'primaryAssetPack' => '', 'license' => [],
                'author' => [], 'tags' => [], 'assetPacks' => [], 'downloads' => [], 'previews' => [], 'thumbnail' => ''] : $data['assets'][$index];
            $r = resource_from_post($_POST, $old, known_licenses($data));
            // the picture editor (same as for games): new order, crops, removed and added pictures
            $shotItems = null;
            $shotOrder = json_decode((string)($_POST['shots_order'] ?? ''), true);
            if (is_array($shotOrder)) {
                $current = resource_shots($old);
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
                if (count($shotItems) > 40) throw new RuntimeException('That are a lot of pictures, please keep it under 40.');
                if ($keptOnly !== null && $keptOnly === $current) $shotItems = null; // nothing changed: leave the files alone
            } elseif (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
                throw new RuntimeException('Too much to upload at once (server limit). Save fewer new pictures at a time.');
            }
            $posted = $r;
            if (trim($r['name']) === '') $errors[] = 'The name is empty.';
            foreach ($r['downloads'] as $u) if (!preg_match('#^https?://#i', $u)) $errors[] = "Download link “{$u}” should start with https://";
            if (!$errors) {
                if ($shotItems !== null) {
                    // the first picture is the card picture; the popup shows all of them, except shared placeholders (music.png…)
                    $paths = save_resource_shots((int)$id, $shotItems);
                    $shared = [];
                    foreach ($data['assets'] as $a) if ((string)$a['id'] !== (string)$id) $shared[(string)$a['thumbnail']] = true;
                    $r['thumbnail'] = $paths[0] ?? '';
                    $r['previews'] = array_values(array_filter($paths, fn($p) => resource_shot_parts($p) !== null || !isset($shared[$p])));
                }
                if ($isNew) $data['assets'][] = $r; else $data['assets'][$index] = $r;
                save_content('resources', refresh_resource_lists($data), ($isNew ? 'Added' : 'Edited') . " resource #$id " . $r['name']);
                // made for an asset request (requests.php): link them, and tell the maker and the requester
                if ($isNew && !empty($_POST['from_request'])) {
                    require_once __DIR__ . '/../inc/requests.php';
                    $req = request_get((int)$_POST['from_request']);
                    if ($req && $req['resource_id'] === null) {
                        db()->prepare('UPDATE asset_requests SET resource_id = ?, updated_at = ? WHERE id = ?')->execute([(int)$id, time(), (int)$req['id']]);
                        $res = ['/resources?resource=' . (int)$id];
                        foreach (array_unique(array_filter([(int)$req['claimed_by'], (int)$req['user_id']])) as $uid) {
                            notify_linked('req', $uid, '', '“' . $r['name'] . '” is in the resources now 🧩',
                                'The asset made for the request board can now be found by every pony on the resources page.', (int)$req['id'], $res[0]);
                        }
                        discord_request_event('resource', $req, ['by' => admin_who(), 'note' => 'Resource #' . $id . ': ' . $r['name']]);
                    }
                }
                $_SESSION['admin_flash'] = ($isNew ? 'Added' : 'Saved') . " “{$r['name']}”.";
                header('Location: resources.php?id=' . urlencode($id)); exit;
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$data = load_content('resources');
$assets = [];
foreach ($data['assets'] as $a) $assets[(string)$a['id']] = $a;
$isNew = isset($_GET['new']);
$editing = $posted ?? (isset($_GET['id']) ? ($assets[(string)$_GET['id']] ?? null) : null);
if ($isNew && !$editing) {
    $editing = ['id' => null, 'name' => '', 'description' => '', 'attribution' => '', 'primaryAssetPack' => '', 'license' => [], 'author' => [],
        'tags' => [], 'assetPacks' => [], 'downloads' => [], 'previews' => [], 'thumbnail' => ''];
}
// ?new&from_request=ID: a finished asset request, filled in (add pictures and a license, then save)
$fromRequest = null;
if ($isNew && !empty($_REQUEST['from_request'])) {
    require_once __DIR__ . '/../inc/requests.php';
    $fromRequest = request_get((int)$_REQUEST['from_request']);
    if ($fromRequest && !isset($posted)) {
        $kindTags = ['sprites' => ['2D', 'Sprite'], 'animation' => ['2D', 'Sprite'], 'backgrounds' => ['2D', 'Background', 'Tileset'], 'ui' => ['2D'],
            'music' => ['Music'], 'sfx' => ['Sound Effect'], '3d' => ['3D'], 'fonts' => [], 'code' => [], 'writing' => [], 'voice' => [], 'other' => []];
        $editing['name'] = (string)$fromRequest['title'];
        $editing['author'] = array_filter([(string)($fromRequest['maker'] ?? '')]);
        $editing['description'] = trim((string)$fromRequest['description'] . ((string)$fromRequest['result_note'] !== '' ? "\n\n" . $fromRequest['result_note'] : ''));
        $editing['downloads'] = array_filter([(string)$fromRequest['result_url']]);
        $editing['tags'] = array_values(array_intersect($kindTags[$fromRequest['kind']] ?? [], (array)($data['allTags'] ?? [])));
    }
}

admin_head($editing ? ($editing['name'] ?: 'New resource') : 'Resources');
admin_nav('resources');
echo '<main class="wide">';
if (!empty($_SESSION['admin_flash'])) { echo '<p class="flash">' . h($_SESSION['admin_flash']) . '</p>'; unset($_SESSION['admin_flash']); }
if ($errors) { echo '<div class="flash flash--error">'; foreach ($errors as $e) echo '<p>' . h($e) . '</p>'; echo '</div>'; }

if (isset($_GET['packs'])): ?>
  <p><a href="resources.php">← All resources</a></p>
  <h2>Asset packs</h2>
  <p class="muted">Download links shown under every resource of the pack (as its “primary pack”). One link per line. New packs appear here once a resource uses them.</p>
  <form method="post" class="stack">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="do" value="packs">
    <table class="list">
      <tr><th>Pack</th><th>Resources</th><th>Download links</th></tr>
      <?php foreach ($data['allAssetPacks'] as $pack => $info):
        $n = count(array_filter($data['assets'], fn($a) => in_array($pack, $a['assetPacks'] ?? [], true))); ?>
      <tr><td><strong><?= h((string)$pack) ?></strong></td><td class="muted"><?= $n ?></td>
        <td><textarea name="pack_downloads[<?= h((string)$pack) ?>]" rows="<?= max(1, count($info['downloads'] ?? [])) ?>" class="wide-input"><?= h(implode("\n", $info['downloads'] ?? [])) ?></textarea></td></tr>
      <?php endforeach ?>
    </table>
    <div class="savebar"><button class="primary">Save pack links</button></div>
  </form>

<?php elseif ($editing):
    $r = $editing;
    $licenses = known_licenses($data);
    $usedLic = array_column((array)$r['license'], 'name');
    $extraTags = array_diff((array)$r['tags'], $data['allTags']); ?>
  <p><a href="resources.php">← All resources</a></p>
  <h2><?= $isNew ? 'New resource' : 'Edit resource #' . h((string)$r['id']) ?></h2>
  <form method="post" enctype="multipart/form-data" class="editor">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="id" value="<?= $isNew ? '' : h((string)$r['id']) ?>">
    <input type="hidden" name="do" value="save">
<?php if ($fromRequest): ?>
    <input type="hidden" name="from_request" value="<?= (int)$fromRequest['id'] ?>">
    <p class="flash">Filled in from the asset request “<?= h((string)$fromRequest['title']) ?>”, made by <?= h((string)($fromRequest['maker'] ?? '?')) ?>.
      Check the type and license, add some pictures, then save. The maker and the requester get a message with a link to it.</p>
<?php endif ?>
    <div class="panel">
      <div class="grid2">
        <label class="span2">Name <input name="name" required value="<?= h($r['name']) ?>"></label>
        <label>Made by <span class="muted">one per line</span><textarea name="author" rows="2"><?= h(implode("\n", (array)$r['author'])) ?></textarea></label>
        <label>Attribution <span class="muted">how people should credit it</span><textarea name="attribution" rows="2"><?= h((string)$r['attribution']) ?></textarea></label>
        <label class="span2">Description <textarea name="description" rows="3"><?= h((string)$r['description']) ?></textarea></label>
        <label>Primary asset pack <input name="primaryAssetPack" list="packs" value="<?= h((string)$r['primaryAssetPack']) ?>"></label>
        <label>All asset packs <span class="muted">one per line</span><textarea name="assetPacks" rows="2"><?= h(implode("\n", (array)$r['assetPacks'])) ?></textarea></label>
      </div>
      <datalist id="packs"><?php foreach (array_keys($data['allAssetPacks']) as $p): ?><option value="<?= h((string)$p) ?>"><?php endforeach ?></datalist>
      <fieldset><legend>Type</legend>
        <?php foreach ($data['allTags'] as $t): ?><label class="check"><input type="checkbox" name="tags[]" value="<?= h($t) ?>"<?= in_array($t, (array)$r['tags'], true) ? ' checked' : '' ?>> <?= h($t) ?></label><?php endforeach ?>
        <label class="inline">New type <input name="tags_extra" value="<?= h(implode("\n", $extraTags)) ?>"></label>
      </fieldset>
      <fieldset><legend>License</legend>
        <?php foreach ($licenses as $name => $url): ?><label class="check"><input type="checkbox" name="license[]" value="<?= h($name) ?>"<?= in_array($name, $usedLic, true) ? ' checked' : '' ?>> <?= h($name) ?></label><?php endforeach ?>
      </fieldset>
      <label>Downloads <span class="muted">one link per line</span><textarea name="downloads" rows="2"><?= h(implode("\n", (array)$r['downloads'])) ?></textarea></label>
    </div>
    <div class="panel" id="pictures">
      <h3>Pictures &amp; videos</h3>
      <?php $tiles = array_map(fn($p) => ['path' => $p, 'managed' => resource_shot_parts($p) !== null], resource_shots($r)); ?>
      <div id="shot-editor" class="shot-editor" data-tiles="<?= h(json_encode($tiles, JSON_UNESCAPED_SLASHES)) ?>">
        <p class="muted shot-help">Drag the pictures into the best order (or use ← →); the first one is the picture on the card. ★ makes a picture the first,
          ✂ crops, 🗑 removes. The popup shows them all. When you save, they're stored as <code>asset_previews/<?= $isNew ? '&lt;new id&gt;' : h((string)$r['id']) ?>/0, 1, 2…</code>
          (shared placeholders like music.png stay where they are).</p>
        <ol class="shot-grid" id="shot-grid"></ol>
        <label class="drop-add" id="shot-add">
          <input type="file" accept="image/png,image/jpeg,image/webp,image/gif" multiple>
          <span><strong>＋ Add pictures</strong> or drop them here</span>
        </label>
        <input type="hidden" name="shots_order" id="shots-order">
        <input type="file" name="shot_files[]" id="shots-files" multiple hidden>
      </div>
      <label>Videos <span class="muted">YouTube links or https links to a video file (.mp4, .webm, …), one per line: shown after the pictures</span>
        <textarea name="videos" rows="2" placeholder="https://www.youtube.com/watch?v=…"><?= h(implode("\n", array_filter((array)($r['videos'] ?? []), 'is_string'))) ?></textarea></label>
    </div>
    <div class="savebar"><button class="primary"><?= $isNew ? 'Add resource' : 'Save changes' ?></button> <a href="resources.php">Cancel</a></div>
  </form>
<?php if (!$isNew): ?>
  <details class="panel danger-zone">
    <summary><strong>Delete this resource</strong></summary>
    <form method="post" class="stack">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="id" value="<?= h((string)$r['id']) ?>"><input type="hidden" name="do" value="delete">
      <label class="check"><input type="checkbox" name="confirm" value="1" required> Yes, remove “<?= h($r['name']) ?>” from resources.json</label>
      <div><button class="danger">Delete resource</button></div>
    </form>
  </details>
<?php endif ?>

<?php else:
    $q = trim((string)($_GET['q'] ?? ''));
    $tag = (string)($_GET['tag'] ?? '');
    $rows = array_values(array_filter($assets, function ($a) use ($q, $tag) {
        if ($tag !== '' && !in_array($tag, $a['tags'] ?? [], true)) return false;
        if ($q === '') return true;
        if (ctype_digit($q) && (string)$a['id'] === $q) return true;
        return mb_stripos($a['name'] . ' ' . implode(' ', $a['author'] ?? []) . ' ' . implode(' ', $a['assetPacks'] ?? []), $q) !== false;
    }));
    usort($rows, fn($a, $b) => (int)$b['id'] <=> (int)$a['id']);
    $per = 50;
    $pages = max(1, (int)ceil(count($rows) / $per));
    $page = max(1, min($pages, (int)($_GET['page'] ?? 1)));
    $shown = array_slice($rows, ($page - 1) * $per, $per); ?>
  <?php render_recent_changes('resources', $csrf); ?>
  <div class="toolbar">
    <form method="get" class="search">
      <input name="q" value="<?= h($q) ?>" placeholder="Name, maker, pack or id" autofocus>
      <select name="tag"><option value="">All types</option><?php foreach ($data['allTags'] as $t): ?><option<?= $tag === $t ? ' selected' : '' ?>><?= h($t) ?></option><?php endforeach ?></select>
      <button>Search</button>
      <span class="muted"><?= count($rows) ?> of <?= count($assets) ?> resources</span>
    </form>
    <div class="toolbar__right">
      <a class="button primary" href="resources.php?new">＋ New resource</a>
      <a class="button" href="resources.php?packs">Asset packs</a>
      <a class="button" href="resources.php?download">⬇ resources.json</a>
    </div>
  </div>
  <p class="muted note-git">Changes go live right away (with a backup of the old file). </p>
  <table class="list">
    <tr><th></th><th>#</th><th>Name</th><th>Made by</th><th>Type</th><th>Pack</th><th></th></tr>
    <?php foreach ($shown as $a): ?>
    <tr>
      <td class="thumb"><?php if (!empty($a['thumbnail'])): ?><img src="<?= h($a['thumbnail']) ?>" alt="" loading="lazy"><?php endif ?></td>
      <td class="muted"><?= h((string)$a['id']) ?></td>
      <td><a href="resources.php?id=<?= h((string)$a['id']) ?>"><strong><?= h($a['name']) ?></strong></a></td>
      <td><?= h(implode(', ', (array)$a['author'])) ?></td>
      <td><?= h(implode(', ', (array)$a['tags'])) ?></td>
      <td class="muted"><?= h((string)$a['primaryAssetPack']) ?></td>
      <td class="acts"><a class="button" href="resources.php?id=<?= h((string)$a['id']) ?>">Edit</a></td>
    </tr>
    <?php endforeach ?>
  </table>
  <?php if ($pages > 1): ?>
  <p class="pager"><?php for ($i = 1; $i <= $pages; $i++): ?><a href="?<?= h(http_build_query(array_filter(['q' => $q, 'tag' => $tag, 'page' => $i]))) ?>" class="<?= $i === $page ? 'on' : '' ?>"><?= $i ?></a><?php endfor ?></p>
  <?php endif ?>
<?php endif ?>
</main>
<script src="admin.js?v=3"></script>
<script src="/js/shot-editor.js?v=1"></script>
<script src="screenshots.js?v=3"></script>
</body></html>
