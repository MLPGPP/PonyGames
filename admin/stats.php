<?php
/**
 * Admin: stats. Visits and page views (api/track.php), downloads and plays,
 * and what the community did (accounts, reviews, discussion, likes,
 * bookmarks, submissions, reports) over the last 7, 30, 90 or 365 days, or all time.
 * Page views older than about a year are thinned out (api/track.php): only the
 * visits and views per day stay, in stats_days, so "All time" still counts them.
 *
 * Days are UTC. A "visit" is one visitor on one day: page views carry no
 * cookie and the visitor hash changes every day, so the same person on two
 * days counts as two visits.
 */

declare(strict_types=1);
require __DIR__ . '/../inc/content.php';
require __DIR__ . '/../inc/admin_ui.php';

admin_require();

const STAT_PERIODS = [7 => '7 days', 30 => '30 days', 90 => '90 days', 365 => '12 months', 0 => 'All time'];
$days = (int)($_GET['days'] ?? 30);
if (!isset(STAT_PERIODS[$days])) $days = 30;

$pdo = db();
$today = intdiv(time(), 86400);
// one specific day (?day=2026-11-08), or the last 7/30/90/365 days
$oneDay = null;
if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string)($_GET['day'] ?? ''), $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
    $d = intdiv(gmmktime(0, 0, 0, (int)$m[2], (int)$m[3], (int)$m[1]), 86400);
    if ($d <= $today && $d > $today - 800) $oneDay = $d;
}
if ($oneDay !== null) {
    $from = $to = $oneDay;
    $len = 1;
    $chartFrom = $oneDay - 13;                       // the two weeks up to that day
    $chartTo = min($today, $oneDay + 7);             // and the week after, if it's there
} elseif ($days === 0) {                            // all time: from the first day anything was counted
    $to = $today;
    $starts = array_filter([
        (int)$pdo->query('SELECT COALESCE(MIN(day), 0) FROM page_views')->fetchColumn(),
        (int)$pdo->query('SELECT COALESCE(MIN(day), 0) FROM stats_days')->fetchColumn(),
        intdiv((int)$pdo->query('SELECT COALESCE(MIN(created_at), 0) FROM users')->fetchColumn(), 86400),
        (int)$pdo->query('SELECT COALESCE(MIN(day), 0) FROM game_clicks')->fetchColumn(),
    ]);
    $from = $starts ? min(min($starts), $today) : $today;
    $len = $to - $from + 1;
    $chartFrom = $from;
    $chartTo = $to;
} else {
    $to = $today;
    $from = $today - $days + 1;                      // first day of this period
    $len = $days;
    $chartFrom = $from;
    $chartTo = $to;
}
$prevFrom = $from - $len;                            // the period before, to compare with
$fromTs = $days === 0 && $oneDay === null ? 0 : $from * 86400; // all time: also what came before the first counted day
$toTs = ($to + 1) * 86400;                           // end (exclusive)
$prevTs = $prevFrom * 86400;
$periodName = $oneDay !== null ? 'day' : STAT_PERIODS[$days];

// one game (?game=332): the same page, but every number is about that game
$games = all_games();
$gameId = isset($_GET['game']) && isset($games[(string)$_GET['game']]) ? (string)$_GET['game'] : null;
$game = $gameId !== null ? $games[$gameId] : null;
/** this page's address with some of its settings changed (keeps the chosen game, period or day) */
function stats_url(array $set): string
{
    global $days, $oneDay, $gameId;
    $q = array_filter([
        'days' => $oneDay === null && $days !== 30 ? $days : null,
        'day' => $oneDay !== null ? gmdate('Y-m-d', $oneDay * 86400) : null,
        'game' => $gameId,
    ], fn($v) => $v !== null);
    foreach ($set as $k => $v) {
        if ($v === null) unset($q[$k]); else $q[$k] = $v;
    }
    if (isset($set['days'])) unset($q['day']);
    if (isset($set['day'])) unset($q['days']);
    return '?' . http_build_query($q);
}

$one = function (string $sql, array $args = []) use ($pdo): int {
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return (int)$st->fetchColumn();
};
$rows = function (string $sql, array $args = []) use ($pdo): array {
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return $st->fetchAll(PDO::FETCH_ASSOC);
};

