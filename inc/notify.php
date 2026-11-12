<?php
/**
 * Notifications for members: updates to the games they bookmarked, and
 * replies to their messages in a game's discussion.
 *
 * - notify_game_updates(): when games.json is saved (admin, accepted
 *   submission, rollback), every changed game that someone bookmarked gives
 *   them a notification; a second change within 6 hours updates the unread one.
 * - notify_reply(): someone answered a member's message; more replies to the
 *   same message while it's unread update that notification.
 * - Email: one daily summary (send_daily_digests()) with everything new and
 *   still unread, of the kinds the member chose. It goes out once a day after
 *   DIGEST_HOUR (Amsterdam time): started by the first visit after that hour
 *   (api/me.php), or by cron/daily-digest.php if a cron job runs it.
 * Members choose what (game updates, replies) and how (bell, email) in their
 * account settings; every email has a one-click link to stop them.
 */

declare(strict_types=1);

const NOTIFY_MERGE_SECONDS = 6 * 3600;     // changes this close together become one notification
const DIGEST_HOUR = 9;                     // the daily summary goes out after 9:00 ...
const DIGEST_TZ = 'Europe/Amsterdam';      // ... in this time zone
const DIGEST_MAX_AGE = 7 * 86400;          // older unsent notifications are not mailed anymore
const DIGEST_MAX_ITEMS = 15;               // per section in one email

/** What changed between two versions of a game, as short lines ([] when nothing that matters). */
function game_update_summary(array $old, array $new): array
{
    $out = [];
    $dlKey = fn($d) => is_array($d) ? trim(($d['label'] ?? '') . ' ' . ($d['version'] ?? '')) . '|' . ($d['url'] ?? '') : '';
    $oldDl = array_map($dlKey, (array)($old['downloads'] ?? []));
    foreach ((array)($new['downloads'] ?? []) as $d) {
        if (!is_array($d) || in_array($dlKey($d), $oldDl, true)) continue;
        $name = trim(($d['label'] ?? '') . ' ' . ($d['version'] ?? ''));
        $out[] = 'New download' . ($name !== '' ? ': ' . $name : '');
    }
    if (($old['status'] ?? '') !== ($new['status'] ?? '') && ($new['status'] ?? '') !== '') {
        $out[] = 'Now ' . $new['status'] . (($old['status'] ?? '') !== '' ? ' (was ' . $old['status'] . ')' : '');
    }
    if (($old['title'] ?? '') !== ($new['title'] ?? '')) $out[] = 'New name: ' . ($new['title'] ?? '');
    if (count((array)($new['videos'] ?? [])) > count((array)($old['videos'] ?? []))) $out[] = 'New video';
    if (count((array)($new['screenshots'] ?? [])) > count((array)($old['screenshots'] ?? []))) $out[] = 'New pictures';
    if (($old['releaseDate'] ?? '') !== ($new['releaseDate'] ?? '') && ($new['releaseDate'] ?? '') !== '') $out[] = 'Release date: ' . $new['releaseDate'];
    if (!$out) {
        // anything else people would care about (not just a moved field or a fixed typo in the tags)
        foreach (['fullDescription', 'shortDescription', 'downloads', 'url', 'author', 'platforms', 'engine', 'thumbnail', 'screenshots', 'videos'] as $k) {
            if (json_encode($old[$k] ?? null) !== json_encode($new[$k] ?? null)) { $out[] = 'Details updated'; break; }
        }
    }
    $out = array_values(array_unique($out));
    if (count($out) > 3) $out = array_merge(array_slice($out, 0, 3), ['and more']);
    return $out;
}

