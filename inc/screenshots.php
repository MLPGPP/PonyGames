<?php
/**
 * Screenshot editor (the Pictures part of the game form in admin/games.php): reorder, remove, crop and add a
 * game's pictures, then save them as 0.ext (cover), 1.ext, 2.ext... in the
 * game's own s/<n>/ folder and update games.json.
 *
 * The old files are moved to backups/screenshots/ (next to the database) and
 * linked to the games.json save in content_log, so a rollback puts the
 * pictures back too. They are deleted together with that save's backup
 * (only the last BACKUPS_KEPT saves keep one).
 */

declare(strict_types=1);
require_once __DIR__ . '/content.php';

const SHOT_EXTS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

/** "/s/924/3.jpg" -> ["924", "3.jpg"], or null for pictures outside s/ (shared placeholders etc.). */
function shot_parts(string $path): ?array
{
    return preg_match('#^/?s/(\d+)/([0-9A-Za-z_.-]+\.(?:jpe?g|png|webp|gif))$#i', $path, $m) ? [$m[1], $m[2]] : null;
}

/** folder number => list of game ids using pictures in it. */
function shot_folder_users(array $games): array
{
    $users = [];
    foreach ($games as $g) {
        foreach (array_merge([(string)($g['thumbnail'] ?? '')], (array)($g['screenshots'] ?? [])) as $p) {
            if ($parts = shot_parts((string)$p)) $users[$parts[0]][(string)$g['id']] = true;
        }
    }
    // ids as strings (PHP turns numeric array keys into ints)
    return array_map(fn($ids) => array_map('strval', array_keys($ids)), $users);
}

/** The pictures of a game in display order: cover first, then the screenshots. */
function game_shots(array $g): array
{
    $list = [];
    foreach (array_merge([(string)($g['thumbnail'] ?? '')], (array)($g['screenshots'] ?? [])) as $p) {
        if ($p !== '' && !in_array($p, $list, true)) $list[] = $p;
    }
    return $list;
}

/**
 * The folder to write this game's pictures to: its current folder when no other
 * game uses it, otherwise a new, unused number (so other games never break).
 */
function shot_target_folder(array $g, array $users): string
{
    $own = null;
    foreach (game_shots($g) as $p) if ($parts = shot_parts($p)) { $own = $parts[0]; break; }
    $isMine = fn($n) => !isset($users[$n]) || $users[$n] === [(string)$g['id']];
    if ($own !== null && $isMine($own)) return $own;
    $id = (string)(int)$g['id'];
    if ($isMine($id) && !is_dir(ROOT . '/s/' . $id)) return $id;
    $n = max(array_merge([0], array_map('intval', array_keys($users)))) + 1;
    while (is_dir(ROOT . '/s/' . $n) || isset($users[(string)$n])) $n++;
    return (string)$n;
}

/**
 * Writes the new set of pictures. $items, in order (first = cover):
 *   ['keep' => '/s/924/3.jpg']                 an existing picture (file is renamed)
 *   ['keep' => '/assets/x.jpg']                a picture outside s/ (kept as it is)
 *   ['file' => '/tmp/...', 'ext' => 'jpg']     a new or cropped picture
 * Returns [thumbnail, screenshots, backup] where backup is ['folder' => ..., 'backup' => dir in
 * backups/screenshots/ or '' when the folder had no pictures yet]; pass it on to save_content().
 */
