<?php
/**
 * Admin: email. The hourly limit (MAIL_LIMIT_PER_HOUR / DIGEST_PER_HOUR, see
 * mail_budget() in inc/auth.php), today's round of daily summaries and who is
 * still waiting for theirs (digest_queue() in inc/notify.php), and how many
 * emails went out per hour in the last two days (mail_log).
 */

declare(strict_types=1);
require __DIR__ . '/../inc/content.php';
require __DIR__ . '/../inc/admin_ui.php';
require_once __DIR__ . '/../inc/notify.php';

$csrf = admin_require();
$pdo = db();
$today = (new DateTimeImmutable('now', new DateTimeZone(DIGEST_TZ)))->format('Y-m-d');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['do'] ?? '') === 'send-now') {
    // the next portion of today's round right away (as far as the hourly limit allows)
    if (site_meta_get('digest_pending') === $today && mail_enabled()) {
        $n = send_daily_digests();
        $_SESSION['admin_flash'] = $n ? "Sent $n summary email" . ($n === 1 ? '' : 's') . '.' : 'Nothing sent: the hourly limit has no room right now.';
    } else {
        $_SESSION['admin_flash'] = 'There is nothing waiting to be sent.';
    }
    header('Location: mail.php');
    exit;
}

$limit = mail_limit();
$digestLimit = $limit ? max(0, min($limit, (int)env('DIGEST_PER_HOUR', (string)min(200, $limit)))) : 0;
$lastHour = mails_last_hour();
$lastDigest = mails_last_hour('digest');
$lastAccount = mails_last_hour('account');
$roomDigest = mail_budget('digest');
$roomAccount = mail_budget('account');

$roundDay = (string)(site_meta_get('digest_day') ?? '');
$cutoff = (int)(site_meta_get('digest_cutoff') ?? 0);
$pending = site_meta_get('digest_pending') === $today;
$roundToday = $roundDay === $today;
$queue = $pending ? digest_queue() : [];
$preview = $pending ? [] : digest_queue(time()); // who would get one if a round started now
$sentRound = 0;
if ($roundToday && $cutoff) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM mail_log WHERE kind = 'digest' AND sent_at >= ?");
    $st->execute([$cutoff]);
    $sentRound = (int)$st->fetchColumn();
}

// emails per hour, last 48 hours
$hours = [];
foreach ($pdo->query('SELECT (sent_at / 3600) AS h, kind, COUNT(*) AS n FROM mail_log WHERE sent_at > ' . (time() - 48 * 3600) . ' GROUP BY h, kind')->fetchAll() as $r) {
    $hours[(int)$r['h']][$r['kind']] = (int)$r['n'];
}
krsort($hours);

$tz = new DateTimeZone(DIGEST_TZ);
$at = fn(int $t) => (new DateTimeImmutable('@' . $t))->setTimezone($tz)->format('j M, H:i');
$users = [];
if ($queue || $preview) {
    $ids = array_map('intval', array_keys($queue ?: $preview));
    foreach (array_chunk($ids, 500) as $chunk) {
        foreach ($pdo->query('SELECT id, display_name, email FROM users WHERE id IN (' . implode(',', $chunk) . ')')->fetchAll() as $u) $users[(int)$u['id']] = $u;
    }
}
$kindNames = ['update' => ['game update', 'game updates'], 'reply' => ['reply', 'replies'], 'mod' => ['moderator message', 'moderator messages'],
    'sub' => ['submission', 'submissions'], 'tip' => ['bounty update', 'bounty updates'], 'req' => ['request update', 'request updates']];
$bar = function (int $n, int $max): string {
    $pct = $max > 0 ? min(100, (int)round($n / $max * 100)) : 0;
    return '<span class="mail-bar"><span style="width:' . $pct . '%"' . ($pct >= 90 ? ' class="full"' : '') . '></span></span>';
};

admin_head('Email');
admin_nav('mail');
echo '<main>';
if (!empty($_SESSION['admin_flash'])) { echo '<p class="flash">' . h($_SESSION['admin_flash']) . '</p>'; unset($_SESSION['admin_flash']); }
?>
  <h2>Email</h2>
<?php if (!mail_enabled()): ?>
  <p class="flash flash--error">Email is not set up: SMTP_HOST and MAIL_FROM are missing in .env, so nothing is sent at all.</p>
<?php endif ?>

  <div class="cols">
    <div class="panel">
      <h3>Last hour</h3>
<?php if (!$limit): ?>
      <p class="muted">No hourly limit set (MAIL_LIMIT_PER_HOUR=0). <?= $lastHour ?> email<?= $lastHour === 1 ? '' : 's' ?> sent in the last hour.</p>
