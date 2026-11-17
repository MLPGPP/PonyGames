<?php
/**
 * Accounts: sessions, CSRF, tokens and email (SMTP).
 *
 * Browsing and downloading work without an account. Liking, bookmarking,
 * reviews and discussion need one; it also shows gold stars and badges, and
 * puts your name and stars next to your reviews and discussion messages.
 *
 * .env keys used here (all optional):
 *   SMTP_HOST, SMTP_PORT (587), SMTP_SECURE (tls|ssl|none), SMTP_USER, SMTP_PASS, MAIL_FROM
 *   MAIL_LIMIT_PER_HOUR (300): what the mail server allows, all emails together
 *   DIGEST_PER_HOUR (200): how many of those may be daily summaries; the rest
 *   stays free for account emails (confirm your email, reset your password)
 */

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

/** Badges moderators can award by hand in /admin. Keep ids stable: they're stored. */
const BADGES = [
    'game-hunter'   => ['Game Hunter',    'Submitted a game that is now on the site.', '🎮'],
    'bounty-hunter' => ['Bounty Hunter',  'Tracked down a missing game from the bounty board.', '🤠'],
    'fact-checker'  => ['Fact Checker',   'Corrected information about a game.', '🔍'],
    'link-fixer'    => ['Link Fixer',     'Reported or fixed a broken download link.', '🔗'],
    'archivist'     => ['Archivist',      'Saved screenshots, builds or history of old games.', '📦'],
    'asset-maker'   => ['Asset Maker',    'Made an asset someone asked for on the request board.', '🎨'],
    'game-dev'      => ['Game Developer', 'Made one of the games on Pony Games.', '🛠️'],
    'jammer'        => ['Jammer',         'Took part in a Pony Games jam.', '🏁'],
    'early-pony'    => ['Early Pony',     'One of the first ponies with an account.', '🌅'],
    'moderator'     => ['Moderator',      'Helps keep the herd friendly.', '🛡️'],
    'superstar'     => ['Superstar',      'Went above and beyond for the site.', '🌟'],
];

/* ---------------------------------------------------------------------------
   Sessions
   --------------------------------------------------------------------------- */

function is_https(): bool
{
    return (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
        || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

/** scheme://host of the current request, for links in emails. */
function request_origin(): string
{
    if (empty($_SERVER['HTTP_HOST'])) return site_url(); // command line (cron): the configured address
    $host = $_SERVER['HTTP_HOST'];
    if (!preg_match('/^[a-z0-9.\-]+(:\d+)?$/i', (string)$host)) $host = parse_url(site_url(), PHP_URL_HOST);
    return (is_https() ? 'https://' : 'http://') . $host;
}

/** Starts the login session. With $onlyIfExists, it won't create one for anonymous visitors. */
function user_session(bool $onlyIfExists = false): bool
{
    if (session_status() === PHP_SESSION_ACTIVE) return true;
    if ($onlyIfExists && empty($_COOKIE['pg_session'])) return false;
    session_name('pg_session');
    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 60, // stay logged in for 60 days
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => is_https(),
    ]);
    ini_set('session.gc_maxlifetime', (string)(60 * 60 * 24 * 60));
    session_start();
    return true;
}

function current_user(): ?array
{
    static $user = false;
    if ($user !== false) return $user;
    $user = null;
    if (!user_session(true) || empty($_SESSION['uid'])) return null;
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([(int)$_SESSION['uid']]);
    $user = $st->fetch() ?: null;
    if (!$user) unset($_SESSION['uid']);
    if ($user && user_banned($user)) {
        // banned: logged out everywhere; the login page says why
        unset($_SESSION['uid']);
        flash('error', ban_message($user));
        $user = null;
    }
    return $user;
}

/*
 * Confirmed email. When the site can send email (SMTP in .env), a new account
 * has to click the link in the confirmation email before it can like,
 * bookmark, review or join a discussion. Accounts from before this rule are
 * exempt (verify_exempt), and REQUIRE_EMAIL_CONFIRM=0 in .env turns it off.
 */
const NEED_CONFIRM_MESSAGE = 'Please confirm your email address first: click the link in the email we sent you. You can send it again from your account page.';