function save_game_shots(array $g, array $items, array $users): array
{
    $folder = shot_target_folder($g, $users);
    $dir = ROOT . '/s/' . $folder;
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) throw new RuntimeException("could not create s/$folder");
    if (!is_writable($dir)) throw new RuntimeException("s/$folder is not writable by PHP. Check the folder permissions on the server.");
    $sharedOld = [];
    foreach (game_shots($g) as $p) {
        if (($parts = shot_parts($p)) && ($users[$parts[0]] ?? []) !== [(string)$g['id']]) $sharedOld[$parts[0]] = true;
    }

    // 1. put the new set together in a staging folder (copies, nothing is changed yet)
    $stage = $dir . '/.staging-' . bin2hex(random_bytes(4));
    mkdir($stage, 0775);
    $paths = [];
    try {
        foreach (array_values($items) as $i => $it) {
            if (isset($it['keep'])) {
                $parts = shot_parts($it['keep']);
                if (!$parts) { $paths[] = $it['keep']; continue; } // outside s/: keep the path
                $src = ROOT . '/s/' . $parts[0] . '/' . $parts[1];
                if (!is_file($src)) throw new RuntimeException('Picture ' . $it['keep'] . ' was not found on the server.');
                $ext = strtolower(pathinfo($parts[1], PATHINFO_EXTENSION)) === 'jpeg' ? 'jpg' : strtolower(pathinfo($parts[1], PATHINFO_EXTENSION));
                if (!copy($src, "$stage/$i.$ext")) throw new RuntimeException('could not copy ' . $it['keep']);
            } else {
                $ext = $it['ext'];
                if (!copy($it['file'], "$stage/$i.$ext")) throw new RuntimeException('could not store a new picture');
            }
            @chmod("$stage/$i.$ext", 0664);
            $paths[] = '/s/' . $folder . '/' . $i . '.' . $ext;
        }

        // 2. move the old pictures of this folder out of the way (into the backups)
        $trash = backups_dir() . '/screenshots/' . $folder . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(2));
        $moved = [];
        foreach (glob($dir . '/*.{jpg,jpeg,png,webp,gif,JPG,PNG}', GLOB_BRACE) ?: [] as $f) {
            if (!is_dir($trash)) mkdir($trash, 0770, true);
            if (!rename($f, $trash . '/' . basename($f))) throw new RuntimeException('could not move ' . basename($f));
            $moved[] = basename($f);
        }
        // 3. and put the new set in place
        foreach (glob($stage . '/*') ?: [] as $f) {
            if (!rename($f, $dir . '/' . basename($f))) {
                // undo: put the old pictures back
                foreach ($moved as $m) @rename($trash . '/' . $m, $dir . '/' . $m);
                throw new RuntimeException('could not move the new pictures into place');
            }
        }
    } finally {
        foreach (glob($stage . '/*') ?: [] as $f) @unlink($f);
        @rmdir($stage);
    }
    $thumb = $paths[0] ?? '';
    return [$thumb, array_slice($paths, 1), ['folder' => (string)$folder, 'backup' => $moved ? basename($trash) : '']];
}

/* ---------------------------------------------------------------- pictures in submissions (submit.php) */

/** Fingerprint of a picture in s/, to recognise it again after the files were renamed. '' for others. */
function shot_hash(string $path): string
{
    $parts = shot_parts($path);
    $file = $parts ? ROOT . '/s/' . $parts[0] . '/' . $parts[1] : '';
    return $file !== '' && is_file($file) ? (string)sha1_file($file) : '';
}

/**
 * Turns the picture order of a correction (its "shots": [{keep, sha} | {upload, cropOf?}])
 * into items for save_game_shots(). Kept pictures are looked up by their fingerprint, so
 * they're still found when the game's pictures were reordered in the meantime.
 * $missing counts pictures that can't be found anymore (they're left out).
 */
function submission_shot_items(array $game, array $shots, int &$missing = 0): array
{
    $current = game_shots($game);
    $byHash = null;
    // where is this picture now? (same path and same file, or found by its fingerprint)
    $find = function (string $p, string $sha) use ($current, &$byHash): ?string {
        if (in_array($p, $current, true) && ($sha === '' || shot_hash($p) === $sha)) return $p;
        if ($sha === '') return null;
        if ($byHash === null) {
            $byHash = [];
            foreach ($current as $c) if (($h = shot_hash($c)) !== '' && !isset($byHash[$h])) $byHash[$h] = $c;
        }
        return $byHash[$sha] ?? null;
    };
    $items = [];
    $used = [];
    foreach ($shots as $s) {
        if (isset($s['upload'])) {
            $f = basename((string)$s['upload']);
            $file = uploads_dir() . '/' . $f;
            if (!preg_match('/^[a-f0-9]{24}\.(jpg|png|webp|gif)$/', $f) || !is_file($file)) { $missing++; continue; }
            $crop = isset($s['cropOf']) ? ($find((string)$s['cropOf'], (string)($s['cropSha'] ?? '')) ?? '') : '';
            $items[] = ['file' => $file, 'ext' => pathinfo($f, PATHINFO_EXTENSION), 'upload' => $f, 'cropOf' => $crop];
            continue;
        }
        $found = $find((string)($s['keep'] ?? ''), (string)($s['sha'] ?? ''));
        if ($found === null) { $missing++; continue; }
        if (isset($used[$found])) continue;
        $used[$found] = true;
        $items[] = ['keep' => $found];
    }
    return $items;
}

