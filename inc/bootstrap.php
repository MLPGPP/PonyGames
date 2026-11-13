<?php
/**
 * Shared setup for the PHP parts of Pony Games:
 * game pages (game.php), the community API (api/), moderation (admin/), sitemap.
 *
 * Configuration comes from the .env file in the repo root (same file the
 * webhook uses). Keys used here:
 *   COMMUNITY_DB         path of the SQLite file for likes/reviews/comments/reports
 *                        (default: ../ponygames-data/community.sqlite, i.e. next to
 *                        the repo folder, so it's never inside the web root or git)
 *   ADMIN_PASSWORD_HASH  password_hash() of the moderation password
 *   HASH_SALT            random string used to hash visitor IPs / tokens
 *   SITE_URL             https://pony.games (used for canonical links and the sitemap)
 */

declare(strict_types=1);

const ROOT = __DIR__ . '/..';

// Settings from .env, kept in our own array: many hosts (aaPanel among them)
// disable putenv()/getenv() for security, so we don't rely on them.
$GLOBALS['PG_ENV'] = [];

function load_env(string $path): void
{
    if (!is_file($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        $pos = strpos($line, '=');
        if ($pos === false) continue;
        $key = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));
        // allow optional quotes around values; without quotes, " # ..." after the value is a comment
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
            $value = substr($value, 1, -1);
        } else {
            $value = rtrim((string)preg_replace('/\s+#.*$/', '', $value));
        }
        $GLOBALS['PG_ENV'][$key] = $value;
    }
}
load_env(ROOT . '/.env');

function env(string $key, string $default = ''): string
{
    $v = $GLOBALS['PG_ENV'][$key] ?? null;
    // real environment variables (e.g. set by the server) still work when allowed
    if (($v === null || $v === '') && function_exists('getenv')) {
        $e = getenv($key);
        if ($e !== false) $v = $e;
    }
    return ($v === null || $v === '') ? $default : (string)$v;
}

/**
 * Runs $job after the answer went to the browser (slow work: the Google Sheet
 * sync, the daily summary emails). The session is saved and unlocked first:
 * otherwise the next page the visitor opens (e.g. the admin page after
 * "Save") waits until the job is done.
 */
function after_response(callable $job, int $timeLimit = 120): void
{
    register_shutdown_function(function () use ($job, $timeLimit) {
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
        ignore_user_abort(true);
        @set_time_limit($timeLimit);
        try {
            $job();
        } catch (Throwable $e) {
            error_log('after_response: ' . $e->getMessage());
        }
    });
}

/**
 * Pages have short addresses (/games, /rules, /account; nginx rewrites them to
 * the .php files, see deploy/nginx-ponygames.conf). The old ones
 * (/games.html) still work; this maps them to the short one, for the stats.
 */
const CLEAN_PAGES = ['games', 'upcoming', 'resources', 'bounties', 'about', 'rules', 'account', 'login', 'submit'];

function clean_path(string $p): string
{
    if ($p === '/index.html' || $p === '/index.php') return '/';
    if (preg_match('#^/(' . implode('|', CLEAN_PAGES) . ')\.(?:html|php)$#', $p, $m)) return '/' . $m[1];
    return $p;
}

function site_url(): string
{
    return rtrim(env('SITE_URL', 'https://pony.games'), '/');
}

/** HTML-escape */
function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/* ---------------------------------------------------------------------------
   Games data
   --------------------------------------------------------------------------- */

// status value in games.json => name on the site
const STATUS_LABELS = [
    'Released' => 'Released',
    'In Development' => 'Unfinished',
    'Cancelled' => 'Cancelled',
    'Abandoned' => 'Cancelled', // older spelling, treated the same
    'Upcoming' => 'Upcoming',
];

function status_label(?string $status): string
{
    return STATUS_LABELS[$status ?? ''] ?? (string)$status;
}

