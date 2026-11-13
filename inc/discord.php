<?php
/**
 * Messages to Discord text channels through webhooks, for the moderators:
 *   DISCORD_WEBHOOK_REPORTS      new reports, and what was done with them
 *   DISCORD_WEBHOOK_SUBMISSIONS  new submissions, and whether they were accepted
 *   DISCORD_WEBHOOK_BOUNTIES     bounty tips and found bounties (empty: the submissions channel)
 * Make a webhook in Discord: channel settings -> Integrations -> Webhooks ->
 * New Webhook -> Copy Webhook URL, and put it in .env. Leave it empty to send nothing.
 *
 * The message goes out after the page has been sent (after_response), so a slow
 * or unreachable Discord never slows down the site. Mentions (@everyone, roles)
 * in what visitors wrote are switched off.
 */

declare(strict_types=1);

const DISCORD_COLORS = ['report' => 0xE5484D, 'new' => 0x8E6CEF, 'ok' => 0x2FBF71, 'no' => 0x8A8FA3, 'info' => 0xF5A524];

function discord_webhook(string $channel): string
{
    $url = trim(env(['reports' => 'DISCORD_WEBHOOK_REPORTS', 'bounties' => 'DISCORD_WEBHOOK_BOUNTIES'][$channel] ?? 'DISCORD_WEBHOOK_SUBMISSIONS'));
    if ($url === '' && $channel === 'bounties') $url = trim(env('DISCORD_WEBHOOK_SUBMISSIONS')); // bounty tips go with the submissions if there's no own channel
    return preg_match('#^https://(?:(?:ptb|canary)\.)?discord(?:app)?\.com/api/webhooks/\d+/[\w-]+$#', $url) ? $url : '';
}

/** Shortens text for Discord (embeds have length limits) and keeps it on a few lines. */
function discord_text(string $s, int $max = 300): string
{
    $s = trim(preg_replace("/\n{3,}/", "\n\n", str_replace("\r", '', $s)) ?? '');
    return mb_strlen($s) > $max ? rtrim(mb_substr($s, 0, $max - 1)) . '…' : $s;
}

/** Quoted block ("> …" on every line) for something a visitor wrote. */
function discord_quote(string $s, int $max = 500): string
{
    $s = discord_text($s, $max);
    return $s === '' ? '' : '> ' . str_replace("\n", "\n> ", $s);
}

/**
 * Send one embed to a channel. $embed: title, url, description, color, fields
 * [[name, value, inline?], …]. Does nothing when no webhook is set.
 */
function discord_send(string $channel, array $embed): void
{
    $url = discord_webhook($channel);
    if ($url === '' || !function_exists('curl_init')) return;
    $fields = [];
    foreach ($embed['fields'] ?? [] as $f) {
        $value = discord_text((string)($f[1] ?? ''), 1000);
        if ($value === '') continue;
        $fields[] = ['name' => discord_text((string)$f[0], 250), 'value' => $value, 'inline' => (bool)($f[2] ?? false)];
    }
    $origin = request_origin();
    $payload = [
        'username' => 'Pony Games',
        'avatar_url' => $origin . '/assets/mascot-head.png',
        'allowed_mentions' => ['parse' => []],
        'embeds' => [array_filter([
            'title' => discord_text((string)($embed['title'] ?? ''), 250),
            'url' => $embed['url'] ?? null,
            'description' => discord_text((string)($embed['description'] ?? ''), 3500),
            'color' => $embed['color'] ?? DISCORD_COLORS['info'],
            'fields' => $fields,
            'footer' => ['text' => parse_url($origin, PHP_URL_HOST) ?: 'Pony Games'],
            'timestamp' => gmdate('c'),
        ], fn($v) => $v !== null && $v !== '' && $v !== [])],
    ];
    after_response(function () use ($url, $payload) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($code < 200 || $code >= 300) error_log('discord webhook: HTTP ' . $code . ' ' . curl_error($ch));
        curl_close($ch);
    }, 30);
}

