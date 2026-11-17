<?php
/**
 * Daily summary emails (see inc/notify.php). You don't need this: the first
 * visit after 9:00 Amsterdam time starts them, and later visits send the rest
 * when there are more than the hourly email limit allows (DIGEST_PER_HOUR).
 * Run it from cron if you want them on time even when nobody visits, e.g.
 * every hour (server time):
 *
 *   5 * * * *  php /path/to/site/cron/daily-digest.php
 *
 * It starts one round per day and sends the next portion of an unfinished
 * round; with --force it starts a new round right now (only what hasn't been mailed yet).
 */

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/notify.php';

if (!mail_enabled()) { fwrite(STDERR, "Email is not set up (SMTP_* in env).\n"); exit(1); }
$today = (new DateTimeImmutable('now', new DateTimeZone(DIGEST_TZ)))->format('Y-m-d');
$force = in_array('--force', $argv, true);
$hour = (int)(new DateTimeImmutable('now', new DateTimeZone(DIGEST_TZ)))->format('G');
if ($force) {
    db()->prepare("UPDATE site_meta SET value = '' WHERE key = 'digest_day'")->execute();
    claim_digest_day($today);
} elseif (site_meta_get('digest_day') !== $today) {
    if ($hour < DIGEST_HOUR) { echo "Not yet: the summaries start after " . DIGEST_HOUR . ":00 (" . DIGEST_TZ . ").\n"; exit(0); }
    if (!claim_digest_day($today)) { echo "Someone else just started today's round.\n"; exit(0); }
} elseif (site_meta_get('digest_pending') !== $today) {
    echo "Today's summaries ($today) are all sent.\n"; exit(0);
}
$n = send_daily_digests();
echo "Sent $n summary email" . ($n === 1 ? '' : 's') . '.' . (site_meta_get('digest_pending') === $today ? ' More follow when the hourly limit has room.' : ' All done for today.') . "\n";
