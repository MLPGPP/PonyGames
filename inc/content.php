<?php
/**
 * Editing the site content (data/games.json, data/resources.json) from /admin.
 *
 * Every save first copies the old file to the backups folder (next to the
 * database) and is logged in the content_log table. The last BACKUPS_KEPT
 * saves can be rolled back from /admin. The files in the repo
 * and on the server can drift apart: use "Download games.json" in /admin to
 * bring the server's version back into git.
 */

declare(strict_types=1);
require_once __DIR__ . '/submissions.php';
require_once __DIR__ . '/sheet_sync.php';
require_once __DIR__ . '/notify.php';

const CONTENT_FILES = [
    // games.json is written like .NET's System.Text.Json does (\u0027, \u002B, \u00E9...), so saving here keeps git diffs small
    'games' => ['file' => 'data/games.json', 'indent' => 2, 'flags' => JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT, 'dotnet' => true],
    'resources' => ['file' => 'data/resources.json', 'indent' => 4, 'flags' => JSON_UNESCAPED_SLASHES],
    'bounties' => ['file' => 'data/bounties.json', 'indent' => 2, 'flags' => JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE],
    'jams' => ['file' => 'data/jams.json', 'indent' => 2, 'flags' => JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE],
    'jam-config' => ['file' => 'data/jam-config.json', 'indent' => 2, 'flags' => JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE],
    'posts' => ['file' => 'data/posts.json', 'indent' => 2, 'flags' => JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE],
];
const BACKUPS_KEPT = 3; // how many saves per file can be rolled back
const GAME_STATUSES = ['Released', 'In Development', 'Upcoming', 'Cancelled'];
const GAME_TAG_TYPES = ['Genre', 'Other', 'warning', 'Black Tags'];

function content_path(string $which): string
{
    return ROOT . '/' . CONTENT_FILES[$which]['file'];
}

function load_content(string $which): array
{
    $data = json_decode((string)file_get_contents(content_path($which)), true);
    if (!is_array($data)) throw new RuntimeException(CONTENT_FILES[$which]['file'] . ' could not be read');
    return $data;
}

function encode_content(string $which, array $data): string
{
    $cfg = CONTENT_FILES[$which];
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | $cfg['flags']);
    if (!empty($cfg['dotnet'])) {
        // also escape + and ` like .NET, and write the hex digits in capitals
        $json = preg_replace_callback('/"(?:[^"\\\\]|\\\\.)*"/', function ($m) {
            $str = strtr($m[0], ['+' => '\\u002B', '`' => '\\u0060']);
            return preg_replace_callback('/\\\\u([0-9a-f]{4})/', fn($u) => '\\u' . strtoupper($u[1]), $str);
        }, $json);
    }
    if ($cfg['indent'] !== 4) {
        // PHP indents with 4 spaces; JSON strings never contain raw newlines, so this is safe
        $json = preg_replace_callback('/^( +)/m', fn($m) => str_repeat(' ', intdiv(strlen($m[1]), 4) * $cfg['indent']), $json);
    }
    return $json;
}

function backups_dir(): string
{
    $dir = dirname(uploads_dir()) . '/backups';
    if (!is_dir($dir)) mkdir($dir, 0770, true);
    return $dir;
}

