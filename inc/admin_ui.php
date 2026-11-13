<?php
/**
 * Shared bits of the /admin pages: session, who may do what, header menu.
 *
 * Two ways in:
 *  - the owner password (ADMIN_PASSWORD_HASH in .env): full access ("owner");
 *  - a site account that was given a role in Accounts:
 *      moderator = everything except managing accounts,
 *      admin     = everything, also accounts and giving others access.
 * Account access is checked again on every page, so taking a role away works at once.
 */

declare(strict_types=1);

const ADMIN_ROLES = ['moderator' => 'Moderator', 'admin' => 'Admin'];

/** Starts the moderators' session (separate from the visitors' login) and links a site account with a role. */
function admin_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE && session_name() === 'pg_admin') return;

    // who is logged in on the site? (read their session first, PHP can only have one open at a time)
    $site = null;
    if (!empty($_COOKIE['pg_session']) && function_exists('current_user')) {
        $site = current_user();
        session_write_close();
    }

    session_name('pg_admin');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/admin/', 'httponly' => true, 'samesite' => 'Lax',
        'secure' => (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off')]);
    $cookie = (string)($_COOKIE['pg_admin'] ?? '');
    session_id(preg_match('/^[a-zA-Z0-9,-]{22,256}$/', $cookie) ? $cookie : session_create_id());
    session_start();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

    $siteRole = $site && isset(ADMIN_ROLES[$site['role'] ?? '']) ? $site['role'] : null;
    if (!empty($_SESSION['admin_user'])) {
        // signed in through an account: still logged in, and still allowed?
        if (!$siteRole || (int)$site['id'] !== (int)$_SESSION['admin_user']) {
            $_SESSION = ['csrf' => $_SESSION['csrf']];
        } else {
            $_SESSION['admin_role'] = $siteRole;
            $_SESSION['admin_name'] = $site['display_name'];
        }
    }
    if (empty($_SESSION['admin']) && $siteRole) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        $_SESSION['admin_user'] = (int)$site['id'];
        $_SESSION['admin_role'] = $siteRole;
        $_SESSION['admin_name'] = $site['display_name'];
    }
    $GLOBALS['PG_SITE_USER'] = $site;
}

/** 'owner' (password login), 'admin', 'moderator', or '' when not logged in. */
function admin_role(): string
{
    if (empty($_SESSION['admin'])) return '';
    return empty($_SESSION['admin_user']) ? 'owner' : (string)($_SESSION['admin_role'] ?? '');
}

/** May manage accounts (stars, badges, roles, deleting)? */
function admin_can_manage_accounts(): bool
{
    return in_array(admin_role(), ['owner', 'admin'], true);
}

/** Name for the change log. */
function admin_who(): string
{
    return admin_role() === 'owner' ? 'owner' : (string)($_SESSION['admin_name'] ?? 'moderator');
}

/** For the pages next to index.php: not logged in -> to the login. */
function admin_require(): string
{
    admin_session();
    if (empty($_SESSION['admin'])) { header('Location: ./'); exit; }
    header('X-Robots-Tag: noindex, nofollow');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' https: data: blob:");
    return $_SESSION['csrf'];
}

function admin_csrf_ok(): bool
{
    return hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''));
}

/** Leaves the admin; account users are logged out of the site too (otherwise they'd be let straight back in). */
function admin_logout(): void
{
    $viaAccount = !empty($_SESSION['admin_user']);
    $_SESSION = [];
    session_destroy();
    if ($viaAccount && function_exists('logout_user')) {
        session_name('pg_session');
        session_id((string)($_COOKIE['pg_session'] ?? session_create_id()));
        logout_user();
    }
}

function admin_head(string $title): void
{
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex, nofollow"><title>' . h($title) . ' | Pony Games admin</title>'
        . '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&amp;family=Silkscreen&amp;display=swap">'
        . '<link rel="stylesheet" href="admin.css?v=26"></head><body>';
}

