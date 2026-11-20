<?php
/**
 * Community API for game pages (called by js/game.js).
 *
 *   GET  /api/community.php?game=ID
 *        -> { likes, liked, reviews, rating }
 *   POST /api/community.php   (JSON body, header X-Requested-With: pony-games)
 *        { action: "like",    game }                      logged in only, toggles
 *        { action: "review",  game, rating (1-5), body }   logged in only; one per
 *                                                         member and game: posting again edits it
 *        { action: "comment", game, body, reply_to? }     a message in the game's discussion, logged in only;
 *                                                         with reply_to (a message id) it's a reply nested under
 *                                                         that message and its writer gets a notification
 *        { action: "edit",    post, body, rating? }        your own review / message
 *        { action: "delete",  post }                      your own review / message
 *        { action: "report",  game, reason, details, post? }
 *
 * Likes, reviews and discussion need an account; a like is stored under
 * "u:<user id>". Reports work without one (an anonymous cookie). IPs are only
 * stored as salted hashes (for rate limits). Moderation: /admin/.
 */

declare(strict_types=1);
require __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/notify.php';
require_once __DIR__ . '/../inc/discord.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function reply(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $message, int $code = 400): never
{
    reply(['error' => $message], $code);
}

/**
 * The like key for a member. A like this browser gave before likes needed an
 * account moves over to the member, so nobody counts twice.
 */
function adopt_guest_like(string $gid, int $userId): string
{
    $voter = 'u:' . $userId;
    $pdo = db();
    $pdo->prepare('UPDATE OR IGNORE likes SET visitor = ? WHERE game_id = ? AND visitor = ?')->execute([$voter, $gid, visitor_id()]);
    $pdo->prepare('DELETE FROM likes WHERE game_id = ? AND visitor = ?')->execute([$gid, visitor_id()]);
    return $voter;
}

