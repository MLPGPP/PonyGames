<?php
/** Your account: bookmarks, gold stars and badges (tab "Account") and settings (tab "Settings"). */

declare(strict_types=1);
require __DIR__ . '/inc/submissions.php';
require __DIR__ . '/inc/layout.php';

header('X-Frame-Options: DENY');
$user = require_login();
$uid = (int)$user['id'];
$pdo = db();
$tab = ($_GET['tab'] ?? '') === 'settings' ? 'settings' : 'account';

/* ---------------------------------------------------------------- actions */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $go = '/account?tab=settings';
    $action = (string)($_POST['action'] ?? '');
    $checkPassword = fn() => $user['password_hash'] && password_verify((string)($_POST['current_password'] ?? ''), $user['password_hash']);

    if (!csrf_ok()) {
        flash('error', 'Your session expired. Please try again.');
    } elseif ($action === 'name') {
        $name = trim((string)($_POST['name'] ?? ''));
        if ($e = display_name_error($name)) flash('error', $e);
        else {
            $pdo->prepare('UPDATE users SET display_name = ? WHERE id = ?')->execute([$name, $uid]);
            flash('ok', 'Your display name is now ' . $name . '.');
        }
    } elseif ($action === 'email') {
        $email = normalize_email((string)($_POST['email'] ?? ''));
        if (!valid_email($email)) flash('error', 'Please enter a valid email address.');
        elseif ($user['password_hash'] && !$checkPassword()) flash('error', 'Your current password is not right.');
        elseif (email_taken($email, $uid)) flash('error', 'That email address is already used by another account.');
        elseif ($email === $user['email']) flash('ok', 'That\'s already your email address.');
        elseif (!rate_ok('email-change', 5, 3600)) flash('error', 'Too many changes. Please try again later.');
        elseif (mail_enabled()) {
            // the new address only becomes active once it's confirmed
            if (send_verify_email($user, $email)) flash('ok', "We sent a confirmation link to $email. Your email changes once you click it.");
            else flash('error', 'We couldn\'t send the confirmation email. Please try again later.');
        } else {
            $pdo->prepare('UPDATE users SET email = ?, email_verified = 0 WHERE id = ?')->execute([$email, $uid]);
            flash('ok', 'Your email address is now ' . $email . '.');
        }
    } elseif ($action === 'notify') {
        $site = empty($_POST['notify_site']) ? 0 : 1;
        $mail = empty($_POST['notify_email']) ? 0 : 1;
        $updates = empty($_POST['notify_updates']) ? 0 : 1;
        $replies = empty($_POST['notify_replies']) ? 0 : 1;
        $pdo->prepare('UPDATE users SET notify_site = ?, notify_email = ?, notify_updates = ?, notify_replies = ? WHERE id = ?')
            ->execute([$site, $mail, $updates, $replies, $uid]);
        if (!$site) $pdo->prepare('UPDATE notifications SET read_at = ? WHERE user_id = ? AND read_at IS NULL')->execute([time(), $uid]);
        flash('ok', 'Your notification settings are saved.');
    } elseif ($action === 'resend') {
        if (mail_enabled() && $user['email'] && !$user['email_verified'] && rate_ok('email-change', 5, 3600)) {
            send_verify_email($user, $user['email']);
            flash('ok', 'We sent a new confirmation link to ' . $user['email'] . '.');
        }
    } elseif ($action === 'password') {
        $new = (string)($_POST['password'] ?? '');
        if ($user['password_hash'] && !$checkPassword()) flash('error', 'Your current password is not right.');
        elseif (!$user['email']) flash('error', 'Add an email address first, so you can log in with it.');
        elseif ($e = password_error($new)) flash('error', $e);
        elseif ($new !== (string)($_POST['password2'] ?? '')) flash('error', 'The two new passwords are not the same.');
        else {
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $uid]);
            session_regenerate_id(true);
            flash('ok', $user['password_hash'] ? 'Your password is changed.' : 'Your password is set. You can now log in with your email.');
        }
    } elseif ($action === 'delete') {
        $confirmed = $user['password_hash'] ? $checkPassword() : trim((string)($_POST['confirm'] ?? '')) === 'DELETE';
        if (!$confirmed) {
            flash('error', $user['password_hash'] ? 'Your password is not right, your account was not deleted.' : 'Type DELETE to confirm.');
        } else {
            delete_user($uid, !empty($_POST['delete_posts']));
            // stay in the (now anonymous) session so the goodbye message shows
            unset($_SESSION['uid']);
            session_regenerate_id(true);
            flash('ok', 'Your account is deleted. Thanks for being part of the herd!');
            header('Location: /login');
            exit;
        }
    }
    header('Location: ' . $go);
    exit;
}