/** The menu at the top of every admin page. $counts: reports, submissions. */
function admin_nav(string $active, array $counts = []): void
{
    $item = function (string $href, string $label, array $views) use ($active): string {
        return '<a href="' . h($href) . '" class="' . (in_array($active, $views, true) ? 'on' : '') . '">' . $label . '</a>';
    };
    $badge = fn(int $c) => $c > 0 ? ' <span class="nav-count">' . $c . '</span>' : '';
    // open reports and submissions show on every admin page, not only on the moderation page
    $counts += [
        'reports' => (int)db()->query('SELECT COUNT(*) FROM reports WHERE resolved = 0')->fetchColumn(),
        'submissions' => (int)db()->query("SELECT COUNT(*) FROM submissions WHERE status = 'open'")->fetchColumn(),
    ];
    $n = fn($k) => $badge((int)($counts[$k] ?? 0));
    $tips = (int)db()->query('SELECT COUNT(*) FROM bounty_tips WHERE resolved = 0')->fetchColumn(); // new tips on the bounty board
    $role = admin_role();
    $me = $role === 'owner' ? 'Owner' : h((string)($_SESSION['admin_name'] ?? '')) . ' · ' . (ADMIN_ROLES[$role] ?? '');
    echo '<header><h1><a href="./" class="brand"><img src="/textlogo.png" alt="Pony Games" width="137" height="32"><span class="brand__tag">admin</span></a></h1><nav>'
        . $item('./?view=reports', 'Reports' . $n('reports'), ['reports', 'resolved', 'report'])
        . $item('./?view=submissions', 'Submissions' . $n('submissions'), ['submissions', 'submission'])
        . $item('./?view=posts', 'Reviews &amp; discussion', ['posts'])
        . (admin_can_manage_accounts() ? $item('./?view=users', 'Accounts', ['users', 'user']) : '')
        . '<span class="sep" aria-hidden="true"></span>'
        . $item('news.php', 'News', ['news'])
        . $item('games.php', 'Games', ['games'])
        . $item('resources.php', 'Resources', ['resources'])
        . $item('bounties.php', 'Bounties' . $badge($tips), ['bounty-board'])
        . $item('jams.php', 'Jams', ['jams'])
        . '<span class="sep" aria-hidden="true"></span>'
        . $item('stats.php', 'Stats', ['stats'])
        . '</nav><div class="header__me"><span class="who">' . $me . '</span>'
        . '<form method="post" action="./"><input type="hidden" name="csrf" value="' . h($_SESSION['csrf'] ?? '') . '"><input type="hidden" name="do" value="logout"><button class="logout">Log out</button></form></div></header>';
}

/**
 * Security check for the admin dashboard: can private files be downloaded from
 * outside (/.env, /inc/, and the database if it's inside the website folder,
 * like storage/community.sqlite)? Asks the site itself over the internet, so it sees what visitors
 * see. Cached for 6 hours; ?recheck=1 runs it again. Returns problems as text.
 */
function security_problems(bool $fresh = false): array
{
    $pdo = db();
    if (!$fresh) {
        $c = json_decode((string)$pdo->query("SELECT value FROM site_meta WHERE key = 'security_check'")->fetchColumn(), true);
        if (is_array($c) && ($c['at'] ?? 0) > time() - 6 * 3600) return (array)$c['problems'];
    }
    $problems = [];
    $root = rtrim(str_replace('\\', '/', realpath(ROOT) ?: ROOT), '/') . '/';
    $dbFile = str_replace('\\', '/', realpath(community_db_path()) ?: community_db_path());
    $probe = ['/.env' => 'your settings file (.env, with passwords)', '/inc/bootstrap.php' => 'the /inc/ folder'];
    // a database inside the website folder (e.g. storage/community.sqlite) is fine as long as nginx blocks it
    if (str_starts_with($dbFile, $root)) {
        $rel = '/' . substr($dbFile, strlen($root));
        $probe[implode('/', array_map('rawurlencode', explode('/', $rel)))] = 'the database, with email addresses and password hashes (and its folder holds the uploads and backups)';
    }
    if (function_exists('curl_init')) {
        foreach ($probe as $path => $what) {
            $ch = curl_init(request_origin() . $path);
            curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4,
                                    CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => 'PonyGames-selfcheck']);
            curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($code === 200) $problems[] = "Anyone can open {$path}: {$what}. Add the \"never serve\" rules from deploy/nginx-ponygames.conf to the server (aaPanel: Website → URL rewrite).";
        }
    }
    $pdo->prepare("INSERT INTO site_meta (key, value) VALUES ('security_check', ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value")
        ->execute([json_encode(['at' => time(), 'problems' => $problems])]);
    return $problems;
}


require_once __DIR__ . '/discord.php';

const REPORT_ACTIONS = ['none' => 'No action', 'hide' => 'Post hidden', 'delete' => 'Post deleted'];

/**
 * Handle a report (admin/index.php ?view=report): do something with the reported
 * post or not, close every open report about the same post, and send the
 * moderator's message to the members who reported it and, when something was
 * done, to the writer of the post. Empty messages get a friendly default.
 * "No action" on a post that was hidden by reports makes it visible again.
 * Returns the line for the flash message.
 */
