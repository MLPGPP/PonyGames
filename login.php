<?php
/** Log in, sign up, and "forgot password". */

declare(strict_types=1);
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/layout.php';

header('X-Frame-Options: DENY');
user_session();

$tab = in_array($_GET['tab'] ?? '', ['signup', 'forgot'], true) ? $_GET['tab'] : 'login';
$next = safe_next($_GET['next'] ?? $_POST['next'] ?? null);
$errors = [];
$notice = null;
$old = ['email' => '', 'name' => ''];

if (current_user() && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: ' . $next);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $tab = $action === 'signup' ? 'signup' : ($action === 'forgot' ? 'forgot' : 'login');
    $email = normalize_email((string)($_POST['email'] ?? ''));
    $old['email'] = $email;
    $pdo = db();

    if (!csrf_ok()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!empty($_POST['website'])) {
        $errors[] = 'Something went wrong. Please try again.'; // honeypot
    } elseif ($action === 'login') {
        if (!rate_ok('login', 10, 900)) {
            $errors[] = 'Too many login attempts. Please wait 15 minutes.';
        } else {
            $st = $pdo->prepare('SELECT * FROM users WHERE email = ?');
            $st->execute([$email]);
            $u = $st->fetch();
            $password = (string)($_POST['password'] ?? '');
            // always run password_verify so wrong emails take as long as wrong passwords
            $ok = password_verify($password, $u['password_hash'] ?? '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG');
            if ($u && $ok && $u['password_hash'] && user_banned($u)) {
                $errors[] = ban_message($u);
            } elseif ($u && $ok && $u['password_hash']) {
                if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
                    $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $u['id']]);
                }
                login_user((int)$u['id']);
                header('Location: ' . $next);
                exit;
            }
            if (!$errors) {
                $errors[] = $u && !$u['password_hash']
                    ? 'This account has no password yet. Use "Forgot your password?" to choose one.'
                    : 'That email and password don\'t match.';
            }
        }
    } elseif ($action === 'signup') {
        $name = trim((string)($_POST['name'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $old['name'] = $name;
        if (!valid_email($email)) $errors[] = 'Please enter a valid email address.';
        if ($e = display_name_error($name)) $errors[] = $e;
        if ($e = password_error($password)) $errors[] = $e;
        if (!$errors && !rate_ok('signup', 5, 3600)) $errors[] = 'Too many new accounts from your connection. Please try again later.';
        if (!$errors && email_taken($email)) $errors[] = 'There\'s already an account with this email. Try logging in instead.';
        if (!$errors) {
            $pdo->prepare('INSERT INTO users (email, password_hash, display_name, created_at) VALUES (?, ?, ?, ?)')
                ->execute([$email, password_hash($password, PASSWORD_DEFAULT), $name, time()]);
            $id = (int)$pdo->lastInsertId();
            login_user($id);
            $welcome = 'Welcome to the herd, ' . $name . '!';
            if (mail_enabled()) {
                $sent = send_verify_email(['id' => $id, 'display_name' => $name], $email);
                if ($sent) $welcome .= email_confirm_required()
                    ? ' We sent you an email: click the link in it to confirm your address, then you can like, bookmark, review and join discussions.'
                    : ' We sent you an email to confirm your address.';
                else $welcome .= ' We couldn\'t send the confirmation email just now' . (mail_busy() ? ' because it\'s very busy' : '')
                    . '. In a few minutes, ask for a new link on your account page.';
            }
            flash('ok', $welcome);
            header('Location: ' . $next);
            exit;
        }
    } elseif ($action === 'forgot') {
        if (!valid_email($email)) {
            $errors[] = 'Please enter a valid email address.';
        } elseif (!rate_ok('forgot', 5, 3600)) {
            $errors[] = 'Too many requests. Please try again later.';
        } elseif (mail_busy()) {
            $errors[] = MAIL_BUSY_MESSAGE; // the same for every address, so it doesn't tell which ones have an account
        } else {
            if (mail_enabled()) {
                $st = $pdo->prepare('SELECT * FROM users WHERE email = ?');
                $st->execute([$email]);
                if ($u = $st->fetch()) {
                    $token = make_token((int)$u['id'], 'reset', '', 3600);
                    send_reset_email($u, $email, $token);
                }
            }
            // same answer whether or not the account exists
            $notice = 'If there\'s an account with that email, we\'ve sent a link to reset your password. Check your inbox (and spam folder).';
        }
    }
}

$flash = take_flash();

render_page_start($tab === 'signup' ? 'Create an account' : 'Log in', '', 'Log in to Pony Games to keep your bookmarks and collect gold stars.');
?>
  <main class="container auth-page">
    <div class="auth-card">
      <img class="auth-card__mascot" src="/assets/mascot-head.png" alt="" width="64" height="84" />
      <h1 class="auth-card__title"><?= $tab === 'signup' ? 'Join the herd' : ($tab === 'forgot' ? 'Forgot your password?' : 'Welcome back!') ?></h1>
      <p class="auth-card__lead">
        <?php if ($tab === 'forgot'): ?>
          No worries, it happens to the best ponies.
        <?php else: ?>
          With an account you can like, bookmark and review games, join the discussions and collect the gold stars you earn by helping the site. Browsing and downloading work without one.
        <?php endif ?>
      </p>

<?php if ($tab !== 'forgot'): ?>
      <div class="auth-tabs" role="tablist">
        <a role="tab" href="/login?<?= h(http_build_query(['next' => $next])) ?>" class="auth-tab<?= $tab === 'login' ? ' is-active' : '' ?>" aria-selected="<?= $tab === 'login' ? 'true' : 'false' ?>">Log in</a>
        <a role="tab" href="/login?<?= h(http_build_query(['tab' => 'signup', 'next' => $next])) ?>" class="auth-tab<?= $tab === 'signup' ? ' is-active' : '' ?>" aria-selected="<?= $tab === 'signup' ? 'true' : 'false' ?>">Create account</a>
      </div>
<?php endif ?>

<?php if ($flash): ?>
      <p class="form-alert form-alert--<?= $flash[0] === 'ok' ? 'ok' : 'error' ?>" role="status"><?= h($flash[1]) ?></p>
<?php endif ?>
<?php if ($errors): ?>
      <div class="form-alert form-alert--error" role="alert"><?php foreach ($errors as $e): ?><p><?= h($e) ?></p><?php endforeach ?></div>
<?php endif ?>
<?php if ($notice): ?>
      <p class="form-alert form-alert--ok" role="status"><?= h($notice) ?></p>
<?php endif ?>

<?php if ($tab === 'forgot'): ?>
  <?php if (mail_enabled()): ?>
      <form method="post" class="auth-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="forgot">
        <input type="hidden" name="next" value="<?= h($next) ?>">
        <label class="post-form__field">Email <input type="email" name="email" required autocomplete="email" value="<?= h($old['email']) ?>"></label>
        <label class="hp" aria-hidden="true">Leave empty <input name="website" tabindex="-1" autocomplete="off"></label>
        <button class="btn btn--accent auth-form__submit">Send me a reset link</button>
      </form>
  <?php else: ?>
      <p class="auth-card__note">
        We can't send emails yet, so ask a moderator on our
        <a href="https://discord.gg/H2vbaAUMzP" target="_blank" rel="noopener">Discord server</a>.
        They can give you a link to choose a new password.
      </p>
  <?php endif ?>
      <p class="auth-card__switch"><a href="/login?<?= h(http_build_query(['next' => $next])) ?>">← Back to log in</a></p>

<?php else: ?>

      <form method="post" class="auth-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="<?= $tab === 'signup' ? 'signup' : 'login' ?>">
        <input type="hidden" name="next" value="<?= h($next) ?>">
  <?php if ($tab === 'signup'): ?>
        <label class="post-form__field">Display name
          <input name="name" required minlength="2" maxlength="30" autocomplete="nickname" value="<?= h($old['name']) ?>" placeholder="Shown next to your reviews and comments">
        </label>
  <?php endif ?>
        <label class="post-form__field">Email <input type="email" name="email" required autocomplete="email" value="<?= h($old['email']) ?>"></label>
        <label class="post-form__field">
          <span class="auth-form__label-row">Password
            <?php if ($tab === 'login'): ?><a href="/login?<?= h(http_build_query(['tab' => 'forgot', 'next' => $next])) ?>">Forgot it?</a><?php endif ?>
          </span>
          <input type="password" name="password" required <?= $tab === 'signup' ? 'minlength="8" autocomplete="new-password" placeholder="At least 8 characters"' : 'autocomplete="current-password"' ?>>
        </label>
        <label class="hp" aria-hidden="true">Leave empty <input name="website" tabindex="-1" autocomplete="off"></label>
        <button class="btn btn--accent auth-form__submit"><?= $tab === 'signup' ? 'Create my account' : 'Log in' ?></button>
  <?php if ($tab === 'signup'): ?>
        <p class="auth-form__terms">By creating an account you agree to our <a href="/rules" target="_blank">Site Rules</a>.</p>
  <?php endif ?>
      </form>
  <?php if ($tab === 'signup'): ?>
      <p class="auth-card__fine">We only use your email to log you in<?= mail_enabled() ? ' and to send password resets' : '' ?>. No newsletters, no sharing. You can delete your account any time.</p>
  <?php endif ?>
<?php endif ?>
    </div>
  </main>
<?php
render_page_end();
