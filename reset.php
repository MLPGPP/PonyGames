<?php
/** Choose a new password with a one-time link (from email or a moderator). */

declare(strict_types=1);
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/layout.php';

header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
user_session();

$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$row = peek_token($token, 'reset');
$error = null;

if ($row && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $password = (string)($_POST['password'] ?? '');
    if (!csrf_ok()) {
        $error = 'Your session expired. Please try again.';
    } elseif ($e = password_error($password)) {
        $error = $e;
    } elseif ($password !== (string)($_POST['password2'] ?? '')) {
        $error = 'The two passwords are not the same.';
    } elseif (use_token($token, 'reset')) {
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $row['user_id']]);
        login_user((int)$row['user_id']);
        flash('ok', 'Your new password is set. You\'re logged in!');
        header('Location: /account');
        exit;
    }
}

render_page_start('Choose a new password');
?>
  <main class="container auth-page">
    <div class="auth-card">
      <img class="auth-card__mascot" src="/assets/mascot-head.png" alt="" width="64" height="84" />
<?php if (!$row): ?>
      <h1 class="auth-card__title">This link doesn't work anymore</h1>
      <p class="auth-card__lead">Reset links work once, for a limited time. Ask for a new one and try again.</p>
      <a class="btn btn--accent auth-form__submit" href="/login?tab=forgot">Get a new link</a>
<?php else: ?>
      <h1 class="auth-card__title">Choose a new password</h1>
<?php if ($error): ?><p class="form-alert form-alert--error" role="alert"><?= h($error) ?></p><?php endif ?>
      <form method="post" class="auth-form">
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= h($token) ?>">
        <label class="post-form__field">New password <input type="password" name="password" required minlength="8" autocomplete="new-password" placeholder="At least 8 characters"></label>
        <label class="post-form__field">Type it again <input type="password" name="password2" required minlength="8" autocomplete="new-password"></label>
        <button class="btn btn--accent auth-form__submit">Save new password</button>
      </form>
<?php endif ?>
    </div>
  </main>
<?php
render_page_end();