function email_confirm_required(): bool
{
    return mail_enabled() && env('REQUIRE_EMAIL_CONFIRM', '1') !== '0';
}

/** May this account like, bookmark, review and post? (logged in, and email confirmed if that's required) */
function user_verified(array $u): bool
{
    return !email_confirm_required() || !empty($u['email_verified']) || !empty($u['verify_exempt']);
}

/** Is this account banned right now? */
function user_banned(array $u): bool
{
    if (!isset($u['ban_until']) || $u['ban_until'] === null || $u['ban_until'] === '') return false;
    return (int)$u['ban_until'] === 0 || (int)$u['ban_until'] > time();
}

/** "This account is banned until Nov 20, 2026. Reason: …" */
function ban_message(array $u): string
{
    $until = (int)($u['ban_until'] ?? 0);
    return 'This account is banned ' . ($until === 0 ? 'for good' : 'until ' . gmdate('F j, Y, H:i', $until) . ' UTC') . '.'
        . (trim((string)($u['ban_reason'] ?? '')) !== '' ? ' Reason: ' . trim((string)$u['ban_reason']) : '');
}

function login_user(int $userId): void
{
    user_session();
    session_regenerate_id(true);
    $_SESSION['uid'] = $userId;
    db()->prepare('UPDATE users SET last_login = ? WHERE id = ?')->execute([time(), $userId]);
}

function logout_user(): void
{
    if (!user_session(true)) return;
    $_SESSION = [];
    $p = session_get_cookie_params();
    setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'httponly' => true, 'samesite' => 'Lax', 'secure' => $p['secure']]);
    session_destroy();
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        header('Location: /login?next=' . urlencode($_SERVER['REQUEST_URI'] ?? '/account'));
        exit;
    }
    return $u;
}

/** Only allow redirects to our own pages. */
function safe_next(?string $next, string $default = '/account'): string
{
    $next = (string)$next;
    return (preg_match('#^/(?!/)[^\s\\\\]*$#', $next)) ? $next : $default;
}

/* ---------------------------------------------------------------------------
   CSRF + flash messages
   --------------------------------------------------------------------------- */

function csrf_token(): string
{
    user_session();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function csrf_ok(): bool
{
    user_session();
    return !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''));
}

function flash(string $type, string $message): void
{
    user_session();
    $_SESSION['flash'] = [$type, $message];
}

function take_flash(): ?array
{
    if (!user_session(true) || empty($_SESSION['flash'])) return null;
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $f;
}

/* ---------------------------------------------------------------------------
   Validation helpers
   --------------------------------------------------------------------------- */

function normalize_email(string $email): string
{
    return mb_strtolower(trim($email));
}

