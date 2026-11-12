<?php
/**
 * Short links: /s/<code> (nginx, see deploy/nginx-ponygames.conf) or
 * /s.php?c=<code> -> the page of that share link (admin/links.php), with
 * ?s=<code> added so js/main.js counts the visit for it. Link previews from
 * Discord and the like follow the redirect but run no script, so they don't count.
 * Unknown codes go to the home page.
 */

declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
$code = strtolower((string)($_GET['c'] ?? ''));
$to = '/';
if (preg_match('/^[a-z0-9]{4,12}$/', $code)) {
    try {
        $st = db()->prepare('SELECT path FROM share_links WHERE code = ?');
        $st->execute([$code]);
        $path = $st->fetchColumn();
        if (is_string($path) && $path !== '' && $path[0] === '/' && !str_starts_with($path, '//')) {
            $to = $path . (str_contains($path, '?') ? '&' : '?') . 's=' . $code;
        }
    } catch (Throwable $e) {
        error_log('short link: ' . $e->getMessage());
    }
}
header('Location: ' . $to, true, 302);