/** Writes the file safely: backup, write to a temp file, then swap it in. */
function save_content(string $which, array $data, string $what, array $shots = []): int
{
    $path = content_path($which);
    $lock = fopen(backups_dir() . '/.' . $which . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('could not lock ' . $which);
    try {
        if (!is_writable($path) || !is_writable(dirname($path))) {
            throw new RuntimeException(CONTENT_FILES[$which]['file'] . ' is not writable by PHP. Check the file permissions on the server.');
        }
        if ($which === 'resources') {
            // empty packs are {} in the file; PHP would turn them into []
            foreach ($data['allAssetPacks'] ?? [] as $k => $v) if ($v === []) $data['allAssetPacks'][$k] = new stdClass();
        }
        $json = encode_content($which, $data);
        $before = (string)file_get_contents($path);
        if (str_ends_with($before, "\n")) $json .= "\n"; // keep the file's own style, for small git diffs
        $backup = $which . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(2)) . '.json';
        copy($path, backups_dir() . '/' . $backup);
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $json) === false || !rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('could not write ' . CONTENT_FILES[$which]['file']);
        }
        $who = function_exists('admin_who') ? admin_who() : '';
        db()->prepare('INSERT INTO content_log (file, what, created_at, backup, who, shots) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$which, mb_substr($what, 0, 300), time(), $backup, $who, $shots ? json_encode(array_values($shots)) : '']);
        $logId = (int)db()->lastInsertId();
        // keep only the newest backups
        $st = db()->prepare("SELECT id, backup, shots FROM content_log WHERE file = ? AND (backup != '' OR shots != '') ORDER BY id DESC LIMIT -1 OFFSET " . BACKUPS_KEPT);
        $st->execute([$which]);
        foreach ($st->fetchAll() as $row) {
            if ($row['backup'] !== '') @unlink(backups_dir() . '/' . basename($row['backup']));
            drop_shot_backups($row['shots']);
            db()->prepare("UPDATE content_log SET backup = '', shots = '' WHERE id = ?")->execute([$row['id']]);
        }
        foreach (glob(backups_dir() . '/' . $which . '-*.json') ?: [] as $f) {
            // files from before rollbacks existed (not in the log): clean up
            $known = db()->prepare('SELECT 1 FROM content_log WHERE backup = ?');
            $known->execute([basename($f)]);
            if (!$known->fetchColumn()) @unlink($f);
        }
        if ($which === 'games') {
            sheet_sync_later(); // copy to the Google Sheet tab (inc/sheet_sync.php)
            // tell members about changes to games they bookmarked (inc/notify.php)
            notify_game_updates((array)(json_decode($before, true)['games'] ?? []), (array)($data['games'] ?? []));
        }
        if ($which === 'games') {
            // picture backups that no rollback can use anymore (also the ones from before this existed)
            $used = [];
            foreach (db()->query("SELECT shots FROM content_log WHERE shots != ''")->fetchAll(PDO::FETCH_COLUMN) as $j) {
                foreach (json_decode($j, true) ?: [] as $sh) if (($sh['backup'] ?? '') !== '') $used[$sh['backup']] = true;
            }
            foreach (glob(backups_dir() . '/screenshots/*', GLOB_ONLYDIR) ?: [] as $d) {
                if (!isset($used[basename($d)])) remove_flat_dir($d);
            }
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    return $logId;
}

/** Deletes a folder that only holds files (picture backups). */
function remove_flat_dir(string $dir): void
{
    foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) if (is_file($f)) @unlink($f);
    @rmdir($dir);
}

/** Deletes the picture backups of one content_log entry (its shots column). */
function drop_shot_backups(string $shotsJson): void
{
    foreach (json_decode($shotsJson, true) ?: [] as $sh) {
        $b = basename((string)($sh['backup'] ?? ''));
        if ($b !== '' && $b !== '.' && $b !== '..') remove_flat_dir(backups_dir() . '/screenshots/' . $b);
    }
}

/**
 * Puts a game folder's pictures back from a picture backup. The first time a folder is
 * touched in a rollback its current pictures are kept as a new backup (in $keep), so the
 * rollback itself can be undone; later (older) steps simply replace what is there.
 */
function restore_shot_folder(array $sh, array &$keep): void
{
    $folder = (string)($sh['folder'] ?? '');
    if (!preg_match('/^\d+$/', $folder)) return;
    $dir = ROOT . '/s/' . $folder;
    $files = is_dir($dir) ? (glob($dir . '/*.{jpg,jpeg,png,webp,gif,JPG,PNG}', GLOB_BRACE) ?: []) : [];
    if (!isset($keep[$folder])) {
        $name = '';
        if ($files) {
            $name = $folder . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(2));
            mkdir(backups_dir() . '/screenshots/' . $name, 0770, true);
            foreach ($files as $f) rename($f, backups_dir() . '/screenshots/' . $name . '/' . basename($f));
        }
        $keep[$folder] = ['folder' => $folder, 'backup' => $name];
    } else {
        foreach ($files as $f) @unlink($f);
    }
    $b = basename((string)($sh['backup'] ?? ''));
    $from = backups_dir() . '/screenshots/' . $b;
    if ($b !== '' && is_dir($from)) {
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        foreach (glob($from . '/*') ?: [] as $f) rename($f, $dir . '/' . basename($f));
        @rmdir($from);
    } elseif (is_dir($dir) && !(glob($dir . '/*') ?: [])) {
        @rmdir($dir); // the folder was new in that save
    }
}

