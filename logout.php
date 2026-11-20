<?php
/** Log out (POST only, so other sites can't log you out with a link). */

declare(strict_types=1);
require __DIR__ . '/inc/auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && csrf_ok()) {
    logout_user();
}
header('Location: /');
exit;