/* ---------------------------------------------------------------- data */
$flash = take_flash();
$badges = user_badges($uid);
$earned = array_column($badges, null, 'badge');
$bookmarks = user_bookmarks($uid);
$st = $pdo->prepare('SELECT amount, reason, created_at FROM star_events WHERE user_id = ? ORDER BY created_at DESC LIMIT 8');
$st->execute([$uid]);
$starLog = $st->fetchAll();
$st = $pdo->prepare('SELECT COUNT(*) FROM posts WHERE user_id = ? AND hidden = 0');
$st->execute([$uid]);
$postCount = (int)$st->fetchColumn();
$stars = (int)$user['stars'];
$st = $pdo->prepare('SELECT id, kind, game_id, data, status, reply, created_at FROM submissions WHERE user_id = ? ORDER BY created_at DESC LIMIT 10');
$st->execute([$uid]);
$mySubs = $st->fetchAll();
// your reviews and discussion messages, newest first
$myPosts = ['review' => [], 'comment' => []];
$st = $pdo->prepare('SELECT id, game_id, kind, rating, body, created_at, edited_at, hidden FROM posts WHERE user_id = ? ORDER BY created_at DESC');
$st->execute([$uid]);
foreach ($st->fetchAll() as $p) $myPosts[$p['kind'] === 'review' ? 'review' : 'comment'][] = $p;

/** One of your posts: the game, your stars (reviews), a bit of the text, and a link to it. */
function my_post_html(array $p, array $games): string
{
    $g = $games[(string)$p['game_id']] ?? null;
    $anchor = $p['kind'] === 'review' ? '#reviews' : '#discussion';
    $href = $g ? game_path($g) . $anchor : '';
    $title = $g['title'] ?? 'A game that is no longer listed';
    $thumb = $g && !empty($g['thumbnail']) ? '<img class="my-post__thumb" src="' . h($g['thumbnail']) . '" alt="" loading="lazy" width="96" height="54">' : '<span class="my-post__thumb" aria-hidden="true"></span>';
    $stars = '';
    if ($p['kind'] === 'review' && $p['rating']) {
        $stars = '<span class="my-post__stars" aria-label="' . (int)$p['rating'] . ' out of 5 stars">'
            . str_repeat('★', (int)$p['rating']) . '<span class="is-off">' . str_repeat('★', 5 - (int)$p['rating']) . '</span></span>';
    }
    $body = trim((string)$p['body']);
    if (mb_strlen($body) > 240) $body = rtrim(mb_substr($body, 0, 240)) . '…';
    $meta = date('M j, Y', (int)$p['created_at'])
        . (!empty($p['edited_at']) ? ' · edited' : '')
        . ($p['hidden'] ? ' · <span class="my-post__hidden" title="Reported by several people or hidden by a moderator">hidden</span>' : '');
    $titleHtml = $href !== '' ? '<a href="' . h($href) . '">' . h($title) . '</a>' : h($title);
    $data = ' data-post="' . (int)$p['id'] . '" data-kind="' . h($p['kind']) . '" data-body="' . h($p['body']) . '"'
        . ($p['rating'] ? ' data-rating="' . (int)$p['rating'] . '"' : '');
    $tools = '<span class="my-post__tools"><button type="button" class="post__edit" data-post-edit>Edit</button>'
        . '<button type="button" class="post__edit post__edit--danger" data-post-delete>Delete</button></span>';
    return '<li class="my-post' . ($p['hidden'] ? ' is-hidden' : '') . '"' . $data . '>' . ($href !== '' ? '<a class="my-post__thumb-link" href="' . h($href) . '" tabindex="-1" aria-hidden="true">' . $thumb . '</a>' : $thumb)
        . '<div class="my-post__main"><div class="my-post__head"><strong class="my-post__game">' . $titleHtml . '</strong>' . $stars
        . '<span class="my-post__meta">' . $meta . '</span>' . $tools . '</div>'
        . '<p class="my-post__body">' . nl2br(h($body)) . '</p></div></li>';
}

