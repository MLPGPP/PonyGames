<?php
/**
 * Admin: share links. Make a short link to any page of the site to share
 * somewhere (Discord, a forum, a video description…): /s/<code>, which s.php
 * sends on to <page>?s=<code>. Visits through it
 * show up in Stats → "Where visitors come from" under the link's name, also
 * from apps that don't send a referrer. Only codes made here are counted
 * (api/track.php checks them), so nobody can add their own sources.
 */

declare(strict_types=1);
require __DIR__ . '/../inc/content.php';
require __DIR__ . '/../inc/admin_ui.php';

admin_require();
$pdo = db();
$errors = [];
$old = ['name' => '', 'page' => ''];

/** "https://pony.games/game/12-x?a=1#top", "/games" or "game/12" -> "/game/12-x?a=1" (a path on this site), or null */
function share_path(string $in): ?string
{
    $in = trim($in);
    if ($in === '') return '/';
    if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $in) && $in[0] !== '/') $in = '/' . ltrim($in, '/');
    $u = parse_url($in);
    if ($u === false) return null;
    if (isset($u['host'])) {
        $host = strtolower(preg_replace('/^www\./', '', $u['host']));
        $ours = array_map(fn($h) => strtolower(preg_replace('/^www\./', '', (string)$h)),
            [parse_url(site_url(), PHP_URL_HOST), preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? ''))]);
        if (!in_array($host, $ours, true)) return null; // only pages of this site
    }
    $path = (string)($u['path'] ?? '/');
    if ($path === '' || $path[0] !== '/' || strlen($path) > 200 || !preg_match('#^[A-Za-z0-9_./%-]*$#', $path)) return null;
    parse_str((string)($u['query'] ?? ''), $q);
    unset($q['s']);
    return $path . ($q ? '?' . http_build_query($q) : '');
}

/** The short link to share: /s/<code> (nginx sends it to s.php) */
function share_url(array $l): string
{
    return request_origin() . '/s/' . $l['code']; // the site you're on: dev links work on dev
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!admin_csrf_ok()) {
        $errors[] = 'Your session expired, please try again.';
    } elseif (($_POST['do'] ?? '') === 'delete') {
        $st = $pdo->prepare('SELECT name FROM share_links WHERE code = ?');
        $st->execute([(string)($_POST['code'] ?? '')]);
        if ($name = $st->fetchColumn()) {
            $pdo->prepare('DELETE FROM share_links WHERE code = ?')->execute([(string)$_POST['code']]);
            $_SESSION['admin_flash'] = "Deleted “{$name}”. The short link now goes to the home page; the visits already counted stay in the stats.";
        }
        header('Location: links.php');
        exit;
    } else {
        $old = ['name' => trim((string)($_POST['name'] ?? '')), 'page' => trim((string)($_POST['page'] ?? ''))];
        $path = share_path($old['page']);
        if ($old['name'] === '' || mb_strlen($old['name']) > 60) $errors[] = 'Give the link a name (at most 60 characters), e.g. "Discord announcement".';
        if ($path === null) $errors[] = 'That isn\'t a page of this site. Paste a link like ' . site_url() . '/game/12 or a path like /games.';
        if (!$errors) {
            // a new random code: 6 characters, at least one letter (only digits, like /s/18, is a screenshot folder)
            $taken = $pdo->prepare('SELECT 1 FROM share_links WHERE code = ?');
            do {
                $code = '';
                for ($i = 0; $i < 6; $i++) $code .= 'abcdefghjkmnpqrstuvwxyz23456789'[random_int(0, 30)];
                $taken->execute([$code]);
            } while (!preg_match('/[a-z]/', $code) || $taken->fetchColumn());
            $pdo->prepare('INSERT INTO share_links (code, name, path, created_at, created_by) VALUES (?, ?, ?, ?, ?)')
                ->execute([$code, $old['name'], $path, time(), admin_who()]);
            $_SESSION['admin_flash'] = "Made “{$old['name']}”. Copy the link below and share it.";
            $_SESSION['admin_new_link'] = $code;
            header('Location: links.php');
            exit;
        }
    }
}

