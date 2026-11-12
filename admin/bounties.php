<?php
/** Admin: the bounty board (data/bounties.json): add, edit, mark as found, delete. */

declare(strict_types=1);
require __DIR__ . '/../inc/content.php';
require __DIR__ . '/../inc/admin_ui.php';

$csrf = admin_require();

/** Tips as cards with Done / Delete / +1 star. $withBounty: say which bounty each tip is for. */
function render_tips(array $tips, string $csrf, string $back, bool $withBounty): void
{
    $bounties = all_bounties();
    $btn = fn(string $do, int $id, string $label, string $class = '') => '<form method="post"><input type="hidden" name="csrf" value="' . h($csrf) . '">'
        . '<input type="hidden" name="do" value="' . $do . '"><input type="hidden" name="tip" value="' . $id . '"><input type="hidden" name="back" value="' . h($back) . '">'
        . '<button class="small ' . $class . '"' . ($do === 'tip-delete' ? ' data-confirm="Delete this tip?"' : '') . '>' . $label . '</button></form>';
    $linkify = fn(string $t) => preg_replace('#(https?://[^\s<]+)#', '<a href="$1" target="_blank" rel="noopener noreferrer nofollow">$1</a>', h($t));
    echo '<ul class="tips">';
    foreach ($tips as $t) {
        $isNew = $t['bounty_id'] === 'new';
        echo '<li class="tip' . ($t['resolved'] ? ' tip--done' : '') . '"><div class="tip__head">';
        if ($withBounty) {
            echo $isNew
                ? '<span class="tip__kind tip__kind--new">New bounty suggested</span> <strong>' . h($t['title']) . '</strong>' . ($t['author'] !== '' ? ' <span class="muted">by ' . h($t['author']) . '</span>' : '')
                : '<span class="tip__kind">Tip for</span> <a href="bounties.php?id=' . h(urlencode((string)$t['bounty_id'])) . '"><strong>' . h($bounties[$t['bounty_id']]['title'] ?? 'bounty #' . $t['bounty_id']) . '</strong></a>';
        } elseif ($t['resolved']) {
            echo '<span class="tip__kind">done</span>';
        }
        echo ' <span class="muted">· ' . date('Y-m-d H:i', (int)$t['created_at']) . ' · from ' . h($t['name'] ?: 'anonymous')
            . ($t['contact'] !== '' ? ' (' . h($t['contact']) . ')' : '')
            . (!empty($t['user_id']) ? ' · <a href="./?view=user&amp;id=' . (int)$t['user_id'] . '">member</a>' : '') . '</span></div>';
        if ($t['links'] !== '') echo '<div class="tip__links">' . nl2br($linkify((string)$t['links'])) . '</div>';
        if ($t['notes'] !== '') echo '<div class="tip__notes">' . nl2br(h($t['notes'])) . '</div>';
        echo '<div class="tip__acts">';
        if ($isNew && !$t['resolved']) echo '<a class="button small-button" href="bounties.php?new&amp;from_tip=' . (int)$t['id'] . '">Make a bounty of this</a>';
        echo $t['resolved'] ? $btn('tip-reopen', (int)$t['id'], 'Reopen') : $btn('tip-done', (int)$t['id'], '✓ Done');
        if (!empty($t['user_id'])) echo $btn('tip-star', (int)$t['id'], '+1 ★');
        echo $btn('tip-delete', (int)$t['id'], 'Delete', 'danger') . '</div></li>';
    }
    echo '</ul>';
}