function handle_report(int $reportId, string $action, string $toReporter, string $toAuthor): string
{
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM reports WHERE id = ?');
    $st->execute([$reportId]);
    $report = $st->fetch();
    if (!$report) return 'That report is gone.';
    if ((int)$report['resolved']) return 'That report was already handled.';

    $post = null;
    if ($report['post_id']) {
        $st = $pdo->prepare('SELECT * FROM posts WHERE id = ?');
        $st->execute([(int)$report['post_id']]);
        $post = $st->fetch() ?: null;
    }
    if (!$post || !isset(REPORT_ACTIONS[$action])) $action = 'none';
    $clean = fn(string $t) => mb_substr(trim(preg_replace('/[ \t]+/u', ' ', str_replace("\r", '', $t)) ?? ''), 0, 500);
    $toReporter = $clean($toReporter);
    $toAuthor = $action === 'none' ? '' : $clean($toAuthor);

    // every open report about this post is answered at once (or just this one for a report about the game page)
    $st = $post
        ? $pdo->prepare('SELECT * FROM reports WHERE resolved = 0 AND post_id = ?')
        : $pdo->prepare('SELECT * FROM reports WHERE resolved = 0 AND id = ?');
    $st->execute([$post ? (int)$post['id'] : $reportId]);
    $reports = $st->fetchAll();
    $gid = (string)$report['game_id'];
    $what = $post && $post['kind'] === 'comment' ? 'discussion message' : 'review';

    $pdo->beginTransaction();
    try {
        if ($post && $action === 'hide') $pdo->prepare('UPDATE posts SET hidden = 1 WHERE id = ?')->execute([(int)$post['id']]);
        if ($post && $action === 'none' && (int)$post['hidden']) $pdo->prepare('UPDATE posts SET hidden = 0 WHERE id = ?')->execute([(int)$post['id']]);
        if ($post && $action === 'delete') {
            lift_replies('id = ?', [(int)$post['id']]);
            $pdo->prepare('DELETE FROM posts WHERE id = ?')->execute([(int)$post['id']]);
        }
        $ids = array_map(fn($r) => (int)$r['id'], $reports);
        $pdo->prepare('UPDATE reports SET resolved = 1, action = ?, reporter_note = ?, author_note = ?, resolved_at = ?, resolved_by = ?
                       WHERE id IN (' . implode(',', $ids) . ')')
            ->execute([$action, $toReporter, $toAuthor, time(), admin_who()]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    // the members who reported it (once each; reports without an account can't get a message)
    $linkPost = $post && $action === 'none' ? (int)$post['id'] : null;
    $default = $action === 'none'
        ? 'A moderator looked at it and it can stay. Thanks for keeping an eye out!'
        : 'A moderator agreed and took action. Thanks for keeping an eye out!';
    $told = [];
    $reporters = 0;
    foreach ($reports as $r) {
        $uid = (int)($r['user_id'] ?? 0);
        if ($uid <= 0 || isset($told[$uid])) continue;
        $told[$uid] = true;
        if ($post && $uid === (int)($post['user_id'] ?? 0)) continue; // reported their own post
        notify_moderation($uid, $gid, 'Your report was handled',
            $toReporter !== '' ? $toReporter : $default, (int)$r['id'], $linkPost);
        $reporters++;
    }
    // the writer, when their post was hidden or deleted
    $author = $post && $action !== 'none' ? (int)($post['user_id'] ?? 0) : 0;
    if ($author > 0) {
        notify_moderation($author, $gid, 'A moderator ' . ($action === 'hide' ? 'hid' : 'removed') . ' your ' . $what,
            $toAuthor !== '' ? $toAuthor : "It doesn't follow the Site Rules.", $reportId);
    }

    $done = ['none' => 'Report closed, no action', 'hide' => 'Post hidden', 'delete' => 'Post deleted'][$action];
    $sent = [];
    if ($reporters) $sent[] = $reporters === 1 ? 'the reporter' : "$reporters reporters";
    if ($author > 0) $sent[] = 'the writer';
    $reasons = array_count_values(array_column($reports, 'reason'));
    discord_report_handled([
        'action' => $action, 'game_id' => $gid, 'what' => $what, 'count' => count($reports),
        'post_name' => $post['name'] ?? '', 'post_body' => $post['body'] ?? '',
        'reasons' => implode(', ', array_map(fn($r, $n) => $n > 1 ? "$r ×$n" : $r, array_keys($reasons), $reasons)),
        'reporter_note' => $toReporter, 'author_note' => $toAuthor, 'by' => admin_who(),
        'sent' => $sent ? implode(' and ', $sent) : 'nobody (no account)',
    ]);
    $extra = count($reports) > 1 ? ' (' . count($reports) . ' reports about this post closed)' : '';
    return $done . $extra . '. ' . ($sent ? 'Message sent to ' . implode(' and ', $sent) . '.' : 'Nobody to send a message to (no account).');
}
