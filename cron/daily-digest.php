<?php
/**
 * Daily summary emails (see inc/notify.php). You don't need this: the first
 * visit after 9:00 Amsterdam time sends them. Run it from cron if you want
 * them on time even when nobody visits, e.g. (server time):
 *
 *   5 9 * * *  php /path/to/site/cron/daily-digest.php
 *
 * It sends at most once per day; with --force it sends again right now
 * (only what hasn't been mailed yet).
 */

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/notify.php';

if (!mail_enabled()) { fwrite(STDERR, "Email is not set up (SMTP_* in env).\n"); exit(1); }
$today = (new DateTimeImmutable('now', new DateTimeZone(DIGEST_TZ)))->format('Y-m-d');
$force = in_array('--force', $argv, true);
if (!$force && !claim_digest_day($today)) { echo "Already sent today ($today).\n"; exit(0); }
if ($force) claim_digest_day($today);
$n = send_daily_digests();
echo "Sent $n summary email" . ($n === 1 ? '' : 's') . ".\n";
