<?php
/**
 * Admin: the news posts on the home page ("News from the herd", data/posts.json):
 * write a new post, edit or delete one. Posts have no id; they're found by
 * their place in the file, checked against their title and date so a post
 * that changed in the meantime is never overwritten by mistake.
 *
 * The text is Markdown (rendered on the home page): **bold**, - lists,
 * [links](https://…), ![pictures](…) and simple HTML like <img width="400">.
 */

declare(strict_types=1);
require __DIR__ . '/../inc/content.php';
require __DIR__ . '/../inc/admin_ui.php';

$csrf = admin_require();

if (isset($_GET['download'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="posts.json"');
    readfile(content_path('posts'));
    exit;
}

/** a short fingerprint of a post, to make sure we change the post that was opened */
function post_key(array $p): string
{
    return substr(sha1((string)($p['date'] ?? '') . "\n" . (string)($p['title'] ?? '')), 0, 12);
}

$errors = [];
$posted = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!admin_csrf_ok()) {
        $errors[] = 'Your session expired, please try again.';
    } else {
        try {
            if (($_POST['do'] ?? '') === 'rollback') {
                $_SESSION['admin_flash'] = rollback_content('posts', (int)($_POST['log'] ?? 0)) . '. A backup of the version before the rollback was kept, so you can undo it again.';
                header('Location: news.php'); exit;
            }
            $data = load_content('posts');
            $data['posts'] = array_values($data['posts'] ?? []);
            $list = &$data['posts'];
            $index = ($_POST['index'] ?? '') === '' ? null : (int)$_POST['index'];
            if ($index !== null && (!isset($list[$index]) || post_key($list[$index]) !== (string)($_POST['key'] ?? ''))) {
                throw new RuntimeException('That post was changed or removed in the meantime. Open it again from the list.');
            }
            $do = (string)($_POST['do'] ?? 'save');

            if ($do === 'delete') {
                if ($index === null) throw new RuntimeException('That post doesn\'t exist anymore.');
                if (empty($_POST['confirm'])) throw new RuntimeException('Tick the box to confirm deleting.');
                $title = (string)$list[$index]['title'];
                array_splice($list, $index, 1);
                save_content('posts', $data, "Deleted news post “{$title}”");
                $_SESSION['admin_flash'] = "Deleted “{$title}”.";
                header('Location: news.php'); exit;
            }

            $isNew = $index === null;
            $t = fn($k) => trim(str_replace("\r\n", "\n", (string)($_POST[$k] ?? '')));
            $p = $isNew ? [] : $list[$index];
            $p['title'] = $t('title');
            $p['date'] = $t('date');
            $p['excerpt'] = $t('excerpt');
            $image = $t('image');
            // a picture shown above the text (optional)
            $cover = uploaded_list('cover');
            if ($cover) {
                $paths = store_site_images(array_slice($cover, 0, 1), 'assets/news', 0);
                if ($paths) $image = $paths[0];
            }
            if ($image !== '') $p['image'] = $image; else unset($p['image']);
            // pictures put into the text, at the end
            $inText = uploaded_list('pictures');
            if ($inText) {
                foreach (store_site_images(array_slice($inText, 0, 10), 'assets/news', 0) as $path) {
                    $p['excerpt'] .= ($p['excerpt'] === '' ? '' : "\n\n") . '![](' . $path . ')';
                }
            }
            $posted = $p + ['_index' => $index];
            if ($p['title'] === '') $errors[] = 'The title is empty.';
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $p['date']) || !checkdate((int)substr($p['date'], 5, 2), (int)substr($p['date'], 8, 2), (int)substr($p['date'], 0, 4))) {
                $errors[] = 'Pick a date.';
            }
            if ($p['excerpt'] === '') $errors[] = 'The text is empty.';
            if ($image !== '' && !preg_match('#^(https?://|/)#i', $image)) $errors[] = 'The picture should be a link (https://…) or a path on the site (/assets/…).';
            if (!$errors) {
                // keep the keys in the usual order: title, date, excerpt, image
                $p = array_filter(['title' => $p['title'], 'date' => $p['date'], 'excerpt' => $p['excerpt'], 'image' => $p['image'] ?? null], fn($v) => $v !== null) + $p;
                if ($isNew) $list[] = $p; else $list[$index] = $p;
                save_content('posts', $data, ($isNew ? 'Posted' : 'Edited') . " news “{$p['title']}”");
                $_SESSION['admin_flash'] = ($isNew ? 'Posted' : 'Saved') . " “{$p['title']}”. It's on the home page now.";
                header('Location: news.php'); exit;
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$posts = array_values(load_content('posts')['posts'] ?? []);
$isNew = isset($_GET['new']);
$editing = $posted;
if (!$editing && isset($_GET['i']) && isset($posts[(int)$_GET['i']]) && post_key($posts[(int)$_GET['i']]) === (string)($_GET['k'] ?? '')) {
    $editing = $posts[(int)$_GET['i']] + ['_index' => (int)$_GET['i']];
}
if ($isNew && !$editing) $editing = ['title' => '', 'date' => date('Y-m-d'), 'excerpt' => '', '_index' => null];
if ($editing) $isNew = $editing['_index'] === null;

admin_head($editing ? ($editing['title'] ?: 'New post') : 'News');
admin_nav('news');
echo '<main class="wide">';
if (!empty($_SESSION['admin_flash'])) { echo '<p class="flash">' . h($_SESSION['admin_flash']) . '</p>'; unset($_SESSION['admin_flash']); }
if ($errors) { echo '<div class="flash flash--error">'; foreach ($errors as $e) echo '<p>' . h($e) . '</p>'; echo '</div>'; }

if ($editing):
    $p = $editing;
    $key = $isNew ? '' : post_key($posts[$p['_index']] ?? $p); ?>
  <p><a href="news.php">← All news</a></p>
  <h2><?= $isNew ? 'New post' : 'Edit post' ?></h2>
  <form method="post" enctype="multipart/form-data" class="editor">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="index" value="<?= $isNew ? '' : (int)$p['_index'] ?>">
    <input type="hidden" name="key" value="<?= h($key) ?>">
    <input type="hidden" name="do" value="save">
    <div class="panel">
      <div class="grid2">
        <label>Title <input name="title" required maxlength="150" value="<?= h((string)$p['title']) ?>" placeholder="Week #4 update"></label>
        <label>Date <span class="muted">newest posts are shown first</span><input type="date" name="date" required value="<?= h((string)$p['date']) ?>"></label>
        <label class="span2">Text <span class="muted">Markdown: **bold**, *italic*, - lists, [link text](https://…), ![](picture link). Simple HTML like &lt;img src="…" width="400"&gt; works too.</span>
          <textarea name="excerpt" rows="14" class="news-text" required><?= h((string)$p['excerpt']) ?></textarea></label>
        <label>Add pictures to the text <span class="muted">put at the end of the text, saved in assets/news/</span><input type="file" name="pictures[]" multiple accept="image/png,image/jpeg,image/webp,image/gif"></label>
      </div>
    </div>
    <div class="panel">
      <h3>Picture above the text <span class="muted">optional</span></h3>
      <div class="grid2">
        <label>Picture <span class="muted">a link or a path on the site, empty = none</span><input name="image" value="<?= h((string)($p['image'] ?? '')) ?>"></label>
        <label>Or upload one <span class="muted">replaces the one on the left</span><input type="file" name="cover[]" accept="image/png,image/jpeg,image/webp,image/gif"></label>
      </div>
      <?php if (!empty($p['image'])): ?><div class="shots small-shots"><figure><img src="<?= h((string)$p['image']) ?>" alt=""></figure></div><?php endif ?>
    </div>
    <div class="savebar"><button class="primary"><?= $isNew ? 'Post it' : 'Save changes' ?></button> <a href="news.php">Cancel</a>
      <span class="muted">Goes live on the home page right away.</span></div>
  </form>
<?php if (!$isNew): ?>
  <details class="panel danger-zone">
    <summary><strong>Delete this post</strong></summary>
    <form method="post" class="stack">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="index" value="<?= (int)$p['_index'] ?>"><input type="hidden" name="key" value="<?= h($key) ?>"><input type="hidden" name="do" value="delete">
      <label class="check"><input type="checkbox" name="confirm" value="1" required> Yes, remove “<?= h((string)$p['title']) ?>” from the home page</label>
      <div><button class="danger">Delete post</button></div>
    </form>
  </details>
<?php endif ?>

<?php else:
    $rows = $posts;
    foreach ($rows as $i => &$r) $r['_index'] = $i;
    unset($r);
    usort($rows, fn($a, $b) => strcmp((string)($b['date'] ?? ''), (string)($a['date'] ?? '')) ?: ($b['_index'] <=> $a['_index'])); ?>
  <?php render_recent_changes('posts', $csrf); ?>
  <div class="toolbar">
    <p class="muted"><?= count($posts) ?> post<?= count($posts) === 1 ? '' : 's' ?> in “News from the herd” on the home page.</p>
    <div class="toolbar__right">
      <a class="button primary" href="news.php?new">＋ New post</a>
      <a class="button" href="/#news-title" target="_blank">View on the site ↗</a>
    </div>
  </div>
  <?php if (!$rows): ?><p class="empty">No news posts yet.</p><?php else: ?>
  <table class="list">
    <tr><th>Date</th><th>Title</th><th>Text</th><th></th></tr>
    <?php foreach ($rows as $r):
        $href = 'news.php?' . http_build_query(['i' => $r['_index'], 'k' => post_key($r)]);
        $preview = trim(preg_replace('/\s+/', ' ', strip_tags(preg_replace('/!\[[^\]]*\]\([^)]*\)/', '', (string)($r['excerpt'] ?? ''))))); ?>
    <tr>
      <td class="muted nowrap"><?= h((string)($r['date'] ?? '')) ?></td>
      <td><a href="<?= h($href) ?>"><strong><?= h((string)($r['title'] ?? '')) ?></strong></a></td>
      <td class="muted news-preview"><?= h(mb_strimwidth($preview, 0, 140, '…')) ?></td>
      <td class="acts"><a class="button" href="<?= h($href) ?>">Edit</a></td>
    </tr>
    <?php endforeach ?>
  </table>
  <?php endif ?>
  <p class="muted note-git">Changes go live on the home page right away (with a backup of the old file). Download <a href="?download">posts.json</a> now and then to put it into git.</p>
<?php endif ?>
</main>
<script src="admin.js?v=3"></script>
</body></html>
