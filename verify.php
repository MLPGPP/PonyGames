<?php
/** Confirms an email address (link from the verification email). */

declare(strict_types=1);
require __DIR__ . '/inc/auth.php';

header('Referrer-Policy: no-referrer');
$row = use_token((string)($_GET['token'] ?? ''), 'verify');
if ($row) {
    $email = $row['data'];
    if (email_taken($email, (int)$row['user_id'])) {
        flash('error', 'That email address is already used by another account.');
    } else {
        db()->prepare('UPDATE users SET email = ?, email_verified = 1 WHERE id = ?')->execute([$email, $row['user_id']]);
        flash('ok', 'Thanks! Your email address is confirmed.');
    }
} else {
    flash('error', 'That confirmation link doesn\'t work anymore. You can send a new one from your settings.');
}
header('Location: ' . (current_user() ? '/account?tab=settings' : '/login'));
exit;