// extra conditions when one game is chosen
$pvF = $gameId !== null ? ' AND path = ' . $pdo->quote('/game/' . $gameId) : '';  // page_views
$gF = $gameId !== null ? ' AND game_id = ' . $pdo->quote($gameId) : '';           // tables with a game_id

/* ---------- headline numbers: this period vs. the one before ---------- */
$count = function (string $table, string $where = '1') use ($one, $fromTs, $toTs, $prevTs): array {
    return [
        $one("SELECT COUNT(*) FROM $table WHERE created_at >= ? AND created_at < ? AND $where", [$fromTs, $toTs]),
        $one("SELECT COUNT(*) FROM $table WHERE created_at >= ? AND created_at < ? AND $where", [$prevTs, $fromTs]),
    ];
};
$byDay = function (string $sql) use ($one, $from, $to, $prevFrom): array {
    return [$one($sql, [$from, $to]), $one($sql, [$prevFrom, $from - 1])];
};
// days that only live on in stats_days (older than what page_views keeps); site-wide totals only
$keptFrom = (int)$one('SELECT COALESCE(MIN(day), 0) FROM page_views') ?: $today + 1;
$oldDays = function (string $col) use ($one, $pvF, $keptFrom): callable {
    return fn(int $a, int $b) => $pvF === '' ? $one("SELECT COALESCE(SUM($col), 0) FROM stats_days WHERE day BETWEEN ? AND ? AND day < ?", [$a, $b, $keptFrom]) : 0;
};
$oldVisits = $oldDays('visits');
$oldViews = $oldDays('views');
$tiles = [
    'Visits' => array_map(fn($n, $range) => $n + $oldVisits(...$range), $byDay("SELECT COUNT(DISTINCT day || '|' || visitor) FROM page_views WHERE day BETWEEN ? AND ?$pvF"), [[$from, $to], [$prevFrom, $from - 1]]),
    'Page views' => array_map(fn($n, $range) => $n + $oldViews(...$range), $byDay("SELECT COALESCE(SUM(hits), 0) FROM page_views WHERE day BETWEEN ? AND ?$pvF"), [[$from, $to], [$prevFrom, $from - 1]]),
    'Downloads' => $byDay("SELECT COUNT(*) FROM game_clicks WHERE kind = 'download' AND day BETWEEN ? AND ?$gF"),
    'Plays' => $byDay("SELECT COUNT(*) FROM game_clicks WHERE kind = 'play' AND day BETWEEN ? AND ?$gF"),
];
if ($gameId === null) $tiles['New accounts'] = $count('users');
$tiles += [
    'Reviews' => $count('posts', "kind = 'review'$gF"),
    'Discussion posts' => $count('posts', "kind = 'comment'$gF"),
    'Likes' => $count('likes', "1$gF"),
    'Bookmarks' => $count('user_bookmarks', "1$gF"),
];
if ($gameId === null) $tiles['Submissions'] = $count('submissions');
$tiles['Reports'] = $count('reports', "1$gF");