render_page_start($tab === 'settings' ? 'Settings' : 'Your account');
?>
  <main class="container account-page">

    <section class="account-hero">
      <?= avatar_html($user, 'account-hero__avatar') ?>
      <div class="account-hero__text">
        <p class="account-hero__kicker">Your account</p>
        <h1 class="account-hero__name"><?= h($user['display_name']) ?></h1>
        <p class="account-hero__meta">
          Member since <?= h(date('F Y', (int)$user['created_at'])) ?>
          · <a href="#your-posts"><span data-my-total><?= $postCount ?></span> post<?= $postCount === 1 ? '' : 's' ?></a>
        </p>
      </div>
      <div class="gold-stars" title="Gold stars earned by helping the site">
        <span class="gold-stars__icon" aria-hidden="true">★</span>
        <span class="gold-stars__count"><?= $stars ?></span>
        <span class="gold-stars__label">gold star<?= $stars === 1 ? '' : 's' ?></span>
      </div>
    </section>

    <nav class="account-tabs" aria-label="Account sections">
      <a href="/account" class="account-tab<?= $tab === 'account' ? ' is-active' : '' ?>"<?= $tab === 'account' ? ' aria-current="page"' : '' ?>>Account</a>
      <a href="/account?tab=settings" class="account-tab<?= $tab === 'settings' ? ' is-active' : '' ?>"<?= $tab === 'settings' ? ' aria-current="page"' : '' ?>>Settings</a>
<?php if (in_array($user['role'] ?? '', ['moderator', 'admin'], true)): ?>
      <a href="/admin/" class="account-tab account-tab--admin">Admin panel ↗</a>
<?php endif ?>
      <form method="post" action="/logout" class="account-tabs__logout"><?= csrf_field() ?><button class="btn btn--ghost">Log out</button></form>
    </nav>

<?php if ($flash): ?>
    <p class="form-alert form-alert--<?= $flash[0] === 'ok' ? 'ok' : 'error' ?>" role="status"><?= h($flash[1]) ?></p>
<?php endif ?>
<?php if (!user_verified($user)): ?>
    <div class="form-alert form-alert--warn confirm-email">
      <p><strong>Confirm your email address</strong> to like, bookmark, review and join discussions. We sent a link to <strong><?= h((string)$user['email']) ?></strong>; check your spam folder too.</p>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="resend"><button class="btn btn--ghost">Send the email again</button></form>
    </div>
<?php endif ?>

<?php if ($tab === 'account'): ?>
    <!-- bookmarks -->
    <section class="account-section">
      <div class="section-head">
        <h2 class="section-title">Your bookmarks <span class="game__count"><?= count($bookmarks) ?: '' ?></span></h2>
        <div class="section-head__links">
          <a href="/games?bookmarks=1" class="section-head__link">Show on games page →</a>
          <div class="carousel-nav" data-carousel="account-bookmarks" hidden>
            <button type="button" class="carousel-btn" data-dir="-1" aria-controls="account-bookmarks" aria-label="Previous bookmarks" disabled>
              <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M15 18l-6-6 6-6"/></svg>
            </button>
            <button type="button" class="carousel-btn" data-dir="1" aria-controls="account-bookmarks" aria-label="Next bookmarks">
              <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M9 18l6-6-6-6"/></svg>
            </button>
          </div>
        </div>
      </div>