/** A game's name for a message (with a link when it's on the site). */
function discord_game(string $gid): string
{
    $g = all_games()[$gid] ?? null;
    return $g ? '[' . discord_text((string)$g['title'], 100) . '](' . request_origin() . game_path($g) . ')' : 'game #' . $gid;
}

function discord_admin_url(string $query): string
{
    return request_origin() . '/admin/?' . $query;
}

/** A visitor sent a report (api/community.php). $autoHidden: the post was hidden by this report (3 reports). */
function discord_report_new(int $reportId, bool $autoHidden = false): void
{
    if (discord_webhook('reports') === '') return;
    try {
        $st = db()->prepare('SELECT r.*, u.display_name AS reporter, p.body AS post_body, p.name AS post_name, p.kind AS post_kind
                             FROM reports r LEFT JOIN users u ON u.id = r.user_id LEFT JOIN posts p ON p.id = r.post_id WHERE r.id = ?');
        $st->execute([$reportId]);
        if (!$r = $st->fetch()) return;
        $what = $r['post_id'] ? ($r['post_kind'] === 'comment' ? 'discussion message' : 'review') : 'game page';
        $open = 0;
        if ($r['post_id']) {
            $c = db()->prepare('SELECT COUNT(*) FROM reports WHERE post_id = ? AND resolved = 0');
            $c->execute([(int)$r['post_id']]);
            $open = (int)$c->fetchColumn();
        }
        discord_send('reports', [
            'title' => '🚩 New report: ' . $r['reason'],
            'url' => discord_admin_url('view=report&id=' . $reportId),
            'color' => DISCORD_COLORS['report'],
            'description' => $r['post_id'] && $r['post_body'] !== null
                ? '**' . discord_text((string)$r['post_name'], 60) . "** wrote:\n" . discord_quote((string)$r['post_body'])
                : '',
            'fields' => [
                ['Game', discord_game((string)$r['game_id']), true],
                ['About', $what, true],
                ['Reported by', $r['reporter'] !== null ? (string)$r['reporter'] : 'someone without an account', true],
                ['Details', discord_quote((string)$r['details'])],
                ['Note', ($open > 1 ? "$open open reports about this post now." : '') . ($autoHidden ? ' It was hidden automatically until a moderator looks at it.' : '')],
                ['Handle it', '[Open in the admin panel](' . discord_admin_url('view=report&id=' . $reportId) . ')'],
            ],
        ]);
    } catch (Throwable $e) {
        error_log('discord report: ' . $e->getMessage());
    }
}

/**
 * A moderator handled a report (inc/admin_ui.php handle_report()).
 * $info: action, game_id, what, post_name, post_body, count, reporter_note, author_note, by, sent
 */
function discord_report_handled(array $info): void
{
    if (discord_webhook('reports') === '') return;
    $labels = ['none' => '✅ Report closed, no action', 'hide' => '🙈 Reported post hidden', 'delete' => '🗑️ Reported post deleted'];
    discord_send('reports', [
        'title' => ($labels[$info['action']] ?? 'Report handled') . ((int)$info['count'] > 1 ? ' (' . (int)$info['count'] . ' reports)' : ''),
        'url' => discord_admin_url('view=resolved'),
        'color' => $info['action'] === 'none' ? DISCORD_COLORS['no'] : DISCORD_COLORS['ok'],
        'description' => ($info['post_body'] ?? '') !== ''
            ? '**' . discord_text((string)$info['post_name'], 60) . '**\'s ' . $info['what'] . ":\n" . discord_quote((string)$info['post_body'], 300)
            : '',
        'fields' => [
            ['Game', discord_game((string)$info['game_id']), true],
            ['Handled by', (string)($info['by'] ?: 'a moderator'), true],
            ['Reasons', (string)($info['reasons'] ?? ''), true],
            ['Message to the reporter', discord_quote((string)$info['reporter_note']) ?: '_standard text_'],
            ['Message to the writer', $info['action'] === 'none' ? '' : (discord_quote((string)$info['author_note']) ?: '_standard text_')],
            ['Sent to', (string)$info['sent']],
        ],
    ]);
}

/** Someone sent a game or a correction (api/submit.php). */
function discord_submission_new(int $subId): void
{
    if (discord_webhook('submissions') === '') return;
    try {
        $st = db()->prepare('SELECT s.*, u.display_name AS member FROM submissions s LEFT JOIN users u ON u.id = s.user_id WHERE s.id = ?');
        $st->execute([$subId]);
        if (!$s = $st->fetch()) return;
        $d = json_decode((string)$s['data'], true) ?: [];
        $isEdit = $s['kind'] === 'edit';
        $v = $d['values'] ?? [];
        $from = $s['member'] !== null ? (string)$s['member'] . ' (account)' : (($s['name'] ?? '') !== '' ? (string)$s['name'] : 'someone without an account');
        if ((int)($s['is_dev'] ?? 0)) $from .= ' · the developer';
        $changes = array_keys($d['changes'] ?? []);
        if (!empty($d['shots'])) $changes[] = 'pictures';
        $images = count($d['images'] ?? []) + count($d['imageUrls'] ?? []);
        discord_send('submissions', [
            'title' => $isEdit ? '✏️ New correction: ' . discord_game_title((string)$s['game_id'])
                               : '🎮 New game submitted: ' . (string)($v['title'] ?? 'untitled'),
            'url' => discord_admin_url('view=submission&id=' . $subId),
            'color' => DISCORD_COLORS['new'],
            'description' => discord_quote((string)($s['note'] ?? '')),
            'fields' => [
                $isEdit ? ['Game', discord_game((string)$s['game_id']), true] : ['Made by', implode(', ', (array)($v['authors'] ?? [])), true],
                ['From', $from, true],
                $isEdit ? ['Changes', $changes ? implode(', ', $changes) : 'only a note', true] : ['Status', trim((string)($v['status'] ?? '') . ' ' . (string)($v['releaseDate'] ?? '')), true],
                ['Pictures', $images ? (string)$images : '', true],
                ['Review it', '[Open in the admin panel](' . discord_admin_url('view=submission&id=' . $subId) . ')'],
            ],
        ]);
    } catch (Throwable $e) {
        error_log('discord submission: ' . $e->getMessage());
    }
}

function discord_game_title(string $gid): string
{
    return (string)(all_games()[$gid]['title'] ?? 'game #' . $gid);
}

/**
 * A submission was accepted or rejected (admin/index.php).
 * $info: accepted, edit, name, game_id (when on the site), live, reply, rewards, by, member, published
 */
function discord_submission_done(int $subId, array $info): void
{
    if (discord_webhook('submissions') === '') return;
    $what = $info['edit'] ? 'Correction' : 'Game';
    discord_send('submissions', [
        'title' => ($info['accepted'] ? '✅ ' . $what . ' accepted: ' : '❌ ' . $what . ' rejected: ') . $info['name'],
        'url' => discord_admin_url('view=submission&id=' . $subId),
        'color' => $info['accepted'] ? DISCORD_COLORS['ok'] : DISCORD_COLORS['no'],
        'fields' => [
            ['Game', $info['live'] ? discord_game((string)$info['game_id']) : '', true],
            ['By', (string)($info['by'] ?: 'a moderator'), true],
            ['Sent by', (string)$info['member'], true],
            ['On the site', $info['accepted'] ? ($info['published'] ? 'Published right away' : 'Not yet, still has to go into games.json') : '', true],
            ['Reply to the sender', discord_quote((string)$info['reply']) ?: ($info['notified'] ? '_standard text_' : '')],
            ['Rewards', (string)$info['rewards']],
        ],
    ]);
}

/** The bounty a tip is about, as text. */
function discord_bounty_name(array $tip): string
{
    if ($tip['bounty_id'] === 'new') return '“' . discord_text((string)$tip['title'], 100) . '” (suggested new bounty)';
    return '“' . discord_text((string)(all_bounties()[(string)$tip['bounty_id']]['title'] ?? 'bounty #' . $tip['bounty_id']), 100) . '”';
}

/** Someone sent a tip for a bounty, or suggested a new one (api/bounty.php). */
function discord_tip_new(int $tipId): void
{
    if (discord_webhook('bounties') === '') return;
    try {
        $st = db()->prepare('SELECT t.*, u.display_name AS member FROM bounty_tips t LEFT JOIN users u ON u.id = t.user_id WHERE t.id = ?');
        $st->execute([$tipId]);
        if (!$t = $st->fetch()) return;
        $isNew = $t['bounty_id'] === 'new';
        discord_send('bounties', [
            'title' => $isNew ? '💡 New bounty suggested: ' . $t['title'] : '🤠 New tip for ' . discord_bounty_name($t),
            'url' => request_origin() . '/admin/bounties.php' . ($isNew ? '' : '?id=' . rawurlencode((string)$t['bounty_id']) . '#tips'),
            'color' => DISCORD_COLORS['new'],
            'description' => discord_quote((string)$t['notes']),
            'fields' => [
                ['Made by', $isNew ? (string)$t['author'] : '', true],
                ['From', ($t['member'] !== null ? (string)$t['member'] . ' (account)' : ((string)$t['name'] !== '' ? (string)$t['name'] : 'someone without an account'))
                    . ((string)$t['contact'] !== '' ? ' · contact: ' . $t['contact'] : ''), true],
                ['Links', discord_text((string)$t['links'], 900)],
                ['Look at it', '[Open in the admin panel](' . request_origin() . '/admin/bounties.php' . ($isNew ? '' : '?id=' . rawurlencode((string)$t['bounty_id']) . '#tips') . ')'],
            ],
        ]);
    } catch (Throwable $e) {
        error_log('discord tip: ' . $e->getMessage());
    }
}

/**
 * Something happened on the bounty board (admin/bounties.php).
 * $kind: 'done' (tip answered), 'deleted', 'bounty' (suggestion became a bounty), 'found'
 */
function discord_bounty_event(string $kind, array $info): void
{
    if (discord_webhook('bounties') === '') return;
    $titles = [
        'done' => !empty($info['suggestion']) ? '✕ Suggestion declined' : (($info['outcome'] ?? '') === 'helpful' ? '✅ Tip marked helpful' : '☑️ Tip closed'),
        'deleted' => '🗑️ Tip deleted',
        'bounty' => '📌 Suggestion added to the bounty board',
        'found' => '🎉 Bounty found',
    ];
    discord_send('bounties', [
        'title' => ($titles[$kind] ?? 'Bounty board') . ': ' . ($info['name'] ?? ''),
        'url' => $info['url'] ?? request_origin() . '/admin/bounties.php',
        'color' => $kind === 'deleted' ? DISCORD_COLORS['no'] : DISCORD_COLORS['ok'],
        'fields' => [
            ['By', (string)($info['by'] ?? '') ?: 'a moderator', true],
            ['Tip from', (string)($info['member'] ?? ''), true],
            ['Found by', (string)($info['found_by'] ?? ''), true],
            ['Game', ($info['game_id'] ?? '') !== '' ? discord_game((string)$info['game_id']) : '', true],
            ['Message to the tipper', discord_quote((string)($info['reply'] ?? '')) ?: (!empty($info['notified']) && $kind === 'done' ? '_standard text_' : '')],
            ['Rewards', (string)($info['rewards'] ?? '')],
            ['Told', (string)($info['told'] ?? '')],
        ],
    ]);
}