/* ---------- per day ---------- */
$dayList = range($chartFrom, $chartTo);
$series = fn() => array_fill_keys($dayList, 0);
$visitsDay = $series(); $viewsDay = $series(); $dlDay = $series(); $playDay = $series(); $likeDay = $series(); $markDay = $series();
foreach ($rows("SELECT day, COUNT(DISTINCT visitor) v, SUM(hits) h FROM page_views WHERE day BETWEEN ? AND ?$pvF GROUP BY day", [$chartFrom, $chartTo]) as $r) {
    $visitsDay[(int)$r['day']] = (int)$r['v'];
    $viewsDay[(int)$r['day']] = (int)$r['h'];
}
if ($pvF === '') foreach ($rows('SELECT day, visits, views FROM stats_days WHERE day BETWEEN ? AND ? AND day < ?', [$chartFrom, $chartTo, $keptFrom]) as $r) {
    $visitsDay[(int)$r['day']] = (int)$r['visits'];
    $viewsDay[(int)$r['day']] = (int)$r['views'];
}
foreach ($rows("SELECT day, kind, COUNT(*) n FROM game_clicks WHERE day BETWEEN ? AND ?$gF GROUP BY day, kind", [$chartFrom, $chartTo]) as $r) {
    if ($r['kind'] === 'download') $dlDay[(int)$r['day']] = (int)$r['n'];
    else $playDay[(int)$r['day']] = (int)$r['n'];
}
if ($gameId !== null) { // likes and bookmarks of the chosen game, per day
    foreach (['likes' => 'likeDay', 'user_bookmarks' => 'markDay'] as $table => $var) {
        foreach ($rows("SELECT created_at / 86400 d, COUNT(*) n FROM $table WHERE created_at >= ? AND created_at < ?$gF GROUP BY d", [$chartFrom * 86400, ($chartTo + 1) * 86400]) as $r) {
            ${$var}[(int)$r['d']] = (int)$r['n'];
        }
    }
}
// a year per day is too fine to read: group by week
$step = $oneDay !== null ? 1 : ($len > 400 ? 30 : ($len > 90 ? 7 : 1)); // more than a year: per 30 days
$bucket = function (array $perDay) use ($step): array {
    if ($step === 1) return $perDay;
    $out = [];
    // weeks end today, so the last point is a whole week (only the first one may be short)
    foreach (array_reverse(array_chunk(array_reverse($perDay, true), $step, true)) as $chunk) $out[min(array_keys($chunk))] = array_sum($chunk);
    return $out;
};

/* ---------- top games ---------- */
$top = [];
$add = function (string $id, string $k, int $n) use (&$top) {
    $top[$id] ??= ['views' => 0, 'download' => 0, 'play' => 0, 'likes' => 0];
    $top[$id][$k] += $n;
};
foreach ($rows("SELECT substr(path, 7) id, SUM(hits) n FROM page_views WHERE day BETWEEN ? AND ? AND path LIKE '/game/%' GROUP BY path", [$from, $to]) as $r) $add((string)$r['id'], 'views', (int)$r['n']);
foreach ($rows('SELECT game_id, kind, COUNT(*) n FROM game_clicks WHERE day BETWEEN ? AND ? GROUP BY game_id, kind', [$from, $to]) as $r) $add((string)$r['game_id'], (string)$r['kind'], (int)$r['n']);
foreach ($rows('SELECT game_id, COUNT(*) n FROM likes WHERE created_at >= ? AND created_at < ? GROUP BY game_id', [$fromTs, $toTs]) as $r) $add((string)$r['game_id'], 'likes', (int)$r['n']);
$top = array_filter($top, fn($t, $id) => isset($games[$id]), ARRAY_FILTER_USE_BOTH);
uasort($top, fn($a, $b) => ($b['views'] + 3 * ($b['download'] + $b['play'])) <=> ($a['views'] + 3 * ($a['download'] + $a['play'])));

