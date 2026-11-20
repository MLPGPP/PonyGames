<?php
/**
 * The notification bell (js/main.js).
 *   GET  /api/notifications.php                      -> { unread, items: [{ id, kind, game, title, body, link, thumb, at, read }] }
 *        kind "update": a bookmarked game changed; "reply": someone answered your message;
 *        "mod": a message from the moderators about a report; "sub": a submission was accepted or rejected;
 *        "tip": about a bounty tip (answered, now a bounty, or the game was found); "req": about an asset request
 *   POST /api/notifications.php { action: "read", id }  -> { unread }   one notification read
 *   POST /api/notifications.php { action: "read-all" }  -> { unread }   all read
 * Only for the logged-in member; the newest 20 are listed.
 */

declare(strict_types=1);
require __DIR__ . '/../inc/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

function reply(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $u = current_user();
    if (!$u) reply(['error' => 'Please log in first.'], 401);
    $uid = (int)$u['id'];
    $pdo = db();
    $unread = fn(): int => notifications_unread($uid);

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'pony-games') reply(['error' => 'Bad request'], 400);
        $in = json_decode((string)file_get_contents('php://input'), true);
        $action = is_array($in) ? (string)($in['action'] ?? '') : '';
        if ($action === 'read') {
            $pdo->prepare('UPDATE notifications SET read_at = ? WHERE id = ? AND user_id = ? AND read_at IS NULL')->execute([time(), (int)($in['id'] ?? 0), $uid]);
        } elseif ($action === 'read-all') {
            $pdo->prepare('UPDATE notifications SET read_at = ? WHERE user_id = ? AND read_at IS NULL')->execute([time(), $uid]);
        } else {
            reply(['error' => 'Unknown action'], 400);
        }
        reply(['unread' => $unread()]);
    }

    $st = $pdo->prepare('SELECT n.*, a.avatar_url AS actor_avatar FROM notifications n LEFT JOIN users a ON a.id = n.actor_id
                         WHERE n.user_id = ? AND ' . NOTIFICATION_LIVE_SQL . ' ORDER BY n.created_at DESC, n.id DESC LIMIT 20');
    $st->execute([$uid]);
    $games = all_games();
    $items = [];
    foreach ($st->fetchAll() as $n) {
        $g = $games[(string)$n['game_id']] ?? null;
        if ($n['kind'] === 'sub' || $n['kind'] === 'tip' || $n['kind'] === 'req') {
            $items[] = [
                'id' => (int)$n['id'],
                'kind' => $n['kind'],
                'game' => (string)$n['game_id'],
                'title' => (string)$n['title'],
                'body' => (string)$n['body'],
                'link' => (string)($n['link'] ?: ['tip' => '/bounties', 'req' => '/requests'][$n['kind']] ?? '/account#submissions'),
                'thumb' => $g['thumbnail'] ?? '/assets/mascot-head.png',
                'avatar_color' => null,
                'at' => (int)$n['created_at'],
                'read' => $n['read_at'] !== null,
            ];
            continue;
        }
        if ($n['kind'] === 'mod') {
            $items[] = [
                'id' => (int)$n['id'],
                'kind' => 'mod',
                'game' => (string)$n['game_id'],
                'title' => (string)$n['title'],
                'body' => 'On ' . ($g['title'] ?? 'a game') . ': ' . $n['body'],
                'link' => $g ? game_path($g) . ($n['post_id'] ? '#post-' . (int)$n['post_id'] : '') : null,
                'thumb' => '/assets/mascot-head.png',
                'avatar_color' => null,
                'at' => (int)$n['created_at'],
                'read' => $n['read_at'] !== null,
            ];
            continue;
        }
        $isReply = $n['kind'] === 'reply';
        $items[] = [
            'id' => (int)$n['id'],
            'kind' => $isReply ? 'reply' : 'update',
            'game' => (string)$n['game_id'],
            // replies: "Rarity replied to your message" + "On <game>: “…”"
            'title' => $isReply ? $n['title'] : ($g['title'] ?? $n['title']),
            'body' => $isReply ? 'On ' . ($g['title'] ?? 'a game') . ': “' . $n['body'] . '”' : $n['body'],
            'link' => $g ? game_path($g) . ($isReply ? '#post-' . (int)$n['post_id'] : '') : null,
            'thumb' => $isReply && !empty($n['actor_avatar']) ? $n['actor_avatar'] : ($g['thumbnail'] ?? null),
            // a reply from someone without a picture: their mascot avatar
            'avatar_color' => $isReply && empty($n['actor_avatar']) && $n['actor_id'] ? avatar_color((int)$n['actor_id']) : null,
            'at' => (int)$n['created_at'],
            'read' => $n['read_at'] !== null,
        ];
    }
    reply(['unread' => $unread(), 'items' => $items]);
} catch (Throwable $e) {
    error_log('notifications api: ' . $e->getMessage());
    reply(['error' => 'Something went wrong.'], 500);
}