/** Puts pictures into the website folder: games in s/<id>/, resources in asset_previews/. Returns site paths. */
function store_site_images(array $files, string $folder, int $startAt = 0): array
{
    $dir = ROOT . '/' . trim($folder, '/');
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) throw new RuntimeException("could not create $folder");
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $exts = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $paths = [];
    $n = $startAt;
    foreach ($files as $f) {
        $mime = $finfo->file($f['tmp']);
        if (!isset($exts[$mime]) || @getimagesize($f['tmp']) === false) continue;
        while (glob($dir . '/' . $n . '.*')) $n++; // never overwrite an existing picture
        $name = $n . '.' . $exts[$mime];
        $ok = !empty($f['uploaded']) ? move_uploaded_file($f['tmp'], "$dir/$name") : copy($f['tmp'], "$dir/$name");
        if ($ok) {
            @chmod("$dir/$name", 0664);
            $paths[] = '/' . trim($folder, '/') . '/' . $name;
        }
        $n++;
    }
    return $paths;
}

/** $_FILES[name] (multiple) as a simple list. */
function uploaded_list(string $field): array
{
    $out = [];
    $f = $_FILES[$field] ?? null;
    if (!$f || !is_array($f['name'])) return $out;
    foreach ($f['name'] as $i => $n) {
        if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || $f['size'][$i] > 10 * 1024 * 1024) continue;
        $out[] = ['tmp' => $f['tmp_name'][$i], 'name' => $n, 'uploaded' => true];
    }
    return $out;
}

function lines(string $text): array
{
    return array_values(array_filter(array_map('trim', preg_split('/\R/', $text) ?: []), fn($l) => $l !== ''));
}

/* ---------------------------------------------------------------------------
   Games
   --------------------------------------------------------------------------- */

function next_game_id(array $games): int
{
    $max = -1;
    foreach ($games as $g) $max = max($max, (int)$g['id']);
    return $max + 1;
}

