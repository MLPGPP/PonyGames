<?php
/**
 * Tiny moderation panel: open reports, recent reviews/discussion messages, hide/delete.
 * Login with the password whose hash is in .env as ADMIN_PASSWORD_HASH
 * (make one with: php -r 'echo password_hash("your-password", PASSWORD_DEFAULT), PHP_EOL;').
 */

declare(strict_types=1);
require __DIR__ . '/../inc/content.php'; // accounts, submissions, games/resources editing (doesn't start the visitor session)
require_once __DIR__ . '/../inc/screenshots.php';
require __DIR__ . '/../inc/admin_ui.php';

header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' https: data: blob:");

admin_session();

// the bounty tips moved to bounties.php
if (($_GET['view'] ?? '') === 'bounties') { header('Location: bounties.php'); exit; }

$hash = env('ADMIN_PASSWORD_HASH');
$error = '';

$csrf = $_SESSION['csrf'];
$siteUser = $GLOBALS['PG_SITE_USER'] ?? null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $error = 'Your session expired, please try again.';
    } elseif (isset($_POST['password'])) {
        if (!rate_ok('admin-login', 8, 900)) {
            $error = 'Too many attempts. Wait 15 minutes.';
        } elseif ($hash !== '' && password_verify((string)$_POST['password'], $hash)) {
            session_regenerate_id(true);
            $_SESSION = ['csrf' => $csrf, 'admin' => true]; // owner login
            header('Location: ./'); exit;
        } else {
            $error = 'Wrong password.';
        }
    } elseif (!empty($_SESSION['admin'])) {
        $pdo = db();
        $id = (int)($_POST['id'] ?? 0);
        $do = (string)($_POST['do'] ?? '');
        if (in_array($do, ['stars', 'badge', 'unbadge', 'reset-link', 'user-delete', 'role', 'ban', 'unban', 'user-posts-delete', 'verify-email'], true) && !admin_can_manage_accounts()) {
            $_SESSION['admin_flash'] = 'Only admins can change accounts.';
            header('Location: ./'); exit;
        }
        switch ($do) {
            case 'resolve': $pdo->prepare('UPDATE reports SET resolved = 1 WHERE id = ?')->execute([$id]); break;
            case 'reopen':  $pdo->prepare('UPDATE reports SET resolved = 0 WHERE id = ?')->execute([$id]); break;
            case 'hide':    $pdo->prepare('UPDATE posts SET hidden = 1 WHERE id = ?')->execute([$id]); break;
            case 'show':    $pdo->prepare('UPDATE posts SET hidden = 0 WHERE id = ?')->execute([$id]); break;
            case 'delete':
                lift_replies('id = ?', [$id]);
                $pdo->prepare('DELETE FROM posts WHERE id = ?')->execute([$id]);
                $pdo->prepare('UPDATE reports SET resolved = 1 WHERE post_id = ?')->execute([$id]);
                break;
            // ---- accounts ----
            case 'stars':
                $amount = max(-100, min(100, (int)($_POST['amount'] ?? 1)));
                if ($amount !== 0 && $id > 0) {
                    $pdo->prepare('UPDATE users SET stars = MAX(0, stars + ?) WHERE id = ?')->execute([$amount, $id]);
                    $pdo->prepare('INSERT INTO star_events (user_id, amount, reason, created_at) VALUES (?, ?, ?, ?)')
                        ->execute([$id, $amount, mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 200), time()]);
                    $_SESSION['admin_flash'] = ($amount > 0 ? "Gave $amount" : 'Took ' . -$amount) . ' gold star' . (abs($amount) === 1 ? '' : 's') . '.';
                }
                break;
            case 'badge':
                $badge = (string)($_POST['badge'] ?? '');
                if (isset(BADGES[$badge])) {
                    $pdo->prepare('INSERT OR REPLACE INTO user_badges (user_id, badge, note, awarded_at) VALUES (?, ?, ?, ?)')
                        ->execute([$id, $badge, mb_substr(trim((string)($_POST['note'] ?? '')), 0, 200), time()]);
                    $_SESSION['admin_flash'] = 'Badge "' . BADGES[$badge][0] . '" awarded.';
                }
                break;
            case 'unbadge':
                $pdo->prepare('DELETE FROM user_badges WHERE user_id = ? AND badge = ?')->execute([$id, (string)($_POST['badge'] ?? '')]);
                break;
            case 'reset-link':
                $token = make_token($id, 'reset', '', 60 * 60 * 24);
                $_SESSION['admin_flash'] = 'Reset link (works once, for 24 hours). Send it to the user privately: '
                    . request_origin() . '/reset.php?token=' . $token;
                break;
            case 'ban':
                $st = $pdo->prepare('SELECT * FROM users WHERE id = ?');
                $st->execute([$id]);
                $target = $st->fetch();
                $reason = mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 300);
                $days = (string)($_POST['duration'] ?? '');
                $durations = ['1' => 86400, '3' => 3 * 86400, '7' => 7 * 86400, '30' => 30 * 86400, '90' => 90 * 86400, '365' => 365 * 86400, 'forever' => 0];
                if (!$target) {
                    $_SESSION['admin_flash'] = 'That account doesn\'t exist anymore.';
                } elseif ($id === (int)($_SESSION['admin_user'] ?? 0) || isset(ADMIN_ROLES[$target['role'] ?? ''])) {
                    $_SESSION['admin_flash'] = "Accounts with admin panel access can't be banned. Remove their access first.";
                } elseif ($reason === '' || !isset($durations[$days])) {
                    $_SESSION['admin_flash'] = 'Give a reason and pick how long the ban lasts.';
                } else {
                    $until = $durations[$days] === 0 ? 0 : time() + $durations[$days];
                    $pdo->prepare('UPDATE users SET ban_until = ?, ban_reason = ? WHERE id = ?')->execute([$until, $reason, $id]);
                    $pdo->prepare('UPDATE user_bans SET lifted_at = ? WHERE user_id = ? AND lifted_at IS NULL AND (until = 0 OR until > ?)')->execute([time(), $id, time()]);
                    $pdo->prepare('INSERT INTO user_bans (user_id, reason, until, created_at, by_who) VALUES (?, ?, ?, ?, ?)')
                        ->execute([$id, $reason, $until, time(), admin_who()]);
                    $removed = delete_user_posts($id, !empty($_POST['delete_reviews']), !empty($_POST['delete_comments']));
                    $_SESSION['admin_flash'] = 'Banned ' . $target['display_name'] . ($until === 0 ? ' for good' : ' until ' . date('Y-m-d H:i', $until)) . '.'
                        . ($removed ? " Deleted $removed of their posts." : '');
                }
                break;
            case 'verify-email':
                $pdo->prepare('UPDATE users SET email_verified = 1 WHERE id = ?')->execute([$id]);
                $_SESSION['admin_flash'] = 'Email marked as confirmed. They can now like, review and post.';
                break;
            case 'unban':
                $pdo->prepare('UPDATE users SET ban_until = NULL WHERE id = ?')->execute([$id]);
                $pdo->prepare('UPDATE user_bans SET lifted_at = ? WHERE user_id = ? AND lifted_at IS NULL AND (until = 0 OR until > ?)')->execute([time(), $id, time()]);
                $_SESSION['admin_flash'] = 'Ban lifted. They can log in again.';
                break;
            case 'user-posts-delete':
                $removed = delete_user_posts($id, !empty($_POST['delete_reviews']), !empty($_POST['delete_comments']));
                $_SESSION['admin_flash'] = $removed ? "Deleted $removed post" . ($removed === 1 ? '' : 's') . '.' : 'Nothing to delete.';
                break;
            case 'user-delete':
                delete_user($id, !empty($_POST['delete_posts']));
                $_SESSION['admin_flash'] = 'Account deleted.';
                header('Location: ./?view=users'); exit;
            // ---- submissions ----
            case 'sub-accept':
            case 'sub-reject':
                $st = $pdo->prepare('SELECT * FROM submissions WHERE id = ?');
                $st->execute([$id]);
                if ($sub = $st->fetch()) {
                    $accepted = $_POST['do'] === 'sub-accept';
                    $published = '';
                    $pubNote = '';
                    if ($accepted && !empty($_POST['publish'])) {
                        // put it straight into games.json (with the pictures in s/<id>/)
                        try {
                            $sd = json_decode($sub['data'], true) ?: [];
                            $content = load_content('games');
                            if ($sub['kind'] === 'edit') {
                                $idx = null;
                                foreach ($content['games'] as $i => $g) if ((string)$g['id'] === (string)$sub['game_id']) { $idx = $i; break; }
                                if ($idx === null) throw new RuntimeException('the game no longer exists');
                                $base = $content['games'][$idx];
                                $published = $base['title'];
                                $what = 'Published correction #' . $id . ' for game #' . $base['id'] . ' ' . $base['title'];
                                if (!empty($sd['shots'])) {
                                    // the suggested set of pictures, in its order (renamed to 0, 1, 2… like the editor does)
                                    $missing = 0;
                                    $items = submission_shot_items($base, $sd['shots'], $missing);
                                    if (!$items) throw new RuntimeException('none of the suggested pictures can be found anymore');
                                    $g = build_game_json($sd['changes'] ?? [], $base);
                                    [$g['thumbnail'], $g['screenshots'], $sb] = save_game_shots($base, $items, shot_folder_users($content['games']));
                                    $content['games'][$idx] = $g;
                                    save_content('games', $content, $what . ' (pictures)', [$sb]);
                                    if ($missing) $pubNote = " Note: $missing of the suggested pictures didn't exist anymore and were left out.";
                                } else {
                                    // older corrections: new pictures are added at the end
                                    $start = count($base['screenshots'] ?? []) + 1;
                                    $paths = publish_submission_images($sd['images'] ?? [], game_image_folder($base), $start);
                                    $content['games'][$idx] = build_game_json($sd['changes'] ?? [], $base, null, $paths);
                                    save_content('games', $content, $what);
                                }
                            } else {
                                $newId = next_game_id($content['games']);
                                $paths = publish_submission_images($sd['images'] ?? [], 's/' . $newId, 0);
                                $g = build_game_json($sd['values'] ?? [], null, $newId, $paths);
                                $content['games'][] = $g;
                                $published = $g['title'];
                                save_content('games', $content, 'Published submission #' . $id . ' as game #' . $newId . ' ' . $g['title']);
                            }
                        } catch (Throwable $e) {
                            $_SESSION['admin_flash'] = 'Could not publish: ' . $e->getMessage() . '. Nothing was changed.';
                            header('Location: ./?view=submission&id=' . $id); exit;
                        }
                    }
                    $pdo->prepare('UPDATE submissions SET status = ?, reply = ?, reviewed_at = ? WHERE id = ?')
                        ->execute([$accepted ? 'accepted' : 'rejected', mb_substr(trim((string)($_POST['reply'] ?? '')), 0, 500), time(), $id]);
                    $uid = (int)($sub['user_id'] ?? 0);
                    $stars = max(0, min(20, (int)($_POST['stars'] ?? 0)));
                    if ($accepted && $uid && $stars) {
                        $what = $sub['kind'] === 'edit' ? 'Corrected ' : 'Submitted ';
                        $title = $sub['kind'] === 'edit' ? (all_games()[$sub['game_id']]['title'] ?? 'a game')
                            : (json_decode($sub['data'], true)['values']['title'] ?? 'a game');
                        $pdo->prepare('UPDATE users SET stars = stars + ? WHERE id = ?')->execute([$stars, $uid]);
                        $pdo->prepare('INSERT INTO star_events (user_id, amount, reason, created_at) VALUES (?, ?, ?, ?)')
                            ->execute([$uid, $stars, $what . $title, time()]);
                    }
                    $badge = (string)($_POST['badge'] ?? '');
                    if ($accepted && $uid && isset(BADGES[$badge])) {
                        $pdo->prepare('INSERT OR IGNORE INTO user_badges (user_id, badge, note, awarded_at) VALUES (?, ?, ?, ?)')
                            ->execute([$uid, $badge, '', time()]);
                    }
                    $_SESSION['admin_flash'] = !$accepted ? 'Marked as rejected.'
                        : ($published !== '' ? "Accepted and published “{$published}” to games.json." . $pubNote : 'Marked as accepted. Don\'t forget to put the JSON in games.json!');
                }
                header('Location: ./?view=submissions'); exit;
            case 'sub-reopen':
                $pdo->prepare("UPDATE submissions SET status = 'open', reviewed_at = NULL WHERE id = ?")->execute([$id]);
                header('Location: ./?view=submission&id=' . $id); exit;
            case 'sub-delete':
                $st = $pdo->prepare('SELECT data FROM submissions WHERE id = ?');
                $st->execute([$id]);
                foreach ((json_decode((string)$st->fetchColumn(), true)['images'] ?? []) as $f) {
                    if (preg_match('/^[a-f0-9]{24}\.(jpg|png|webp|gif)$/', $f)) @unlink(uploads_dir() . '/' . $f);
                }
                $pdo->prepare('DELETE FROM submissions WHERE id = ?')->execute([$id]);
                $_SESSION['admin_flash'] = 'Submission deleted.';
                header('Location: ./?view=submissions'); exit;
            case 'role':
                $role = (string)($_POST['role'] ?? '');
                if ($id === (int)($_SESSION['admin_user'] ?? 0)) {
                    $_SESSION['admin_flash'] = "You can't change your own access.";
                } else {
                    $pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([isset(ADMIN_ROLES[$role]) ? $role : '', $id]);
                    $_SESSION['admin_flash'] = isset(ADMIN_ROLES[$role]) ? 'This account now has ' . ADMIN_ROLES[$role] . ' access to the admin panel.' : 'Admin access removed.';
                }
                break;
            case 'logout': admin_logout(); header('Location: ./'); exit;
        }
        $back = isset($_POST['view']) ? '?view=' . urlencode((string)$_POST['view']) : '';
        if (($_POST['view'] ?? '') === 'user') $back .= '&id=' . $id;
        if (($_POST['view'] ?? '') === 'posts' && !empty($_POST['user'])) $back .= '&user=' . (int)$_POST['user'];
        header('Location: ./' . $back); exit;
    }
}