const REPORT_REASONS = [
    'Broken or dead link', 'Wrong information', 'Missing NSFW / gore tag',
    'Spam or abuse', 'Something else',
];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method === 'GET') {
        $game = find_game($_GET['game'] ?? '');
        if (!$game) fail('Unknown game', 404);
        $gid = (string)$game['id'];
        $account = current_user();
        $liked = false;
        if ($account) {
            $voter = adopt_guest_like($gid, (int)$account['id']);
            $st = db()->prepare('SELECT 1 FROM likes WHERE game_id = ? AND visitor = ?');
            $st->execute([$gid, $voter]);
            $liked = (bool)$st->fetchColumn();
        }
        reply(community_summary($gid) + ['liked' => $liked, 'loggedIn' => (bool)$account]);
    }

    if ($method !== 'POST') fail('Method not allowed', 405);

    // Only our own pages may post: a custom header forces a CORS preflight
    // for other sites, and the Origin (when sent) has to be this host.
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'pony-games') fail('Bad request', 400);
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== parse_url('//' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)) {
        fail('Bad origin', 403);
    }

    $in = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($in)) fail('Bad request');
    if (!empty($in['website'])) reply(['ok' => true]); // honeypot filled in: a bot, pretend all is fine

    $pdo = db();
    $visitor = visitor_id();
    $action = (string)($in['action'] ?? '');

    $clean = function (string $key, int $min, int $max, string $what) use ($in): string {
        $v = trim(preg_replace('/\s+\n/', "\n", (string)($in[$key] ?? '')));
        $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '';
        $len = mb_strlen($v);
        if ($len < $min) fail($min <= 1 ? "Please fill in your $what." : "Your $what is too short (at least $min characters).");
        if ($len > $max) fail("Your $what is too long (at most $max characters).");
        return $v;
    };
    $noLinks = "Links aren't allowed in reviews or discussion messages. Please remove the link and try again.";

    // editing or deleting your own review / discussion message (also from the account page)
    if ($action === 'edit' || $action === 'delete') {
        $account = current_user();
        if (!$account) fail('Please log in first.', 401);
        if ($action === 'edit' && !user_verified($account)) fail(NEED_CONFIRM_MESSAGE, 403);
        $st = $pdo->prepare('SELECT id, game_id, kind, rating, created_at FROM posts WHERE id = ? AND user_id = ?');
        $st->execute([(int)($in['post'] ?? 0), (int)$account['id']]);
        $own = $st->fetch();
        if (!$own) fail('That post doesn\'t exist anymore, or it isn\'t yours.', 404);
        $pid = (int)$own['id'];
        if ($action === 'delete') {
            lift_replies('id = ?', [$pid]);
            $pdo->prepare('DELETE FROM posts WHERE id = ?')->execute([$pid]);
            $pdo->prepare('UPDATE reports SET resolved = 1 WHERE post_id = ?')->execute([$pid]);
            forget_reply_notification($pid);
            reply(['ok' => true, 'deleted' => $pid, 'kind' => $own['kind']] + community_summary((string)$own['game_id']));
        }
        $isReview = $own['kind'] === 'review';
        $body = $isReview ? $clean('body', 10, 2000, 'review') : $clean('body', 2, 1500, 'message');
        if (has_link($body)) fail($noLinks);
        $rating = $own['rating'] === null ? null : (int)$own['rating'];
        if ($isReview && isset($in['rating'])) {
            $rating = (int)$in['rating'];
            if ($rating < 1 || $rating > 5) fail('Please pick a rating from 1 to 5 stars.');
        }
        if (!rate_ok('post-edit', 20, 600)) fail("You're editing a lot! Please wait a bit.", 429);
        $now = time();
        $pdo->prepare('UPDATE posts SET body = ?, rating = ?, name = ?, edited_at = ? WHERE id = ?')
            ->execute([$body, $rating, (string)$account['display_name'], $now, $pid]);
        reply([
            'ok' => true, 'edited' => true,
            'post' => ['id' => $pid, 'kind' => $own['kind'], 'rating' => $rating, 'name' => (string)$account['display_name'], 'body' => $body,
                       'created_at' => (int)$own['created_at'], 'edited_at' => $now, 'stars' => (int)$account['stars'],
                       'avatar' => $account['avatar_url'] ?? '', 'avatar_color' => avatar_color((int)$account['id']), 'mine' => true,
                       'badges' => badge_list(array_column(user_badges((int)$account['id']), 'badge'))],
        ] + community_summary((string)$own['game_id']));
    }

    $game = find_game($in['game'] ?? '');
    if (!$game) fail('Unknown game', 404);
    $gid = (string)$game['id'];

    switch ($action) {
        case 'like':
            $account = current_user();
            if (!$account) fail('Please log in to like games.', 401);
            if (!user_verified($account)) fail(NEED_CONFIRM_MESSAGE, 403);
            if (!rate_ok('like', 60, 600)) fail('Slow down a little, pony!', 429);
            $voter = adopt_guest_like($gid, (int)$account['id']);
            $st = $pdo->prepare('SELECT 1 FROM likes WHERE game_id = ? AND visitor = ?');
            $st->execute([$gid, $voter]);
            if ($st->fetchColumn()) {
                $pdo->prepare('DELETE FROM likes WHERE game_id = ? AND visitor = ?')->execute([$gid, $voter]);
                $liked = false;
            } else {
                $pdo->prepare('INSERT INTO likes (game_id, visitor, created_at) VALUES (?, ?, ?)')->execute([$gid, $voter, time()]);
                $liked = true;
            }
            reply(community_summary($gid) + ['liked' => $liked]);

        case 'review':
        case 'comment':
            $isReview = $action === 'review';
            $account = current_user();
            if (!$account) fail($isReview ? 'Please log in to write a review.' : 'Please log in to join the discussion.', 401);
            if (!user_verified($account)) fail(NEED_CONFIRM_MESSAGE, 403);
            $uid = (int)$account['id'];
            $name = (string)$account['display_name'];
            $body = $isReview ? $clean('body', 10, 2000, 'review') : $clean('body', 2, 1500, 'message');
            if (has_link($body)) fail($noLinks);
            $rating = null;
            $mine = null;
            $target = null; // the message this one replies to
            if (!$isReview && !empty($in['reply_to'])) {
                $st = $pdo->prepare("SELECT p.id, p.game_id, p.user_id, p.parent_id, COALESCE(u.display_name, p.name) AS name
                                     FROM posts p LEFT JOIN users u ON u.id = p.user_id
                                     WHERE p.id = ? AND p.game_id = ? AND p.kind = 'comment' AND p.hidden = 0");
                $st->execute([(int)$in['reply_to'], $gid]);
                $target = $st->fetch() ?: null;
                if (!$target) fail('That message no longer exists, so you can\'t reply to it.', 404);
            }
            if ($isReview) {
                $rating = (int)($in['rating'] ?? 0);
                if ($rating < 1 || $rating > 5) fail('Please pick a rating from 1 to 5 stars.');
                $st = $pdo->prepare("SELECT id, created_at FROM posts WHERE game_id = ? AND kind = 'review' AND user_id = ? ORDER BY id LIMIT 1");
                $st->execute([$gid, $uid]);
                $mine = $st->fetch() ?: null;
            }
            if (!rate_ok('post', 10, 600) || !rate_ok('post-day', 60, 86400)) {
                fail("You're posting a lot! Please wait a bit before posting again.", 429);
            }
            $member = ['stars' => (int)$account['stars'], 'avatar' => $account['avatar_url'] ?? '', 'avatar_color' => avatar_color((int)$account['id']), 'mine' => true,
                       'badges' => badge_list(array_column(user_badges((int)$account['id']), 'badge'))];
            if ($mine) {
                // one review per member: posting again updates it
                $pdo->prepare('UPDATE posts SET rating = ?, body = ?, name = ?, edited_at = ? WHERE id = ?')
                    ->execute([$rating, $body, $name, time(), (int)$mine['id']]);
                reply([
                    'ok' => true, 'edited' => true,
                    'post' => ['id' => (int)$mine['id'], 'kind' => 'review', 'rating' => $rating, 'name' => $name, 'body' => $body,
                               'created_at' => (int)$mine['created_at'], 'edited_at' => time()] + $member,
                ] + community_summary($gid));
            }
            // a reply always goes into the thread of the first message
            $parentId = $target ? (int)($target['parent_id'] ?: $target['id']) : null;
            $replyUser = $target && $target['user_id'] ? (int)$target['user_id'] : null;
            $replyTo = $target ? (int)$target['id'] : null;
            $pdo->prepare('INSERT INTO posts (game_id, kind, rating, name, body, visitor, ip_hash, created_at, user_id, parent_id, reply_user, reply_to) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$gid, $action, $rating, $name, $body, $visitor, ip_hash(), time(), $uid, $parentId, $replyUser, $replyTo]);
            $id = (int)$pdo->lastInsertId();
            if ($target) notify_reply($target, ['id' => $id, 'body' => $body], $account, $game);
            reply([
                'ok' => true,
                'post' => ['id' => $id, 'kind' => $action, 'rating' => $rating, 'name' => $name, 'body' => $body, 'created_at' => time(),
                           'parent_id' => $parentId, 'reply_to' => $replyTo] + $member,
            ] + community_summary($gid), 201);

        case 'report':
            $reason = (string)($in['reason'] ?? '');
            if (!in_array($reason, REPORT_REASONS, true)) fail('Please choose a reason.');
            $details = $clean('details', 0, 1000, 'details');
            $postId = isset($in['post']) && $in['post'] !== '' ? (int)$in['post'] : null;
            if ($postId !== null) {
                $st = $pdo->prepare('SELECT 1 FROM posts WHERE id = ? AND game_id = ?');
                $st->execute([$postId, $gid]);
                if (!$st->fetchColumn()) fail('That post no longer exists.', 404);
            }
            if (!rate_ok('report', 10, 3600)) fail('Thanks, we already got several reports from you. Please try later.', 429);
            $reporter = current_user();
            $pdo->prepare('INSERT INTO reports (game_id, post_id, reason, details, ip_hash, created_at, user_id) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$gid, $postId, $reason, $details, ip_hash(), time(), $reporter ? (int)$reporter['id'] : null]);
            $reportId = (int)$pdo->lastInsertId();
            // a post reported by 3 different people is hidden until a moderator looks at it
            $autoHidden = false;
            if ($postId !== null) {
                $st = $pdo->prepare('SELECT COUNT(DISTINCT ip_hash) FROM reports WHERE post_id = ? AND resolved = 0');
                $st->execute([$postId]);
                if ((int)$st->fetchColumn() >= 3) {
                    $st = $pdo->prepare('UPDATE posts SET hidden = 1 WHERE id = ? AND hidden = 0');
                    $st->execute([$postId]);
                    $autoHidden = $st->rowCount() > 0;
                }
            }
            discord_report_new($reportId, $autoHidden); // to the moderators' Discord channel, if set up
            reply(['ok' => true], 201);

        default:
            fail('Unknown action');
    }
} catch (Throwable $e) {
    error_log('community api: ' . $e->getMessage());
    fail('Something went wrong on our side. Please try again later.', 500);
}