/** Builds a games.json entry from the admin game form. Unknown keys of $old are kept. */
function game_from_post(array $p, array $old): array
{
    $t = fn($k) => trim(str_replace("\r\n", "\n", (string)($p[$k] ?? '')));
    $g = $old;
    $g['title'] = $t('title');
    $g['author'] = lines($t('author'));
    // pictures come from the screenshot editor (see admin/games.php); keep them when the fields aren't sent
    $g['thumbnail'] = array_key_exists('thumbnail', $p) ? $t('thumbnail') : (string)($old['thumbnail'] ?? '');
    $g['fullDescription'] = $t('fullDescription');
    $short = $t('shortDescription');
    if ($short === '' && $g['fullDescription'] !== '') {
        $short = mb_strlen($g['fullDescription']) > 50 ? rtrim(mb_substr($g['fullDescription'], 0, 50)) . '...' : $g['fullDescription'];
    }
    $g['shortDescription'] = $short;

    $tags = [];
    foreach ((array)($p['tag_label'] ?? []) as $i => $label) {
        $label = trim((string)$label);
        if ($label === '') continue;
        $type = (string)($p['tag_type'][$i] ?? 'Other');
        $tags[] = ['label' => $label, 'type' => in_array($type, GAME_TAG_TYPES, true) ? $type : 'Other'];
    }
    $g['tags'] = $tags;
    $chars = array_values(array_unique(array_merge((array)($p['characters'] ?? []), lines(str_replace(',', "\n", $t('characters_extra'))))));
    $g['characters'] = $chars ?: ['Unknown'];
    $g['playtime'] = $t('playtime') ?: 'Unknown';
    $g['status'] = in_array($p['status'] ?? '', GAME_STATUSES, true) ? $p['status'] : 'Released';
    $g['engine'] = $t('engine') ?: 'Unknown';
    $g['platforms'] = array_values(array_unique(array_merge((array)($p['platforms'] ?? []), lines($t('platforms_extra')))));
    $g['url'] = $t('url');
    $g['releaseDate'] = $t('releaseDate');
    $g['dateAdded'] = $t('dateAdded') ?: date('Y-m-d');

    $downloads = [];
    foreach ((array)($p['dl_url'] ?? []) as $i => $url) {
        $url = trim((string)$url);
        if ($url === '') continue;
        $d = ['label' => trim((string)($p['dl_label'][$i] ?? ''))];
        if (($v = trim((string)($p['dl_version'][$i] ?? ''))) !== '') $d['version'] = $v;
        $d['url'] = $url;
        if (($b = trim((string)($p['dl_button'][$i] ?? ''))) !== '') $d['buttonText'] = $b;
        if (in_array($p['dl_type'][$i] ?? '', ['download', 'web', 'source'], true)) $d['type'] = $p['dl_type'][$i];
        $mirrors = [];
        foreach (lines((string)($p['dl_mirrors'][$i] ?? '')) as $line) {
            [$a, $b] = array_pad(array_map('trim', explode('|', $line, 2)), 2, '');
            if ($b === '') { $b = $a; $a = parse_url($a, PHP_URL_HOST) ?: 'Mirror'; }
            $mirrors[] = ['url' => $b, 'label' => $a];
        }
        if ($mirrors) $d['mirrors'] = $mirrors;
        $downloads[] = $d;
    }
    $g['downloads'] = $downloads ?: null;

    $socials = [];
    foreach (lines($t('socials')) as $line) {
        [$a, $b] = array_pad(array_map('trim', explode('|', $line, 2)), 2, '');
        $socials[] = $b === '' ? ['url' => $a] : ['url' => $b, 'label' => $a];
    }
    if ($socials) $g['socials'] = $socials; else unset($g['socials']);

    $g['screenshots'] = array_key_exists('screenshots', $p) ? lines($t('screenshots')) : array_values((array)($old['screenshots'] ?? []));

    // YouTube videos and video files, shown after the screenshots; bad lines are kept so game_problems() can name them
    if (array_key_exists('videos', $p)) {
        $videos = parse_video_lines($t('videos'), $badVideos);
        $videos = array_merge($videos, $badVideos);
        if ($videos) $g['videos'] = $videos; else unset($g['videos']);
    }
    return $g;
}

/** Checks a game entry; returns a list of problems (empty = fine). */
function game_problems(array $g): array
{
    $e = [];
    if (trim((string)($g['title'] ?? '')) === '') $e[] = 'The title is empty.';
    if (($g['releaseDate'] ?? '') !== '' && !preg_match('/^\d{4}(-\d{2}(-\d{2})?)?$/', (string)$g['releaseDate'])) $e[] = 'Release date should look like 2014, 2014-05 or 2014-05-21.';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($g['dateAdded'] ?? ''))) $e[] = 'Date added should look like 2024-01-31.';
    foreach ((array)($g['downloads'] ?? []) as $d) {
        if (!preg_match('#^https?://#i', (string)$d['url'])) $e[] = 'Download link "' . $d['url'] . '" should start with https://';
    }
    if (($g['url'] ?? '') !== '' && !preg_match('#^https?://#i', (string)$g['url'])) $e[] = 'The website link should start with https://';
    foreach ((array)($g['videos'] ?? []) as $v) {
        if (!is_string($v) || video_entry($v) === null) $e[] = 'Video "' . (is_string($v) ? $v : json_encode($v)) . '" isn\'t a YouTube link or an https link to a video file (.mp4, .webm, .ogv, .m4v, .mov).';
    }
    return $e;
}