$games = all_games();
$gameLink = function (string $gid) use ($games): string {
    $g = $games[$gid] ?? null;
    return $g ? '<a href="' . h(game_path($g)) . '" target="_blank" rel="noopener">' . h($g['title']) . '</a>' : 'game #' . h($gid);
};
$when = fn(int $t) => date('Y-m-d H:i', $t);
$form = function (string $do, int $id, string $label, string $view, bool $danger = false, array $extra = []) use ($csrf): string {
    $more = '';
    foreach ($extra as $k => $v) $more .= '<input type="hidden" name="' . h($k) . '" value="' . h((string)$v) . '">';
    return '<form method="post"><input type="hidden" name="csrf" value="' . h($csrf) . '">'
        . '<input type="hidden" name="do" value="' . h($do) . '"><input type="hidden" name="id" value="' . $id . '">'
        . '<input type="hidden" name="view" value="' . h($view) . '">' . $more
        . '<button' . ($danger ? ' class="danger" title="Deletes it for good"' : '') . '>' . h($label) . '</button></form>';
};
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Moderation | Pony Games</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&amp;family=Silkscreen&amp;display=swap">
<link rel="stylesheet" href="admin.css?v=23">
</head>
<body>
<?php if (empty($_SESSION['admin'])): ?>
  <form class="login" method="post">
    <img class="login__mascot" src="/assets/mascot-head.png" alt="" width="72" height="72">
    <h1>Pony Games admin</h1>
    <p class="muted login__sub">Moderation and content management</p>
    <?php if ($hash === ''): ?>
      <p class="err">No ADMIN_PASSWORD_HASH is set in .env yet, so nobody can log in.</p>
    <?php endif ?>
    <?php if ($error): ?><p class="err"><?= h($error) ?></p><?php endif ?>
    <?php if ($siteUser): ?>
      <p class="err">You're logged in as <strong><?= h($siteUser['display_name']) ?></strong>, but this account has no access to the admin panel. Ask an admin to give it access.</p>
    <?php else: ?>
      <a class="button primary wide-btn" href="/login?next=/admin/">Log in with your Pony Games account</a>
      <p class="muted or">or with the owner password</p>
    <?php endif ?>
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <label>Password <input type="password" name="password" autocomplete="current-password" required></label>
    <button>Log in</button>
  </form>
