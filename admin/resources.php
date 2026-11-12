<?php
/** Admin: list, add, edit and delete resources, and the download links of asset packs (data/resources.json). */

declare(strict_types=1);
require __DIR__ . '/../inc/content.php';
require __DIR__ . '/../inc/admin_ui.php';

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
            $files = uploaded_list('images');
            if ($files) {
                $paths = store_site_images($files, 'asset_previews/' . $id, 0);
                $r['previews'] = array_merge($r['previews'], $paths);
                if ($r['thumbnail'] === '' && $paths) $r['thumbnail'] = $paths[0];
            }
            $posted = $r;
            if (trim($r['name']) === '') $errors[] = 'The name is empty.';
            foreach ($r['downloads'] as $u) if (!preg_match('#^https?://#i', $u)) $errors[] = "Download link “{$u}” should start with https://";
            if (!$errors) {
                if ($isNew) $data['assets'][] = $r; else $data['assets'][$index] = $r;
                save_content('resources', refresh_resource_lists($data), ($isNew ? 'Added' : 'Edited') . " resource #$id " . $r['name']);
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
    <div class="panel">
      <h3>Pictures</h3>
      <div class="grid2">
        <label>Thumbnail <input name="thumbnail" value="<?= h((string)$r['thumbnail']) ?>"></label>
        <label>Previews <span class="muted">one path per line</span><textarea name="previews" rows="3"><?= h(implode("\n", (array)$r['previews'])) ?></textarea></label>
      </div>
      <div class="shots small-shots"><?php foreach (array_unique(array_filter(array_merge([(string)$r['thumbnail']], (array)$r['previews']))) as $src): ?>
        <figure><img src="<?= h($src) ?>" alt="" loading="lazy"><figcaption><?= h(basename($src)) ?></figcaption></figure><?php endforeach ?></div>
      <label>Upload previews <span class="muted">saved in asset_previews/<?= $isNew ? '&lt;new id&gt;' : h((string)$r['id']) ?>/</span>
        <input type="file" name="images[]" multiple accept="image/png,image/jpeg,image/webp,image/gif"></label>
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
</body></html>