/** The s/<n>/ folder to put new pictures of this game in (never a folder another game uses too). */
function game_image_folder(array $g): string
{
    require_once __DIR__ . '/screenshots.php';
    return 's/' . shot_target_folder($g, shot_folder_users(all_games()));
}

/** Copies a submission's uploaded pictures into s/<id>/ and returns their site paths. */
function publish_submission_images(array $files, string $folder, int $startAt): array
{
    $list = [];
    foreach ($files as $f) {
        if (preg_match('/^[a-f0-9]{24}\.(jpg|png|webp|gif)$/', $f) && is_file($p = uploads_dir() . '/' . $f)) $list[] = ['tmp' => $p, 'name' => $f];
    }
    return $list ? store_site_images($list, $folder, $startAt) : [];
}

/* ---------------------------------------------------------------------------
   Resources
   --------------------------------------------------------------------------- */

function resource_from_post(array $p, array $old, array $licenses): array
{
    $t = fn($k) => trim(str_replace("\r\n", "\n", (string)($p[$k] ?? '')));
    $r = $old;
    $r['name'] = $t('name');
    $r['description'] = $t('description');
    $r['attribution'] = $t('attribution');
    $r['author'] = lines($t('author')) ?: ['Anonymous'];
    $r['tags'] = array_values(array_unique(array_merge((array)($p['tags'] ?? []), lines($t('tags_extra')))));
    $r['assetPacks'] = lines($t('assetPacks'));
    $r['primaryAssetPack'] = $t('primaryAssetPack');
    if ($r['primaryAssetPack'] !== '' && !in_array($r['primaryAssetPack'], $r['assetPacks'], true)) array_unshift($r['assetPacks'], $r['primaryAssetPack']);
    $lic = [];
    foreach ((array)($p['license'] ?? []) as $name) {
        if (isset($licenses[$name])) $lic[] = ['name' => $name, 'url' => $licenses[$name]];
    }
    $r['license'] = $lic;
    $r['downloads'] = lines($t('downloads'));
    // pictures: from the picture editor (admin/resources.php, saved separately); the old text fields still work
    if (array_key_exists('previews', $p)) $r['previews'] = lines($t('previews'));
    if (array_key_exists('thumbnail', $p) || array_key_exists('previews', $p)) $r['thumbnail'] = $t('thumbnail') ?: ($r['previews'][0] ?? '');
    // videos (YouTube or video file links), shown after the pictures, like for games
    $videos = array_values(array_filter(lines($t('videos')), fn($u) => preg_match('#^https?://#i', $u)));
    if ($videos) $r['videos'] = $videos; else unset($r['videos']);
    return $r;
}

/** Keeps the lists at the top of resources.json in step with the assets. */
function refresh_resource_lists(array $data): array
{
    $add = function (array $list, array $values): array {
        foreach ($values as $v) if ($v !== '' && !in_array($v, $list, true)) $list[] = $v;
        return $list;
    };
    foreach ($data['assets'] as $a) {
        $data['allTags'] = $add($data['allTags'] ?? [], $a['tags'] ?? []);
        $data['allAuthors'] = $add($data['allAuthors'] ?? [], $a['author'] ?? []);
        $data['allLicenses'] = $add($data['allLicenses'] ?? [], array_column($a['license'] ?? [], 'name'));
        foreach ($a['assetPacks'] ?? [] as $pack) {
            if ($pack !== '' && !isset($data['allAssetPacks'][$pack])) $data['allAssetPacks'][$pack] = new stdClass();
        }
    }
    return $data;
}

/** name => url of the licenses used so far (plus a few common ones). */
function known_licenses(array $data): array
{
    $out = [
        'CC-BY 4.0' => 'https://creativecommons.org/licenses/by/4.0/',
        'CC-BY-SA 4.0' => 'https://creativecommons.org/licenses/by-sa/4.0/',
        'CC-BY-NC 4.0' => 'https://creativecommons.org/licenses/by-nc/4.0/',
        'CC-BY-NC-SA 3.0' => 'https://creativecommons.org/licenses/by-nc-sa/3.0/',
        'CC-BY-NC-SA 4.0' => 'https://creativecommons.org/licenses/by-nc-sa/4.0/',
        'CC0 1.0' => 'https://creativecommons.org/publicdomain/zero/1.0/',
        'GPL 3.0' => 'https://www.gnu.org/licenses/gpl-3.0.html',
        'MIT' => 'https://opensource.org/license/mit',
    ];
    foreach ($data['assets'] as $a) foreach ($a['license'] ?? [] as $l) if (!empty($l['name'])) $out[$l['name']] = $l['url'] ?? ($out[$l['name']] ?? '');
    ksort($out);
    return $out;
}

