<?php
/** One-click "turn off these emails" link from the update emails (no login needed). */

declare(strict_types=1);
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/notify.php';

header('Referrer-Policy: no-referrer');
$uid = (int)($_GET['u'] ?? 0);
if (notify_off_valid($uid, (string)($_GET['t'] ?? ''))) {
    db()->prepare('UPDATE users SET notify_email = 0 WHERE id = ?')->execute([$uid]);
    flash('ok', "Done: you won't get emails about your bookmarked games anymore. You can turn them back on in your account settings.");
} else {
    flash('error', 'That link doesn\'t work. You can turn the emails off in your account settings.');
}
header('Location: ' . (current_user() ? '/account?tab=settings#notifications' : '/login'));
exit;