<?php if ($bookmarks): ?>
      <div id="account-bookmarks" class="card-grid carousel" tabindex="0" aria-label="Your bookmarked games, scroll for more"
           data-bookmarks="<?= h(json_encode($bookmarks)) ?>"></div>
<?php else: ?>
      <div class="account-empty">
        <p><strong>No bookmarks yet.</strong> Press <em>Bookmark</em> on any game page and it shows up here, on all your devices.</p>
        <a class="btn btn--accent" href="/games">Browse games</a>
      </div>
<?php endif ?>
    </section>

    <!-- badges -->
    <section class="account-section">
      <div class="section-head">
        <h2 class="section-title">Badges <span class="game__count"><?= count($badges) ?>/<?= count(BADGES) ?></span></h2>
      </div>
      <p class="account-section__intro">
        Gold stars and badges are our thank-you for helping Pony Games: submitting games, fixing info, finding lost games on the
        <a href="/bounties">bounty board</a>. The moderators hand them out.
      </p>
      <ul class="badge-grid">
<?php foreach (BADGES as $id => [$bName, $bDesc, $bIcon]): $have = $earned[$id] ?? null; ?>
        <li class="badge<?= $have ? ' badge--earned' : '' ?>">
          <span class="badge__icon" aria-hidden="true"><?= $bIcon ?></span>
          <span class="badge__name"><?= h($bName) ?></span>
          <span class="badge__desc"><?= h($have && $have['note'] !== '' ? $have['note'] : $bDesc) ?></span>
          <span class="badge__state"><?= $have ? 'Earned ' . h(date('M j, Y', (int)$have['awarded_at'])) : 'Not yet' ?></span>
        </li>
<?php endforeach ?>
      </ul>
    </section>

    <section class="account-section" id="submissions">
      <div class="section-head">
        <h2 class="section-title">Your submissions</h2>
        <div class="section-head__links"><a class="section-head__link" href="/submit">Submit a game →</a></div>
      </div>
<?php if (!$mySubs): ?>
      <p class="account-section__intro">Games you submit and info you fix show up here, with what the moderators decided.
        <a href="/submit">Submit a game</a> or use <em>Suggest an edit</em> on any game page.</p>
<?php else: $allGames = all_games(); ?>
      <ul class="sub-list">
<?php foreach ($mySubs as $sub): $sd = json_decode($sub['data'], true) ?: [];
        $t = $sub['kind'] === 'edit' ? ($allGames[$sub['game_id']]['title'] ?? 'a game') : ($sd['values']['title'] ?? $sd['values']['name'] ?? 'a game'); ?>
        <li class="sub-item">
          <span class="sub-item__kind"><?= ['edit' => 'Fixed info of', 'resource' => 'New resource'][$sub['kind']] ?? 'New game' ?></span>
          <strong class="sub-item__title"><?= h($t) ?></strong>
          <span class="sub-item__status sub-item__status--<?= h($sub['status']) ?>"><?= $sub['status'] === 'open' ? 'Waiting for review' : ($sub['status'] === 'accepted' ? 'Accepted' : 'Not accepted') ?></span>
          <time class="sub-item__date"><?= h(date('M j, Y', (int)$sub['created_at'])) ?></time>
          <?php if ($sub['reply'] !== ''): ?><p class="sub-item__reply">“<?= h($sub['reply']) ?>”</p><?php endif ?>
        </li>
<?php endforeach ?>
      </ul>
<?php endif ?>
    </section>

    <section class="account-section" id="your-posts">
      <div class="section-head">
        <h2 class="section-title">Your reviews &amp; discussion</h2>
      </div>