/* ---------------------------------------------------------------------------
   Recent changes + rollback
   --------------------------------------------------------------------------- */

function recent_changes(string $which, int $limit = 10): array
{
    $st = db()->prepare('SELECT id, what, created_at, backup, who FROM content_log WHERE file = ? ORDER BY id DESC LIMIT ' . $limit);
    $st->execute([$which]);
    return array_map(function ($r) {
        $r['can_undo'] = $r['backup'] !== '' && is_file(backups_dir() . '/' . basename($r['backup']));
        return $r;
    }, $st->fetchAll());
}

/** Puts the file back as it was before log entry $logId (undoing it and everything after it). */
function rollback_content(string $which, int $logId): string
{
    $st = db()->prepare('SELECT * FROM content_log WHERE id = ? AND file = ?');
    $st->execute([$logId, $which]);
    $row = $st->fetch();
    if (!$row || $row['backup'] === '') throw new RuntimeException('That change can\'t be rolled back anymore.');
    $file = backups_dir() . '/' . basename($row['backup']);
    $data = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
    if (!is_array($data)) throw new RuntimeException('The backup for that change is missing or damaged.');
    $label = 'Rolled back to before: ' . $row['what'];
    $later = db()->prepare('SELECT id, what, shots FROM content_log WHERE file = ? AND id >= ? ORDER BY id DESC');
    $later->execute([$which, $logId]);
    $later = $later->fetchAll();
    foreach ($later as $r) {
        // picture saves from before picture rollbacks existed: the files can't be put back
        if ($r['shots'] === '' && str_ends_with($r['what'], '(pictures)')) {
            throw new RuntimeException('That change can\'t be rolled back: pictures were changed after it before picture rollbacks existed.');
        }
    }
    // put the pictures back, newest change first
    $keep = [];
    foreach ($later as $r) foreach (json_decode($r['shots'], true) ?: [] as $sh) restore_shot_folder($sh, $keep);
    // the undone changes can't be rolled back on their own anymore; the rollback itself can be undone
    db()->prepare("UPDATE content_log SET backup = '', shots = '' WHERE file = ? AND id >= ?")->execute([$which, $logId]);
    save_content($which, $data, $label, array_values($keep));
    @unlink($file);
    return $label;
}

/** The "Recent changes" box with undo buttons, shown at the top of games.php / resources.php. */
function render_recent_changes(string $which, string $csrf, string $title = 'Recent changes'): void
{
    // only the changes that can still be rolled back
    $rows = array_values(array_filter(recent_changes($which, 20), fn($r) => $r['can_undo']));
    if (!$rows) return;
    echo '<details class="panel changes" open><summary><strong>' . h($title) . '</strong> <span class="muted">the last '
        . BACKUPS_KEPT . ' saves can be rolled back</span></summary><ul class="log">';
    foreach ($rows as $r) {
        echo '<li><span>' . h($r['what']) . ' <span class="muted">' . date('Y-m-d H:i', (int)$r['created_at']) . ($r['who'] !== '' ? ' · by ' . h($r['who']) : '') . '</span></span>';
        {
            echo '<form method="post"><input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="do" value="rollback"><input type="hidden" name="which" value="' . h($which) . '">'
                . '<input type="hidden" name="log" value="' . (int)$r['id'] . '">'
                . '<button class="small" data-confirm="Put ' . h(basename(CONTENT_FILES[$which]['file'])) . ' back to how it was before this change? Later changes are undone too.">↶ Roll back</button></form>';
        }
        echo '</li>';
    }
    echo '</ul></details>';
}

