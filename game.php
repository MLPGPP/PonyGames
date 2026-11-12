<?php
/**
 * Individual game page: /game/<id>-<slug>  (also works as game.php?id=<id>)
 * Rendered on the server from data/games.json so search engines see the
 * full page. Likes, reviews, comments and reports come from the community
 * database (see inc/bootstrap.php and api/community.php).
 */

declare(strict_types=1);
require __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/submissions.php'; // video_entry()

/** Sites whose game player may be shown inside our game page (they allow being framed). */
const EMBED_PLAY_HOSTS = ['flash.mare.by'];
function embeddable_play_url(string $url): bool
{
    return parse_url($url, PHP_URL_SCHEME) === 'https' && in_array(strtolower((string)parse_url($url, PHP_URL_HOST)), EMBED_PLAY_HOSTS, true);
}
require __DIR__ . '/inc/layout.php';

$me = current_user();
$canAct = $me && user_verified($me); // logged in with a confirmed email (when that's required)

$id = $_GET['id'] ?? '';
if ($id === '' && isset($_GET['slug']) && preg_match('/^(\d+)/', (string)$_GET['slug'], $m)) $id = $m[1];
$game = $id !== '' ? find_game($id) : null;

if (!$game) {
    http_response_code(404);
    $title = 'Game not found';
} else {
    // one URL per game: send old or misspelled slugs to the real one
    $path = game_path($game);
    $requested = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    if ($requested !== $path) {
        // keep a share link's code (?s=…, admin/links.php) so the stats still see where the visit came from
        $keep = array_intersect_key($_GET, array_flip(['s']));
        $keep = array_filter($keep, 'is_string');
        header('Location: ' . $path . ($keep ? '?' . http_build_query($keep) : ''), true, 301);
        exit;
    }
    $title = (string)$game['title'];
}

$authors = $game ? game_authors($game) : [];
$nsfw = $game ? is_nsfw($game) : false;
$status = $game['status'] ?? '';
$statusLabel = status_label($status);
$images = $game ? array_values(array_filter(array_merge([$game['thumbnail'] ?? ''], $game['screenshots'] ?? []))) : [];
// trailers / gameplay, shown after the pictures: YouTube (only loads from YouTube
// when played) or a video file (plays in the page)
$videos = [];
foreach ((array)($game['videos'] ?? []) as $vu) if (is_string($vu) && ($ve = video_entry($vu)) !== null) $videos[] = $ve;
$videos = array_values(array_unique($videos, SORT_REGULAR));
$slideCount = count($images) + count($videos);
$description = $game ? trim((string)($game['fullDescription'] ?? $game['shortDescription'] ?? '')) : '';
$short = $game ? trim((string)($game['shortDescription'] ?? '')) : '';
$metaDesc = $short !== '' ? $short : mb_substr($description, 0, 155);
$canonical = $game ? site_url() . game_path($game) : site_url() . '/games';
$ogImage = ($game && !$nsfw && !empty($game['thumbnail'])) ? site_url() . $game['thumbnail'] : site_url() . '/og-image.png';
$summary = $game ? community_summary((string)$game['id']) : ['likes' => 0, 'reviews' => 0, 'rating' => null];

// category the breadcrumb points back to
$categoryLink = '/games';
$categoryName = 'Games';
if ($game) {
    $isMod = false;
    foreach ($game['tags'] ?? [] as $t) if (is_array($t) && strcasecmp((string)$t['label'], 'Mod') === 0) $isMod = true;
    if ($isMod) [$categoryLink, $categoryName] = ['/games?tag=Mod', 'Mods'];
    elseif ($status === 'Upcoming') [$categoryLink, $categoryName] = ['/upcoming', 'Upcoming games'];
    elseif ($status !== '') [$categoryLink, $categoryName] = ['/games?status=' . rawurlencode($status), $statusLabel . ' games'];
}

// facts shown next to the gallery
$facts = [];
if ($game) {
    if ($rt = release_text($game['releaseDate'] ?? '')) $facts['Released'] = h($rt);
    if (!empty($game['playtime']) && !in_array($game['playtime'], ['Unknown', 'N/A'], true)) $facts['Playtime'] = h($game['playtime']);
    if (!empty($game['engine']) && $game['engine'] !== 'Unknown') $facts['Engine'] = h($game['engine']);
    if (!empty($game['platforms'])) $facts['Platforms'] = h(implode(', ', (array)$game['platforms']));
    if (!empty($game['characters'])) $facts['Characters'] = h(implode(', ', (array)$game['characters']));
    if (!empty($game['dateAdded']) && ($ts = strtotime($game['dateAdded']))) $facts['Added'] = h(date('F j, Y', $ts));
}

// tags in the same order as the cards: warnings, genres, the rest
$tags = $game['tags'] ?? [];
usort($tags, function ($a, $b) {
    $order = ['warning' => 0, 'black' => 1, 'genre' => 2, 'other' => 3];
    return ($order[tag_type((array)$a)] ?? 4) <=> ($order[tag_type((array)$b)] ?? 4);
});

