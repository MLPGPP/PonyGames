<?php
/**
 * Counts what the admin stats page shows (js/main.js sends these).
 *
 *   POST /api/track.php  { game, kind: "download" | "play" }   -> 204
 *       a click on a game's download or "Play" link; one count per visitor,
 *       game, kind and day, so refreshing or clicking a few mirrors doesn't
 *       inflate the numbers. The visitor is the anonymous cookie used for likes.
 *   POST /api/track.php  { kind: "view", path, ref, src? }      -> 204
 *       a page view. No cookie: the visitor is a hash of IP + browser + day,
 *       so the same person is a new visitor tomorrow and can't be followed.
 *       Only the referring site's host is kept; a visit through a share link
 *       made in the admin (?s=<code>, see admin/links.php) is kept as "#<code>"
 *       and wins over it. Unknown codes are ignored. Bots are skipped (Do Not
 *       Track / Global Privacy Control browsers are counted: nothing personal is kept).
 */

declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'pony-games') {
    http_response_code(405);
    exit;
}
$in = json_decode((string)file_get_contents('php://input'), true);
$kind = is_array($in) ? (string)($in['kind'] ?? '') : '';

/** "/game/332-drifter" -> "/game/332", "/games.html" -> "/games", "/index.html" -> "/"; null for anything odd */
function view_path(string $p): ?string
{
    $p = (string)parse_url($p, PHP_URL_PATH);
    if ($p === '' || $p[0] !== '/' || strlen($p) > 120 || !preg_match('#^[A-Za-z0-9_./-]+$#', $p)) return null;
    if (preg_match('#^/game/(\d+)#', $p, $m)) return '/game/' . $m[1];
    return clean_path($p); // the old addresses with .html/.php count as the page itself
}

try {
    $day = intdiv(time(), 86400);
    if ($kind === 'view') {
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        if ($ua === '' || preg_match('/bot|crawl|spider|slurp|preview|headless|lighthouse|facebookexternalhit|embedly|curl|wget|python|java\//i', $ua)) {
            header('X-PG-Track: skipped (bot)');
            http_response_code(204);
            exit;
        }
        $path = view_path((string)($in['path'] ?? ''));
        if ($path === null) { http_response_code(400); exit; }
        $ref = strtolower((string)parse_url((string)($in['ref'] ?? ''), PHP_URL_HOST));
        $ref = preg_replace('/^www\./', '', $ref);
        $self = strtolower(preg_replace('/^www\./', '', (string)preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? ''))));
        if ($ref === $self || strlen($ref) > 100 || !preg_match('/^[a-z0-9.-]*$/', $ref)) $ref = '';
        $src = (string)($in['src'] ?? '');
        if ($src !== '' && preg_match('/^[a-z0-9]{4,12}$/', $src)) {
            $st = db()->prepare('SELECT 1 FROM share_links WHERE code = ?');
            $st->execute([$src]);
            if ($st->fetchColumn()) $ref = '#' . $src; // one of our share links
        }
        $ip = (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
        $visitor = substr(hash_hmac('sha256', $ip . '|' . $ua . '|' . $day, salt()), 0, 32);
        $device = preg_match('/Mobi|Android|iPhone|iPad|iPod/i', $ua) ? 'mobile' : 'desktop';
        if (!rate_ok('view', 300, 600)) {
            header('X-PG-Track: skipped (too many)');
            http_response_code(204);
            exit;
        }
        // first view of this page by this visitor today, or one more (works on older SQLite too)
        $ins = db()->prepare('INSERT OR IGNORE INTO page_views (day, path, visitor, referrer, device) VALUES (?, ?, ?, ?, ?)');
        $ins->execute([$day, $path, $visitor, $ref, $device]);
        if ($ins->rowCount() === 0) {
            // one more; a source seen now fills in one that was missing
            db()->prepare("UPDATE page_views SET hits = hits + 1, referrer = CASE WHEN referrer = '' THEN ? ELSE referrer END WHERE day = ? AND path = ? AND visitor = ?")
                ->execute([$ref, $day, $path, $visitor]);
        }
        header('X-PG-Track: counted');
        // keep a little over a year; of older days only the totals stay (stats_days, for "All time" in admin/stats.php)
        if (random_int(1, 500) === 1) {
            db()->prepare('INSERT OR IGNORE INTO stats_days (day, visits, views) SELECT day, COUNT(DISTINCT visitor), SUM(hits) FROM page_views WHERE day < ? GROUP BY day')
                ->execute([$day - 400]);
            db()->prepare('DELETE FROM page_views WHERE day < ?')->execute([$day - 400]);
        }
        http_response_code(204);
        exit;
    }

    $game = is_array($in) ? find_game((string)($in['game'] ?? '')) : null;
    if (!$game || !in_array($kind, ['download', 'play'], true)) {
        http_response_code(400);
        exit;
    }
    if (rate_ok('track', 60, 600)) {
        db()->prepare('INSERT OR IGNORE INTO game_clicks (game_id, kind, visitor, day, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([(string)$game['id'], $kind, visitor_id(), $day, time()]);
    }
} catch (Throwable $e) {
    // shows up in the PHP error log, and as a 500 in the browser's network tab
    error_log('track: ' . $e->getMessage());
    header('X-PG-Track: error');
    http_response_code(500);
    exit;
}
http_response_code(204);
