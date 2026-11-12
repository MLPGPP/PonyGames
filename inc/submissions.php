<?php
/**
 * Game submissions and corrections (submit.php -> api/submit.php -> /admin).
 *
 * Nothing goes into data/games.json (or s/) until a moderator accepts the
 * submission in /admin; then it can be published right away.
 */

declare(strict_types=1);
require_once __DIR__ . '/auth.php';

const SUBMIT_STATUSES  = ['Released' => 'Released', 'In Development' => 'Unfinished (playable, still being made)', 'Upcoming' => 'Upcoming (announced, nothing playable yet)', 'Cancelled' => 'Cancelled'];
const SUBMIT_PLAYTIMES = ['Unknown', '<5 min', '5m-30m', '30m-2h', '2h-4h', '4h-10h', '10h+'];
const SUBMIT_PLATFORMS = ['Windows', 'Web', 'Flash', 'Linux', 'OSX', 'Android', 'iOS', 'VR', 'Source code'];
const SUBMIT_ENGINES   = ['Unknown', 'Unity', 'Godot', 'Gamemaker', 'RPGMaker', "Ren'Py", 'Construct', 'HTML5', 'Flash', 'UE', 'Clickteam Fusion', 'Java', 'LÖVE', 'Blender', 'Custom/None'];
const SUBMIT_CHARACTERS = ['M6', 'CMC', 'Secondary Cast', 'Background', 'OC'];
const SUBMIT_WARNINGS  = ['NSFW', 'Gore'];
const SUBMIT_LINK_TYPES = [
    'download' => 'Download',
    'play'     => 'Play in browser',
    'page'     => 'Game page / author site',
    'source'   => 'Source code',
    'support'  => 'Patreon / support',
    'social'   => 'Social / devlog',
];
const SUBMIT_MAX_IMAGES = 12; // new or cropped pictures per submission
const SUBMIT_MAX_IMAGE_BYTES = 5 * 1024 * 1024;

/**
 * The 11-character id of a YouTube video link (watch?v=, youtu.be/, /embed/,
 * /shorts/, /live/, youtube-nocookie.com), or null when it isn't one.
 */