function valid_email(string $email): bool
{
    return strlen($email) <= 190 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/** Returns an error message, or null when the name is fine. */
function display_name_error(string $name): ?string
{
    $len = mb_strlen($name);
    if ($len < 2) return 'Your display name needs at least 2 characters.';
    if ($len > 30) return 'Your display name can be at most 30 characters.';
    if (preg_match('/[\x00-\x1F\x7F<>]/u', $name)) return 'Your display name contains characters we can\'t use.';
    return null;
}

function password_error(string $password): ?string
{
    if (strlen($password) < 8) return 'Your password needs at least 8 characters.';
    if (strlen($password) > 200) return 'That password is too long.';
    return null;
}

function email_taken(string $email, int $exceptUser = 0): bool
{
    $st = db()->prepare('SELECT 1 FROM users WHERE email = ? AND id != ?');
    $st->execute([$email, $exceptUser]);
    return (bool)$st->fetchColumn();
}

/* ---------------------------------------------------------------------------
   One-time tokens (password reset, email verification)
   --------------------------------------------------------------------------- */

function make_token(int $userId, string $purpose, string $data = '', int $ttl = 3600): string
{
    $raw = bin2hex(random_bytes(24));
    $pdo = db();
    $pdo->prepare('DELETE FROM user_tokens WHERE expires_at < ?')->execute([time()]);
    $pdo->prepare('DELETE FROM user_tokens WHERE user_id = ? AND purpose = ?')->execute([$userId, $purpose]);
    $pdo->prepare('INSERT INTO user_tokens (token_hash, user_id, purpose, data, expires_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([hash('sha256', $raw), $userId, $purpose, $data, time() + $ttl]);
    return $raw;
}

/** Looks up a token without using it up. */
function peek_token(string $raw, string $purpose): ?array
{
    if (!preg_match('/^[a-f0-9]{48}$/', $raw)) return null;
    $st = db()->prepare('SELECT * FROM user_tokens WHERE token_hash = ? AND purpose = ? AND expires_at >= ?');
    $st->execute([hash('sha256', $raw), $purpose, time()]);
    return $st->fetch() ?: null;
}

function use_token(string $raw, string $purpose): ?array
{
    $row = peek_token($raw, $purpose);
    if ($row) db()->prepare('DELETE FROM user_tokens WHERE token_hash = ?')->execute([$row['token_hash']]);
    return $row;
}

/* ---------------------------------------------------------------------------
   Email (SMTP). Off until SMTP_HOST is set in .env.
   --------------------------------------------------------------------------- */

function mail_enabled(): bool
{
    return env('SMTP_HOST') !== '' && env('MAIL_FROM') !== '';
}

/* ---------------------------------------------------------------- hourly email limit */

/** Emails per hour the mail server allows (0 = no limit). */
function mail_limit(): int
{
    return max(0, (int)env('MAIL_LIMIT_PER_HOUR', '300'));
}

/** Emails sent in the last hour, all or of one kind ('digest', 'account'). */
function mails_last_hour(?string $kind = null): int
{
    $st = db()->prepare('SELECT COUNT(*) FROM mail_log WHERE sent_at > ?' . ($kind !== null ? ' AND kind = ?' : ''));
    $st->execute($kind !== null ? [time() - 3600, $kind] : [time() - 3600]);
    return (int)$st->fetchColumn();
}

/**
 * How many emails of this kind may go out right now. Daily summaries get at
 * most DIGEST_PER_HOUR an hour, so there's always room left for account emails
 * (confirm your email, reset your password), which may use everything up to the limit.
 */
function mail_budget(string $kind): int
{
    $limit = mail_limit();
    if ($limit === 0) return PHP_INT_MAX;
    $left = $limit - mails_last_hour();
    if ($kind === 'digest') {
        $digests = max(0, min($limit, (int)env('DIGEST_PER_HOUR', (string)min(200, $limit))));
        $left = min($left, $digests - mails_last_hour('digest'));
    }
    return max(0, $left);
}

/** Account emails can't go out right now: the hourly limit is reached (say so instead of "we sent it"). */
function mail_busy(): bool
{
    try {
        return mail_enabled() && mail_budget('account') < 1;
    } catch (Throwable $e) {
        return false;
    }
}

const MAIL_BUSY_MESSAGE = 'We can\'t send emails right now because it\'s very busy. Please try again in a few minutes.';

/**
 * Sends an email: plain text, plus an HTML version when $html is given (mail
 * apps show the HTML one, or the text one if they can't). Returns false (and
 * logs why) when it fails, or when the hourly limit is reached ($kind: 'digest'
 * for the daily summary, 'account' for the rest; see mail_budget()).
 */
function send_mail(string $to, string $subject, string $body, ?string $html = null, string $kind = 'account'): bool
{
    if (!mail_enabled()) return false;
    try {
        if (mail_budget($kind) < 1) {
            error_log("mail to $to not sent: the hourly limit (MAIL_LIMIT_PER_HOUR) is reached");
            return false;
        }
    } catch (Throwable $e) {
        error_log('mail limit: ' . $e->getMessage()); // no database: send anyway
    }
    $host = env('SMTP_HOST');
    $port = (int)env('SMTP_PORT', '587');
    $secure = strtolower(env('SMTP_SECURE', $port === 465 ? 'ssl' : 'tls'));
    $from = env('MAIL_FROM');
    $fromAddr = preg_match('/<([^>]+)>/', $from, $m) ? $m[1] : $from;

    try {
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
        $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $fp = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) throw new RuntimeException("connect failed: $errstr");
        stream_set_timeout($fp, 15);

        $read = function () use ($fp): string {
            $data = '';
            while (($line = fgets($fp, 515)) !== false) {
                $data .= $line;
                if (strlen($line) < 4 || $line[3] === ' ') break;
            }
            return $data;
        };
        $cmd = function (string $line, array $ok) use ($fp, $read): string {
            fwrite($fp, $line . "\r\n");
            $resp = $read();
            if (!in_array((int)substr($resp, 0, 3), $ok, true)) {
                throw new RuntimeException('SMTP said: ' . trim($resp) . ' (after ' . explode(' ', $line)[0] . ')');
            }
            return $resp;
        };

        $read(); // greeting
        $helo = parse_url(site_url(), PHP_URL_HOST) ?: 'localhost';
        $cmd("EHLO $helo", [250]);
        if ($secure === 'tls') {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new RuntimeException('STARTTLS failed');
            }
            $cmd("EHLO $helo", [250]);
        }
        if (env('SMTP_USER') !== '') {
            $cmd('AUTH LOGIN', [334]);
            $cmd(base64_encode(env('SMTP_USER')), [334]);
            $cmd(base64_encode(env('SMTP_PASS')), [235]);
        }
        $cmd("MAIL FROM:<$fromAddr>", [250]);
        $cmd("RCPT TO:<$to>", [250, 251]);
        $cmd('DATA', [354]);

        $headers = [
            'From: ' . $from,
            'To: <' . $to . '>',
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'Date: ' . date('r'),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $helo . '>',
            'MIME-Version: 1.0',
        ];
        $text = str_replace(["\r\n", "\r"], "\n", $body);
        if ($html === null) {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: 8bit';
            $text = preg_replace('/^\./m', '..', $text); // dot-stuffing
            $message = str_replace("\n", "\r\n", $text);
        } else {
            // both versions base64-encoded: short lines, no dot-stuffing needed
            $b = 'pg-' . bin2hex(random_bytes(12));
            $headers[] = 'Content-Type: multipart/alternative; boundary="' . $b . '"';
            $part = fn(string $type, string $content) => "--$b\r\nContent-Type: $type; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
                . rtrim(chunk_split(base64_encode($content), 76, "\r\n")) . "\r\n";
            $message = "This is a message in HTML and plain text.\r\n\r\n"
                . $part('text/plain', str_replace("\n", "\r\n", $text))
                . $part('text/html', $html) . "--$b--";
        }
        $cmd(implode("\r\n", $headers) . "\r\n\r\n" . $message . "\r\n.", [250]);
        $cmd('QUIT', [221]);
        fclose($fp);
        try { // count it for the hourly limit; forget what's older than two days
            db()->prepare('INSERT INTO mail_log (kind, sent_at) VALUES (?, ?)')->execute([$kind, time()]);
            if (random_int(1, 50) === 1) db()->prepare('DELETE FROM mail_log WHERE sent_at < ?')->execute([time() - 2 * 86400]);
        } catch (Throwable $e) {
            error_log('mail log: ' . $e->getMessage());
        }
        return true;
    } catch (Throwable $e) {
        error_log('pony games mail: ' . $e->getMessage());
        return false;
    }
}