/** The paths the items will get once saved with save_game_shots() (for the admin's preview). */
function shot_item_paths(array $game, array $items, array $users): array
{
    $folder = shot_target_folder($game, $users);
    $paths = [];
    foreach (array_values($items) as $i => $it) {
        if (isset($it['keep']) && !shot_parts($it['keep'])) { $paths[] = $it['keep']; continue; }
        $ext = strtolower(isset($it['keep']) ? pathinfo($it['keep'], PATHINFO_EXTENSION) : $it['ext']);
        $paths[] = '/s/' . $folder . '/' . $i . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
    }
    return $paths;
}

/* ---------------------------------------------------------------- pictures of resources (admin/resources.php) */

/** "/asset_previews/12/3.png" -> ["12", "3.png"], or null for pictures outside a resource's own folder (placeholders, old single files). */
function resource_shot_parts(string $path): ?array
{
    return preg_match('#^/?asset_previews/(\d+)/([0-9A-Za-z_.-]+\.(?:jpe?g|png|webp|gif))$#i', $path, $m) ? [$m[1], $m[2]] : null;
}

/** The pictures of a resource in display order: the card picture (thumbnail) first, then the previews. */
function resource_shots(array $r): array
{
    $list = [];
    foreach (array_merge([(string)($r['thumbnail'] ?? '')], (array)($r['previews'] ?? [])) as $p) {
        if ($p !== '' && !in_array($p, $list, true)) $list[] = $p;
    }
    return $list;
}

/**
 * Writes the new set of pictures of resource $id to asset_previews/<id>/ as 0.ext, 1.ext, …
 * $items as for save_game_shots(); pictures outside that folder keep their path. The old files of
 * the folder are moved to backups/asset_previews/. Returns the paths in order (the first is the card picture).
 */
function save_resource_shots(int $id, array $items): array
{
    $dir = ROOT . '/asset_previews/' . $id;
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) throw new RuntimeException("could not create asset_previews/$id");
    if (!is_writable($dir)) throw new RuntimeException("asset_previews/$id is not writable by PHP. Check the folder permissions on the server.");
    $stage = $dir . '/.staging-' . bin2hex(random_bytes(4));
    mkdir($stage, 0775);
    $paths = [];
    try {
        $n = 0;
        foreach (array_values($items) as $it) {
            if (isset($it['keep'])) {
                $parts = resource_shot_parts($it['keep']);
                if (!$parts || $parts[0] !== (string)$id) { $paths[] = $it['keep']; continue; } // not ours: keep the path
                $src = $dir . '/' . $parts[1];
                if (!is_file($src)) throw new RuntimeException('Picture ' . $it['keep'] . ' was not found on the server.');
                $ext = strtolower(pathinfo($parts[1], PATHINFO_EXTENSION));
                if ($ext === 'jpeg') $ext = 'jpg';
                if (!copy($src, "$stage/$n.$ext")) throw new RuntimeException('could not copy ' . $it['keep']);
            } else {
                $ext = $it['ext'];
                if (!copy($it['file'], "$stage/$n.$ext")) throw new RuntimeException('could not store a new picture');
            }
            @chmod("$stage/$n.$ext", 0664);
            $paths[] = '/asset_previews/' . $id . '/' . $n . '.' . $ext;
            $n++;
        }
        // the old pictures of this folder out of the way (kept in the backups), then the new ones in place
        $trash = backups_dir() . '/asset_previews/' . $id . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(2));
        $moved = [];
        foreach (glob($dir . '/*.{jpg,jpeg,png,webp,gif,JPG,JPEG,PNG,GIF,WEBP}', GLOB_BRACE) ?: [] as $f) {
            if (!is_dir($trash)) mkdir($trash, 0770, true);
            if (!rename($f, $trash . '/' . basename($f))) throw new RuntimeException('could not move ' . basename($f));
            $moved[] = basename($f);
        }
        foreach (glob($stage . '/*') ?: [] as $f) {
            if (!rename($f, $dir . '/' . basename($f))) {
                foreach ($moved as $m) @rename($trash . '/' . $m, $dir . '/' . $m);
                throw new RuntimeException('could not move the new pictures into place');
            }
        }
    } finally {
        foreach (glob($stage . '/*') ?: [] as $f) @unlink($f);
        @rmdir($stage);
    }
    return $paths;
}