<?php else:
  $view = in_array($_GET['view'] ?? '', ['reports', 'resolved', 'posts', 'users', 'user', 'submissions', 'submission'], true) ? $_GET['view'] : 'reports';
  if (in_array($view, ['users', 'user'], true) && !admin_can_manage_accounts()) $view = 'reports';
  $pdo = db();
  $openCount = (int)$pdo->query('SELECT COUNT(*) FROM reports WHERE resolved = 0')->fetchColumn();
  $subCount = (int)$pdo->query("SELECT COUNT(*) FROM submissions WHERE status = 'open'")->fetchColumn();
?>
  <?php admin_nav($view, ['reports' => $openCount, 'submissions' => $subCount]); ?>
  <main>
  <?php $pageTitle = ['reports' => 'Reports', 'resolved' => 'Reports', 'submissions' => 'Submissions', 'posts' => 'Reviews &amp; discussion', 'users' => 'Accounts'][$view] ?? '';
    if ($pageTitle !== ''): ?><h2 class="page-title"><?= $pageTitle ?></h2><?php endif ?>
  <?php if (!empty($_SESSION['admin_flash'])): ?><p class="flash"><?= h($_SESSION['admin_flash']) ?></p><?php unset($_SESSION['admin_flash']); endif ?>
  <?php // security check (inc/admin_ui.php): private files that can be downloaded from outside
    foreach (security_problems(isset($_GET['recheck'])) as $problem): ?>
    <p class="flash flash--error"><strong>Security:</strong> <?= h($problem) ?> <a href="?recheck=1">Check again</a></p>
  <?php endforeach ?>
  <?php if ($view === 'submissions'):
    $show = in_array($_GET['show'] ?? '', ['open', 'accepted', 'rejected'], true) ? $_GET['show'] : 'open';
    $st = $pdo->prepare('SELECT s.*, u.display_name AS member FROM submissions s LEFT JOIN users u ON u.id = s.user_id WHERE s.status = ? ORDER BY s.created_at DESC LIMIT 300');
    $st->execute([$show]);
    $rows = $st->fetchAll(); ?>
    <p class="subnav"><?php foreach (['open' => 'Open', 'accepted' => 'Accepted', 'rejected' => 'Rejected'] as $k => $l): ?>
      <a href="?view=submissions&amp;show=<?= $k ?>" class="<?= $show === $k ? 'on' : '' ?>"><?= $l ?></a><?php endforeach ?></p>
    <?php if (!$rows): ?><p class="empty">No <?= $show ?> submissions.</p><?php else: ?>
    <table>
      <tr><th>When</th><th>Type</th><th>Game</th><th>From</th><th>Note</th><th></th></tr>
      <?php foreach ($rows as $r): $d = json_decode($r['data'], true) ?: []; ?>
        <tr>
          <td class="muted"><?= $when((int)$r['created_at']) ?></td>
          <td><?= $r['kind'] === 'edit' ? '✎ Correction' : '＋ New game' ?><?= $r['is_dev'] ? '<br><span class="muted">by the developer</span>' : '' ?></td>
          <td><?php if ($r['kind'] === 'edit'): ?><?= $gameLink((string)$r['game_id']) ?><br><span class="muted"><?= h(implode(', ', array_map(fn($k) => SUBMIT_FIELD_LABELS[$k] ?? $k, array_keys($d['changes'] ?? [])))) ?: 'note only' ?></span>
              <?php else: ?><strong><?= h($d['values']['title'] ?? '?') ?></strong><?php endif ?></td>
          <td><?= h($r['name'] ?: 'anonymous') ?><?= $r['member'] ? '<br><span class="muted">member</span>' : '' ?></td>
          <td class="body"><?= h(mb_strimwidth((string)$r['note'], 0, 140, '…')) ?></td>
          <td class="acts"><a class="button" href="?view=submission&amp;id=<?= (int)$r['id'] ?>">Review</a></td>
        </tr>
      <?php endforeach ?>
    </table>
    <?php endif ?>
  <?php elseif ($view === 'submission'):
    $st = $pdo->prepare('SELECT s.*, u.display_name AS member, u.stars AS member_stars FROM submissions s LEFT JOIN users u ON u.id = s.user_id WHERE s.id = ?');
    $st->execute([(int)($_GET['id'] ?? 0)]);
    $sub = $st->fetch();
    if (!$sub): ?><p class="empty">That submission doesn't exist (anymore). <a href="?view=submissions">Back</a></p><?php else:
      $d = json_decode($sub['data'], true) ?: [];
      $isEdit = $sub['kind'] === 'edit';
      $game = $isEdit ? ($games[(string)$sub['game_id']] ?? null) : null;
      $nextId = $games ? max(array_map('intval', array_keys($games))) + 1 : 1;
      $gid = $isEdit ? (string)$sub['game_id'] : (string)$nextId;
      $first = $isEdit && $game ? count($game['screenshots'] ?? []) + 1 : 0;
      $paths = [];
      $shotItems = null; // corrections with a new set of pictures
      $shotMissing = 0;
      if ($isEdit && $game && !empty($d['shots'])) {
          $shotItems = submission_shot_items($game, $d['shots'], $shotMissing);
          $paths = shot_item_paths($game, $shotItems, shot_folder_users($games));
          $json = build_game_json($d['changes'] ?? [], $game);
          if ($paths) { $json['thumbnail'] = $paths[0]; $json['screenshots'] = array_slice($paths, 1); }
      } else {
          foreach ($d['images'] ?? [] as $i => $f) $paths[] = '/s/' . $gid . '/' . ($first + $i) . '.' . pathinfo($f, PATHINFO_EXTENSION);
          $json = $isEdit
            ? ($game ? build_game_json($d['changes'] ?? [], $game, null, $paths) : null)
            : build_game_json($d['values'] ?? [], null, $nextId, $paths);
      }
      $current = $game ? game_form_values($game) : []; ?>
    <p><a href="?view=submissions">← All submissions</a></p>
    <div class="panel">
      <h2><?= $isEdit ? '✎ Correction for ' . $gameLink((string)$sub['game_id']) : '＋ New game: ' . h($d['values']['title'] ?? '?') ?>
        <span class="tag tag--<?= h($sub['status']) ?>"><?= h($sub['status']) ?></span></h2>
      <p class="muted">Sent <?= $when((int)$sub['created_at']) ?> by <strong><?= h($sub['name'] ?: 'anonymous') ?></strong>
        <?= $sub['contact'] !== '' ? '(' . h($sub['contact']) . ')' : '' ?>
        <?= $sub['member'] ? ' · member <a href="?view=user&amp;id=' . (int)$sub['user_id'] . '">' . h($sub['member']) . '</a> ★ ' . (int)$sub['member_stars'] : ' · no account' ?>
        <?= $sub['is_dev'] ? ' · <strong>says they made this game</strong>' : '' ?></p>
      <?php if ($sub['note'] !== ''): ?><blockquote class="note"><?= nl2br(h($sub['note'])) ?></blockquote><?php endif ?>
    </div>

    <div class="panel">
      <h3><?= $isEdit ? 'Changes' : 'Details' ?></h3>
      <?php $fields = $isEdit ? ($d['changes'] ?? []) : ($d['values'] ?? []); ?>
      <?php if (!$fields): ?><p class="muted">No fields changed, see the note.</p><?php else: ?>
      <table class="diff">
        <tr><th>Field</th><?php if ($isEdit): ?><th>Now</th><?php endif ?><th><?= $isEdit ? 'Suggested' : 'Value' ?></th></tr>
        <?php foreach ($fields as $k => $val): if (!$isEdit && ($val === '' || $val === [])) continue; ?>
          <tr><td><strong><?= h(SUBMIT_FIELD_LABELS[$k] ?? $k) ?></strong></td>
            <?php if ($isEdit): ?><td class="body old"><?= h(submission_value_text($current[$k] ?? '')) ?></td><?php endif ?>
            <td class="body new"><?= h(submission_value_text($val)) ?></td></tr>
        <?php endforeach ?>
      </table>
      <?php endif ?>
    </div>

    <?php if ($shotItems !== null): $nowShots = game_shots($game); ?>
    <div class="panel">
      <h3>Pictures <span class="muted">new order, first = cover</span></h3>
      <?php if ($shotMissing): ?><p class="flash flash--error"><?= $shotMissing ?> of the suggested pictures don't exist anymore (the game's pictures were changed since); they'll be left out.</p><?php endif ?>
      <p class="muted">Now:</p>
      <div class="shots small-shots"><?php foreach ($nowShots as $i => $p): ?>
        <figure><img src="<?= h($p) ?>" alt="" loading="lazy"><figcaption><?= $i === 0 ? '★ cover' : $i ?> · <code><?= h(basename($p)) ?></code></figcaption></figure>
      <?php endforeach ?></div>
      <p class="muted">Suggested:</p>
      <div class="shots small-shots"><?php foreach ($shotItems as $i => $it):
          if (isset($it['keep'])) { $src = $it['keep']; $was = array_search($it['keep'], $nowShots, true); $what = $was === $i ? 'same place' : 'was ' . ($was === 0 ? 'cover' : $was); }
          else { $src = 'file.php?f=' . rawurlencode($it['upload']); $what = $it['cropOf'] !== '' ? '<strong>cropped</strong> from ' . h(basename($it['cropOf'])) : '<strong>new</strong>'; } ?>
        <figure><a href="<?= h($src) ?>" target="_blank"><img src="<?= h($src) ?>" alt="" loading="lazy"></a>
          <figcaption><?= $i === 0 ? '★ cover' : $i ?> · <?= $what ?></figcaption></figure>
      <?php endforeach ?></div>
      <?php $removed = array_diff($nowShots, array_column($shotItems, 'keep'), array_filter(array_column($shotItems, 'cropOf'))); ?>
      <?php if ($removed): ?><p class="muted">Removed: <?= h(implode(', ', array_map('basename', $removed))) ?></p><?php endif ?>
      <?php if (!empty($d['imageUrls'])): ?>
      <p>Picture links:</p><ul><?php foreach ($d['imageUrls'] as $u): ?><li><a href="<?= h($u) ?>" target="_blank" rel="noopener noreferrer nofollow"><?= h($u) ?></a></li><?php endforeach ?></ul>
      <?php endif ?>
    </div>
    <?php elseif (!empty($d['images']) || !empty($d['imageUrls'])): ?>
    <div class="panel">
      <h3>Pictures</h3>
      <?php if (!empty($d['images'])): ?>
      <p class="muted">Download each picture and save it in the repo as shown (the JSON below uses these paths).</p>
      <div class="shots"><?php foreach ($d['images'] as $i => $f): ?>
        <figure><a href="file.php?f=<?= h($f) ?>" target="_blank"><img src="file.php?f=<?= h($f) ?>" alt=""></a>
          <figcaption><code><?= h(ltrim($paths[$i], '/')) ?></code> · <a href="file.php?f=<?= h($f) ?>&amp;download=1">download</a></figcaption></figure>
      <?php endforeach ?></div>
      <?php endif ?>
      <?php if (!empty($d['imageUrls'])): ?>
      <p>Picture links:</p><ul><?php foreach ($d['imageUrls'] as $u): ?><li><a href="<?= h($u) ?>" target="_blank" rel="noopener noreferrer nofollow"><?= h($u) ?></a></li><?php endforeach ?></ul>
      <?php endif ?>
    </div>
    <?php endif ?>

    <?php if ($json): ?>
    <div class="panel">
      <h3><?= $isEdit ? 'Updated entry for games.json' : 'New entry for games.json' ?></h3>
      <p class="muted"><?= $isEdit ? 'Replace the game with id ' . h($gid) . ' in data/games.json with this.' : 'Add this to the "games" list in data/games.json. Check that id ' . $nextId . ' is still free, and add mirrors/screenshots if you have them.' ?></p>
      <textarea class="json" readonly rows="18"><?= h(json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></textarea>
    </div>
    <?php endif ?>

    <div class="panel">
      <?php if ($sub['status'] === 'open'): ?>
      <h3>Decision</h3>
      <form method="post" class="stack">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="id" value="<?= (int)$sub['id'] ?>">
        <label>Message for the sender (optional, members see it on their account page) <input name="reply" maxlength="500" placeholder="e.g. Thanks, it's on the site now!"></label>
        <?php if ($sub['user_id']): ?>
        <div class="row">
          <label>Gold stars <input type="number" name="stars" value="1" min="0" max="20"></label>
          <label>Badge <select name="badge"><option value="">none</option><?php foreach (BADGES as $bid => $b): ?><option value="<?= h($bid) ?>"<?= $bid === ($isEdit ? 'fact-checker' : ($sub['is_dev'] ? 'game-dev' : 'game-hunter')) ? ' selected' : '' ?>><?= $b[2] ?> <?= h($b[0]) ?></option><?php endforeach ?></select></label>
        </div>
        <?php else: ?><p class="muted">Sent without an account, so no stars or badges.</p><?php endif ?>
        <label class="check"><input type="checkbox" name="publish" value="1" checked> Publish it to games.json right away<?= $shotItems !== null && $paths ? ' (and save the pictures in ' . h(dirname(ltrim($paths[0], '/'))) . '/ in this order)' : (!empty($d['images']) ? ' (and put the pictures in s/' . h($gid) . '/)' : '') ?></label>
        <div class="row"><button name="do" value="sub-accept">✓ Accept</button><button name="do" value="sub-reject" class="danger">✗ Reject</button></div>
      </form>
      <?php else: ?>
      <p><?= $sub['status'] === 'accepted' ? '✓ Accepted' : '✗ Rejected' ?> on <?= $when((int)$sub['reviewed_at']) ?><?= $sub['reply'] !== '' ? ': “' . h($sub['reply']) . '”' : '' ?></p>
      <?= $form('sub-reopen', (int)$sub['id'], 'Reopen', 'submission') ?>
      <?php endif ?>
      <?= $form('sub-delete', (int)$sub['id'], 'Delete submission and its pictures', 'submission', true) ?>
    </div>
    <?php endif ?>
  <?php elseif ($view === 'users'):
    $q = trim((string)($_GET['q'] ?? ''));
    $st = $pdo->prepare("SELECT u.*, (SELECT GROUP_CONCAT(badge) FROM user_badges b WHERE b.user_id = u.id) AS badges
                         FROM users u WHERE (? = '' OR u.display_name LIKE ? OR u.email LIKE ?) ORDER BY u.created_at DESC LIMIT 300");
    $like = '%' . $q . '%';
    $st->execute([$q, $like, $like]);
    $rows = $st->fetchAll();
    $total = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(); ?>
    <form class="search" method="get"><input type="hidden" name="view" value="users">
      <input name="q" value="<?= h($q) ?>" placeholder="Search name or email"><button>Search</button>
      <span class="muted"><?= $total ?> account<?= $total === 1 ? '' : 's' ?></span></form>
    <?php if (!$rows): ?><p class="empty">No accounts<?= $q !== '' ? ' match that search' : ' yet' ?>.</p><?php else: ?>
    <table>
      <tr><th>Name</th><th>Email</th><th>Login</th><th>Stars</th><th>Badges</th><th>Joined</th><th></th></tr>
      <?php foreach ($rows as $u): ?>
        <tr>
          <td><strong><?= h($u['display_name']) ?></strong><?= isset(ADMIN_ROLES[$u['role'] ?? '']) ? ' <span class="tag tag--open">' . ADMIN_ROLES[$u['role']] . '</span>' : '' ?><?= user_banned($u) ? ' <span class="tag tag--banned" title="' . h((string)$u['ban_reason']) . '">Banned</span>' : '' ?></td>
          <td><?= h($u['email'] ?? '') ?><?= $u['email'] && $u['email_verified'] ? ' <span class="muted" title="confirmed">✓</span>' : '' ?><?= !user_verified($u) ? ' <span class="tag" title="Can\'t like, review or post until the email is confirmed">not confirmed</span>' : '' ?></td>
          <td class="muted"><?= $u['password_hash'] ? 'email' : 'no password' ?></td>
          <td>★ <?= (int)$u['stars'] ?></td>
          <td><?php foreach (array_filter(explode(',', (string)$u['badges'])) as $b) echo isset(BADGES[$b]) ? '<span title="' . h(BADGES[$b][0]) . '">' . BADGES[$b][2] . '</span> ' : ''; ?></td>
          <td class="muted"><?= date('Y-m-d', (int)$u['created_at']) ?></td>
          <td class="acts"><a class="button" href="?view=user&amp;id=<?= (int)$u['id'] ?>">Manage</a>
            <?= $form('stars', (int)$u['id'], '+1 ★', 'users') ?></td>
        </tr>
      <?php endforeach ?>
    </table>
    <?php endif ?>
  <?php elseif ($view === 'user'):
    $st = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([(int)($_GET['id'] ?? 0)]);
    $u = $st->fetch();
    if (!$u): ?><p class="empty">That account doesn't exist (anymore). <a href="?view=users">Back to accounts</a></p><?php else:
      $uid = (int)$u['id'];
      $ub = array_column(user_badges($uid), null, 'badge');
      $st = $pdo->prepare('SELECT * FROM star_events WHERE user_id = ? ORDER BY created_at DESC LIMIT 30');
      $st->execute([$uid]);
      $events = $st->fetchAll();
      $st = $pdo->prepare("SELECT kind, COUNT(*) n FROM posts WHERE user_id = ? GROUP BY kind");
      $st->execute([$uid]);
      $byKind = array_column($st->fetchAll(), 'n', 'kind');
      $nReviews = (int)($byKind['review'] ?? 0);
      $nComments = (int)($byKind['comment'] ?? 0);
      $nPosts = $nReviews + $nComments;
      $banned = user_banned($u);
      $st = $pdo->prepare('SELECT * FROM user_bans WHERE user_id = ? ORDER BY created_at DESC LIMIT 20');
      $st->execute([$uid]);
      $bans = $st->fetchAll();
      $canBan = $uid !== (int)($_SESSION['admin_user'] ?? 0) && !isset(ADMIN_ROLES[$u['role'] ?? '']);
      $st = $pdo->prepare('SELECT COUNT(*) FROM bounty_tips WHERE user_id = ?');
      $st->execute([$uid]);
      $nTips = (int)$st->fetchColumn(); ?>
    <p><a href="?view=users">← All accounts</a></p>
    <div class="panel">
      <h2><?= h($u['display_name']) ?> <span class="muted">★ <?= (int)$u['stars'] ?></span><?= $banned ? ' <span class="tag tag--banned">Banned</span>' : '' ?></h2>
      <p class="muted">
        <?= h($u['email'] ?? 'no email') ?> ·
        joined <?= date('Y-m-d', (int)$u['created_at']) ?><?= $u['last_login'] ? ' · last login ' . date('Y-m-d', (int)$u['last_login']) : '' ?> ·
        <?= $nReviews ?> review<?= $nReviews === 1 ? '' : 's' ?> · <?= $nComments ?> discussion message<?= $nComments === 1 ? '' : 's' ?> · <?= $nTips ?> bounty tips
      </p>
<?php if ($banned): ?>
      <div class="ban-box">
        <p><strong>Banned <?= (int)$u['ban_until'] === 0 ? 'for good' : 'until ' . h(date('Y-m-d H:i', (int)$u['ban_until'])) ?>.</strong> Reason: <?= h((string)$u['ban_reason']) ?></p>
        <p class="muted">They can't log in; when they try, they see this reason<?= (int)$u['ban_until'] === 0 ? '' : ' and the end date' ?>.</p>
        <?= $form('unban', $uid, 'Lift the ban', 'user') ?>
      </div>
<?php endif ?>
    </div>
    <div class="cols">
      <div class="panel">
        <h3><?= $banned ? 'Change the ban' : 'Ban this account' ?></h3>
<?php if (!$canBan): ?>
        <p class="muted"><?= $uid === (int)($_SESSION['admin_user'] ?? 0) ? 'This is you.' : 'This account has admin panel access. Remove that first to ban it.' ?></p>
<?php else: ?>
        <form method="post" class="stack">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="do" value="ban">
          <input type="hidden" name="id" value="<?= $uid ?>"><input type="hidden" name="view" value="user">
          <label>Reason <span class="muted">the user sees this when they try to log in</span>
            <input name="reason" required maxlength="300" placeholder="e.g. Spam in the discussions"></label>
          <label>How long
            <select name="duration" required>
              <option value="1">1 day</option><option value="3">3 days</option><option value="7" selected>1 week</option>
              <option value="30">1 month</option><option value="90">3 months</option><option value="365">1 year</option>
              <option value="forever">For good</option>
            </select></label>
          <label class="check"><input type="checkbox" name="delete_reviews" value="1"> Also delete all their reviews (<?= $nReviews ?>)</label>
          <label class="check"><input type="checkbox" name="delete_comments" value="1"> Also delete all their discussion messages (<?= $nComments ?>)</label>
          <div><button class="danger"><?= $banned ? 'Ban again (replaces the current ban)' : 'Ban account' ?></button></div>
        </form>
<?php endif ?>
<?php if ($bans): ?>
        <h4 class="ban-history__title">Ban history</h4>
        <ul class="log">
<?php foreach ($bans as $b): ?>
          <li><?= h(date('Y-m-d', (int)$b['created_at'])) ?>: <?= (int)$b['until'] === 0 ? 'for good' : 'until ' . h(date('Y-m-d', (int)$b['until'])) ?> · <?= h((string)$b['reason']) ?>
            <span class="muted"><?= $b['by_who'] !== '' ? 'by ' . h((string)$b['by_who']) : '' ?><?= $b['lifted_at'] ? ' · lifted ' . h(date('Y-m-d', (int)$b['lifted_at'])) : '' ?></span></li>
<?php endforeach ?>
        </ul>
<?php endif ?>
      </div>
      <div class="panel">
        <h3>Their reviews &amp; discussion</h3>
<?php if (!$nPosts): ?>
        <p class="muted">This account hasn't posted anything.</p>
<?php else: ?>
        <p class="muted"><a href="?view=posts&amp;user=<?= $uid ?>">See them all</a> (hide or delete one by one), or delete them in one go:</p>
        <form method="post" class="stack">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="do" value="user-posts-delete">
          <input type="hidden" name="id" value="<?= $uid ?>"><input type="hidden" name="view" value="user">
          <label class="check"><input type="checkbox" name="delete_reviews" value="1"<?= $nReviews ? '' : ' disabled' ?>> All their reviews (<?= $nReviews ?>)</label>
          <label class="check"><input type="checkbox" name="delete_comments" value="1"<?= $nComments ? '' : ' disabled' ?>> All their discussion messages (<?= $nComments ?>)</label>
          <div><button class="danger" data-confirm="Delete these posts for good?">Delete for good</button></div>
        </form>
<?php endif ?>
      </div>
    </div>
    <div class="cols">
      <div class="panel">
        <h3>Gold stars</h3>
        <form method="post" class="stack">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="do" value="stars">
          <input type="hidden" name="id" value="<?= $uid ?>"><input type="hidden" name="view" value="user">
          <label>Amount <input type="number" name="amount" value="1" min="-100" max="100"></label>
          <label>Reason (the user sees this) <input name="reason" maxlength="200" placeholder="e.g. Fixed the download links of Drifter"></label>
          <button>Give stars</button>
        </form>
        <?php if ($events): ?><ul class="log"><?php foreach ($events as $e): ?>
          <li><strong><?= $e['amount'] > 0 ? '+' : '' ?><?= (int)$e['amount'] ?></strong> <?= h($e['reason']) ?> <span class="muted"><?= date('Y-m-d', (int)$e['created_at']) ?></span></li>
        <?php endforeach ?></ul><?php endif ?>
      </div>
      <div class="panel">
        <h3>Badges</h3>
        <form method="post" class="stack">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="do" value="badge">
          <input type="hidden" name="id" value="<?= $uid ?>"><input type="hidden" name="view" value="user">
          <label>Badge <select name="badge"><?php foreach (BADGES as $bid => $b): ?><option value="<?= h($bid) ?>"><?= $b[2] ?> <?= h($b[0]) ?><?= isset($ub[$bid]) ? ' (has it)' : '' ?></option><?php endforeach ?></select></label>
          <label>Note (optional, shown instead of the default text) <input name="note" maxlength="200" placeholder="e.g. Found Cloudsdale Courier!"></label>
          <button>Award badge</button>
        </form>
        <ul class="log"><?php foreach ($ub as $bid => $b): ?>
          <li><?= BADGES[$bid][2] ?> <?= h(BADGES[$bid][0]) ?> <span class="muted"><?= date('Y-m-d', (int)$b['awarded_at']) ?></span>
            <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="do" value="unbadge">
              <input type="hidden" name="id" value="<?= $uid ?>"><input type="hidden" name="view" value="user"><input type="hidden" name="badge" value="<?= h($bid) ?>"><button class="small">remove</button></form></li>
        <?php endforeach ?></ul>
      </div>
      <div class="panel">
        <h3>Admin panel access</h3>
        <?php if ($uid === (int)($_SESSION['admin_user'] ?? 0)): ?>
          <p class="muted">This is you: you have <?= h(ADMIN_ROLES[$u['role']] ?? 'no') ?> access. Someone else has to change it.</p>
        <?php else: ?>
        <form method="post" class="stack">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="do" value="role">
          <input type="hidden" name="id" value="<?= $uid ?>"><input type="hidden" name="view" value="user">
          <label class="check"><input type="radio" name="role" value=""<?= isset(ADMIN_ROLES[$u['role'] ?? '']) ? '' : ' checked' ?>> No access</label>
          <label class="check"><input type="radio" name="role" value="moderator"<?= ($u['role'] ?? '') === 'moderator' ? ' checked' : '' ?>> Moderator: reports, submissions, reviews, games, resources, bounties</label>
          <label class="check"><input type="radio" name="role" value="admin"<?= ($u['role'] ?? '') === 'admin' ? ' checked' : '' ?>> Admin: all of that, plus accounts and giving others access</label>
          <div><button>Save access</button></div>
        </form>
        <?php endif ?>
      </div>
      <div class="panel">
        <h3>Login help</h3>
        <p class="muted">Forgot their password? Make a one-time link and send it to them privately (e.g. a Discord DM).</p>
        <?= $form('reset-link', $uid, 'Make password reset link', 'user') ?>
<?php if (!user_verified($u)): ?>
        <p class="muted" style="margin-top:1rem">Their email address isn't confirmed yet, so they can't like, review or post. Didn't get the email?</p>
        <?= $form('verify-email', $uid, 'Mark email as confirmed', 'user') ?>
<?php endif ?>
        <h3 style="margin-top:1.5rem">Delete account</h3>
        <form method="post" class="stack">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="do" value="user-delete">
          <input type="hidden" name="id" value="<?= $uid ?>"><input type="hidden" name="view" value="user">
          <label class="check"><input type="checkbox" name="delete_posts" value="1"> Also delete their reviews and discussion messages</label>
          <label class="check"><input type="checkbox" required> Yes, delete this account for good</label>
          <button class="danger">Delete account</button>
        </form>
      </div>
    </div>
    <?php endif ?>
  <?php elseif ($view === 'posts'):
    // ?user=12: only the posts of one account (from its Manage page)
    $onlyUser = (int)($_GET['user'] ?? 0);
    $st = $pdo->prepare('SELECT p.*, u.display_name AS member FROM posts p LEFT JOIN users u ON u.id = p.user_id
                         WHERE (? = 0 OR p.user_id = ?) ORDER BY p.created_at DESC LIMIT ' . ($onlyUser ? 1000 : 200));
    $st->execute([$onlyUser, $onlyUser]);
    $rows = $st->fetchAll();
    $extra = $onlyUser ? ['user' => $onlyUser] : [];
    if ($onlyUser): ?>
    <p><a href="?view=user&amp;id=<?= $onlyUser ?>">← Back to the account</a> · showing only the posts of <strong><?= h((string)($rows[0]['member'] ?? 'this account')) ?></strong> · <a href="?view=posts">show everyone's</a></p>
    <?php endif;
    if (!$rows): ?><p class="empty">No reviews or discussion messages <?= $onlyUser ? 'from this account' : 'yet' ?>.</p><?php else: ?>
    <table>
      <tr><th>When</th><th>Game</th><th>Type</th><th>Name</th><th>Text</th><th></th></tr>
      <?php foreach ($rows as $p): ?>
        <tr class="<?= $p['hidden'] ? 'hidden-row' : '' ?>">
          <td class="muted"><?= $when((int)$p['created_at']) ?></td>
          <td><?= $gameLink((string)$p['game_id']) ?></td>
          <td><?= $p['kind'] === 'comment' ? 'discussion' : 'review' ?><?= $p['edited_at'] ?? null ? ' · edited' : '' ?><?= $p['rating'] ? ' · ' . str_repeat('★', (int)$p['rating']) : '' ?><?= $p['hidden'] ? '<br><span class="muted">hidden</span>' : '' ?></td>
          <td><?= $p['user_id'] && $p['member'] !== null && admin_can_manage_accounts() ? '<a href="?view=user&amp;id=' . (int)$p['user_id'] . '">' . h($p['name']) . '</a>' : h($p['name']) ?></td>
          <td class="body"><?= h($p['body']) ?></td>
          <td class="acts">
            <?= $p['hidden'] ? $form('show', (int)$p['id'], 'Show', $view, false, $extra) : $form('hide', (int)$p['id'], 'Hide', $view, false, $extra) ?>
            <?= $form('delete', (int)$p['id'], 'Delete', $view, true, $extra) ?>
          </td>
        </tr>
      <?php endforeach ?>
    </table>
    <?php endif ?>
  <?php else:
    echo '<p class="subnav"><a href="?view=reports" class="' . ($view === 'reports' ? 'on' : '') . '">Open</a><a href="?view=resolved" class="' . ($view === 'resolved' ? 'on' : '') . '">Resolved</a></p>';
    $st = $pdo->prepare('SELECT r.*, p.body AS post_body, p.name AS post_name, p.kind AS post_kind, p.hidden AS post_hidden
                         FROM reports r LEFT JOIN posts p ON p.id = r.post_id
                         WHERE r.resolved = ? ORDER BY r.created_at DESC LIMIT 200');
    $st->execute([$view === 'resolved' ? 1 : 0]);
    $rows = $st->fetchAll();
    if (!$rows): ?><p class="empty"><?= $view === 'resolved' ? 'Nothing resolved yet.' : 'No open reports. All quiet in Equestria!' ?></p><?php else: ?>
    <table>
      <tr><th>When</th><th>Game</th><th>Reason</th><th>Details</th><th>Reported post</th><th></th></tr>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="muted"><?= $when((int)$r['created_at']) ?></td>
          <td><?= $gameLink((string)$r['game_id']) ?></td>
          <td><?= h($r['reason']) ?></td>
          <td class="body"><?= h($r['details']) ?></td>
          <td class="body"><?php if ($r['post_id']): ?>
            <?php if ($r['post_body'] === null): ?><span class="muted">(deleted)</span>
            <?php else: ?><strong><?= h($r['post_name']) ?></strong> <span class="muted"><?= $r['post_kind'] === 'comment' ? 'discussion' : 'review' ?><?= $r['post_hidden'] ? ', hidden' : '' ?></span><br><?= h($r['post_body']) ?><?php endif ?>
          <?php else: ?><span class="muted">the game page itself</span><?php endif ?></td>
          <td class="acts">
            <?php if ($r['post_id'] && $r['post_body'] !== null): ?>
              <?= $r['post_hidden'] ? $form('show', (int)$r['post_id'], 'Show post', $view) : $form('hide', (int)$r['post_id'], 'Hide post', $view) ?>
              <?= $form('delete', (int)$r['post_id'], 'Delete post', $view, true) ?>
            <?php endif ?>
            <?= $view === 'resolved' ? $form('reopen', (int)$r['id'], 'Reopen', $view) : $form('resolve', (int)$r['id'], 'Resolve', $view) ?>
          </td>
        </tr>
      <?php endforeach ?>
    </table>
    <?php endif ?>
  <?php endif ?>
  </main>
<?php endif ?>
</body>
</html>