function youtube_id(string $url): ?string
{
    $url = trim($url);
    if (!preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
    $p = parse_url($url);
    $host = strtolower(preg_replace('/^(www\.|m\.|music\.)/', '', (string)($p['host'] ?? '')));
    $path = (string)($p['path'] ?? '');
    $id = null;
    if ($host === 'youtu.be') {
        $id = explode('/', ltrim($path, '/'))[0];
    } elseif ($host === 'youtube.com' || $host === 'youtube-nocookie.com') {
        if (preg_match('#^/(?:embed|shorts|live|v)/([^/?]+)#', $path, $m)) {
            $id = $m[1];
        } else {
            parse_str((string)($p['query'] ?? ''), $q);
            $id = is_string($q['v'] ?? null) ? $q['v'] : null;
        }
    }
    return $id !== null && preg_match('/^[A-Za-z0-9_-]{11}$/', $id) ? $id : null;
}

/** "https://www.youtube.com/watch?v=<id>", the form videos are kept in games.json. */
function youtube_url(string $id): string
{
    return 'https://www.youtube.com/watch?v=' . $id;
}

/** Extensions of video files a browser can play by itself. */
const VIDEO_FILE_EXTENSIONS = ['mp4', 'webm', 'ogv', 'ogg', 'm4v', 'mov'];

/** A direct https link to a video file (.mp4, .webm, ...), tidied, or null. */
function video_file_url(string $url): ?string
{
    $url = trim($url);
    if (!preg_match('#^https://[^\s"\'<>]+$#i', $url) || !filter_var($url, FILTER_VALIDATE_URL)) return null;
    $ext = strtolower(pathinfo((string)parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
    return in_array($ext, VIDEO_FILE_EXTENSIONS, true) ? $url : null;
}

/** A link from games.json "videos" as ['youtube' => id] or ['file' => url], or null. */
function video_entry(string $url): ?array
{
    if (($id = youtube_id($url)) !== null) return ['youtube' => $id];
    if (($file = video_file_url($url)) !== null) return ['file' => $file];
    return null;
}

/**
 * Video links, one per line: YouTube videos as tidy YouTube links, video files
 * (https, .mp4/.webm/...) as they are; duplicates dropped. Other lines come back in $bad.
 */
function parse_video_lines(string $text, ?array &$bad = null): array
{
    $bad = [];
    $out = [];
    foreach (preg_split('/\R/', $text) ?: [] as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $entry = video_entry($line);
        if ($entry === null) { $bad[] = $line; continue; }
        $out[] = isset($entry['youtube']) ? youtube_url($entry['youtube']) : $entry['file'];
    }
    return array_values(array_unique($out));
}

/** Genre and other tag labels that already exist, so people pick the same words. */
function known_tags(): array
{
    static $tags = null;
    if ($tags !== null) return $tags;
    $count = ['genre' => [], 'other' => []];
    foreach (all_games() as $g) {
        foreach ($g['tags'] ?? [] as $t) {
            if (!is_array($t) || empty($t['label'])) continue;
            $type = tag_type($t);
            if ($type === 'genre') $count['genre'][$t['label']] = ($count['genre'][$t['label']] ?? 0) + 1;
            elseif ($type === 'other') $count['other'][$t['label']] = ($count['other'][$t['label']] ?? 0) + 1;
        }
    }
    arsort($count['genre']);
    arsort($count['other']);
    unset($count['other']['Cash Grab']); // moderators decide that one
    return $tags = ['genre' => array_keys($count['genre']), 'other' => array_keys($count['other'])];
}

function uploads_dir(): string
{
    $dir = dirname(community_db_path()) . '/uploads';
    if (!is_dir($dir)) mkdir($dir, 0770, true);
    return $dir;
}

/** The fields of a game as the submit form uses them (also used to compare edits). */
function game_form_values(array $g): array
{
    $tags = ['genres' => [], 'tags' => [], 'warnings' => []];
    foreach ($g['tags'] ?? [] as $t) {
        if (!is_array($t) || empty($t['label'])) continue;
        $type = tag_type($t);
        if ($type === 'genre') $tags['genres'][] = $t['label'];
        elseif ($type === 'warning') $tags['warnings'][] = $t['label'];
        elseif ($type === 'other') $tags['tags'][] = $t['label'];
    }
    $links = [];
    if (!empty($g['url'])) $links[] = ['type' => 'page', 'label' => '', 'url' => $g['url']];
    foreach ((array)($g['downloads'] ?? []) as $d) {
        if (empty($d['url']) || $d['url'] === ($g['url'] ?? '')) continue;
        $kind = classify_download($d);
        // in games.json "source" means the author's site; source code is a download labelled so
        $isCode = (bool)preg_match('/source\s*code/i', ($d['label'] ?? '') . ' ' . ($d['buttonText'] ?? ''));
        $links[] = ['type' => $isCode ? 'source' : ($kind === 'web' ? 'play' : ($kind === 'source' ? 'page' : 'download')),
                    'label' => trim(($d['label'] ?? '') . (!empty($d['version']) ? ' ' . $d['version'] : '')), 'url' => $d['url']];
    }
    foreach ((array)($g['socials'] ?? []) as $s) {
        if (!empty($s['url'])) $links[] = ['type' => 'social', 'label' => (string)($s['label'] ?? ''), 'url' => $s['url']];
    }
    return [
        'title' => (string)($g['title'] ?? ''),
        'authors' => array_values(array_filter((array)($g['author'] ?? []), 'is_string')),
        'status' => (string)($g['status'] ?? ''),
        'releaseDate' => (string)($g['releaseDate'] ?? ''),
        'engine' => (string)($g['engine'] ?? ''),
        'playtime' => (string)($g['playtime'] ?? ''),
        'platforms' => array_values((array)($g['platforms'] ?? [])),
        'genres' => $tags['genres'],
        'tags' => $tags['tags'],
        'warnings' => $tags['warnings'],
        'characters' => array_values(array_diff((array)($g['characters'] ?? []), ['Unknown'])),
        'shortDescription' => (string)($g['shortDescription'] ?? ''),
        'fullDescription' => (string)($g['fullDescription'] ?? ''),
        'links' => $links,
        'videos' => array_values(array_filter((array)($g['videos'] ?? []), fn($x) => is_string($x) && video_entry($x) !== null)),
    ];
}

const SUBMIT_FIELD_LABELS = [
    'title' => 'Title', 'authors' => 'Made by', 'status' => 'Status', 'releaseDate' => 'Release date',
    'engine' => 'Engine', 'playtime' => 'Playtime', 'platforms' => 'Platforms', 'genres' => 'Genres',
    'tags' => 'Other tags', 'warnings' => 'Content warnings', 'characters' => 'Characters',
    'shortDescription' => 'Short description', 'fullDescription' => 'Description', 'links' => 'Links',
    'videos' => 'Videos',
];
const SUBMIT_MAX_VIDEOS = 10;

/** Builds a games.json entry from submitted values (for new games, or an edited copy of an existing one). */
function build_game_json(array $v, ?array $base = null, ?int $newId = null, array $imagePaths = []): array
{
    $g = $base ?? [
        'id' => $newId, 'title' => '', 'author' => [], 'thumbnail' => '', 'shortDescription' => '', 'fullDescription' => '',
        'tags' => [], 'characters' => ['Unknown'], 'playtime' => 'Unknown', 'status' => 'Released', 'engine' => 'Unknown',
        'platforms' => [], 'url' => '', 'releaseDate' => '', 'dateAdded' => date('Y-m-d'), 'downloads' => null, 'screenshots' => [],
    ];
    $set = fn(string $k) => array_key_exists($k, $v);
    if ($set('title')) $g['title'] = $v['title'];
    if ($set('authors')) $g['author'] = $v['authors'];
    if ($set('status')) $g['status'] = $v['status'];
    if ($set('releaseDate')) $g['releaseDate'] = $v['releaseDate'];
    if ($set('engine')) $g['engine'] = $v['engine'] ?: 'Unknown';
    if ($set('playtime')) $g['playtime'] = $v['playtime'] ?: 'Unknown';
    if ($set('platforms')) $g['platforms'] = $v['platforms'];
    if ($set('characters')) $g['characters'] = $v['characters'] ?: ['Unknown'];
    if ($set('fullDescription')) $g['fullDescription'] = $v['fullDescription'];
    if ($set('shortDescription') || $set('fullDescription')) {
        $short = $v['shortDescription'] ?? $g['shortDescription'];
        if ($short === '' && $g['fullDescription'] !== '') {
            $short = mb_strlen($g['fullDescription']) > 50 ? rtrim(mb_substr($g['fullDescription'], 0, 50)) . '...' : $g['fullDescription'];
        }
        $g['shortDescription'] = $short;
    }
    if ($set('genres') || $set('tags') || $set('warnings')) {
        $old = $g['tags'] ?? [];
        $keep = array_values(array_filter($old, fn($t) => is_array($t) && !in_array(tag_type($t), ['genre', 'other', 'warning'], true)));
        $cur = game_form_values(['tags' => $old]);
        $tags = [];
        foreach ($v['genres'] ?? $cur['genres'] as $l) $tags[] = ['label' => $l, 'type' => 'Genre'];
        foreach ($v['tags'] ?? $cur['tags'] as $l) $tags[] = ['label' => $l, 'type' => 'Other'];
        foreach ($v['warnings'] ?? $cur['warnings'] as $l) $tags[] = ['label' => $l, 'type' => 'warning'];
        // existing "Cash Grab" stays, it isn't offered in the form
        foreach ($old as $t) if (is_array($t) && ($t['label'] ?? '') === 'Cash Grab') $tags[] = $t;
        $g['tags'] = array_merge($tags, $keep);
    }
    if ($set('links')) {
        $downloads = [];
        $socials = [];
        $url = '';
        foreach ($v['links'] as $l) {
            if ($l['type'] === 'page' && $url === '') {
                $url = $l['url'];
            } elseif (in_array($l['type'], ['download', 'play', 'source', 'page'], true)) {
                [$label, $button, $type] = match ($l['type']) {
                    'play'   => ['Web', 'Play', 'web'],
                    'source' => ['Source code', 'Source code', 'download'],
                    'page'   => ['Website', 'Author Site', 'source'],
                    default  => ['Download', 'Download', 'download'],
                };
                $downloads[] = ['label' => $l['label'] !== '' ? $l['label'] : $label, 'url' => $l['url'], 'buttonText' => $button, 'type' => $type];
            } else {
                $socials[] = array_filter(['url' => $l['url'], 'label' => $l['label']], fn($x) => $x !== '');
            }
        }
        // keep mirrors the moderators added to downloads that are still there
        foreach ((array)($g['downloads'] ?? []) as $old) {
            foreach ($downloads as &$d) {
                if ($d['url'] === ($old['url'] ?? null) && !empty($old['mirrors'])) $d['mirrors'] = $old['mirrors'];
            }
            unset($d);
        }
        $g['url'] = $url !== '' ? $url : ($downloads[0]['url'] ?? ($g['url'] ?? ''));
        $g['downloads'] = $downloads ?: null;
        if ($socials) $g['socials'] = $socials; else unset($g['socials']);
    }
    if ($set('videos')) {
        if ($v['videos']) $g['videos'] = array_values($v['videos']); else unset($g['videos']);
    }
    if ($imagePaths) {
        if (empty($g['thumbnail']) || $base === null) $g['thumbnail'] = $imagePaths[0];
        $g['screenshots'] = array_values(array_unique(array_merge($g['screenshots'] ?? [], $base === null ? array_slice($imagePaths, 1) : $imagePaths)));
    }
    return $g;
}

/** Plain-text version of a field value for the admin's before/after table. */
function submission_value_text($value): string
{
    if (is_array($value)) {
        if ($value && is_array(reset($value))) {
            return implode("\n", array_map(fn($l) => (SUBMIT_LINK_TYPES[$l['type']] ?? $l['type']) . ($l['label'] !== '' ? ' (' . $l['label'] . ')' : '') . ': ' . $l['url'], $value));
        }
        return implode(', ', $value);
    }
    return (string)$value;
}
