<?php
/**
 * Asset requests (/requests): like the bounty board, but the other way around.
 * A member asks for an asset ("a Rarity walk cycle"), a moderator approves it,
 * then any member can claim it ("I'll make this", saying how long they need:
 * REQUEST_CLAIM_CHOICES) and mark it done with a link to the result. A claim
 * that isn't delivered in time opens the request again; the maker can ask for
 * more time before that. A delivered result waits for a moderator (status
 * 'review'), who approves it (and can add it to the resources page) or sends
 * it back to the maker. Moderators handle it all in /admin/requests.php.
 *
 * Statuses: pending (waiting for a moderator), open, claimed, review (delivered, a moderator checks it), done, closed
 * (by the requester or a moderator), rejected.
 * Everyone involved hears about it under the bell (notification kind 'req'),
 * the moderators in Discord (DISCORD_WEBHOOK_REQUESTS, see inc/discord.php).
 */

declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/notify.php';
require_once __DIR__ . '/discord.php';

const REQUEST_KINDS = [
    'sprites' => 'Sprites & characters',
    'animation' => 'Animation',
    'backgrounds' => 'Backgrounds & tiles',
    'ui' => 'UI & icons',
    'music' => 'Music',
    'sfx' => 'Sound effects',
    'voice' => 'Voice acting',
    '3d' => '3D models',
    'fonts' => 'Fonts',
    'code' => 'Code & scripts',
    'writing' => 'Writing',
    'other' => 'Something else',
];
const REQUEST_STATUSES = ['pending' => 'Waiting for a moderator', 'open' => 'Open', 'claimed' => 'Being made', 'review' => 'Being checked',
    'done' => 'Done', 'closed' => 'Closed', 'rejected' => 'Not accepted'];
const REQUEST_CLAIM_DAYS = 30; // the default choice, and for claims made before makers could choose
const REQUEST_CLAIM_CHOICES = [3 => '3 days', 7 => '1 week', 14 => '2 weeks', 30 => '1 month', 60 => '2 months', 90 => '3 months'];

/** Until when a claim runs. */
function request_claim_until(array $r): int
{
    return (int)($r['claim_until'] ?? 0) ?: (int)$r['claimed_at'] + REQUEST_CLAIM_DAYS * 86400;
}

/** The chosen number of days from a form, or the default. */
function request_claim_days($v): int
{
    $d = (int)$v;
    return isset(REQUEST_CLAIM_CHOICES[$d]) ? $d : REQUEST_CLAIM_DAYS;
}

/** A <select> with the time choices. */
function request_claim_select(string $label): string
{
    $out = '<label class="req-time"><span>' . h($label) . '</span><select name="days">';
    foreach (REQUEST_CLAIM_CHOICES as $d => $l) $out .= '<option value="' . $d . '"' . ($d === REQUEST_CLAIM_DAYS ? ' selected' : '') . '>' . h($l) . '</option>';
    return $out . '</select></label>';
}

/** One request with the names of the requester and the maker. */
function request_get(int $id): ?array
{
    $st = db()->prepare('SELECT r.*, u.display_name AS requester, c.display_name AS maker FROM asset_requests r
                         LEFT JOIN users u ON u.id = r.user_id LEFT JOIN users c ON c.id = r.claimed_by WHERE r.id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Reference or result links: http(s) only, at most $max. */
function request_links(string $text, int $max = 5): array
{
    $out = [];
    foreach (preg_split('/\s+/', trim($text)) ?: [] as $l) {
        if (preg_match('#^https?://[^\s<>"]{3,500}$#i', $l) && filter_var($l, FILTER_VALIDATE_URL)) $out[] = $l;
        if (count($out) >= $max) break;
    }
    return array_values(array_unique($out));
}

/** Tell a member about a request (bell + daily email). */
function request_notify(int $uid, array $r, string $title, string $body): void
{
    notify_linked('req', $uid, '', $title, $body, (int)$r['id'], request_url((int)$r['id']));
}

/** Link to one request: the board opens on the tab it's in (?r=). */
function request_url(int $id): string
{
    return '/requests?r=' . $id . '#request-' . $id;
}

/**
 * Claims past their time without a result: the request is open
 * again, the maker and the requester hear about it. Runs when the board or the
 * admin page is opened, so no cron job is needed.
 */
function requests_expire_claims(): void
{
    $pdo = db();
    $st = $pdo->prepare("SELECT r.*, c.display_name AS maker FROM asset_requests r LEFT JOIN users c ON c.id = r.claimed_by
                         WHERE r.status = 'claimed' AND COALESCE(r.claim_until, r.claimed_at + " . (REQUEST_CLAIM_DAYS * 86400) . ') < ' . time());
    $st->execute(); // numbers written in: bound values arrive as text, and SQLite never finds a number smaller than text
    foreach ($st->fetchAll() as $r) {
        $pdo->prepare("UPDATE asset_requests SET status = 'open', claimed_by = NULL, claimed_at = NULL, claim_until = NULL, updated_at = ? WHERE id = ? AND status = 'claimed'")
            ->execute([time(), (int)$r['id']]);
        if ($r['claimed_by']) request_notify((int)$r['claimed_by'], $r, 'Your claim on “' . $r['title'] . '” ran out',
            'The time you picked has passed without a result, so the request is open again. Still working on it? Just claim it again.');
        request_notify((int)$r['user_id'], $r, '“' . $r['title'] . '” is open again',
            'The pony who claimed it didn\'t finish it in time, so someone else can pick it up now.');
        discord_request_event('expired', $r, ['maker' => (string)($r['maker'] ?? '')]);
    }
}

/** Requests that are open for business on the board, newest first. $show: open, claimed, done. */
function requests_on_board(string $show): array
{
    $st = db()->prepare('SELECT r.*, u.display_name AS requester, c.display_name AS maker FROM asset_requests r
                         LEFT JOIN users u ON u.id = r.user_id LEFT JOIN users c ON c.id = r.claimed_by
                         WHERE r.status IN (' . ($show === 'claimed' ? "'claimed', 'review'" : '?') . ') ORDER BY ' . ($show === 'done' ? 'r.done_at' : 'r.updated_at') . ' DESC LIMIT 300');
    $st->execute($show === 'claimed' ? [] : [$show]); // "being made" also shows what waits for a moderator's check
    return $st->fetchAll();
}

function requests_counts(): array
{
    $out = array_fill_keys(array_keys(REQUEST_STATUSES), 0);
    foreach (db()->query('SELECT status, COUNT(*) AS n FROM asset_requests GROUP BY status')->fetchAll() as $r) $out[$r['status']] = (int)$r['n'];
    return $out;
}
