<?php
/**
 * Account actions from JavaScript (logged-in users only).
 *   POST { action: "bookmark", game, on: true|false }  -> { bookmarks: [...] }
 *   POST { action: "merge-bookmarks", games: [...] }    -> { bookmarks: [...] }
 *        (bookmarks made before logging in are added to the account)
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
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') reply(['error' => 'Method not allowed'], 405);
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'pony-games') reply(['error' => 'Bad request'], 400);
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== parse_url('//' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)) {
        reply(['error' => 'Bad origin'], 403);
    }
    $u = current_user();
    if (!$u) reply(['error' => 'Please log in first.'], 401);
    if (!user_verified($u)) reply(['error' => NEED_CONFIRM_MESSAGE], 403);
    $uid = (int)$u['id'];
    $in = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($in)) reply(['error' => 'Bad request'], 400);
    if (!rate_ok('account-api', 120, 600)) reply(['error' => 'Slow down a little, pony!'], 429);

    $pdo = db();
    $games = all_games();
    $add = $pdo->prepare('INSERT OR IGNORE INTO user_bookmarks (user_id, game_id, created_at) VALUES (?, ?, ?)');

    switch ($in['action'] ?? '') {
        case 'bookmark':
            $gid = (string)($in['game'] ?? '');
            if (!isset($games[$gid])) reply(['error' => 'Unknown game'], 404);
            if (!empty($in['on'])) $add->execute([$uid, $gid, time()]);
            else $pdo->prepare('DELETE FROM user_bookmarks WHERE user_id = ? AND game_id = ?')->execute([$uid, $gid]);
            break;
        case 'merge-bookmarks':
            $list = array_slice(array_map('strval', (array)($in['games'] ?? [])), 0, 2000);
            $pdo->beginTransaction();
            $t = time() - count($list); // older than anything bookmarked from now on
            foreach (array_reverse($list) as $gid) {
                if (isset($games[$gid])) $add->execute([$uid, $gid, $t++]);
            }
            $pdo->commit();
            break;
        default:
            reply(['error' => 'Unknown action'], 400);
    }
    reply(['bookmarks' => user_bookmarks($uid)]);
} catch (Throwable $e) {
    error_log('account api: ' . $e->getMessage());
    reply(['error' => 'Something went wrong on our side. Please try again later.'], 500);
}
