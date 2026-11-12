<?php
/**
 * Numbers for the game cards on the home page and the games list (js/main.js):
 * the average review score, likes and bookmarks of every game that has any.
 *
 *   GET /api/ratings.php -> { "ratings":   { "<game id>": [average, number of reviews], ... },
 *                             "likes":     { "<game id>": n, ... },
 *                             "bookmarks": { "<game id>": n, ... },
 *                             "downloads": { "<game id>": n, ... },   (see api/track.php)
 *                             "plays":     { "<game id>": n, ... } }
 *
 * Hidden reviews don't count; bookmarks are those of members (the others only
 * live in the visitor's browser). Cached for half a minute.
 */

declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=30');
header('X-Content-Type-Options: nosniff');

$ratings = $likes = $bookmarks = $downloads = $plays = [];
try {
    $rows = db()->query("SELECT game_id, AVG(rating) AS avg, COUNT(*) AS n FROM posts
                         WHERE kind = 'review' AND hidden = 0 AND rating BETWEEN 1 AND 5 GROUP BY game_id");
    foreach ($rows as $r) $ratings[(string)$r['game_id']] = [round((float)$r['avg'], 1), (int)$r['n']];
    foreach (db()->query('SELECT game_id, COUNT(*) AS n FROM likes GROUP BY game_id') as $r) $likes[(string)$r['game_id']] = (int)$r['n'];
    foreach (db()->query('SELECT game_id, COUNT(*) AS n FROM user_bookmarks GROUP BY game_id') as $r) $bookmarks[(string)$r['game_id']] = (int)$r['n'];
    foreach (db()->query('SELECT game_id, kind, COUNT(*) AS n FROM game_clicks GROUP BY game_id, kind') as $r) {
        if ($r['kind'] === 'download') $downloads[(string)$r['game_id']] = (int)$r['n'];
        else $plays[(string)$r['game_id']] = (int)$r['n'];
    }
} catch (Throwable $e) {
    error_log('ratings api: ' . $e->getMessage());
}
echo json_encode(['ratings' => (object)$ratings, 'likes' => (object)$likes, 'bookmarks' => (object)$bookmarks,
    'downloads' => (object)$downloads, 'plays' => (object)$plays], JSON_UNESCAPED_SLASHES);