/**
 * The HTML version of our emails, in the Moonbeam colours of the site: logo,
 * a heading, a few lines of text, one big button and a small note.
 * Inline styles and tables, because that's what mail apps understand.
 */
function mail_html(string $heading, array $paragraphs, string $button, string $link, string $note, ?array $footerLink = null): string
{
    $e = fn(string $t) => htmlspecialchars($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $site = request_origin();
    $font = "font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;";
    $p = '';
    // a paragraph is plain text, or ['html' => ...] for a ready-made block (the daily summary's lists)
    foreach ($paragraphs as $line) $p .= is_array($line) ? (string)$line['html'] : '<p style="margin:0 0 14px;' . $font . 'font-size:16px;line-height:1.55;color:#eaeaea;">' . $e($line) . '</p>';
    return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="color-scheme" content="dark light"><meta name="supported-color-schemes" content="dark light"><title>' . $e($heading) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#1a1a2e;">'
        . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;">' . $e($paragraphs[0] ?? $heading) . '</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#1a1a2e;"><tr><td align="center" style="padding:32px 16px;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;">'
        // logo
        . '<tr><td align="center" style="padding:0 0 22px;"><a href="' . $e($site) . '" style="text-decoration:none;">'
        . '<img src="' . $e($site) . '/textlogo.png" width="171" height="40" alt="Pony Games" style="display:block;border:0;' . $font . 'font-size:24px;font-weight:700;color:#e94560;"></a></td></tr>'
        // card
        . '<tr><td style="background:#16213e;border:1px solid #2a2a4a;border-radius:16px;padding:32px 30px;">'
        . '<h1 style="margin:0 0 18px;' . $font . 'font-size:22px;line-height:1.3;color:#ffffff;">' . $e($heading) . '</h1>'
        . $p
        . '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px 0 22px;"><tr><td style="border-radius:999px;background:#e94560;">'
        . '<a href="' . $e($link) . '" style="display:inline-block;padding:13px 28px;' . $font . 'font-size:16px;font-weight:700;color:#14121c;text-decoration:none;border-radius:999px;">' . $e($button) . '</a>'
        . '</td></tr></table>'
        . '<p style="margin:0 0 6px;' . $font . 'font-size:13px;line-height:1.5;color:#a0a0b8;">Button not working? Copy this link into your browser:</p>'
        . '<p style="margin:0 0 20px;' . $font . 'font-size:13px;line-height:1.5;word-break:break-all;"><a href="' . $e($link) . '" style="color:#ff6b81;">' . $e($link) . '</a></p>'
        . '<p style="margin:0;padding-top:16px;border-top:1px solid #2a2a4a;' . $font . 'font-size:13px;line-height:1.5;color:#a0a0b8;">' . $e($note) . '</p>'
        . '</td></tr>'
        // footer
        . '<tr><td align="center" style="padding:20px 10px 0;' . $font . 'font-size:12px;line-height:1.5;color:#a0a0b8;">'
        . 'Sent by <a href="' . $e($site) . '" style="color:#a0a0b8;">Pony Games</a>, the home of fan-made pony games.<br>- The Pony Games herd'
        . ($footerLink ? '<br><br><a href="' . $e($footerLink[1]) . '" style="color:#a0a0b8;">' . $e($footerLink[0]) . '</a>' : '') . '</td></tr>'
        . '</table></td></tr></table></body></html>';
}

function send_verify_email(array $user, string $email): bool
{
    $token = make_token((int)$user['id'], 'verify', $email, 60 * 60 * 48);
    $link = request_origin() . '/verify.php?token=' . $token;
    $name = (string)$user['display_name'];
    return send_mail($email, 'Confirm your email for Pony Games',
        "Hi $name!\n\nPlease confirm this email address for your Pony Games account:\n\n$link\n\n"
        . "The link works for 48 hours. Didn't make an account? Then you can ignore this email.\n\n- The Pony Games herd",
        mail_html('Confirm your email address', [
            "Hi $name!",
            'Welcome to the herd! Please confirm this email address for your Pony Games account. After that you can like and bookmark games, write reviews and join the discussions.',
        ], 'Confirm my email', $link, "The link works for 48 hours. Didn't make an account? Then you can ignore this email."));
}

function send_reset_email(array $user, string $email, string $token): bool
{
    $link = request_origin() . '/reset.php?token=' . $token;
    $name = (string)$user['display_name'];
    return send_mail($email, 'Reset your Pony Games password',
        "Hi $name!\n\nSomeone (hopefully you) asked to reset the password of your Pony Games account. "
        . "Choose a new password here:\n\n$link\n\n"
        . "The link works for 1 hour. Didn't ask for this? Then you can ignore this email, your password stays the same.\n\n- The Pony Games herd",
        mail_html('Choose a new password', [
            "Hi $name!",
            'Someone (hopefully you) asked to reset the password of your Pony Games account. Press the button to choose a new one.',
        ], 'Choose a new password', $link, "The link works for 1 hour. Didn't ask for this? Then you can ignore this email, your password stays the same."));
}

/* ---------------------------------------------------------------------------
   Account data
   --------------------------------------------------------------------------- */

function user_badges(int $userId): array
{
    $st = db()->prepare('SELECT badge, note, awarded_at FROM user_badges WHERE user_id = ? ORDER BY awarded_at');
    $st->execute([$userId]);
    return array_values(array_filter($st->fetchAll(), fn($b) => isset(BADGES[$b['badge']])));
}

function user_bookmarks(int $userId): array
{
    $st = db()->prepare('SELECT game_id FROM user_bookmarks WHERE user_id = ? ORDER BY created_at DESC');
    $st->execute([$userId]);
    return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** What the browser may know about the logged-in user (for the header, forms, bookmarks). */
function public_user(array $u): array
{
    return [
        'id' => (int)$u['id'],
        'name' => $u['display_name'],
        'avatar' => $u['avatar_url'],
        'avatar_color' => avatar_color((int)$u['id']), // for the mascot avatar when there's no picture
        'stars' => (int)$u['stars'],
        'verified' => user_verified($u),
    ];
}

/**
 * Deletes an account's reviews and/or discussion messages (open reports about
 * them are marked done). Returns how many were deleted.
 */
/**
 * Before discussion messages are deleted: the replies under each one move up
 * to the message it answered, so the conversation keeps its shape.
 * ($where selects the posts that are about to go.)
 */
function lift_replies(string $where, array $args): void
{
    $pdo = db();
    $st = $pdo->prepare("SELECT id FROM posts WHERE kind = 'comment' AND parent_id IS NOT NULL AND ($where)");
    $st->execute($args);
    $one = $pdo->prepare('SELECT reply_to, parent_id FROM posts WHERE id = ?');
    $move = $pdo->prepare('UPDATE posts SET reply_to = ? WHERE reply_to = ?');
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $one->execute([(int)$id]); // read it again: an earlier one in this loop may have moved it
        $r = $one->fetch();
        if ($r) $move->execute([(int)($r['reply_to'] ?: $r['parent_id']), (int)$id]);
    }
}

function delete_user_posts(int $userId, bool $reviews, bool $comments): int
{
    $kinds = array_keys(array_filter(['review' => $reviews, 'comment' => $comments]));
    if (!$kinds || $userId <= 0) return 0;
    $pdo = db();
    $in = implode(',', array_fill(0, count($kinds), '?'));
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE reports SET resolved = 1 WHERE post_id IN (SELECT id FROM posts WHERE user_id = ? AND kind IN ($in))")
        ->execute(array_merge([$userId], $kinds));
    lift_replies("user_id = ? AND kind IN ($in)", array_merge([$userId], $kinds));
    $st = $pdo->prepare("DELETE FROM posts WHERE user_id = ? AND kind IN ($in)");
    $st->execute(array_merge([$userId], $kinds));
    $n = $st->rowCount();
    $pdo->commit();
    return $n;
}

/** Removes an account. Posts stay (unless $deletePosts) but are no longer linked to it. */
function delete_user(int $userId, bool $deletePosts = false): void
{
    $pdo = db();
    $pdo->beginTransaction();
    if ($deletePosts) {
        lift_replies('user_id = ?', [$userId]);
        $pdo->prepare('DELETE FROM posts WHERE user_id = ?')->execute([$userId]);
    } else {
        $pdo->prepare('UPDATE posts SET user_id = NULL WHERE user_id = ?')->execute([$userId]);
    }
    $pdo->prepare('UPDATE bounty_tips SET user_id = NULL WHERE user_id = ?')->execute([$userId]);
    // their asset requests go; what they had claimed is open again
    $pdo->prepare('DELETE FROM asset_requests WHERE user_id = ?')->execute([$userId]);
    $pdo->prepare("UPDATE asset_requests SET status = 'open', claimed_by = NULL, claimed_at = NULL WHERE claimed_by = ? AND status = 'claimed'")->execute([$userId]);
    $pdo->prepare('UPDATE asset_requests SET claimed_by = NULL WHERE claimed_by = ?')->execute([$userId]);
    foreach (['user_bookmarks', 'user_badges', 'star_events', 'user_tokens', 'notifications'] as $t) {
        $pdo->prepare("DELETE FROM $t WHERE user_id = ?")->execute([$userId]);
    }
    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
    $pdo->commit();
}

/** Small round avatar: the account picture, or the first letter of the name. */
/**
 * Accounts without a picture get the mascot on a background colour of their
 * own (like Derpibooru's default avatars): always the same colour per account.
 */
const AVATAR_COLORS = ['#2ec4b6', '#4361ee', '#7b2cbf', '#ffd166', '#06d6a0', '#3a86ff', '#9b5de5', '#2b4c7e', '#f4a261', '#00b4d8', '#8ac926', '#6a4c93'];

function avatar_color(int $uid): string
{
    return AVATAR_COLORS[crc32('pony-avatar|' . $uid) % count(AVATAR_COLORS)];
}

function pony_avatar_html(int $uid, string $class): string
{
    return '<span class="' . h($class) . ' pony-avatar" style="--avatar-bg: ' . avatar_color($uid) . '" aria-hidden="true"></span>';
}

function avatar_html(array $u, string $class = 'avatar'): string
{
    if (!empty($u['avatar_url'])) {
        return '<img class="' . h($class) . '" src="' . h($u['avatar_url']) . '" alt="" width="40" height="40" loading="lazy">';
    }
    if (!empty($u['id'])) return pony_avatar_html((int)$u['id'], $class);
    $letter = mb_strtoupper(mb_substr(trim((string)$u['display_name']), 0, 1)) ?: '?';
    return '<span class="' . h($class) . ' ' . h($class) . '--letter" aria-hidden="true">' . h($letter) . '</span>';
}

/* Notifications (bell). A reply that was deleted or hidden since no longer counts. */
const NOTIFICATION_LIVE_SQL = "(n.kind <> 'reply' OR EXISTS (SELECT 1 FROM posts p WHERE p.id = n.post_id AND p.hidden = 0))";

function notifications_unread(int $uid): int
{
    $st = db()->prepare('SELECT COUNT(*) FROM notifications n WHERE n.user_id = ? AND n.read_at IS NULL AND ' . NOTIFICATION_LIVE_SQL);
    $st->execute([$uid]);
    return (int)$st->fetchColumn();
}

/**
 * Does this text contain a link? Reviews and discussion messages may not have
 * any: http(s)://, www. and bare addresses like example.com or discord.gg/abc.
 * File names such as setup.exe or readme.md don't count.
 */
function has_link(string $text): bool
{
    if (preg_match('~(?:https?|ftp)://|\bwww\.|\[[^\]]*\]\([^)]*\)~iu', $text)) return true;
    // no TLDs that are also everyday words (it, to, me, in, us, be, one…): "loved it.It was" isn't a link
    $tld = 'com|net|org|io|gg|co|ly|xyz|ru|de|nl|uk|info|biz|app|dev|site|online|top|tk|ml|ga|cf|gq|tv|ca|eu|fr|pl|br|cn|jp|au|cc|ws|su|club|shop|store|icu|vip|blog|moe|onion|website|cloud';
    return (bool)preg_match('~(?<![\w@.-])[a-z0-9][a-z0-9-]*(?:\.[a-z0-9-]+)*\.(?:' . $tld . ')(?![a-z0-9-])~iu', $text);
}

/**
 * Earned badges for next to a member's name (reviews, discussion): a list of
 * badge ids (array or comma-separated) -> [{id, name, icon, desc}], in BADGES order.
 */
function badge_list($ids): array
{
    $have = array_flip(is_array($ids) ? $ids : array_filter(explode(',', (string)$ids)));
    $out = [];
    foreach (BADGES as $id => [$name, $desc, $icon]) {
        if (isset($have[$id])) $out[] = ['id' => $id, 'name' => $name, 'icon' => $icon, 'desc' => $desc];
    }
    return $out;
}

function badge_chips_html($ids): string
{
    $list = badge_list($ids);
    if (!$list) return '';
    $out = '';
    foreach ($list as $b) {
        $out .= '<span class="badge-chip" title="' . h($b['name'] . ': ' . $b['desc']) . '" role="img" aria-label="' . h($b['name']) . '">' . h($b['icon']) . '</span>';
    }
    return '<span class="post__badges" aria-label="Badges">' . $out . '</span>';
}