/** All games, keyed by id (string). Parsed once per request. */
function all_games(): array
{
    static $games = null;
    if ($games !== null) return $games;
    $raw = json_decode((string)file_get_contents(ROOT . '/data/games.json'), true);
    $list = is_array($raw) && isset($raw['games']) ? $raw['games'] : (is_array($raw) ? $raw : []);
    $games = [];
    foreach ($list as $g) {
        if (isset($g['id'])) $games[(string)$g['id']] = $g;
    }
    return $games;
}

function find_game($id): ?array
{
    $games = all_games();
    return $games[(string)$id] ?? null;
}

/** Same rules as gameSlug() in js/main.js, keep them in step. */
function slugify(string $title): string
{
    $s = $title;
    if (class_exists('Normalizer')) $s = Normalizer::normalize($s, Normalizer::FORM_KD) ?: $s;
    $s = preg_replace('/\p{Mn}+/u', '', $s);          // strip accents
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim($s, '-');
    if (strlen($s) > 60) $s = rtrim(substr($s, 0, 60), '-');
    return $s;
}

function game_path(array $g): string
{
    $slug = slugify((string)($g['title'] ?? ''));
    return '/game/' . $g['id'] . ($slug !== '' ? '-' . $slug : '');
}

function game_authors(array $g): array
{
    $a = $g['author'] ?? [];
    if (is_string($a)) $a = $a === '' ? [] : [$a];
    return array_values(array_filter(array_map('strval', $a), fn($x) => trim($x) !== ''));
}

function tag_type(array $t): string
{
    return strtolower((string)($t['type'] ?? 'other'));
}

function is_nsfw(array $g): bool
{
    foreach ($g['tags'] ?? [] as $t) {
        if (is_array($t) && tag_type($t) === 'warning') return true;
    }
    return false;
}

/** "2014-03-02" / "2014" / "" => display text */
function release_text(?string $date): string
{
    $date = trim((string)$date);
    if ($date === '') return '';
    if (preg_match('/^\d{4}$/', $date)) return $date;
    $ts = strtotime($date);
    return $ts ? date('F j, Y', $ts) : $date;
}

/* ---------------------------------------------------------------------------
   Links: downloads and "follow" links (same rules as js/main.js)
   --------------------------------------------------------------------------- */

function classify_download(array $d): string
{
    if (!empty($d['type']) && in_array($d['type'], ['source', 'web', 'download'], true)) return $d['type'];
    $text = strtolower(($d['label'] ?? '') . ' ' . ($d['url'] ?? ''));
    $looksLikeFile = (bool)preg_match('/\.(zip|exe|apk|rar|7z|dmg|tar\.gz)(\?|$)/', $text);
    if (!$looksLikeFile && preg_match('/steam|itch\.io\/?(\s|$)|author|website|homepage|source/', $text)) return 'source';
    if (preg_match('/web|browser|html5/', $text)) return 'web';
    return 'download';
}

const LINK_SITES = [
    ['/patreon\.com/', 'Patreon'],
    ['/(^|\.)(twitter|x)\.com/', 'Twitter'],
    ['/youtube\.com|youtu\.be/', 'YouTube'],
    ['/discord\.(gg|com)/', 'Discord'],
    ['/store\.steampowered\.com/', 'Steam'],
    ['/itch\.io/', 'itch.io'],
    ['/gamejolt\.com/', 'Game Jolt'],
    ['/ko-fi\.com/', 'Ko-fi'],
    ['/subscribestar/', 'SubscribeStar'],
    ['/bsky\.app/', 'Bluesky'],
    ['/pone\.social|mastodon/', 'Mastodon'],
    ['/deviantart\.com/', 'DeviantArt'],
    ['/tumblr\.com/', 'Tumblr'],
    ['/equestriadaily\.com/', 'Equestria Daily'],
    ['/roblox\.com/', 'Roblox'],
    ['/web\.archive\.org/', 'Archived page'],
];

function link_site(string $url): string
{
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    $host = preg_replace('/^www\./', '', $host);
    if ($host === '') return 'Website';
    foreach (LINK_SITES as [$re, $name]) {
        if (preg_match($re, $host)) return $name;
    }
    return $host;
}

/* ---------------------------------------------------------------------------
   Community database (SQLite)
   --------------------------------------------------------------------------- */