/** Called by save_content() for games.json with the list before and after the save. */
function notify_game_updates(array $oldGames, array $newGames): void
{
    try {
        $before = [];
        foreach ($oldGames as $g) if (isset($g['id'])) $before[(string)$g['id']] = $g;
        $changed = [];
        foreach ($newGames as $g) {
            $id = (string)($g['id'] ?? '');
            if ($id === '' || !isset($before[$id])) continue; // new games: nobody bookmarked them yet
            $lines = game_update_summary($before[$id], $g);
            if ($lines) $changed[$id] = ['title' => (string)($g['title'] ?? 'A game'), 'body' => implode(' · ', $lines), 'game' => $g];
        }
        if (!$changed) return;

        $pdo = db();
        $in = implode(',', array_fill(0, count($changed), '?'));
        $st = $pdo->prepare("SELECT b.game_id, u.id, u.notify_site, u.notify_email, u.notify_updates, u.ban_until
                             FROM user_bookmarks b JOIN users u ON u.id = b.user_id WHERE b.game_id IN ($in)");
        $st->execute(array_map('strval', array_keys($changed)));
        $now = time();
        foreach ($st->fetchAll() as $r) {
            if (user_banned($r) || !(int)$r['notify_updates']) continue;
            if (!(int)$r['notify_site'] && !(int)$r['notify_email']) continue;
            $gid = (string)$r['game_id'];
            $c = $changed[$gid];
            $uid = (int)$r['id'];
            // one unread notification per game: a change soon after the last one updates it
            $q = $pdo->prepare("SELECT id, body FROM notifications WHERE user_id = ? AND game_id = ? AND kind = 'update' AND read_at IS NULL AND created_at > ? ORDER BY id DESC LIMIT 1");
            $q->execute([$uid, $gid, $now - NOTIFY_MERGE_SECONDS]);
            if ($open = $q->fetch()) {
                $body = implode(' · ', array_slice(array_unique(array_merge(explode(' · ', (string)$open['body']), explode(' · ', $c['body']))), 0, 4));
                $pdo->prepare('UPDATE notifications SET title = ?, body = ?, created_at = ?, emailed_at = NULL WHERE id = ?')->execute([$c['title'], $body, $now, $open['id']]);
            } else {
                $pdo->prepare('INSERT INTO notifications (user_id, game_id, title, body, created_at) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$uid, $gid, $c['title'], $c['body'], $now]);
            }
        }
    } catch (Throwable $e) {
        error_log('notify: ' . $e->getMessage()); // a failed notification never stops a save
    }
}

/**
 * Someone replied to a member's message in a game's discussion.
 * $target = the message that was answered (needs user_id, id, game_id),
 * $reply = the new reply (id, body), $actor = who wrote it.
 * A second reply to the same message while the first notification is still
 * unread updates that one. Email: in the daily summary.
 */
function notify_reply(array $target, array $reply, array $actor, array $game): void
{
    try {
        $uid = (int)($target['user_id'] ?? 0);
        if ($uid <= 0 || $uid === (int)$actor['id']) return; // nobody to tell, or your own message
        $pdo = db();
        $st = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $st->execute([$uid]);
        $u = $st->fetch();
        if (!$u || user_banned($u) || !(int)($u['notify_replies'] ?? 1)) return;
        if (!(int)$u['notify_site'] && !(int)$u['notify_email']) return;
        $gid = (string)$target['game_id'];
        $ref = (int)$target['id'];
        $name = (string)$actor['display_name'];
        $snippet = reply_snippet((string)$reply['body']);
        $now = time();
        $q = $pdo->prepare("SELECT id, actor_id, title FROM notifications WHERE user_id = ? AND kind = 'reply' AND ref_id = ? AND read_at IS NULL ORDER BY id DESC LIMIT 1");
        $q->execute([$uid, $ref]);
        if ($open = $q->fetch()) {
            $others = (int)$open['actor_id'] !== (int)$actor['id'] || str_contains((string)$open['title'], ' and others ');
            $title = $name . ($others ? ' and others replied' : ' replied') . ' to your message';
            $pdo->prepare('UPDATE notifications SET title = ?, body = ?, post_id = ?, actor_id = ?, created_at = ?, emailed_at = NULL WHERE id = ?')
                ->execute([$title, $snippet, (int)$reply['id'], (int)$actor['id'], $now, (int)$open['id']]);
        } else {
            $pdo->prepare("INSERT INTO notifications (user_id, game_id, title, body, created_at, kind, ref_id, post_id, actor_id) VALUES (?, ?, ?, ?, ?, 'reply', ?, ?, ?)")
                ->execute([$uid, $gid, $name . ' replied to your message', $snippet, $now, $ref, (int)$reply['id'], (int)$actor['id']]);
        }
    } catch (Throwable $e) {
        error_log('notify reply: ' . $e->getMessage()); // never stops the reply itself
    }
}

/** A short one-line version of a message for the bell. */
function reply_snippet(string $body, int $max = 140): string
{
    $s = trim(preg_replace('/\s+/u', ' ', $body) ?? '');
    return mb_strlen($s) > $max ? rtrim(mb_substr($s, 0, $max - 1)) . '…' : $s;
}

/** A reply was deleted: its notification goes too (if it's still unread and about that reply). */
function forget_reply_notification(int $postId): void
{
    try {
        db()->prepare("DELETE FROM notifications WHERE kind = 'reply' AND post_id = ? AND read_at IS NULL")->execute([$postId]);
    } catch (Throwable $e) {
    }
}

/** The link in every notification email that turns those emails off, no login needed. */
function notify_off_url(int $uid): string
{
    $sig = substr(hash_hmac('sha256', 'notify-off|' . $uid, salt()), 0, 24);
    return request_origin() . '/notify-off.php?u=' . $uid . '&t=' . $sig;
}

function notify_off_valid(int $uid, string $sig): bool
{
    return $uid > 0 && hash_equals(substr(hash_hmac('sha256', 'notify-off|' . $uid, salt()), 0, 24), $sig);
}

/* ---------- the daily summary email ---------- */

/** Key-value store for small bits of site state (here: the day the summary last went out). */
function site_meta_get(string $key): ?string
{
    $st = db()->prepare('SELECT value FROM site_meta WHERE key = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string)$v;
}

/**
 * Called on visits (api/me.php): after DIGEST_HOUR, the first visit of the day
 * claims the run and sends the summaries after its answer went to the browser.
 */
function maybe_send_digests(): void
{
    try {
        if (!mail_enabled()) return;
        $now = new DateTimeImmutable('now', new DateTimeZone(DIGEST_TZ));
        if ((int)$now->format('G') < DIGEST_HOUR) return;
        $today = $now->format('Y-m-d');
        if (site_meta_get('digest_day') === $today) return; // cheap check first
        if (!claim_digest_day($today)) return;
        after_response('send_daily_digests', 0);
    } catch (Throwable $e) {
        error_log('digest: ' . $e->getMessage());
    }
}

/** Only one request (or the cron job) gets to send today's summaries. */
function claim_digest_day(string $today): bool
{
    $pdo = db();
    $pdo->exec("INSERT OR IGNORE INTO site_meta (key, value) VALUES ('digest_day', '')");
    $st = $pdo->prepare("UPDATE site_meta SET value = ? WHERE key = 'digest_day' AND value <> ?");
    $st->execute([$today, $today]);
    return $st->rowCount() === 1;
}

/**
 * One email per member with everything new since the last summary that they
 * haven't seen on the site yet: updates to bookmarked games and replies to
 * their messages, as far as they switched those on. Returns the number of emails sent.
 */
function send_daily_digests(): int
{
    $pdo = db();
    $since = time() - DIGEST_MAX_AGE;
    $st = $pdo->prepare("SELECT n.*, a.display_name AS actor_name FROM notifications n
                         JOIN users u ON u.id = n.user_id
                         LEFT JOIN users a ON a.id = n.actor_id
                         WHERE n.emailed_at IS NULL AND n.read_at IS NULL AND n.created_at > ?
                           AND u.notify_email = 1 AND u.email_verified = 1 AND u.email <> ''
                           AND ((n.kind = 'update' AND u.notify_updates = 1) OR (n.kind = 'reply' AND u.notify_replies = 1))
                           AND " . NOTIFICATION_LIVE_SQL . "
                         ORDER BY n.user_id, n.created_at DESC");
    $st->execute([$since]);
    $byUser = [];
    foreach ($st->fetchAll() as $n) $byUser[(int)$n['user_id']][] = $n;
    $sent = 0;
    $games = all_games();
    $users = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $mark = fn(array $ids) => $pdo->prepare('UPDATE notifications SET emailed_at = ? WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')')->execute([time()]);
    foreach ($byUser as $uid => $items) {
        try {
            $users->execute([$uid]);
            $u = $users->fetch();
            if (!$u || user_banned($u)) continue;
            if (send_digest_email($u, $items, $games)) {
                $sent++;
                $mark(array_column($items, 'id'));
            }
        } catch (Throwable $e) {
            error_log('digest for user ' . $uid . ': ' . $e->getMessage());
        }
    }
    return $sent;
}

function send_digest_email(array $user, array $items, array $games): bool
{
    $site = request_origin();
    $e = fn(string $t) => htmlspecialchars($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $name = (string)$user['display_name'];
    $updates = array_values(array_filter($items, fn($n) => $n['kind'] !== 'reply'));
    $replies = array_values(array_filter($items, fn($n) => $n['kind'] === 'reply'));
    $font = "font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;";
    $count = fn(int $n, string $one, string $more) => $n . ' ' . ($n === 1 ? $one : $more);

    $parts = [];
    if ($updates) $parts[] = $count(count($updates), 'game update', 'game updates');
    if ($replies) $parts[] = $count(count($replies), 'reply', 'replies');
    $subject = 'Your Pony Games summary: ' . implode(', ', $parts);

    $text = "Hi $name!\n\nHere's what happened since your last summary.\n";
    $html = [];
    $section = function (string $title, array $list, callable $row) use (&$text, &$html, $e, $font) {
        if (!$list) return;
        $text .= "\n" . strtoupper($title) . "\n";
        $rows = '';
        foreach (array_slice($list, 0, DIGEST_MAX_ITEMS) as $n) {
            [$head, $sub, $link] = $row($n);
            $text .= "- $head: $sub\n  $link\n";
            $rows .= '<tr><td style="padding:10px 0;border-top:1px solid #2a2a4a;">'
                . '<a href="' . $e($link) . '" style="' . $font . 'font-size:15px;font-weight:700;color:#ff6b81;text-decoration:none;">' . $e($head) . '</a>'
                . '<div style="' . $font . 'font-size:14px;line-height:1.45;color:#eaeaea;padding-top:3px;">' . $e($sub) . '</div></td></tr>';
        }
        if (count($list) > DIGEST_MAX_ITEMS) {
            $more = count($list) - DIGEST_MAX_ITEMS;
            $text .= "- and $more more\n";
            $rows .= '<tr><td style="padding:10px 0;border-top:1px solid #2a2a4a;' . $font . 'font-size:14px;color:#a0a0b8;">and ' . $more . ' more on the site</td></tr>';
        }
        $html[] = ['html' => '<h2 style="margin:22px 0 6px;' . $font . 'font-size:13px;letter-spacing:0.06em;text-transform:uppercase;color:#a0a0b8;">' . $e($title) . '</h2>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $rows . '</table>'];
    };
    $gameLink = fn(string $gid, string $hash = '') => isset($games[$gid]) ? $site . game_path($games[$gid]) . $hash : $site . '/games';
    $gameTitle = fn(string $gid, string $fallback) => (string)($games[$gid]['title'] ?? $fallback);
    $section('Games you bookmarked', $updates, fn($n) => [$gameTitle((string)$n['game_id'], (string)$n['title']), (string)$n['body'], $gameLink((string)$n['game_id'])]);
    $section('Replies to your messages', $replies, fn($n) => [
        (string)$n['title'] . ' on ' . $gameTitle((string)$n['game_id'], 'a game'),
        '“' . $n['body'] . '”',
        $gameLink((string)$n['game_id'], '#post-' . (int)$n['post_id']),
    ]);

    $off = notify_off_url((int)$user['id']);
    $settings = $site . '/account?tab=settings#notifications';
    $text .= "\nChange what you get: $settings\nDon't want these emails anymore? $off\n\n- The Pony Games herd";
    $body = mail_html(
        'Your daily summary',
        array_merge(["Hi $name!", "Here's what happened since your last summary."], $html),
        'Open Pony Games',
        $site . '/',
        'You get this summary at most once a day, only when something happened. Choose what goes in it in your account settings, or turn these emails off with the link below.',
        ['Turn off these emails', $off]
    );
    return send_mail((string)$user['email'], $subject, $text, $body);
}