// the links, with their visits: last 30 days and all time
$day = intdiv(time(), 86400);
$links = $pdo->query("SELECT l.*,
        (SELECT COUNT(DISTINCT day || '|' || visitor) FROM page_views v WHERE v.referrer = '#' || l.code AND v.day > $day - 30) AS recent,
        (SELECT COUNT(DISTINCT day || '|' || visitor) FROM page_views v WHERE v.referrer = '#' || l.code) AS total
    FROM share_links l ORDER BY l.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
$newCode = (string)($_SESSION['admin_new_link'] ?? '');
unset($_SESSION['admin_new_link']);

admin_head('Share links');
admin_nav('stats');
echo '<main class="wide">';
if (!empty($_SESSION['admin_flash'])) { echo '<p class="flash">' . h($_SESSION['admin_flash']) . '</p>'; unset($_SESSION['admin_flash']); }
if ($errors) { echo '<div class="flash flash--error">'; foreach ($errors as $e) echo '<p>' . h($e) . '</p>'; echo '</div>'; }
?>
  <div class="stats__head">
    <div class="stats__title">
      <a class="stats__back" href="stats.php">← Stats</a>
      <h2>Share links</h2>
    </div>
  </div>
  <p class="muted">Make a short link (<?= h(request_origin()) ?>/s/…) to any page of the site to share somewhere: a Discord post, a forum, a video description. Visits through it show up in
    Stats under “Where visitors come from” with the name you give it, also from apps like Discord or WhatsApp that don't say where visitors came from.
    Only links made here count, so nobody can add sources of their own.</p>

  <section class="panel">
    <h3>New share link</h3>
    <form method="post" class="stack share-form">
      <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
      <label>Name <span class="muted">what you'll see in the stats</span>
        <input name="name" maxlength="60" required value="<?= h($old['name']) ?>" placeholder="Discord announcement, November">
      </label>
      <label>Page <span class="muted">a link to a page of the site, or a path; empty = the home page</span>
        <input name="page" value="<?= h($old['page']) ?>" placeholder="<?= h(request_origin()) ?>/game/12" list="share-pages">
      </label>
      <datalist id="share-pages">
        <option value="/">Home</option><option value="/games">Games</option><option value="/upcoming">Upcoming</option>
        <option value="/resources">Resources</option><option value="/bounties">Bounties</option><option value="/about">About</option>
<?php foreach (all_games() as $g): ?>
        <option value="<?= h(game_path($g)) ?>"><?= h((string)($g['title'] ?? '')) ?></option>
<?php endforeach ?>
      </datalist>
      <div><button class="primary">Make link</button></div>
    </form>
  </section>

  <section class="panel">
    <h3>Your share links</h3>
<?php if (!$links): ?>
    <p class="empty">No share links yet.</p>
<?php else: ?>
    <table class="list stats-table share-table">
      <thead><tr><th>Name</th><th>Link</th><th class="num">Visits (30 days)</th><th class="num">All time</th><th></th></tr></thead>
      <tbody>
<?php foreach ($links as $l): $url = share_url($l); ?>
        <tr<?= $l['code'] === $newCode ? ' class="is-new"' : '' ?>>
          <td><strong><?= h($l['name']) ?></strong><br><span class="muted">→ <a href="<?= h($l['path']) ?>" target="_blank" rel="noopener"><?= h($l['path']) ?></a> · made <?= h(gmdate('M j, Y', (int)$l['created_at'])) ?><?= $l['created_by'] !== '' ? ' by ' . h($l['created_by']) : '' ?></span></td>
          <td class="share-url"><input readonly value="<?= h($url) ?>" aria-label="Share link for <?= h($l['name']) ?>" data-select-all> <button type="button" class="small" data-copy="<?= h($url) ?>">Copy</button></td>
          <td class="num"><?= number_format((int)$l['recent']) ?></td>
          <td class="num"><?= number_format((int)$l['total']) ?></td>
          <td><form method="post" data-confirm="Delete “<?= h($l['name']) ?>”? The short link then goes to the home page and visits are no longer counted for it.">
            <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="do" value="delete"><input type="hidden" name="code" value="<?= h($l['code']) ?>">
            <button class="small danger">Delete</button></form></td>
        </tr>
<?php endforeach ?>
      </tbody>
    </table>
<?php endif ?>
  </section>
</main>
<script src="admin.js?v=3"></script>
</body></html>
