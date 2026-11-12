<?php
/**
 * Receives the forms of submit.php (multipart, sent by js/submit.js).
 *   kind=game  a new game
 *   kind=edit  corrections for an existing game (game=<id>); when the pictures were
 *              changed, shots_order is the new order: [{keep: path} | {file: n, cropOf?}]
 *              where n is the index in images[]
 * Stored for review in /admin -> Submissions. Returns JSON.
 */

declare(strict_types=1);
require __DIR__ . '/../inc/screenshots.php'; // also loads inc/submissions.php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function reply(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function fail(string $message, int $code = 400, ?string $field = null): never
{
    reply(['error' => $message, 'field' => $field], $code);
}

$saved = [];
$savedAt = []; // index in images[] => stored file
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') fail('Method not allowed', 405);
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'pony-games') fail('Bad request');
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== parse_url('//' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)) {
        fail('Bad origin', 403);
    }
    if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        fail('That was too much to upload at once. Try fewer or smaller pictures.', 413);
    }
    if (!empty($_POST['website'])) reply(['ok' => true]); // honeypot

    $kind = ($_POST['kind'] ?? '') === 'edit' ? 'edit' : 'game';
    $game = null;
    if ($kind === 'edit') {
        $game = find_game($_POST['game'] ?? '');
        if (!$game) fail('Please pick the game you want to correct.', 400, 'game');
    }

    $str = function (string $key, int $max, string $what, bool $multiline = false): string {
        $v = (string)($_POST[$key] ?? '');
        $v = preg_replace($multiline ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u', $multiline ? '' : ' ', $v) ?? '';
        $v = trim(str_replace("\r\n", "\n", $v));
        if (mb_strlen($v) > $max) fail("The $what is too long (at most $max characters).", 400, $key);
        return $v;
    };
    $pick = function (string $key, array $allowed): array {
        $in = array_map('strval', (array)($_POST[$key] ?? []));
        return array_values(array_intersect($allowed, $in)); // keeps our order, drops unknown values
    };
    $url = fn(string $u) => preg_match('#^https?://[^\s<>"]+\.[^\s<>"]+$#i', $u) && strlen($u) <= 500;

    /* ---------------- the game fields ---------------- */
    $v = [];
    $v['title'] = $str('title', 150, 'title');
    if (mb_strlen($v['title']) < 1) fail('Please fill in the title of the game.', 400, 'title');
    $authors = array_values(array_filter(array_map('trim', explode(',', $str('authors', 400, 'list of makers')))));
    if (count($authors) > 12) fail('That are a lot of makers! Please list at most 12.', 400, 'authors');
    foreach ($authors as $a) if (mb_strlen($a) > 60) fail('One of the maker names is too long.', 400, 'authors');
    $v['authors'] = $authors;
    $v['status'] = (string)($_POST['status'] ?? '');
    if (!isset(SUBMIT_STATUSES[$v['status']])) fail('Please choose a status.', 400, 'status');
    $v['releaseDate'] = $str('releaseDate', 10, 'release date');
    if ($v['releaseDate'] !== '' && !preg_match('/^\d{4}(-\d{2}(-\d{2})?)?$/', $v['releaseDate'])) {
        fail('Please write the release date like 2014, 2014-05 or 2014-05-21.', 400, 'releaseDate');
    }
    $engine = (string)($_POST['engine'] ?? 'Unknown');
    if ($engine === 'other') $engine = $str('engine_other', 40, 'engine name');
    elseif (!in_array($engine, SUBMIT_ENGINES, true)) $engine = 'Unknown';
    $v['engine'] = $engine ?: 'Unknown';
    $v['playtime'] = in_array($_POST['playtime'] ?? '', SUBMIT_PLAYTIMES, true) ? $_POST['playtime'] : 'Unknown';
    $v['platforms'] = $pick('platforms', SUBMIT_PLATFORMS);
    $known = known_tags();
    $v['genres'] = $pick('genres', $known['genre']);
    $v['tags'] = $pick('tags', $known['other']);
    $v['warnings'] = $pick('warnings', SUBMIT_WARNINGS);
    $v['characters'] = $pick('characters', SUBMIT_CHARACTERS);
    $v['shortDescription'] = $str('shortDescription', 200, 'short description');
    $v['fullDescription'] = $str('fullDescription', 5000, 'description', true);

    $links = [];
    $types = (array)($_POST['link_type'] ?? []);
    $labels = (array)($_POST['link_label'] ?? []);
    $urls = (array)($_POST['link_url'] ?? []);
    foreach ($urls as $i => $u) {
        $u = trim((string)$u);
        if ($u === '') continue;
        if (!$url($u)) fail('One of the links doesn\'t look like a web address (it should start with https://).', 400, 'links');
        $t = (string)($types[$i] ?? 'download');
        $links[] = ['type' => isset(SUBMIT_LINK_TYPES[$t]) ? $t : 'download', 'label' => mb_substr(trim((string)($labels[$i] ?? '')), 0, 40), 'url' => $u];
    }
    if (count($links) > 20) fail('Please share at most 20 links.', 400, 'links');
    $v['links'] = $links;

    // trailers / gameplay videos, one per line: YouTube or a video file
    $v['videos'] = parse_video_lines($str('videos', 3000, 'list of videos', true), $badVideos);
    if ($badVideos) fail('"' . mb_substr($badVideos[0], 0, 80) . '" isn\'t a YouTube link or an https link to a video file (.mp4, .webm, ...).', 400, 'videos');
    if (count($v['videos']) > SUBMIT_MAX_VIDEOS) fail('Please share at most ' . SUBMIT_MAX_VIDEOS . ' videos.', 400, 'videos');

    if ($kind === 'game') {
        if ($v['status'] !== 'Upcoming' && !$links) fail('Please add at least one link where the game can be found.', 400, 'links');
        if ($v['fullDescription'] === '' && $v['shortDescription'] === '') fail('Please tell us a bit about the game.', 400, 'fullDescription');
    }

    $imageUrls = array_values(array_filter(array_map('trim', preg_split('/\s+/', $str('image_urls', 3000, 'list of picture links', true)) ?: [])));
    foreach ($imageUrls as $u) if (!$url($u)) fail('One of the picture links doesn\'t look like a web address.', 400, 'image_urls');

    $note = $str('note', 3000, 'note', true);
    $name = $str('name', 60, 'name');
    $contact = $str('contact', 120, 'contact info');
    $isDev = !empty($_POST['is_dev']);
    $account = current_user();
    if ($account && $name === '') $name = (string)$account['display_name'];

    /* ---------------- what changed (corrections) ---------------- */
    $data = ['values' => $v];
    if ($kind === 'edit') {
        $cur = game_form_values($game);
        $changes = [];
        foreach ($v as $k => $val) {
            $a = $val;
            $b = $cur[$k] ?? null;
            // order doesn't matter for these lists
            if (in_array($k, ['platforms', 'genres', 'tags', 'warnings', 'characters'], true)) {
                sort($a);
                $b = (array)$b;
                sort($b);
            }
            if ($k === 'engine' && $val === 'Unknown' && ($b === '' || $b === 'Unknown')) continue;
            if ($k === 'playtime' && $val === 'Unknown' && ($b === '' || $b === 'Unknown')) continue;
            if ($a != $b) $changes[$k] = $val;
        }
        // the pictures: new order, removed, cropped or added ones
        $shotOrder = null;
        $raw = json_decode((string)($_POST['shots_order'] ?? ''), true);
        if (is_array($raw)) {
            $current = game_shots($game);
            $shotOrder = [];
            $keeps = [];
            foreach ($raw as $it) {
                if (!is_array($it)) continue;
                if (isset($it['keep']) && in_array($it['keep'], $current, true) && !in_array($it['keep'], $keeps, true)) {
                    $shotOrder[] = ['keep' => $it['keep']];
                    $keeps[] = $it['keep'];
                } elseif (isset($it['file']) && is_int($it['file']) && $it['file'] >= 0 && $it['file'] < SUBMIT_MAX_IMAGES) {
                    $crop = isset($it['cropOf']) && in_array($it['cropOf'], $current, true) ? $it['cropOf'] : '';
                    $shotOrder[] = ['file' => $it['file'], 'cropOf' => $crop];
                }
            }
            $hasNew = (bool)array_filter($shotOrder, fn($x) => isset($x['file']));
            if (!$hasNew && array_column($shotOrder, 'keep') === $current) $shotOrder = null; // nothing really changed
            elseif (!$shotOrder) fail('Please keep at least one picture.', 400, 'images');
            elseif (count($shotOrder) > 60) fail('That are a lot of pictures, please keep it under 60.', 400, 'images');
        }
        if (!$changes && !$imageUrls && $shotOrder === null && mb_strlen($note) < 5) {
            fail('You didn\'t change anything yet. Fix the wrong info in the form, or tell us what\'s wrong in the note.', 400, 'note');
        }
        $data = ['changes' => $changes];
    }
    $data['imageUrls'] = $imageUrls;

    if (!rate_ok('submit', 6, 3600) || !rate_ok('submit-day', 25, 86400)) {
        fail('Thanks for all the help! Please wait a bit before sending more.', 429);
    }

    /* ---------------- pictures ---------------- */
    $files = $_FILES['images'] ?? null;
    if ($files && is_array($files['name'])) {
        $count = 0;
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $exts = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        $bad = function (string $msg) use (&$saved): never {
            foreach ($saved as $f) @unlink(uploads_dir() . '/' . $f);
            fail($msg, 400, 'images');
        };
        foreach ($files['name'] as $i => $origName) {
            if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
            if ($kind === 'edit' && $shotOrder === null) continue; // pictures of a correction always come with the order
            if (++$count > SUBMIT_MAX_IMAGES) $bad('Please upload at most ' . SUBMIT_MAX_IMAGES . ' pictures.');
            if ($files['error'][$i] !== UPLOAD_ERR_OK || $files['size'][$i] > SUBMIT_MAX_IMAGE_BYTES) {
                $bad('Each picture can be at most 5 MB.');
            }
            $mime = $finfo->file($files['tmp_name'][$i]);
            if (!isset($exts[$mime]) || @getimagesize($files['tmp_name'][$i]) === false) {
                $bad('Pictures have to be JPG, PNG, WebP or GIF.');
            }
            $file = bin2hex(random_bytes(12)) . '.' . $exts[$mime];
            if (!move_uploaded_file($files['tmp_name'][$i], uploads_dir() . '/' . $file)) throw new RuntimeException('could not store upload');
            $saved[] = $file;
            $savedAt[$i] = $file;
        }
    }
    if ($kind === 'edit' && $shotOrder !== null) {
        // the new set of pictures; kept ones get a fingerprint so they're found again if the files get renamed
        $shots = [];
        foreach ($shotOrder as $it) {
            if (isset($it['keep'])) {
                $shots[] = ['keep' => $it['keep'], 'sha' => shot_hash($it['keep'])];
            } elseif (isset($savedAt[$it['file']])) {
                $shots[] = $it['cropOf'] !== ''
                    ? ['upload' => $savedAt[$it['file']], 'cropOf' => $it['cropOf'], 'cropSha' => shot_hash($it['cropOf'])]
                    : ['upload' => $savedAt[$it['file']]];
            }
        }
        // uploads that aren't in the order aren't needed
        $inOrder = array_column($shots, 'upload');
        foreach ($saved as $k => $f) if (!in_array($f, $inOrder, true)) { @unlink(uploads_dir() . '/' . $f); unset($saved[$k]); }
        $saved = array_values($saved);
        if (!$shots) fail('Please keep at least one picture.', 400, 'images');
        $data['shots'] = $shots;
    }
    $data['images'] = $saved;

    db()->prepare('INSERT INTO submissions (kind, game_id, data, note, is_dev, name, contact, user_id, ip_hash, created_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$kind, $game ? (string)$game['id'] : null, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                   $note, $isDev ? 1 : 0, $name, $contact, $account ? (int)$account['id'] : null, ip_hash(), time()]);
    reply(['ok' => true], 201);
} catch (Throwable $e) {
    foreach ($saved as $f) @unlink(uploads_dir() . '/' . $f);
    error_log('submit api: ' . $e->getMessage());
    fail('Something went wrong on our side. Please try again later.', 500);
}