<?php if (!$myPosts['review'] && !$myPosts['comment']): ?>
      <p class="account-section__intro">Reviews you write and messages you post in a game's discussion show up here.
        Find a game you like on the <a href="/games">games page</a> and tell the herd what you think!</p>
<?php else: $allGames = $allGames ?? all_games(); ?>
      <div class="my-posts">
<?php foreach (['review' => 'Reviews', 'comment' => 'Discussion'] as $kind => $label): $list = $myPosts[$kind]; ?>
        <div class="my-posts__col">
          <h3 class="my-posts__title"><?= $label ?> <span class="game__count" data-my-count="<?= $kind ?>"><?= count($list) ?: '' ?></span></h3>
          <p class="my-posts__empty" data-my-empty="<?= $kind ?>"<?= $list ? ' hidden' : '' ?>><?= $kind === 'review' ? 'No reviews yet.' : 'No messages yet.' ?></p>
<?php if ($list): ?>
          <ul class="my-post-list">
<?php foreach (array_slice($list, 0, 30) as $p) echo '            ' . my_post_html($p, $allGames) . "\n"; ?>
          </ul>
<?php if (count($list) > 30): ?><p class="my-posts__empty">And <?= count($list) - 30 ?> older ones.</p><?php endif ?>
<?php endif ?>
        </div>
<?php endforeach ?>
      </div>
<?php endif ?>
    </section>

<?php if ($starLog): ?>
    <section class="account-section">
      <h2 class="section-title">Recent stars</h2>
      <ul class="star-log">
<?php foreach ($starLog as $ev): ?>
        <li><span class="star-log__amount<?= $ev['amount'] < 0 ? ' is-negative' : '' ?>"><?= $ev['amount'] > 0 ? '+' : '' ?><?= (int)$ev['amount'] ?> ★</span>
            <span class="star-log__reason"><?= h($ev['reason'] ?: 'Thanks for helping!') ?></span>
            <time class="star-log__date"><?= h(date('M j, Y', (int)$ev['created_at'])) ?></time></li>
<?php endforeach ?>
      </ul>
    </section>
<?php endif ?>

<?php else: /* settings */ ?>
    <div class="settings-grid">
      <section class="settings-card">
        <h2 class="settings-card__title">Display name</h2>
        <p class="settings-card__text">Shown next to your reviews and discussion messages.</p>
        <form method="post" class="settings-form">
          <?= csrf_field() ?><input type="hidden" name="action" value="name">
          <label class="post-form__field"><span class="visually-hidden">Display name</span>
            <input name="name" required minlength="2" maxlength="30" value="<?= h($user['display_name']) ?>"></label>
          <button class="btn btn--accent">Save name</button>
        </form>
      </section>

      <section class="settings-card" id="notifications">
        <h2 class="settings-card__title">Notifications</h2>
        <form method="post" class="settings-form">
          <?= csrf_field() ?><input type="hidden" name="action" value="notify">
          <fieldset class="settings-group">
            <legend>Let me know when</legend>
            <label class="settings-check"><input type="checkbox" name="notify_updates" value="1"<?= (int)($user['notify_updates'] ?? 1) ? ' checked' : '' ?>> <span>A game I bookmarked gets an update <span class="muted">(a new download or version, a status change…)</span></span></label>
            <label class="settings-check"><input type="checkbox" name="notify_replies" value="1"<?= (int)($user['notify_replies'] ?? 1) ? ' checked' : '' ?>> <span>Someone replies to my message in a discussion</span></label>
          </fieldset>
          <fieldset class="settings-group">
            <legend>How</legend>
            <label class="settings-check"><input type="checkbox" name="notify_site" value="1"<?= (int)($user['notify_site'] ?? 1) ? ' checked' : '' ?>> <span>Under the bell <span aria-hidden="true">🔔</span> on the site</span></label>
            <label class="settings-check"><input type="checkbox" name="notify_email" value="1"<?= (int)($user['notify_email'] ?? 1) ? ' checked' : '' ?>> <span>By email <span class="muted">(one summary a day, around 9:00 Amsterdam time, only when something happened<?= mail_enabled() && !$user['email_verified'] ? '; once your email address is confirmed' : '' ?>)</span></span></label>
          </fieldset>
          <p class="settings-card__text muted">Messages from the moderators about your reports or your posts always show up under the bell.</p>
          <button class="btn btn--accent">Save</button>
        </form>
      </section>

      <section class="settings-card">
        <h2 class="settings-card__title">Email</h2>
        <p class="settings-card__text">
