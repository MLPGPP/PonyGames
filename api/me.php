<?php
/**
 * Who is logged in? Used by main.js for likes, bookmarks, the notification bell
 * and to keep bookmarks in sync.
 *   GET /api/me.php -> { user: null, pending } or { user: {...}, bookmarks: [...], notifications, pending }
 *   notifications: { on: bell wanted, unread: number }
 *   pending: ids of games with a suggested change waiting for a moderator
 * The first call after 9:00 (Amsterdam) also starts the daily summary emails
 * (inc/notify.php), after this answer went out.
 */

declare(strict_types=1);
require __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/notify.php';
maybe_send_digests();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

try {
    $u = current_user();
    $out = $u ? ['user' => public_user($u), 'bookmarks' => user_bookmarks((int)$u['id'])] : ['user' => null];
    if ($u) {
        $out['notifications'] = ['on' => (bool)(int)($u['notify_site'] ?? 1), 'unread' => notifications_unread((int)$u['id'])];
    }
    $out['pending'] = array_map('strval', array_keys(pending_game_edits())); // games with a change waiting for review
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('me api: ' . $e->getMessage());
    http_response_code(500);
    echo '{"user":null}';
}