<?php else: ?>
      <table class="mail-limits">
        <tr><td>All emails</td><td><?= $bar($lastHour, $limit) ?></td><td><strong><?= $lastHour ?></strong> / <?= $limit ?></td></tr>
        <tr><td>Daily summaries</td><td><?= $bar($lastDigest, $digestLimit) ?></td><td><strong><?= $lastDigest ?></strong> / <?= $digestLimit ?></td></tr>
        <tr><td>Account emails <span class="muted">confirm, reset</span></td><td></td><td><strong><?= $lastAccount ?></strong></td></tr>
      </table>
      <p class="muted">Room right now: <strong><?= $roomDigest ?></strong> summar<?= $roomDigest === 1 ? 'y' : 'ies' ?>,
        <strong><?= $roomAccount ?></strong> account email<?= $roomAccount === 1 ? '' : 's' ?>. Counted over the last 60 minutes,
        so room comes back bit by bit. Change the numbers with MAIL_LIMIT_PER_HOUR and DIGEST_PER_HOUR in .env.</p>
<?php endif ?>
    </div>

    <div class="panel">
      <h3>Today's summaries</h3>
<?php if (!$roundToday): ?>
      <p>Not started yet: the round starts with the first visit after <?= DIGEST_HOUR ?>:00 (<?= h(DIGEST_TZ) ?>).</p>
      <p class="muted"><?= count($preview) ?> member<?= count($preview) === 1 ? '' : 's' ?> would get a summary if it started now.</p>
<?php elseif ($pending): ?>
      <p>Started <?= $at($cutoff) ?>: <strong><?= $sentRound ?></strong> sent, <strong><?= count($queue) ?></strong> still waiting.</p>
      <p class="muted">The rest goes out when the hourly limit has room again, with the next visit (at most every 5 minutes) or the cron job.</p>
      <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="do" value="send-now">
        <button class="primary"<?= $roomDigest < 1 ? ' disabled title="The hourly limit has no room right now"' : '' ?>>Send the next portion now<?= $roomDigest > 0 && $roomDigest < count($queue) ? ' (' . $roomDigest . ')' : '' ?></button></form>
<?php else: ?>
      <p>✓ All sent: <strong><?= $sentRound ?></strong> summar<?= $sentRound === 1 ? 'y' : 'ies' ?> since <?= $at($cutoff) ?>.</p>
      <p class="muted">What came in after that goes in tomorrow's summaries: <?= count($preview) ?> member<?= count($preview) === 1 ? '' : 's' ?> so far.</p>
<?php endif ?>
    </div>
  </div>

<?php $list = $queue ?: $preview; if ($list): ?>
  <div class="panel">
    <h3><?= $queue ? 'Waiting for their summary today' : 'Would get a summary in the next round' ?> <span class="muted"><?= count($list) ?></span></h3>
    <table>
      <tr><th>Member</th><th>Email</th><th>What's in it</th><th>Oldest</th></tr>
<?php foreach (array_slice($list, 0, 300, true) as $uid => $items):
        $u = $users[$uid] ?? null;
        $kinds = array_count_values(array_column($items, 'kind')); ?>
      <tr>
        <td><?= $u ? '<a href="./?view=user&amp;id=' . (int)$uid . '">' . h((string)$u['display_name']) . '</a>' : '#' . (int)$uid ?></td>
        <td class="muted"><?= h((string)($u['email'] ?? '')) ?></td>
        <td><?= h(implode(', ', array_map(fn($k, $n) => $n . ' ' . ($kindNames[$k][$n === 1 ? 0 : 1] ?? $k), array_keys($kinds), $kinds))) ?></td>
        <td class="muted"><?= $at((int)min(array_column($items, 'created_at'))) ?></td>
      </tr>
<?php endforeach ?>
    </table>
<?php if (count($list) > 300): ?><p class="muted">… and <?= count($list) - 300 ?> more.</p><?php endif ?>
  </div>
<?php endif ?>

  <div class="panel">
    <h3>Sent per hour <span class="muted">last 48 hours, <?= h(DIGEST_TZ) ?></span></h3>
<?php if (!$hours): ?>
    <p class="muted">No emails sent in the last 48 hours.</p>
<?php else: ?>
    <table class="mail-hours">
      <tr><th>Hour</th><th>Summaries</th><th>Account emails</th><th>Total</th><th></th></tr>
<?php foreach ($hours as $h => $k): $tot = array_sum($k); ?>
      <tr><td><?= $at($h * 3600) ?></td><td><?= (int)($k['digest'] ?? 0) ?></td><td><?= (int)($k['account'] ?? 0) ?></td>
        <td><strong><?= $tot ?></strong></td><td><?= $limit ? $bar($tot, $limit) : '' ?></td></tr>
<?php endforeach ?>
    </table>
<?php endif ?>
  </div>
</main>
<script src="admin.js?v=3"></script>
</body></html>