<?php if ($user['email']): ?>
          Now: <strong><?= h($user['email']) ?></strong>
          <?php if (mail_enabled()): ?><span class="pill <?= $user['email_verified'] ? 'pill--ok' : 'pill--warn' ?>"><?= $user['email_verified'] ? 'confirmed' : 'not confirmed' ?></span><?php endif ?>
<?php else: ?>
          You don't have an email address on your account yet.
<?php endif ?>
        </p>
<?php if (mail_enabled() && $user['email'] && !$user['email_verified']): ?>
        <form method="post" class="settings-form settings-form--inline"><?= csrf_field() ?><input type="hidden" name="action" value="resend"><button class="btn btn--ghost">Send confirmation again</button></form>
<?php endif ?>
        <form method="post" class="settings-form">
          <?= csrf_field() ?><input type="hidden" name="action" value="email">
          <label class="post-form__field">New email <input type="email" name="email" required autocomplete="email"></label>
<?php if ($user['password_hash']): ?>
          <label class="post-form__field">Current password <input type="password" name="current_password" required autocomplete="current-password"></label>
<?php endif ?>
          <button class="btn btn--accent">Change email</button>
        </form>
      </section>

      <section class="settings-card">
        <h2 class="settings-card__title"><?= $user['password_hash'] ? 'Password' : 'Set a password' ?></h2>
        <p class="settings-card__text"><?= $user['password_hash'] ? 'Choose a new password for logging in with your email.' : 'Your account has no password yet. Choose one to log in with your email.' ?></p>
        <form method="post" class="settings-form">
          <?= csrf_field() ?><input type="hidden" name="action" value="password">
<?php if ($user['password_hash']): ?>
          <label class="post-form__field">Current password <input type="password" name="current_password" required autocomplete="current-password"></label>
<?php endif ?>
          <label class="post-form__field">New password <input type="password" name="password" required minlength="8" autocomplete="new-password" placeholder="At least 8 characters"></label>
          <label class="post-form__field">New password again <input type="password" name="password2" required minlength="8" autocomplete="new-password"></label>
          <button class="btn btn--accent"><?= $user['password_hash'] ? 'Change password' : 'Set password' ?></button>
        </form>
      </section>

      <section class="settings-card settings-card--danger">
        <h2 class="settings-card__title">Delete account</h2>
        <p class="settings-card__text">
          This removes your account, bookmarks, gold stars and badges for good. Your reviews and discussion messages stay up without your name
          linked to them, unless you tick the box below.
        </p>
        <form method="post" class="settings-form">
          <?= csrf_field() ?><input type="hidden" name="action" value="delete">
          <label class="settings-check"><input type="checkbox" name="delete_posts" value="1"> Also delete my reviews and discussion messages</label>
<?php if ($user['password_hash']): ?>
          <label class="post-form__field">Your password, to confirm <input type="password" name="current_password" required autocomplete="current-password"></label>
<?php else: ?>
          <label class="post-form__field">Type DELETE to confirm <input name="confirm" required pattern="DELETE" autocomplete="off"></label>
<?php endif ?>
          <button class="btn btn--danger">Delete my account</button>
        </form>
      </section>
    </div>
<?php endif ?>

  </main>
<?php
render_page_end(['/js/post-tools.js', '/js/account.js']);