// "More like this": games sharing the most genres, no NSFW unless this one is
$related = [];
if ($game) {
    $genres = [];
    foreach ($game['tags'] ?? [] as $t) if (is_array($t) && tag_type($t) === 'genre') $genres[] = $t['label'];
    $scored = [];
    foreach (all_games() as $g) {
        if ((string)$g['id'] === (string)$game['id'] || (!$nsfw && is_nsfw($g))) continue;
        $score = 0;
        foreach ($g['tags'] ?? [] as $t) if (is_array($t) && tag_type($t) === 'genre' && in_array($t['label'], $genres, true)) $score += 2;
        if (array_intersect(game_authors($g), $authors)) $score += 3;
        if ($score > 0) $scored[] = [$score, $g['dateAdded'] ?? '', $g];
    }
    usort($scored, fn($a, $b) => [$b[0], $b[1]] <=> [$a[0], $a[1]]);
    $related = array_map(fn($x) => $x[2], array_slice($scored, 0, 5));
}

// reviews and discussion messages ("comment" in the database), newest first
$reviews = $comments = $replies = [];
$commentCount = 0;
$myReview = null; // the logged-in member's own review: they can edit it, not post a second one
$ratingDist = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
if ($game) {
    try {
        $cols = 'p.id, p.kind, p.rating, COALESCE(u.display_name, p.name) AS name, p.body, p.created_at, p.edited_at, p.parent_id, p.reply_user, p.reply_to,
                 u.id AS user_id, u.stars, u.avatar_url,
                 (SELECT GROUP_CONCAT(b.badge) FROM user_badges b WHERE b.user_id = u.id) AS badges';
        // reviews and the first message of each discussion thread
        $st = db()->prepare("SELECT $cols FROM posts p LEFT JOIN users u ON u.id = p.user_id
                              WHERE p.game_id = ? AND p.hidden = 0 AND (p.kind = 'review' OR p.parent_id IS NULL) ORDER BY p.created_at DESC LIMIT 60");
        $st->execute([(string)$game['id']]);
        foreach ($st->fetchAll() as $p) {
            if ($p['kind'] === 'review') $reviews[] = $p; else $comments[] = $p;
        }
        // replies, oldest first under their thread (thread_html() nests them)
        $st = db()->prepare("SELECT $cols FROM posts p LEFT JOIN users u ON u.id = p.user_id
                              WHERE p.game_id = ? AND p.hidden = 0 AND p.kind = 'comment' AND p.parent_id IS NOT NULL ORDER BY p.created_at ASC LIMIT 400");
        $st->execute([(string)$game['id']]);
        foreach ($st->fetchAll() as $r) $replies[(int)$r['parent_id']][] = $r;
        // a thread whose first message was deleted or hidden: its replies still show, on their own
        $shown = array_map(fn($p) => (int)$p['id'], $comments);
        $lost = array_diff(array_keys($replies), $shown);
        if ($lost) {
            $in = implode(',', array_map('intval', $lost));
            $alive = db()->query("SELECT id FROM posts WHERE id IN ($in) AND hidden = 0")->fetchAll(PDO::FETCH_COLUMN); // older than the 60 shown
            foreach ($lost as $pid) {
                if (in_array($pid, array_map('intval', $alive), true)) continue;
                $comments[] = ['id' => $pid, 'gone' => true, 'created_at' => (int)$replies[$pid][0]['created_at']];
            }
            usort($comments, fn($a, $b) => (int)$b['created_at'] <=> (int)$a['created_at']);
        }
        $st = db()->prepare("SELECT COUNT(*) FROM posts WHERE game_id = ? AND kind = 'comment' AND hidden = 0");
        $st->execute([(string)$game['id']]);
        $commentCount = (int)$st->fetchColumn();
        if ($me) {
            $st = db()->prepare("SELECT id, rating, body FROM posts WHERE game_id = ? AND kind = 'review' AND user_id = ? ORDER BY id LIMIT 1");
            $st->execute([(string)$game['id'], (int)$me['id']]);
            $myReview = $st->fetch() ?: null;
        }
        $st = db()->prepare("SELECT rating, COUNT(*) FROM posts WHERE game_id = ? AND kind = 'review' AND hidden = 0 GROUP BY rating");
        $st->execute([(string)$game['id']]);
        foreach ($st->fetchAll(PDO::FETCH_NUM) as [$r, $n]) $ratingDist[(int)$r] = (int)$n;
    } catch (Throwable $e) {
        // database not available: the page still shows the game
    }
}

function stars(?float $rating): string
{
    $out = '';
    for ($i = 1; $i <= 5; $i++) {
        $fill = $rating === null ? 0 : max(0, min(1, $rating - ($i - 1)));
        $out .= '<span class="stars__star" style="--fill:' . round($fill * 100) . '%"></span>';
    }
    return '<span class="stars" aria-hidden="true">' . $out . '</span>';
}

function post_html(array $p, string $tag = 'li'): string
{
    global $me, $canAct;
    $when = date('M j, Y', (int)$p['created_at']);
    $mine = $me && !empty($p['user_id']) && (int)$p['user_id'] === (int)$me['id'];
    $edited = !empty($p['edited_at'])
        ? ' <span class="post__edited" title="Edited ' . h(date('M j, Y', (int)$p['edited_at'])) . '">· edited</span>' : '';
    $tool = $mine
        ? '<span class="post__tools"><button type="button" class="post__edit" data-post-edit>Edit</button>'
          . '<button type="button" class="post__edit post__edit--danger" data-post-delete>Delete</button></span>'
        : '<button type="button" class="post__report" data-report-post="' . (int)$p['id'] . '" aria-label="Report this post" title="Report">'
          . '<svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 22V4m0 0h13l-2 4 2 4H4"/></svg></button>';
    $rating = $p['kind'] === 'review' && $p['rating'] ? stars((float)$p['rating']) . '<span class="visually-hidden">' . (int)$p['rating'] . ' out of 5 stars</span>' : '';
    $member = !empty($p['user_id']);
    // a member's picture, or the mascot on their own colour; guests keep the letter
    $avatar = !$member ? '' : (!empty($p['avatar_url'])
        ? '<img class="post__avatar" src="' . h($p['avatar_url']) . '" alt="" width="30" height="30" loading="lazy">'
        : pony_avatar_html((int)$p['user_id'], 'post__avatar'));
    $starsChip = $member
        ? '<span class="post__member" title="Pony Games member with ' . (int)$p['stars'] . ' gold star' . ((int)$p['stars'] === 1 ? '' : 's') . '">★ ' . (int)$p['stars'] . '</span>' : '';
    $badgeChips = $member ? badge_chips_html($p['badges'] ?? '') : '';
    $isReply = !empty($p['parent_id']);
    $foot = $p['kind'] === 'comment' && $canAct
        ? '<div class="post__foot"><button type="button" class="post__reply" data-post-reply>'
          . '<svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11"/></svg>Reply</button></div>' : '';
    return '<' . $tag . ' id="post-' . (int)$p['id'] . '" class="post' . ($isReply ? ' post--reply' : '') . ($member ? ' post--member' : '') . ($avatar ? ' post--avatar' : '') . ($mine ? ' post--mine' : '') . '" data-post="' . (int)$p['id'] . '" data-kind="' . h($p['kind']) . '"'
        . ($isReply ? ' data-parent="' . (int)$p['parent_id'] . '"' : '')
        . ($mine ? ' data-body="' . h($p['body']) . '"' . ($p['rating'] ? ' data-rating="' . (int)$p['rating'] . '"' : '') : '') . ' data-initial="' . h(mb_strtoupper(mb_substr(trim((string)$p['name']), 0, 1)) ?: '?') . '">'
        . $avatar
        . '<div class="post__head"><strong class="post__name">' . h($p['name']) . '</strong>' . ($mine ? '<span class="post__you">you</span>' : '') . $starsChip . $badgeChips . $rating
        . '<time class="post__date" datetime="' . date('c', (int)$p['created_at']) . '">' . $when . '</time>' . $edited
        . $tool . '</div>'
        . '<p class="post__body">' . nl2br(h($p['body'])) . '</p>' . $foot . '</' . $tag . '>';
}

/**
 * A discussion thread, Reddit-style: the first message, every reply nested
 * under the message it answers. A reply to a message that was removed hangs
 * under a "removed" placeholder.
 */
function thread_html(array $p, array $replies): string
{
    $root = (int)$p['id'];
    $ids = array_map(fn($r) => (int)$r['id'], $replies);
    $kids = [];
    foreach ($replies as $r) {
        $to = (int)($r['reply_to'] ?? 0) ?: $root;
        if ($to !== $root && !in_array($to, $ids, true)) $to = -$to; // its message is gone: placeholder
        $kids[$to][] = $r;
    }
    // placeholders for removed messages, under the first message
    foreach (array_keys($kids) as $k) if ($k < 0) $kids[$root][] = ['gone' => true, 'id' => $k, 'created_at' => $kids[$k][0]['created_at']];
    if (!empty($kids[$root])) usort($kids[$root], fn($a, $b) => (int)$a['created_at'] <=> (int)$b['created_at']);
    $first = empty($p['gone']) ? post_html($p, 'div') : '<p class="post-gone">This message was removed.</p>';
    return '<li class="thread" data-thread="' . $root . '">' . $first . reply_tree($root, $kids, 1) . '</li>';
}

function reply_tree(int $id, array $kids, int $depth): string
{
    $out = '';
    foreach ($kids[$id] ?? [] as $r) {
        $out .= '<li class="reply-node" data-node="' . (int)$r['id'] . '">'
            . (empty($r['gone']) ? post_html($r, 'div') : '<p class="post-gone">This message was removed.</p>')
            . reply_tree((int)$r['id'], $kids, $depth + 1) . '</li>';
    }
    return '<ul class="post-replies" data-replies data-depth="' . $depth . '">' . $out . '</ul>';
}

$brandIcons = [
    'Patreon' => 'M0 .48v23.04h4.22V.48zm15.385 0c-4.764 0-8.641 3.88-8.641 8.65 0 4.755 3.877 8.623 8.641 8.623 4.75 0 8.615-3.868 8.615-8.623C24 4.36 20.136.48 15.385.48z',
    'Twitter' => 'M23.953 4.57a10 10 0 01-2.825.775 4.958 4.958 0 002.163-2.723c-.951.555-2.005.959-3.127 1.184a4.92 4.92 0 00-8.384 4.482C7.69 8.095 4.067 6.13 1.64 3.162a4.822 4.822 0 00-.666 2.475c0 1.71.87 3.213 2.188 4.096a4.904 4.904 0 01-2.228-.616v.06a4.923 4.923 0 003.946 4.827 4.996 4.996 0 01-2.212.085 4.936 4.936 0 004.604 3.417 9.867 9.867 0 01-6.102 2.105c-.39 0-.779-.023-1.17-.067a13.995 13.995 0 007.557 2.209c9.053 0 13.998-7.496 13.998-13.985 0-.21 0-.42-.015-.63A9.935 9.935 0 0024 4.59z',
    'YouTube' => 'M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z',
];
function link_icon(string $site, array $brandIcons): string
{
    if (isset($brandIcons[$site])) {
        return '<svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="' . $brandIcons[$site] . '"/></svg>';
    }
    return '<svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 14L21 3M15 3h6v6M21 14v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5"/></svg>';
}

// structured data for search engines
$jsonLd = null;
if ($game) {
    $jsonLd = [
        '@context' => 'https://schema.org',
        '@type' => 'VideoGame',
        'name' => $game['title'],
        'url' => $canonical,
        'description' => $metaDesc,
        'genre' => array_values(array_map(fn($t) => $t['label'], array_filter($game['tags'] ?? [], fn($t) => is_array($t) && tag_type($t) === 'genre'))),
        'author' => array_map(fn($a) => ['@type' => 'Person', 'name' => $a], $authors),
    ];
    if (!$nsfw && !empty($game['thumbnail'])) $jsonLd['image'] = site_url() . $game['thumbnail'];
    if (!empty($game['releaseDate'])) $jsonLd['datePublished'] = $game['releaseDate'];
    if (!empty($game['platforms'])) $jsonLd['gamePlatform'] = array_values((array)$game['platforms']);
    if ($summary['reviews'] > 0 && $summary['rating'] !== null) {
        $jsonLd['aggregateRating'] = ['@type' => 'AggregateRating', 'ratingValue' => $summary['rating'], 'ratingCount' => $summary['reviews'], 'bestRating' => 5, 'worstRating' => 1];
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <script>
    try {
      var pgTheme = localStorage.getItem("pg-theme");
      if (pgTheme) document.documentElement.dataset.theme = pgTheme;
    } catch (e) {}
  </script>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= h($title) ?> | Pony Games</title>
<?php if ($game): ?>
  <meta name="description" content="<?= h($metaDesc) ?>" />
  <link rel="canonical" href="<?= h($canonical) ?>" />
  <meta property="og:type" content="website" />
  <meta property="og:site_name" content="Pony Games" />
  <meta property="og:title" content="<?= h($title) ?>" />
  <meta property="og:description" content="<?= h($metaDesc) ?>" />
  <meta property="og:url" content="<?= h($canonical) ?>" />
  <meta property="og:image" content="<?= h($ogImage) ?>" />
  <meta name="twitter:card" content="summary_large_image" />
  <meta name="twitter:title" content="<?= h($title) ?>" />
  <meta name="twitter:description" content="<?= h($metaDesc) ?>" />
  <meta name="twitter:image" content="<?= h($ogImage) ?>" />
  <script type="application/ld+json"><?= json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
<?php else: ?>
  <meta name="robots" content="noindex" />
<?php endif; ?>
  <meta name="referrer" content="same-origin">
  <meta name="theme-color" content="#16213e" />
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Silkscreen&display=swap">
  <link rel="stylesheet" href="/css/style.css?v=<?= ASSET_V ?>" />
</head>
<body>
<?php render_header(); ?>

  <main class="container game-page">
<?php if (!$game): ?>
    <div class="games-empty games-empty--friendly game-404">
      <img class="games-empty__mascot" src="/assets/mascot-head.png" alt="" width="120" height="157" />
      <h1 class="games-empty__title">No pony found here!</h1>
      <p class="games-empty__text">This game doesn't exist (anymore). Maybe it trotted off to another page.</p>
      <div class="games-empty__actions"><a class="btn btn--accent" href="/games">Browse all games</a></div>
    </div>
<?php else: ?>
    <nav class="breadcrumb" aria-label="Breadcrumb">
      <a href="/games">Games</a><span aria-hidden="true">›</span>
      <a href="<?= h($categoryLink) ?>"><?= h($categoryName) ?></a><span aria-hidden="true">›</span>
      <span aria-current="page"><?= h($title) ?></span>
    </nav>

    <article class="game" data-game-id="<?= h((string)$game['id']) ?>" data-game-title="<?= h($title) ?>">
      <div class="game__layout">
        <!-- gallery -->
        <section class="gallery<?= $nsfw ? ' gallery--nsfw' : '' ?>" aria-label="Screenshots">
          <!-- the Flash player, opened in place of the screenshots by a "Play" button (js/game.js) -->
          <div class="game-player" data-player hidden>
            <div class="game-player__frame" data-player-frame></div>
            <div class="game-player__bar">
              <span class="game-player__note">Flash player by <a href="https://flash.mare.by" target="_blank" rel="noopener noreferrer">flash.mare.by</a></span>
              <a class="game-player__btn" href="#" target="_blank" rel="noopener noreferrer" data-player-newtab>Open in new tab ↗</a>
              <button type="button" class="game-player__btn game-player__btn--close" data-player-close>✕ Close game</button>
            </div>
          </div>
          <div class="gallery__main">
<?php foreach ($images as $i => $src): ?>
            <img class="gallery__slide" src="<?= h($src) ?>" alt="<?= h($title) ?> screenshot <?= $i + 1 ?>"<?= $i ? ' hidden loading="lazy"' : '' ?> />
<?php endforeach; ?>
<?php foreach ($videos as $vi => $ve): $n = count($images) + $vi; $vlabel = 'Play video ' . ($vi + 1) . (count($videos) > 1 ? ' of ' . count($videos) : '') . ': ' . $title; ?>
<?php if (isset($ve['file'])): ?>
            <div class="gallery__slide gallery__video gallery__video--file"<?= $n ? ' hidden' : '' ?>>
              <video class="video-file" src="<?= h($ve['file']) ?>" controls playsinline preload="<?= $n ? 'none' : 'metadata' ?>" aria-label="<?= h('Video ' . ($vi + 1) . ': ' . $title) ?>"></video>
            </div>
<?php else: $vid = $ve['youtube']; ?>
            <div class="gallery__slide gallery__video" data-yt="<?= h($vid) ?>" data-yt-label="<?= h($vlabel) ?>"<?= $n ? ' hidden' : '' ?>>
              <button type="button" class="video-cover" data-yt-start="<?= h($vid) ?>" aria-label="<?= h($vlabel) ?>"><img src="https://i.ytimg.com/vi/<?= h($vid) ?>/hqdefault.jpg" alt="" loading="lazy" referrerpolicy="no-referrer" /><span class="video-cover__play"><svg aria-hidden="true" viewBox="0 0 68 48" width="68" height="48"><path d="M66.5 7.7a8.5 8.5 0 0 0-6-6C55.3.3 34 .3 34 .3s-21.3 0-26.5 1.4a8.5 8.5 0 0 0-6 6C.1 13 .1 24 .1 24s0 11 1.4 16.3a8.5 8.5 0 0 0 6 6C12.7 47.7 34 47.7 34 47.7s21.3 0 26.5-1.4a8.5 8.5 0 0 0 6-6C67.9 35 67.9 24 67.9 24s0-11-1.4-16.3z" fill="#e00"/><path d="M45 24 27 14v20z" fill="#fff"/></svg></span></button>
            </div>
<?php endif; ?>
<?php endforeach; ?>
<?php if ($nsfw): ?>
            <button type="button" class="gallery__reveal">🔞 Contains mature content. Click to show images</button>
<?php endif; ?>
<?php if ($slideCount > 1): ?>
            <button type="button" class="gallery__nav gallery__nav--prev" aria-label="Previous screenshot">‹</button>
            <button type="button" class="gallery__nav gallery__nav--next" aria-label="Next screenshot">›</button>
<?php endif; ?>
          </div>
<?php if ($slideCount > 1): ?>
          <div class="gallery__thumbs">
<?php foreach ($images as $i => $src): ?>
            <button type="button" class="gallery__thumb<?= $i ? '' : ' is-active' ?>" data-index="<?= $i ?>" aria-label="Show screenshot <?= $i + 1 ?>"><img src="<?= h($src) ?>" alt="" loading="lazy" /></button>
<?php endforeach; ?>
<?php foreach ($videos as $vi => $ve): $n = count($images) + $vi; ?>
            <button type="button" class="gallery__thumb gallery__thumb--video<?= isset($ve['file']) ? ' gallery__thumb--file' : '' ?><?= $n ? '' : ' is-active' ?>" data-index="<?= $n ?>" aria-label="Show video <?= $vi + 1 ?>"><?php if (isset($ve['youtube'])): ?><img src="https://i.ytimg.com/vi/<?= h($ve['youtube']) ?>/mqdefault.jpg" alt="" loading="lazy" referrerpolicy="no-referrer" /><?php endif; ?></button>
<?php endforeach; ?>
          </div>
<?php endif; ?>
        </section>

        <!-- info -->
        <aside class="game__info">
          <div class="game__badges">
            <span class="status-badge status-badge--<?= h(slugify($statusLabel)) ?>"><?= h($statusLabel) ?></span>
<?php if (isset(pending_game_edits()[(string)$game['id']])): ?>
            <span class="pending-badge" title="Someone suggested a change to this game. A moderator will check it soon."><span aria-hidden="true">⏳</span> Change under review</span>
<?php endif; ?>
            <span class="game__tools">
              <a class="icon-btn" href="/submit?edit=<?= h(urlencode((string)$game['id'])) ?>" title="Suggest a correction" aria-label="Suggest a correction">
                <svg aria-hidden="true" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>
              </a>
              <button type="button" class="icon-btn icon-btn--report" data-report-game title="Report a problem with this game" aria-label="Report a problem with this game">
                <svg aria-hidden="true" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 22V4m0 0h13l-2 4 2 4H4"/></svg>
              </button>
            </span>
          </div>
          <h1 class="game__title"><?= h($title) ?></h1>
<?php if ($authors): ?>
          <p class="game__authors">by <?= implode(', ', array_map(fn($a) => '<a href="/games?search=' . rawurlencode($a) . '">' . h($a) . '</a>', $authors)) ?></p>
<?php endif; ?>
<?php if ($summary['reviews'] > 0): ?>
          <a class="game__rating" href="#reviews"><?= stars((float)$summary['rating']) ?> <strong><?= h(number_format((float)$summary['rating'], 1)) ?></strong> <span>(<?= $summary['reviews'] ?> review<?= $summary['reviews'] === 1 ? '' : 's' ?>)</span></a>
<?php endif; ?>

          <div class="game__actions">
            <button type="button" class="action-btn action-btn--like" aria-pressed="false" data-like<?= $canAct ? '' : ' data-login-required' ?>>
              <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8l1 1.1L12 21l7.8-7.6 1-1.1a5.5 5.5 0 0 0 0-7.7z"/></svg>
              <span class="action-btn__label">Like</span> <span class="action-btn__count" data-like-count><?= $summary['likes'] ?></span>
            </button>
            <button type="button" class="action-btn action-btn--bookmark" aria-pressed="false" data-bookmark<?= $canAct ? '' : ' data-login-required' ?>>
              <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg>
              <span class="action-btn__label">Bookmark</span> <span class="action-btn__count" data-bookmark-count title="Members who bookmarked this game"><?= (int)($summary['bookmarks'] ?? 0) ?></span>
            </button>
            <button type="button" class="action-btn action-btn--share" data-share data-share-url="<?= h(game_path($game)) ?>" data-share-title="<?= h($game['title']) ?>">
              <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4"/></svg>
              <span class="action-btn__label">Share</span>
            </button>
          </div>
<?php if (!$me): ?>
          <p class="game__login-hint" data-login-hint hidden>
            <a href="/login?next=<?= h(urlencode(game_path($game))) ?>">Log in</a> or <a href="/login?tab=signup&amp;next=<?= h(urlencode(game_path($game))) ?>">create an account</a> to like and bookmark games.
          </p>
<?php elseif (!$canAct): ?>
          <p class="game__login-hint" data-login-hint hidden>
            Confirm your email address first: click the link in the email we sent you. <a href="/account?tab=settings">Send it again</a>
          </p>
<?php endif; ?>

<?php if ($facts): ?>
          <dl class="game__facts">
<?php foreach ($facts as $k => $v): ?>
            <div><dt><?= h($k) ?></dt><dd><?= $v ?></dd></div>
<?php endforeach; ?>
          </dl>
<?php endif; ?>

<?php if ($tags): ?>
          <ul class="game__tags" aria-label="Tags">
<?php foreach ($tags as $t): if (!is_array($t)) continue; $type = tag_type($t); ?>
            <li><a class="tag tag--<?= h($type) ?>" href="/games?<?= $type === 'genre' ? 'genre' : 'tag' ?>=<?= rawurlencode((string)$t['label']) ?>"><?= h($t['label']) ?></a></li>
<?php endforeach; ?>
          </ul>
<?php endif; ?>

<?php $dl = (int)($summary['downloads'] ?? 0); $pl = (int)($summary['plays'] ?? 0); ?>
          <p class="game__counters"<?= $dl + $pl ? '' : ' hidden' ?>>
            <span title="Times a download link was clicked (once per visitor per day)"<?= $dl ? '' : ' hidden' ?>><svg aria-hidden="true" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12m0 0l-5-5m5 5l5-5M4 21h16"/></svg> <b data-click-count="download"><?= number_format($dl) ?></b> download<?= $dl === 1 ? '' : 's' ?></span>
            <span title="Times the game was opened to play in the browser"<?= $pl ? '' : ' hidden' ?>><svg aria-hidden="true" width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><path d="M7 4.5v15l13-7.5z"/></svg> <b data-click-count="play"><?= number_format($pl) ?></b> play<?= $pl === 1 ? '' : 's' ?></span>
          </p>
          <div class="game__links">
<?php if ($status === 'Upcoming'): ?>
<?php
    $follow = [];
    if (!empty($game['url'])) {
        $site = link_site($game['url']);
        $follow[] = [$game['url'], $site === 'Steam' ? 'Wishlist on Steam' : (isset($brandIcons[$site]) ? "Follow on $site" : "Dev page · $site"), $site, true];
    }
    foreach ($game['socials'] ?? [] as $sc) {
        if (empty($sc['url']) || $sc['url'] === ($game['url'] ?? '')) continue;
        $site = link_site($sc['url']);
        $follow[] = [$sc['url'], $sc['label'] ?? $site, $site, false];
    }
?>
<?php if ($follow): ?>
            <div class="follow-links">
              <p class="follow-links__title">Not out yet. Follow the development:</p>
              <div class="follow-links__list">
<?php foreach ($follow as [$url, $text, $site, $main]): ?>
                <a class="follow-link<?= $main ? ' follow-link--main' : '' ?>" href="<?= h($url) ?>" target="_blank" rel="noopener noreferrer"><?= link_icon($site, $brandIcons) ?><span><?= h($text) ?></span></a>
<?php endforeach; ?>
              </div>
            </div>
<?php endif; ?>
<?php else: ?>
<?php
    $entries = [];
    if (!empty($game['url'])) $entries[] = ['label' => 'Source', 'url' => $game['url'], 'type' => 'source'];
    foreach ($game['downloads'] ?? [] as $d) if (($d['url'] ?? '') !== ($game['url'] ?? '')) $entries[] = $d;
    $order = ['source' => 0, 'web' => 1, 'download' => 2];
    foreach ($entries as &$e) $e['_type'] = classify_download($e);
    unset($e);
    usort($entries, fn($a, $b) => ($order[$a['_type']] ?? 3) <=> ($order[$b['_type']] ?? 3));
    $btnText = ['source' => 'Author Site', 'web' => 'Play', 'download' => 'Download'];
?>
<?php foreach ($entries as $d): ?>
            <div class="download-row">
              <div class="download-row__btns">
<?php
    // "Play" buttons (browser / Flash games) count as plays, the rest as downloads
    $isPlay = $d['_type'] === 'web' || preg_match('/^\s*play\b/i', (string)($d['buttonText'] ?? ''));
    $track = $d['_type'] === 'source' ? '' : ' data-track="' . ($isPlay ? 'play' : 'download') . '" data-track-game="' . h((string)$game['id']) . '"';
    // Flash games on flash.mare.by open in a player on this page
    $embed = fn(string $url) => $isPlay && embeddable_play_url($url) ? ' data-embed' : ''; ?>
                <a class="download-row__btn" href="<?= h($d['url']) ?>" target="_blank" rel="noopener noreferrer"<?= $track ?><?= $embed((string)$d['url']) ?>><?= h($d['buttonText'] ?? $btnText[$d['_type']]) ?></a>
<?php foreach ($d['mirrors'] ?? [] as $m): ?>
                <a class="download-row__btn download-row__btn--mirror" href="<?= h($m['url']) ?>" target="_blank" rel="noopener noreferrer"<?= $track ?><?= $embed((string)($m['url'] ?? '')) ?>><?= h($m['label'] ?? 'Mirror') ?></a>
<?php endforeach; ?>
              </div>
              <span class="download-row__info">
                <span class="download-row__label"><?= h($d['label'] ?? '') ?></span>
<?php if (!empty($d['version'])): ?>
                <span class="download-row__version"><?= h($d['version']) ?></span>
<?php endif; ?>
              </span>
            </div>
<?php endforeach; ?>
<?php endif; ?>
          </div>
        </aside>

        <div class="game__main">
<?php if ($description !== ''): ?>
      <section class="game__section" aria-labelledby="about-title">
        <h2 id="about-title" class="section-title">About this game</h2>
        <div class="game__description"><p><?= nl2br(h($description)) ?></p></div>
      </section>
<?php endif; ?>

      <!-- reviews -->
      <section id="reviews" class="game__section" aria-labelledby="reviews-title">
        <h2 id="reviews-title" class="section-title">Reviews <span class="game__count"><?= $summary['reviews'] ?: '' ?></span></h2>
<?php if ($summary['reviews'] > 0): $maxDist = max(1, max($ratingDist)); ?>
        <div class="review-summary">
          <div class="review-summary__score">
            <strong><?= h(number_format((float)$summary['rating'], 1)) ?></strong>
            <?= stars((float)$summary['rating']) ?>
            <span><?= $summary['reviews'] ?> review<?= $summary['reviews'] === 1 ? '' : 's' ?></span>
          </div>
          <ul class="review-summary__bars" aria-label="Ratings breakdown">
<?php foreach ($ratingDist as $r => $n): ?>
            <li><span class="review-summary__label"><?= $r ?> ★</span><span class="review-summary__bar"><span style="width:<?= round($n / $maxDist * 100) ?>%"></span></span><span class="review-summary__n"><?= $n ?></span></li>
<?php endforeach; ?>
          </ul>
        </div>
<?php endif; ?>
        <p class="post-empty" data-empty="review"<?= $reviews ? ' hidden' : '' ?>>No reviews yet. Be the first pony to share what you think!</p>
<?php if (!$canAct): ?>
        <p class="post-login"><svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>
<?php if ($me): ?>
          <span>Confirm your email address to write a review: click the link in the email we sent you. <a href="/account?tab=settings">Send it again</a></span></p>
<?php else: ?>
          <span><a href="/login?next=<?= h(urlencode(game_path($game) . '#reviews')) ?>">Log in</a> or <a href="/login?tab=signup&amp;next=<?= h(urlencode(game_path($game) . '#reviews')) ?>">create an account</a> to write a review.</span></p>
<?php endif; ?>
<?php else: ?>
        <details class="post-compose" id="review-compose"<?= (!$reviews && !$myReview) ? ' open' : '' ?>>
        <summary class="post-compose__toggle"><svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg> <span data-compose-label><?= $myReview ? 'Edit your review' : 'Write a review' ?></span></summary>
        <form class="post-form" data-kind="review"<?= $myReview ? ' data-editing' : '' ?>>
          <fieldset class="rating-input">
            <legend>Your rating</legend>
<?php for ($i = 5; $i >= 1; $i--): ?>
            <input type="radio" id="rate-<?= $i ?>" name="rating" value="<?= $i ?>" required<?= $myReview && (int)$myReview['rating'] === $i ? ' checked' : '' ?> /><label for="rate-<?= $i ?>" title="<?= $i ?> star<?= $i > 1 ? 's' : '' ?>"><span class="visually-hidden"><?= $i ?> star<?= $i > 1 ? 's' : '' ?></span></label>
<?php endfor; ?>
          </fieldset>
          <p class="post-form__as">Posting as <?= avatar_html($me, 'post-form__avatar') ?> <strong><?= h($me['display_name']) ?></strong> <span class="post-form__rules">· <a href="/rules" target="_blank">Site Rules</a></span></p>
          <label class="post-form__field">Review <textarea name="body" rows="4" maxlength="2000" minlength="10" required placeholder="What did you like? What could be better?"><?= $myReview ? h($myReview['body']) : '' ?></textarea></label>
          <label class="hp" aria-hidden="true">Leave empty <input name="website" tabindex="-1" autocomplete="off" /></label>
          <div class="post-form__foot">
            <button type="submit" class="btn btn--accent"><?= $myReview ? 'Save changes' : 'Post review' ?></button>
            <p class="post-form__msg" role="status"></p>
          </div>
        </form>
        </details>
<?php endif; ?>
        <ul class="post-list" data-list="review">
<?php foreach ($reviews as $p) echo post_html($p); ?>
        </ul>
      </section>

      <!-- discussion (stored as kind "comment") -->
      <section id="discussion" class="game__section" aria-labelledby="discussion-title">
        <h2 id="discussion-title" class="section-title">Discussion <span class="game__count" data-comment-count><?= $commentCount ?: '' ?></span></h2>
<?php if ($canAct): ?>
        <form class="post-form post-form--compact" data-kind="comment">
          <label class="post-form__field post-form__field--body"><span class="visually-hidden">Message</span><textarea name="body" rows="2" maxlength="1500" minlength="2" required placeholder="Ask a question, share a tip or say something nice…"></textarea></label>
          <p class="post-form__as post-form__field--name">as <?= avatar_html($me, 'post-form__avatar') ?> <strong><?= h($me['display_name']) ?></strong> <span class="post-form__rules">· <a href="/rules" target="_blank">Site Rules</a></span></p>
          <label class="hp" aria-hidden="true">Leave empty <input name="website" tabindex="-1" autocomplete="off" /></label>
          <button type="submit" class="btn btn--accent post-form__send">Post</button>
          <p class="post-form__msg" role="status"></p>
        </form>
<?php else: ?>
        <p class="post-login"><svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
<?php if ($me): ?>
          <span>Confirm your email address to join the discussion: click the link in the email we sent you. <a href="/account?tab=settings">Send it again</a></span></p>
<?php else: ?>
          <span><a href="/login?next=<?= h(urlencode(game_path($game) . '#discussion')) ?>">Log in</a> or <a href="/login?tab=signup&amp;next=<?= h(urlencode(game_path($game) . '#discussion')) ?>">create an account</a> to join the discussion.</span></p>
<?php endif; ?>
<?php endif; ?>
        <ul class="post-list" data-list="comment">
<?php foreach ($comments as $p) echo thread_html($p, $replies[(int)$p['id']] ?? []); ?>
        </ul>
        <p class="post-empty" data-empty="comment"<?= $comments ? ' hidden' : '' ?>>No messages yet. Start the discussion!</p>
      </section>

        </div><!-- /.game__main -->
      </div><!-- /.game__layout -->

<?php if ($related): ?>
      <section class="game__section game__related" aria-labelledby="related-title">
        <h2 id="related-title" class="section-title">More like this</h2>
        <ul class="related">
<?php foreach ($related as $g): ?>
          <li><a class="related__item" href="<?= h(game_path($g)) ?>">
            <img src="<?= h($g['thumbnail'] ?? '') ?>" alt="" loading="lazy" />
            <span class="related__title"><?= h($g['title']) ?></span>
            <span class="related__meta"><?= h(implode(', ', game_authors($g))) ?></span>
          </a></li>
<?php endforeach; ?>
        </ul>
      </section>
<?php endif; ?>
    </article>

    <!-- report dialog -->
    <dialog id="report-dialog" class="news-dialog report-dialog" aria-labelledby="report-title">
      <button type="button" class="modal-close" data-close aria-label="Close">&times;</button>
      <form class="news-dialog__inner report-form" method="dialog">
        <h2 id="report-title" class="news-dialog__title">Report a problem</h2>
        <p class="report-form__about" data-report-about></p>
        <label class="post-form__field">What's wrong?
          <select name="reason" required>
            <option value="">Choose a reason…</option>
            <option>Broken or dead link</option>
            <option>Wrong information</option>
            <option>Missing NSFW / gore tag</option>
            <option>Spam or abuse</option>
            <option>Something else</option>
          </select>
        </label>
        <p class="report-form__fix" data-fix-hint hidden>
          Know the right info or a working link? <a href="/submit?edit=<?= h(urlencode((string)($game['id'] ?? ''))) ?>">Suggest the correction directly</a>, then a moderator only has to check it.
        </p>
        <label class="post-form__field">Details (optional) <textarea name="details" rows="3" maxlength="1000"></textarea></label>
        <label class="hp" aria-hidden="true">Leave empty <input name="website" tabindex="-1" autocomplete="off" /></label>
        <div class="post-form__foot">
          <button type="submit" class="btn btn--accent" value="send">Send report</button>
          <p class="post-form__msg" role="status"></p>
        </div>
      </form>
    </dialog>
<?php endif; ?>
  </main>

<?php render_footer(); ?>

  <script src="/js/main.js?v=<?= ASSET_V ?>"></script>
  <script src="/js/post-tools.js?v=<?= ASSET_V ?>"></script>
  <script src="/js/game.js?v=<?= ASSET_V ?>"></script>
</body>
</html>