/* ---------- pages, sources, devices ---------- */
$pages = $rows('SELECT path, COUNT(*) visits, SUM(hits) views FROM page_views WHERE day BETWEEN ? AND ? GROUP BY path ORDER BY views DESC LIMIT 15', [$from, $to]);
// Where visits came in: every source counts
// through a share link shows under both); "direct" = visits that never came from anywhere.
// A visit is a visitor on a day. For one game: the visits that included its page.
$visitIn = $pvF !== '' ? " AND day || '|' || visitor IN (SELECT day || '|' || visitor FROM page_views WHERE day BETWEEN ? AND ?$pvF)" : '';
$visitArgs = $pvF !== '' ? [$from, $to, $from, $to] : [$from, $to];
$refs = $rows("SELECT referrer, COUNT(DISTINCT day || '|' || visitor) visits FROM page_views
               WHERE day BETWEEN ? AND ?$visitIn AND referrer <> '' GROUP BY referrer ORDER BY visits DESC", $visitArgs);
$refsDirect = $one("SELECT COUNT(*) FROM (SELECT 1 FROM page_views WHERE day BETWEEN ? AND ?$visitIn GROUP BY day, visitor HAVING MAX(referrer) = '')", $visitArgs);
$refsTotal = array_sum(array_map(fn($r) => (int)$r['visits'], $refs)) + $refsDirect; // arrivals, for the share
$refs = array_slice(array_values($refs), 0, 15);
$shareNames = $pdo->query('SELECT code, name FROM share_links')->fetchAll(PDO::FETCH_KEY_PAIR); // "#code" sources
$devices = $rows("SELECT device, COUNT(DISTINCT day || '|' || visitor) n FROM page_views WHERE day BETWEEN ? AND ?$pvF GROUP BY device", [$from, $to]);
$deviceN = array_column($devices, 'n', 'device');
$deviceTotal = array_sum($deviceN);

/* ---------- all time ---------- */
$allTime = [
    'games listed' => count($games),
    'accounts' => $one('SELECT COUNT(*) FROM users'),
    'downloads' => $one("SELECT COUNT(*) FROM game_clicks WHERE kind = 'download'"),
    'plays' => $one("SELECT COUNT(*) FROM game_clicks WHERE kind = 'play'"),
    'reviews' => $one("SELECT COUNT(*) FROM posts WHERE kind = 'review' AND hidden = 0"),
    'likes' => $one('SELECT COUNT(*) FROM likes'),
    'bookmarks' => $one('SELECT COUNT(*) FROM user_bookmarks'),
];
if ($gameId !== null) { // everything this game ever got
    $rating = $rows("SELECT COUNT(*) n, AVG(rating) avg FROM posts WHERE kind = 'review' AND hidden = 0$gF")[0];
    $allTime = [
        'page views' => $one("SELECT COALESCE(SUM(hits), 0) FROM page_views WHERE 1$pvF"),
        'downloads' => $one("SELECT COUNT(*) FROM game_clicks WHERE kind = 'download'$gF"),
        'plays' => $one("SELECT COUNT(*) FROM game_clicks WHERE kind = 'play'$gF"),
        'likes' => $one("SELECT COUNT(*) FROM likes WHERE 1$gF"),
        'bookmarks' => $one("SELECT COUNT(*) FROM user_bookmarks WHERE 1$gF"),
        'reviews' => (int)$rating['n'],
        'discussion posts' => $one("SELECT COUNT(*) FROM posts WHERE kind = 'comment' AND hidden = 0$gF"),
    ];
}
$firstView = $one('SELECT COALESCE(MIN(day), 0) FROM page_views');

/* ---------- drawing ---------- */
function stat_day(int $day, int $step = 1): string
{
    $s = gmdate('M j', $day * 86400);
    if ($step >= 30) return gmdate('M j, Y', $day * 86400) . ' + 30 days';
    return $step > 1 ? 'week of ' . $s : $s;
}

/** A round number at or above $max that splits into 4 whole, round steps (the chart's scale). */
function nice_max(int $max): int
{
    $raw = max(1, $max) / 4;
    $mag = 10 ** (int)floor(log10($raw));
    foreach ([1, 1.2, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10] as $m) {
        $step = $m * $mag;
        if ($step >= $raw && floor($step) == $step) return (int)($step * 4);
    }
    return (int)(ceil($raw) * 4);
}

/**
 * A line chart as inline SVG. $lines: [[label, cssVar, [day => n]], ...], all
 * over the same days. Hover (admin/stats.js) shows the numbers of a day.
 */
function line_chart(string $title, array $lines, int $step, ?int $mark = null): string
{
    $days = array_keys($lines[0][2]);
    $n = count($days);
    $W = 760; $H = 220; $L = 44; $R = 12; $T = 12; $B = 28;
    $max = nice_max(max(1, ...array_map(fn($l) => max($l[2] ?: [0]), $lines)));
    $x = fn(int $i) => $L + ($n > 1 ? $i * ($W - $L - $R) / ($n - 1) : ($W - $L - $R) / 2);
    $y = fn(float $v) => $T + ($H - $T - $B) * (1 - $v / $max);
    $svg = '';
    for ($g = 0; $g <= 4; $g++) { // grid + scale
        $v = $max * $g / 4;
        $gy = round($y($v), 1);
        $svg .= '<line class="chart__grid' . ($g === 0 ? ' chart__base' : '') . '" x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $gy . '" y2="' . $gy . '"/>'
            . '<text class="chart__tick" x="' . ($L - 8) . '" y="' . ($gy + 4) . '" text-anchor="end">' . number_format($v) . '</text>';
    }
    $every = max(1, (int)ceil($n / 7)); // about 7 dates under the chart
    foreach ($days as $i => $d) {
        if ($i % $every !== 0 && $i !== $n - 1) continue;
        if ($i !== $n - 1 && $n - 1 - $i < $every / 2) continue; // not too close to the last one
        $svg .= '<text class="chart__tick" x="' . round($x($i), 1) . '" y="' . ($H - 8) . '" text-anchor="middle">' . gmdate('M j', $d * 86400) . '</text>';
    }
    if ($mark !== null && ($mi = array_search($mark, $days, true)) !== false) { // the chosen day
        $mx = $x($mi);
        $half = $n > 1 ? ($W - $L - $R) / ($n - 1) / 2 : 20;
        $svg .= '<rect class="chart__mark" x="' . round($mx - $half, 1) . '" y="' . $T . '" width="' . round(2 * $half, 1) . '" height="' . ($H - $T - $B) . '"/>';
    }
    $data = ['labels' => array_map(fn($d) => stat_day($d, $step), $days), 'days' => $step === 1 ? array_map(fn($d) => gmdate('Y-m-d', $d * 86400), $days) : [], 'series' => []];
    foreach ($lines as [$label, $var, $vals]) {
        $pts = [];
        foreach (array_values($vals) as $i => $v) $pts[] = round($x($i), 1) . ',' . round($y($v), 1);
        $svg .= '<polyline class="chart__line" style="stroke: var(' . $var . ')" points="' . implode(' ', $pts) . '"/>';
        $data['series'][] = ['label' => $label, 'color' => $var, 'values' => array_values($vals)];
    }
    $legend = '';
    if (count($lines) > 1) {
        $legend = '<ul class="chart__legend">';
        foreach ($lines as [$label, $var]) $legend .= '<li><span class="chart__key" style="background: var(' . $var . ')"></span>' . h($label) . '</li>';
        $legend .= '</ul>';
    }
    // the same numbers as a table
    $table = '<details class="chart__table"><summary>Show as table</summary><table class="list"><thead><tr><th>' . ($step > 1 ? 'Week of' : 'Day') . '</th>';
    foreach ($lines as [$label]) $table .= '<th class="num">' . h($label) . '</th>';
    $table .= '</tr></thead><tbody>';
    foreach (array_reverse($days) as $d) {
        $table .= '<tr><td>' . gmdate('D M j, Y', $d * 86400) . '</td>';
        foreach ($lines as [, , $vals]) $table .= '<td class="num">' . number_format($vals[$d]) . '</td>';
        $table .= '</tr>';
    }
    $table .= '</tbody></table></details>';
    return '<figure class="chart"><figcaption class="chart__title">' . h($title) . '</figcaption>' . $legend
        . '<div class="chart__plot" data-chart="' . h(json_encode($data)) . '" data-x0="' . $L . '" data-x1="' . ($W - $R) . '" data-w="' . $W . '" data-t="' . $T . '" data-b="' . ($H - $B) . '" data-max="' . $max . '">'
        . '<svg viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="' . h($title) . '">' . $svg . '</svg></div>' . $table . '</figure>';
}

function delta(int $now, int $before): string
{
    if ($GLOBALS['days'] === 0 && $GLOBALS['oneDay'] === null) return ''; // all time: nothing to compare with
    if ($before === 0) return $now > 0 ? '<span class="tile__delta">new</span>' : '<span class="tile__delta">–</span>';
    $p = (int)round(($now - $before) / $before * 100);
    $cls = $p > 0 ? ' is-up' : ($p < 0 ? ' is-down' : '');
    return '<span class="tile__delta' . $cls . '" title="' . number_format($before) . ' ' . ($GLOBALS['oneDay'] !== null ? 'the day before' : 'in the ' . STAT_PERIODS[$GLOBALS['days']] . ' before') . '">' . ($p > 0 ? '▲ ' : ($p < 0 ? '▼ ' : '')) . abs($p) . '%</span>';
}

function game_name(array $games, string $id): string
{
    return (string)($games[$id]['title'] ?? ('#' . $id));
}

admin_head('Stats');
admin_nav('stats');
?>
<main class="wide stats">
  <div class="stats__head">
<?php if ($game): ?>
    <div class="stats__title">
      <a class="stats__back" href="<?= h(stats_url(['game' => null])) ?>">← All stats</a>
      <h2><?= h((string)$game['title']) ?></h2>
      <span class="stats__links"><a href="/game/<?= h(urlencode($gameId)) ?>" target="_blank" rel="noopener">Open the game page ↗</a> · <a href="games.php?id=<?= h(urlencode($gameId)) ?>">Edit the game</a></span>
    </div>
<?php else: ?>
    <div class="stats__title">
      <h2>Stats</h2>
      <span class="stats__links"><a href="links.php">🔗 Share links</a></span>
    </div>
<?php endif ?>
    <div class="stats__period">
      <nav class="seg" aria-label="Period">
<?php foreach (STAT_PERIODS as $d => $label): $on = $oneDay === null && $d === $days; ?>
        <a href="<?= h(stats_url(['days' => $d])) ?>" class="<?= $on ? 'on' : '' ?>"<?= $on ? ' aria-current="page"' : '' ?>><?= $label ?></a>
<?php endforeach ?>
      </nav>
      <form method="get" class="daypick<?= $oneDay !== null ? ' is-on' : '' ?>">
<?php if ($oneDay !== null): ?>
        <a class="daypick__step" href="<?= h(stats_url(['day' => gmdate('Y-m-d', ($oneDay - 1) * 86400)])) ?>" title="Day before" aria-label="Day before">‹</a>
<?php endif ?>
        <label><span class="visually-hidden">Day</span><input type="date" name="day" value="<?= $oneDay !== null ? gmdate('Y-m-d', $oneDay * 86400) : '' ?>" max="<?= gmdate('Y-m-d', $today * 86400) ?>" min="<?= gmdate('Y-m-d', ($today - 799) * 86400) ?>" data-autosubmit></label>
<?php if ($oneDay !== null): ?>
        <a class="daypick__step<?= $oneDay >= $today ? ' is-off' : '' ?>" href="<?= h(stats_url(['day' => gmdate('Y-m-d', min($today, $oneDay + 1) * 86400)])) ?>" title="Day after" aria-label="Day after"<?= $oneDay >= $today ? ' aria-disabled="true" tabindex="-1"' : '' ?>>›</a>
<?php endif ?>
<?php if ($gameId !== null): ?>
        <input type="hidden" name="game" value="<?= h($gameId) ?>">
<?php endif ?>
        <noscript><button class="small">Show</button></noscript>
      </form>
    </div>
  </div>
  <p class="muted stats__note"><?php if ($oneDay !== null): ?><?= gmdate('l, F j, Y', $oneDay * 86400) ?><?= $oneDay === $today ? ' (today, so far)' : '' ?> (UTC), compared with the day before. The charts show the days around it; click a day in a chart to pick it.<?php elseif ($days === 0): ?>All time: everything since <?= gmdate('M j, Y', $from * 86400) ?> (UTC).<?= $pvF === '' ? '' : ' Page views of one game are kept for about a year.' ?> Lists like top pages and sources cover the last year or so; older page views are only kept as totals per day.<?php else: ?>Last <?= STAT_PERIODS[$days] ?> (<?= gmdate('M j', $fromTs) ?> – today, UTC), compared with the <?= STAT_PERIODS[$days] ?> before.<?= $step === 1 ? ' Click a day in a chart to see just that day.' : '' ?><?php endif ?>
<?php if (!$firstView): ?> Page views are counted from now on; there are none yet.<?php elseif ($firstView > $prevFrom && $days !== 0): ?> Page views are counted since <?= gmdate('M j, Y', $firstView * 86400) ?>.<?php endif ?></p>

  <div class="tiles">
<?php foreach ($tiles as $label => [$now, $before]): ?>
    <div class="tile"><span class="tile__label"><?= h($label) ?></span><strong class="tile__value"><?= number_format($now) ?></strong><?= delta($now, $before) ?></div>
<?php endforeach ?>
  </div>

  <section class="panel">
    <?= line_chart('Visits and page views' . ($step >= 30 ? ' per 30 days' : ($step > 1 ? ' per week' : ' per day')), [['Visits', '--series-1', $bucket($visitsDay)], ['Page views', '--series-2', $bucket($viewsDay)]], $step, $oneDay) ?>
  </section>
  <section class="panel">
    <?= line_chart('Downloads and plays' . ($step >= 30 ? ' per 30 days' : ($step > 1 ? ' per week' : ' per day')), [['Downloads', '--series-1', $bucket($dlDay)], ['Plays', '--series-2', $bucket($playDay)]], $step, $oneDay) ?>
  </section>
<?php if ($game): ?>
  <section class="panel">
    <?= line_chart('Likes and bookmarks' . ($step >= 30 ? ' per 30 days' : ($step > 1 ? ' per week' : ' per day')), [['Likes', '--series-1', $bucket($likeDay)], ['Bookmarks', '--series-2', $bucket($markDay)]], $step, $oneDay) ?>
  </section>
<?php endif ?>

<?php if (!$game): ?>
  <section class="panel">
    <h3>Games<?= $top ? ' <span class="muted">(' . count($top) . ')</span>' : '' ?></h3>
    <p class="muted stats__hint">Every game that was viewed, downloaded, played or liked<?= $oneDay !== null ? ' on this day' : ' in this period' ?>, busiest first. Click a game for its own charts.</p>
<?php if (!$top): ?>
    <p class="empty">No game was viewed, downloaded, played or liked <?= $oneDay !== null ? 'on this day' : 'in this period' ?>.</p>
<?php else: ?>
    <div class="stats-search search">
      <input type="search" placeholder="Search games…" aria-label="Search games" data-table-search="stats-games" autocomplete="off">
      <span class="muted stats-search__count" data-search-count></span>
    </div>
    <div class="stats-scroll">
    <table class="list stats-table" id="stats-games">
      <thead><tr><th>Game</th><th class="num">Page views</th><th class="num">Downloads</th><th class="num">Plays</th><th class="num">Likes</th></tr></thead>
      <tbody>
<?php foreach ($top as $id => $t): ?>
        <tr><td><a href="<?= h(stats_url(['game' => (string)$id])) ?>"><?= h(game_name($games, (string)$id)) ?></a> <a class="ext" href="/game/<?= h(urlencode((string)$id)) ?>" target="_blank" rel="noopener" title="Open the game page" aria-label="Open the game page of <?= h(game_name($games, (string)$id)) ?>">↗</a></td>
          <td class="num"><?= number_format($t['views']) ?></td><td class="num"><?= number_format($t['download']) ?></td><td class="num"><?= number_format($t['play']) ?></td><td class="num"><?= number_format($t['likes']) ?></td></tr>
<?php endforeach ?>
      </tbody>
    </table>
    <p class="empty" data-search-empty hidden>No game matches that search.</p>
    </div>
<?php endif ?>
  </section>
<?php endif ?>

  <div class="stats__cols">
<?php if (!$game): ?>
    <section class="panel">
      <h3>Pages</h3>
<?php if (!$pages): ?>
      <p class="empty">No page views <?= $oneDay !== null ? 'on this day' : 'in this period' ?>.</p>
<?php else: ?>
      <table class="list stats-table">
        <thead><tr><th>Page</th><th class="num">Visits</th><th class="num">Views</th></tr></thead>
        <tbody>
<?php foreach ($pages as $p): $path = (string)$p['path']; $isGame = preg_match('#^/game/(.+)$#', $path, $gm) && isset($games[$gm[1]]); ?>
          <tr><td><?php if ($isGame): ?><a href="<?= h(stats_url(['game' => $gm[1]])) ?>"><?= h(game_name($games, $gm[1])) ?></a> <span class="muted">(game)</span><?php else: ?><a href="<?= h($path) ?>" target="_blank" rel="noopener"><?= h($path) ?></a><?php endif ?></td><td class="num"><?= number_format((int)$p['visits']) ?></td><td class="num"><?= number_format((int)$p['views']) ?></td></tr>
<?php endforeach ?>
        </tbody>
      </table>
<?php endif ?>
    </section>
<?php endif ?>
    <section class="panel">
      <h3>Where visitors <?= $game ? 'of this game page ' : '' ?>come from</h3>
<?php if (!$refsTotal): ?>
      <p class="empty">No visits <?= $oneDay !== null ? 'on this day' : 'in this period' ?>.</p>
<?php else: ?>
      <table class="list stats-table">
        <thead><tr><th>Source</th><th class="num">Visits</th><th class="num">Share</th></tr></thead>
        <tbody>
<?php foreach ($refs as $r): $src = (string)$r['referrer']; ?>
          <tr><td><?php if ($src[0] === '#'): $code = substr($src, 1); ?><span class="stats-tag" title="Share link ?s=<?= h($code) ?>">🔗 <?= h($shareNames[$code] ?? 'Deleted share link (' . $code . ')') ?></span><?php else: ?><?= h($src) ?><?php endif ?></td>
            <td class="num"><?= number_format((int)$r['visits']) ?></td><td class="num"><?= round((int)$r['visits'] / $refsTotal * 100) ?>%</td></tr>
<?php endforeach ?>
<?php if ($refsDirect): ?>
          <tr class="stats-direct"><td title="Typed in, a bookmark, an app like Discord or WhatsApp, or a browser that doesn't say where it came from">Direct / unknown</td>
            <td class="num"><?= number_format($refsDirect) ?></td><td class="num"><?= round($refsDirect / $refsTotal * 100) ?>%</td></tr>
<?php endif ?>
        </tbody>
      </table>
      <p class="note">🔗 = one of your <a href="links.php">share links</a>. Make one for every place you post a link (a Discord announcement, a forum post, a video) to see how many visitors it brings, also from apps that don't say where visitors came from.</p>
<?php endif ?>
<?php if ($deviceTotal): ?>
      <h3 class="stats__sub">Devices</h3>
      <div class="split" role="img" aria-label="<?= h(implode(', ', array_map(fn($k) => ucfirst($k) . ' ' . round(($deviceN[$k] ?? 0) / $deviceTotal * 100) . '%', ['desktop', 'mobile']))) ?>">
<?php foreach (['desktop' => '--series-1', 'mobile' => '--series-2'] as $k => $var): $pct = ($deviceN[$k] ?? 0) / $deviceTotal * 100; if ($pct <= 0) continue; ?>
        <span class="split__part" style="width: <?= round($pct, 2) ?>%; background: var(<?= $var ?>)" title="<?= ucfirst($k) ?>: <?= number_format((int)($deviceN[$k] ?? 0)) ?> visits"></span>
<?php endforeach ?>
      </div>
      <ul class="chart__legend">
<?php foreach (['desktop' => '--series-1', 'mobile' => '--series-2'] as $k => $var): ?>
        <li><span class="chart__key" style="background: var(<?= $var ?>)"></span><?= ucfirst($k) ?> <?= round(($deviceN[$k] ?? 0) / $deviceTotal * 100) ?>%</li>
<?php endforeach ?>
      </ul>
<?php endif ?>
    </section>
  </div>

  <section class="panel">
    <h3>All time<?= $game ? ' for this game' : '' ?></h3>
    <p class="alltime"><?php foreach ($allTime as $label => $n): ?><span><strong><?= number_format($n) ?></strong> <?= h($label) ?></span><?php endforeach ?><?php if ($game && (int)$rating['n']): ?><span><strong><?= number_format((float)$rating['avg'], 1) ?> ★</strong> average review</span><?php endif ?></p>
    <p class="muted">Page views have no cookie: the visitor is a hash of IP address and browser that changes every day, and only the referring site's name is kept. Bots aren't counted. Page views older than about a year are removed.</p>
  </section>
</main>
<div class="chart-tip" id="chart-tip" hidden></div>
<script src="admin.js?v=3"></script>
<script src="stats.js?v=4"></script>
</body></html>