/**
 * The database file. Uploads and backups live next to it. It must be outside
 * the website folder (anything inside can be downloaded); the default is
 * ../ponygames-data/, next to the website folder.
 */
function community_db_path(): string
{
    return env('COMMUNITY_DB', dirname(realpath(ROOT) ?: ROOT) . '/ponygames-data/community.sqlite');
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $path = community_db_path();
    $dir = dirname($path);
    if (!is_dir($dir)) mkdir($dir, 0770, true);
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL; PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 3000;');
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS likes (
            game_id TEXT NOT NULL,
            visitor TEXT NOT NULL,
            created_at INTEGER NOT NULL,
            PRIMARY KEY (game_id, visitor)
        );
        CREATE TABLE IF NOT EXISTS posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            game_id TEXT NOT NULL,
            kind TEXT NOT NULL CHECK (kind IN ('review', 'comment')),
            rating INTEGER,
            name TEXT NOT NULL,
            body TEXT NOT NULL,
            visitor TEXT NOT NULL,
            ip_hash TEXT NOT NULL,
            created_at INTEGER NOT NULL,
            hidden INTEGER NOT NULL DEFAULT 0
        );
        CREATE INDEX IF NOT EXISTS posts_game ON posts (game_id, kind, hidden, created_at);
        CREATE TABLE IF NOT EXISTS reports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            game_id TEXT NOT NULL,
            post_id INTEGER,
            reason TEXT NOT NULL,
            details TEXT NOT NULL DEFAULT '',
            ip_hash TEXT NOT NULL,
            created_at INTEGER NOT NULL,
            resolved INTEGER NOT NULL DEFAULT 0
        );
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT UNIQUE,                  -- lowercase
            email_verified INTEGER NOT NULL DEFAULT 0,
            password_hash TEXT,                 -- NULL until a password is set
            discord_id TEXT UNIQUE,             -- unused (Discord login was removed)
            display_name TEXT NOT NULL,
            avatar_url TEXT NOT NULL DEFAULT '',
            stars INTEGER NOT NULL DEFAULT 0,
            created_at INTEGER NOT NULL,
            last_login INTEGER
        );
        CREATE TABLE IF NOT EXISTS game_clicks (
            game_id TEXT NOT NULL,
            kind TEXT NOT NULL CHECK (kind IN ('download', 'play')),
            visitor TEXT NOT NULL,
            day INTEGER NOT NULL,
            created_at INTEGER NOT NULL,
            PRIMARY KEY (game_id, kind, visitor, day)
        );
        CREATE TABLE IF NOT EXISTS user_bookmarks (
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            game_id TEXT NOT NULL,
            created_at INTEGER NOT NULL,
            PRIMARY KEY (user_id, game_id)
        );
        CREATE TABLE IF NOT EXISTS user_badges (
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            badge TEXT NOT NULL,
            note TEXT NOT NULL DEFAULT '',
            awarded_at INTEGER NOT NULL,
            PRIMARY KEY (user_id, badge)
        );
        CREATE TABLE IF NOT EXISTS star_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            amount INTEGER NOT NULL,
            reason TEXT NOT NULL DEFAULT '',
            created_at INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS user_tokens (
            token_hash TEXT PRIMARY KEY,        -- sha256 of the token sent by email / given by a moderator
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            purpose TEXT NOT NULL,              -- 'reset' or 'verify'
            data TEXT NOT NULL DEFAULT '',      -- e.g. the new email address for 'verify'
            expires_at INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS bounty_tips (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            bounty_id TEXT NOT NULL,            -- id from data/bounties.json, or "new" for a suggested bounty
            title TEXT NOT NULL DEFAULT '',     -- only for "new"
            author TEXT NOT NULL DEFAULT '',
            links TEXT NOT NULL DEFAULT '',
            notes TEXT NOT NULL DEFAULT '',
            name TEXT NOT NULL DEFAULT '',
            contact TEXT NOT NULL DEFAULT '',
            ip_hash TEXT NOT NULL,
            created_at INTEGER NOT NULL,
            resolved INTEGER NOT NULL DEFAULT 0
        );
        CREATE TABLE IF NOT EXISTS submissions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            kind TEXT NOT NULL,                 -- 'game' (new) or 'edit' (correction)
            game_id TEXT,                       -- for 'edit'
            data TEXT NOT NULL,                 -- JSON: values / changes, images, imageUrls
            note TEXT NOT NULL DEFAULT '',
            is_dev INTEGER NOT NULL DEFAULT 0,
            name TEXT NOT NULL DEFAULT '',
            contact TEXT NOT NULL DEFAULT '',
            user_id INTEGER,
            ip_hash TEXT NOT NULL,
            created_at INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT 'open', -- open, accepted, rejected
            reply TEXT NOT NULL DEFAULT '',     -- moderator's message, shown to the member
            reviewed_at INTEGER
        );
        CREATE TABLE IF NOT EXISTS content_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            file TEXT NOT NULL,                 -- 'games' or 'resources'
            what TEXT NOT NULL,
            created_at INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS rate_events (
            ip_hash TEXT NOT NULL,
            action TEXT NOT NULL,
            created_at INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS rate_lookup ON rate_events (ip_hash, action, created_at);
        -- page views for the admin stats: no cookie, the visitor is a hash of
        -- IP + browser that changes every day (so visitors can't be followed)
        CREATE TABLE IF NOT EXISTS page_views (
            day INTEGER NOT NULL,               -- days since 1970 (UTC)
            path TEXT NOT NULL,                 -- "/", "/games", "/game/332"
            visitor TEXT NOT NULL,
            referrer TEXT NOT NULL DEFAULT '',  -- other site the visit came from (host only)
            device TEXT NOT NULL DEFAULT '',    -- 'mobile' or 'desktop'
            hits INTEGER NOT NULL DEFAULT 1,    -- views of that page by that visitor that day
            PRIMARY KEY (day, path, visitor)
        );
        CREATE INDEX IF NOT EXISTS game_clicks_day ON game_clicks (day);
    SQL);
    // columns added after the first release
    $cols = array_column($pdo->query('PRAGMA table_info(posts)')->fetchAll(), 'name');
    if (!in_array('user_id', $cols, true)) $pdo->exec('ALTER TABLE posts ADD COLUMN user_id INTEGER');
    if (!in_array('edited_at', $cols, true)) $pdo->exec('ALTER TABLE posts ADD COLUMN edited_at INTEGER');
    // replies in the discussion: parent_id = the first message of the thread,
    // reply_user = the member whose message was answered (they get a notification)
    if (!in_array('parent_id', $cols, true)) $pdo->exec('ALTER TABLE posts ADD COLUMN parent_id INTEGER');
    if (!in_array('reply_user', $cols, true)) $pdo->exec('ALTER TABLE posts ADD COLUMN reply_user INTEGER');
    // reply_to = the exact message answered (replies nest under it, Reddit-style; empty = the thread's first message)
    if (!in_array('reply_to', $cols, true)) $pdo->exec('ALTER TABLE posts ADD COLUMN reply_to INTEGER');
    $cols = array_column($pdo->query('PRAGMA table_info(bounty_tips)')->fetchAll(), 'name');
    if (!in_array('user_id', $cols, true)) $pdo->exec('ALTER TABLE bounty_tips ADD COLUMN user_id INTEGER');
    $cols = array_column($pdo->query('PRAGMA table_info(content_log)')->fetchAll(), 'name');
    if (!in_array('backup', $cols, true)) $pdo->exec("ALTER TABLE content_log ADD COLUMN backup TEXT NOT NULL DEFAULT ''");
    if (!in_array('who', $cols, true)) $pdo->exec("ALTER TABLE content_log ADD COLUMN who TEXT NOT NULL DEFAULT ''");
    if (!in_array('shots', $cols, true)) $pdo->exec("ALTER TABLE content_log ADD COLUMN shots TEXT NOT NULL DEFAULT ''");
    $cols = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(), 'name');
    if (!in_array('role', $cols, true)) $pdo->exec("ALTER TABLE users ADD COLUMN role TEXT NOT NULL DEFAULT ''"); // '', 'moderator' or 'admin'
    // bans: ban_until NULL = not banned, 0 = for good, otherwise the time it ends
    if (!in_array('verify_exempt', $cols, true)) {
        // accounts made before confirming the email was required keep working
        $pdo->exec('ALTER TABLE users ADD COLUMN verify_exempt INTEGER NOT NULL DEFAULT 0');
        $pdo->exec('UPDATE users SET verify_exempt = 1 WHERE email_verified = 0');
    }
    // notifications about bookmarked games: on the site (bell) and by email
    if (!in_array('notify_site', $cols, true)) $pdo->exec('ALTER TABLE users ADD COLUMN notify_site INTEGER NOT NULL DEFAULT 1');
    if (!in_array('notify_email', $cols, true)) $pdo->exec('ALTER TABLE users ADD COLUMN notify_email INTEGER NOT NULL DEFAULT 1');
    if (!in_array('notify_updates', $cols, true)) $pdo->exec('ALTER TABLE users ADD COLUMN notify_updates INTEGER NOT NULL DEFAULT 1');
    if (!in_array('notify_replies', $cols, true)) $pdo->exec('ALTER TABLE users ADD COLUMN notify_replies INTEGER NOT NULL DEFAULT 1');
    $pdo->exec('CREATE TABLE IF NOT EXISTS notifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        game_id TEXT NOT NULL,
        title TEXT NOT NULL,                -- the game\'s title
        body TEXT NOT NULL,                 -- what changed, e.g. "New download: Windows 1.0.3"
        created_at INTEGER NOT NULL,
        read_at INTEGER,
        emailed_at INTEGER
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS notifications_user ON notifications (user_id, read_at, created_at)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS site_meta (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
    // once: page views of "/games.html" etc. count for the short address "/games" from now on
    if (!$pdo->query("SELECT 1 FROM site_meta WHERE key = 'clean_paths'")->fetchColumn()) {
        $pdo->beginTransaction();
        foreach (['/index.html' => '/'] + array_combine(array_map(fn($p) => "/$p.html", CLEAN_PAGES), array_map(fn($p) => "/$p", CLEAN_PAGES))
                 + array_combine(array_map(fn($p) => "/$p.php", CLEAN_PAGES), array_map(fn($p) => "/$p", CLEAN_PAGES)) as $old => $new) {
            // a visitor seen on both on the same day: add the hits together
            $pdo->prepare('UPDATE page_views SET hits = hits + (SELECT o.hits FROM page_views o WHERE o.day = page_views.day AND o.visitor = page_views.visitor AND o.path = ?)
                           WHERE path = ? AND EXISTS (SELECT 1 FROM page_views o WHERE o.day = page_views.day AND o.visitor = page_views.visitor AND o.path = ?)')
                ->execute([$old, $new, $old]);
            $pdo->prepare('UPDATE OR IGNORE page_views SET path = ? WHERE path = ?')->execute([$new, $old]);
            $pdo->prepare('DELETE FROM page_views WHERE path = ?')->execute([$old]);
        }
        $pdo->exec("INSERT INTO site_meta (key, value) VALUES ('clean_paths', '1')");
        $pdo->commit();
    }
    // share links made in /admin/links.php: <page>?s=<code>; visits through one are counted as source "#<code>"
    $pdo->exec('CREATE TABLE IF NOT EXISTS share_links (
        code TEXT PRIMARY KEY,
        name TEXT NOT NULL,
        path TEXT NOT NULL,
        created_at INTEGER NOT NULL,
        created_by TEXT NOT NULL DEFAULT \'\'
    )');
    // kind 'update' (a bookmarked game changed), 'reply' (someone answered your message:
    // ref_id = your message, post_id = the newest reply, actor_id = who replied) or 'mod'
    // (a moderator handled your report, or a report about your post: ref_id = the report) or 'sub'
    // (your submission was accepted or rejected: ref_id = the submission, link = where to look) or 'tip'
    // (about your bounty tip: ref_id = the tip, link = where to look)
    $ncols = array_column($pdo->query('PRAGMA table_info(notifications)')->fetchAll(), 'name');
    if (!in_array('kind', $ncols, true)) $pdo->exec("ALTER TABLE notifications ADD COLUMN kind TEXT NOT NULL DEFAULT 'update'");
    if (!in_array('ref_id', $ncols, true)) $pdo->exec('ALTER TABLE notifications ADD COLUMN ref_id INTEGER');
    if (!in_array('post_id', $ncols, true)) $pdo->exec('ALTER TABLE notifications ADD COLUMN post_id INTEGER');
    if (!in_array('actor_id', $ncols, true)) $pdo->exec('ALTER TABLE notifications ADD COLUMN actor_id INTEGER');
    if (!in_array('link', $ncols, true)) $pdo->exec('ALTER TABLE notifications ADD COLUMN link TEXT'); // kind 'sub': where it goes
    if (!in_array('ban_until', $cols, true)) $pdo->exec('ALTER TABLE users ADD COLUMN ban_until INTEGER');
    if (!in_array('ban_reason', $cols, true)) $pdo->exec("ALTER TABLE users ADD COLUMN ban_reason TEXT NOT NULL DEFAULT ''");
    $pdo->exec('CREATE TABLE IF NOT EXISTS user_bans (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        reason TEXT NOT NULL,
        until INTEGER NOT NULL,             -- 0 = for good
        created_at INTEGER NOT NULL,
        by_who TEXT NOT NULL DEFAULT \'\',
        lifted_at INTEGER                   -- set when a moderator lifts it early
    )');
    // a moderator's answer to a bounty tip (admin/bounties.php): outcome 'helpful' or 'no', the message to the tipper
    $tcols = array_column($pdo->query('PRAGMA table_info(bounty_tips)')->fetchAll(), 'name');
    if (!in_array('outcome', $tcols, true)) $pdo->exec("ALTER TABLE bounty_tips ADD COLUMN outcome TEXT NOT NULL DEFAULT ''");
    if (!in_array('reply', $tcols, true)) $pdo->exec("ALTER TABLE bounty_tips ADD COLUMN reply TEXT NOT NULL DEFAULT ''");
    if (!in_array('resolved_at', $tcols, true)) $pdo->exec('ALTER TABLE bounty_tips ADD COLUMN resolved_at INTEGER');
    if (!in_array('resolved_by', $tcols, true)) $pdo->exec("ALTER TABLE bounty_tips ADD COLUMN resolved_by TEXT NOT NULL DEFAULT ''");
    // a suggested bounty that was put on the board: its id there (the suggester hears when it's found)
    if (!in_array('made_bounty', $tcols, true)) $pdo->exec('ALTER TABLE bounty_tips ADD COLUMN made_bounty TEXT');
    $cols = array_column($pdo->query('PRAGMA table_info(reports)')->fetchAll(), 'name');
    if (!in_array('user_id', $cols, true)) $pdo->exec('ALTER TABLE reports ADD COLUMN user_id INTEGER');
    // how a moderator handled it (admin/index.php ?view=report): action 'none', 'hide' or 'delete',
    // and the messages that went to the reporter and to the writer of the post
    if (!in_array('action', $cols, true)) $pdo->exec("ALTER TABLE reports ADD COLUMN action TEXT NOT NULL DEFAULT ''");
    if (!in_array('reporter_note', $cols, true)) $pdo->exec("ALTER TABLE reports ADD COLUMN reporter_note TEXT NOT NULL DEFAULT ''");
    if (!in_array('author_note', $cols, true)) $pdo->exec("ALTER TABLE reports ADD COLUMN author_note TEXT NOT NULL DEFAULT ''");
    if (!in_array('resolved_at', $cols, true)) $pdo->exec('ALTER TABLE reports ADD COLUMN resolved_at INTEGER');
    if (!in_array('resolved_by', $cols, true)) $pdo->exec("ALTER TABLE reports ADD COLUMN resolved_by TEXT NOT NULL DEFAULT ''");
    return $pdo;
}

function salt(): string
{
    return env('HASH_SALT', 'change-me-in-.env');
}

/** Visitor IP, hashed: we never store the raw address. */
function ip_hash(): string
{
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return hash_hmac('sha256', $ip, salt());
}

/**
 * Anonymous visitor id from a long-lived random cookie (no account needed).
 * Used for "you already liked this" and to let people find their own posts.
 */
function visitor_id(): string
{
    $token = $_COOKIE['pg_visitor'] ?? '';
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        $token = bin2hex(random_bytes(16));
        setcookie('pg_visitor', $token, [
            'expires' => time() + 60 * 60 * 24 * 365 * 2,
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE['pg_visitor'] = $token;
    }
    return hash_hmac('sha256', $token, salt());
}

/** True if this IP did $action fewer than $max times in the last $seconds; records the event. */
function rate_ok(string $action, int $max, int $seconds): bool
{
    $pdo = db();
    $since = time() - $seconds;
    $pdo->prepare('DELETE FROM rate_events WHERE created_at < ?')->execute([time() - 86400]);
    $st = $pdo->prepare('SELECT COUNT(*) FROM rate_events WHERE ip_hash = ? AND action = ? AND created_at >= ?');
    $st->execute([ip_hash(), $action, $since]);
    if ((int)$st->fetchColumn() >= $max) return false;
    $pdo->prepare('INSERT INTO rate_events (ip_hash, action, created_at) VALUES (?, ?, ?)')
        ->execute([ip_hash(), $action, time()]);
    return true;
}

/** Likes + review summary for a game, used by the page (for JSON-LD) and the API. */
function community_summary(string $gameId): array
{
    try {
        $pdo = db();
        $likes = $pdo->prepare('SELECT COUNT(*) FROM likes WHERE game_id = ?');
        $likes->execute([$gameId]);
        $bm = $pdo->prepare('SELECT COUNT(*) FROM user_bookmarks WHERE game_id = ?');
        $bm->execute([$gameId]);
        $cl = $pdo->prepare('SELECT kind, COUNT(*) FROM game_clicks WHERE game_id = ? GROUP BY kind');
        $cl->execute([$gameId]);
        $clicks = $cl->fetchAll(PDO::FETCH_KEY_PAIR);
        $r = $pdo->prepare("SELECT COUNT(*) AS n, AVG(rating) AS avg FROM posts WHERE game_id = ? AND kind = 'review' AND hidden = 0");
        $r->execute([$gameId]);
        $row = $r->fetch() ?: ['n' => 0, 'avg' => null];
        return [
            'likes' => (int)$likes->fetchColumn(),
            'bookmarks' => (int)$bm->fetchColumn(), // members' bookmarks (others are only in their browser)
            'downloads' => (int)($clicks['download'] ?? 0),  // clicks on a download link (once per visitor per day)
            'plays' => (int)($clicks['play'] ?? 0),          // clicks on a "Play" (browser) link
            'reviews' => (int)$row['n'],
            'rating' => $row['avg'] !== null ? round((float)$row['avg'], 1) : null,
        ];
    } catch (Throwable $e) {
        // the page still works if the database isn't set up yet
        return ['likes' => 0, 'bookmarks' => 0, 'downloads' => 0, 'plays' => 0, 'reviews' => 0, 'rating' => null];
    }
}

/** Bounties (missing games) from data/bounties.json, keyed by id. */
/** Games with a suggested change (submit.php "Fix game info") that a moderator hasn't handled yet: [game id => count]. */
function pending_game_edits(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    try {
        foreach (db()->query("SELECT game_id, COUNT(*) AS n FROM submissions WHERE kind = 'edit' AND status = 'open' AND game_id IS NOT NULL GROUP BY game_id") as $r) {
            $cache[(string)$r['game_id']] = (int)$r['n'];
        }
    } catch (Throwable $e) {
        error_log('pending_game_edits: ' . $e->getMessage());
    }
    return $cache;
}

function all_bounties(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $raw = @file_get_contents(ROOT . '/data/bounties.json');
    $data = $raw ? json_decode($raw, true) : null;
    $cache = [];
    foreach (($data['bounties'] ?? []) as $b) {
        if (isset($b['id'])) $cache[(string)$b['id']] = $b;
    }
    return $cache;
}