if (isset($_GET['download'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="bounties.json"');
    readfile(content_path('bounties'));
    exit;
}

$errors = [];
$posted = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!admin_csrf_ok()) {
        $errors[] = 'Your session expired, please try again.';
    } else {
        try {
            // tips sent in by visitors
            $tipDo = (string)($_POST['do'] ?? '');
            if (str_starts_with($tipDo, 'tip-')) {
                $tid = (int)($_POST['tip'] ?? 0);
                $pdo = db();
                switch ($tipDo) {
                    case 'tip-done':   $pdo->prepare('UPDATE bounty_tips SET resolved = 1 WHERE id = ?')->execute([$tid]); break;
                    case 'tip-reopen': $pdo->prepare('UPDATE bounty_tips SET resolved = 0 WHERE id = ?')->execute([$tid]); break;
                    case 'tip-delete': $pdo->prepare('DELETE FROM bounty_tips WHERE id = ?')->execute([$tid]); break;
                    case 'tip-star':
                        $st = $pdo->prepare('SELECT t.user_id, t.bounty_id, t.title FROM bounty_tips t WHERE t.id = ? AND t.user_id IS NOT NULL');
                        $st->execute([$tid]);
                        if ($row = $st->fetch()) {
                            $title = $row['bounty_id'] === 'new' ? $row['title'] : (all_bounties()[$row['bounty_id']]['title'] ?? 'a bounty');
                            $pdo->prepare('UPDATE users SET stars = stars + 1 WHERE id = ?')->execute([$row['user_id']]);
                            $pdo->prepare('INSERT INTO star_events (user_id, amount, reason, created_at) VALUES (?, 1, ?, ?)')
                                ->execute([$row['user_id'], 'Bounty tip for ' . $title, time()]);
                            $_SESSION['admin_flash'] = 'Gave 1 gold star.';
                        }
                        break;
                }
                $back = (string)($_POST['back'] ?? '');
                header('Location: bounties.php' . (preg_match('/^\?[a-z0-9=&_%.#-]*$/i', $back) ? $back : '')); exit;
            }
            if (($_POST['do'] ?? '') === 'rollback') {
                $_SESSION['admin_flash'] = rollback_content('bounties', (int)($_POST['log'] ?? 0)) . '. A backup of the version before the rollback was kept, so you can undo it again.';
                header('Location: bounties.php'); exit;
            }
            $data = load_content('bounties');
            $data['bounties'] = $data['bounties'] ?? [];
            $list = &$data['bounties'];
            $id = (string)($_POST['id'] ?? '');
            $index = null;
            foreach ($list as $i => $b) if ((string)$b['id'] === $id) { $index = $i; break; }
            $do = (string)($_POST['do'] ?? 'save');

            if ($do === 'delete') {
                if ($index === null) throw new RuntimeException('That bounty doesn\'t exist anymore.');
                if (empty($_POST['confirm'])) throw new RuntimeException('Tick the box to confirm deleting.');
                $title = $list[$index]['title'];
                array_splice($list, $index, 1);
                save_content('bounties', $data, "Deleted bounty #$id $title");
                $_SESSION['admin_flash'] = "Deleted “{$title}”.";
                header('Location: bounties.php'); exit;
            }

            $isNew = $index === null;
            if ($isNew) {
                $max = 0;
                foreach ($list as $b) $max = max($max, (int)$b['id']);
                $id = (string)($max + 1);
            }
            $t = fn($k) => trim(str_replace("\r\n", "\n", (string)($_POST[$k] ?? '')));
            $b = $isNew ? [] : $list[$index];
            $b['id'] = $id;
            $b['title'] = $t('title');
            $b['authors'] = lines($t('authors'));
            $b['year'] = $t('year');
            $b['platform'] = $t('platform');
            $b['description'] = $t('description');
            $b['lastSeen'] = $t('lastSeen');
            $b['lastSeenUrl'] = $t('lastSeenUrl');
            $b['image'] = $t('image');
            $b['status'] = ($_POST['status'] ?? '') === 'found' ? 'found' : 'wanted';
            if ($b['status'] === 'found') {
                $b['foundGameId'] = $t('foundGameId');
                $b['foundBy'] = $t('foundBy');
                if ($b['foundGameId'] === '') unset($b['foundGameId']);
                if ($b['foundBy'] === '') unset($b['foundBy']);
            } else {
                unset($b['foundGameId'], $b['foundBy']);
            }
            $files = uploaded_list('picture');
            if ($files) {
                $paths = store_site_images(array_slice($files, 0, 1), 'assets/bounties/' . $id, 0);
                if ($paths) $b['image'] = $paths[0];
            }
            $posted = $b;
            if ($b['title'] === '') $errors[] = 'The title is empty.';
            if ($b['lastSeenUrl'] !== '' && !preg_match('#^https?://#i', $b['lastSeenUrl'])) $errors[] = 'The "last seen" link should start with https://';
            if (!empty($b['foundGameId']) && !find_game($b['foundGameId'])) $errors[] = 'There is no game with id ' . $b['foundGameId'] . ' in games.json.';
            if (!$errors) {
                if (!empty($_POST['from_tip'])) db()->prepare('UPDATE bounty_tips SET resolved = 1 WHERE id = ?')->execute([(int)$_POST['from_tip']]);
                if ($isNew) $list[] = $b; else $list[$index] = $b;
                save_content('bounties', $data, ($isNew ? 'Added' : 'Edited') . " bounty #$id " . $b['title'] . ($b['status'] === 'found' ? ' (found)' : ''));
                $_SESSION['admin_flash'] = ($isNew ? 'Added' : 'Saved') . " “{$b['title']}”.";
                header('Location: bounties.php'); exit;
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$data = load_content('bounties');
$bounties = [];
foreach ($data['bounties'] ?? [] as $b) $bounties[(string)$b['id']] = $b;
$isNew = isset($_GET['new']);
$editing = $posted ?? (isset($_GET['id']) ? ($bounties[(string)$_GET['id']] ?? null) : null);
if ($isNew && !$editing) {
    $editing = ['id' => null, 'title' => '', 'authors' => [], 'year' => '', 'platform' => '', 'description' => '', 'lastSeen' => '',
        'lastSeenUrl' => '', 'image' => '', 'status' => 'wanted'];
    // "Make a bounty" from a visitor's suggestion
    if (isset($_GET['from_tip'])) {
        $st = db()->prepare("SELECT * FROM bounty_tips WHERE id = ? AND bounty_id = 'new'");
        $st->execute([(int)$_GET['from_tip']]);
        if ($tip = $st->fetch()) {
            $firstLink = lines((string)$tip['links'])[0] ?? '';
            $editing['title'] = $tip['title'];
            $editing['authors'] = array_values(array_filter(array_map('trim', explode(',', (string)$tip['author']))));
            $editing['description'] = $tip['notes'];
            $editing['lastSeenUrl'] = preg_match('#^https?://\S+$#i', $firstLink) ? $firstLink : '';
            $editing['lastSeen'] = $editing['lastSeenUrl'] === '' ? $firstLink : '';
            $editing['_fromTip'] = (int)$tip['id'];
        }
    }
}
// tips sent in per bounty (from the bounty board form)
$tips = [];
foreach (db()->query("SELECT bounty_id, COUNT(*) AS n, SUM(resolved = 0) AS open FROM bounty_tips GROUP BY bounty_id")->fetchAll() as $r) {
    $tips[(string)$r['bounty_id']] = $r;
}

admin_head($editing ? ($editing['title'] ?: 'New bounty') : 'Bounties');
admin_nav('bounty-board');
echo '<main class="wide">';
if (!empty($_SESSION['admin_flash'])) { echo '<p class="flash">' . h($_SESSION['admin_flash']) . '</p>'; unset($_SESSION['admin_flash']); }
if ($errors) { echo '<div class="flash flash--error">'; foreach ($errors as $e) echo '<p>' . h($e) . '</p>'; echo '</div>'; }

if ($editing):
    $b = $editing;
    $found = ($b['status'] ?? '') === 'found'; ?>
  <p><a href="bounties.php">← All bounties</a></p>
  <h2><?= $isNew ? 'New bounty' : 'Edit bounty #' . h((string)$b['id']) ?></h2>
  <form method="post" enctype="multipart/form-data" class="editor">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="id" value="<?= $isNew ? '' : h((string)$b['id']) ?>">
    <input type="hidden" name="do" value="save">
    <?php if (!empty($b['_fromTip'])): ?><input type="hidden" name="from_tip" value="<?= (int)$b['_fromTip'] ?>">
    <p class="flash">Filled in from a visitor's suggestion. Check it, then save to put it on the board (the suggestion is then marked done).</p><?php endif ?>
    <div class="panel">
      <div class="grid2">
        <label class="span2">Title of the missing game <input name="title" required value="<?= h((string)$b['title']) ?>"></label>
        <label>Made by <span class="muted">one per line</span><textarea name="authors" rows="2"><?= h(implode("\n", (array)($b['authors'] ?? []))) ?></textarea></label>
        <div class="grid2 nested">
          <label>Year <input name="year" value="<?= h((string)($b['year'] ?? '')) ?>" placeholder="2013"></label>
          <label>Platform <input name="platform" value="<?= h((string)($b['platform'] ?? '')) ?>" placeholder="Flash, Windows…"></label>
        </div>
        <label class="span2">Description <span class="muted">what was the game like?</span><textarea name="description" rows="3"><?= h((string)($b['description'] ?? '')) ?></textarea></label>
        <label>Last seen <span class="muted">where was it last seen?</span><input name="lastSeen" value="<?= h((string)($b['lastSeen'] ?? '')) ?>" placeholder="A Mediafire link in a 2012 forum post"></label>
        <label>Last seen link <span class="muted">optional, e.g. an archive.org page</span><input name="lastSeenUrl" value="<?= h((string)($b['lastSeenUrl'] ?? '')) ?>" placeholder="https://web.archive.org/…"></label>
      </div>
    </div>
    <div class="panel">
      <h3>Picture</h3>
      <div class="grid2">
        <label>Picture path <span class="muted">empty = our mascot</span><input name="image" value="<?= h((string)($b['image'] ?? '')) ?>"></label>
        <label>Upload a picture <span class="muted">replaces the one above, saved in assets/bounties/</span><input type="file" name="picture[]" accept="image/png,image/jpeg,image/webp,image/gif"></label>
      </div>
      <?php if (!empty($b['image'])): ?><div class="shots small-shots"><figure><img src="<?= h((string)$b['image']) ?>" alt=""></figure></div><?php endif ?>
    </div>
    <div class="panel">
      <h3>Status</h3>
      <fieldset>
        <label class="check"><input type="radio" name="status" value="wanted"<?= $found ? '' : ' checked' ?>> 🤠 Wanted</label>
        <label class="check"><input type="radio" name="status" value="found"<?= $found ? ' checked' : '' ?>> ✅ Found (gets a FOUND stamp)</label>
      </fieldset>
      <div class="grid2">
        <label>Game on the site <span class="muted">id in games.json, links “Play it now”</span>
          <input name="foundGameId" list="gamelist" value="<?= h((string)($b['foundGameId'] ?? '')) ?>" placeholder="e.g. 332"></label>
        <label>Found by <span class="muted">who to thank</span><input name="foundBy" value="<?= h((string)($b['foundBy'] ?? '')) ?>"></label>
      </div>
      <datalist id="gamelist"><?php foreach (all_games() as $gid => $g): ?><option value="<?= h((string)$gid) ?>"><?= h($g['title']) ?></option><?php endforeach ?></datalist>
    </div>
    <div class="savebar"><button class="primary"><?= $isNew ? 'Add bounty' : 'Save changes' ?></button> <a href="bounties.php">Cancel</a></div>
  </form>
<?php if (!$isNew):
    $st = db()->prepare('SELECT t.*, u.display_name AS member FROM bounty_tips t LEFT JOIN users u ON u.id = t.user_id WHERE t.bounty_id = ? ORDER BY t.resolved ASC, t.created_at DESC');
    $st->execute([(string)$b['id']]);
    $myTips = $st->fetchAll(); ?>
  <div class="panel" id="tips">
    <h3>Tips from visitors <span class="muted"><?= count($myTips) ?></span></h3>
    <?php if (!$myTips): ?><p class="muted">Nobody sent a tip for this bounty yet.</p>
    <?php else: render_tips($myTips, $csrf, '?id=' . urlencode((string)$b['id']) . '#tips', false); endif ?>
  </div>
  <details class="panel danger-zone">
    <summary><strong>Delete this bounty</strong></summary>
    <form method="post" class="stack">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="id" value="<?= h((string)$b['id']) ?>"><input type="hidden" name="do" value="delete">
      <label class="check"><input type="checkbox" name="confirm" value="1" required> Yes, remove “<?= h((string)$b['title']) ?>” from the bounty board</label>
      <div><button class="danger">Delete bounty</button></div>
    </form>
  </details>
<?php endif ?>

<?php else:
    $show = in_array($_GET['show'] ?? '', ['wanted', 'found'], true) ? $_GET['show'] : '';
    $rows = array_values(array_filter($bounties, fn($b) => $show === '' || (($b['status'] ?? 'wanted') === 'found') === ($show === 'found')));
    usort($rows, fn($a, $b) => (int)$b['id'] <=> (int)$a['id']);
    $nFound = count(array_filter($bounties, fn($b) => ($b['status'] ?? '') === 'found')); ?>
  <?php render_recent_changes('bounties', $csrf); ?>
  <div class="toolbar">
    <p class="subnav">
      <a href="bounties.php" class="<?= $show === '' ? 'on' : '' ?>">All (<?= count($bounties) ?>)</a>
      <a href="?show=wanted" class="<?= $show === 'wanted' ? 'on' : '' ?>">Wanted (<?= count($bounties) - $nFound ?>)</a>
      <a href="?show=found" class="<?= $show === 'found' ? 'on' : '' ?>">Found (<?= $nFound ?>)</a>
    </p>
    <div class="toolbar__right">
      <a class="button primary" href="bounties.php?new">＋ New bounty</a>
      <a class="button" href="/bounties" target="_blank">View the board ↗</a>
    </div>
  </div>
  <?php
    $open = db()->query("SELECT t.*, u.display_name AS member FROM bounty_tips t LEFT JOIN users u ON u.id = t.user_id WHERE t.resolved = 0 ORDER BY t.created_at DESC LIMIT 100")->fetchAll();
    if ($open): ?>
  <div class="panel tips-panel">
    <h3>New tips from visitors <span class="muted"><?= count($open) ?></span></h3>
    <?php render_tips($open, $csrf, '', true); ?>
  </div>
  <?php endif ?>
  <?php if (!$rows): ?><p class="empty">No bounties here yet.</p><?php else: ?>
  <table class="list">
    <tr><th></th><th>#</th><th>Title</th><th>Made by</th><th>Year</th><th>Status</th><th>Tips</th><th></th></tr>
    <?php foreach ($rows as $b): $tp = $tips[(string)$b['id']] ?? null; ?>
    <tr>
      <td class="thumb"><img src="<?= h((string)($b['image'] ?: '/assets/mascot-head.png')) ?>" alt="" loading="lazy"></td>
      <td class="muted"><?= h((string)$b['id']) ?></td>
      <td><a href="bounties.php?id=<?= h(urlencode((string)$b['id'])) ?>"><strong><?= h((string)$b['title']) ?></strong></a></td>
      <td><?= h(implode(', ', (array)($b['authors'] ?? []))) ?></td>
      <td class="muted"><?= h((string)($b['year'] ?? '')) ?></td>
      <td><?= ($b['status'] ?? '') === 'found' ? '✅ Found' . (!empty($b['foundGameId']) ? ' <span class="muted">→ #' . h((string)$b['foundGameId']) . '</span>' : '') : '🤠 Wanted' ?></td>
      <td><?= $tp ? '<a href="bounties.php?id=' . h(urlencode((string)$b['id'])) . '#tips">' . (int)$tp['n'] . '</a>' . ((int)$tp['open'] ? ' <strong>(' . (int)$tp['open'] . ' new)</strong>' : '') : '<span class="muted">0</span>' ?></td>
      <td class="acts"><a class="button" href="bounties.php?id=<?= h(urlencode((string)$b['id'])) ?>">Edit</a></td>
    </tr>
    <?php endforeach ?>
  </table>
  <?php endif ?>
  <p class="muted note-git">Changes go live on the bounty board right away (with a backup of the old file). Download <a href="?download">bounties.json</a></p>
<?php endif ?>
</main>
<script src="admin.js?v=3"></script>
</body></html>
